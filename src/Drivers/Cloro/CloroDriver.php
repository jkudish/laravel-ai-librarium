<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Cloro;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Recovery\DurableSubmission;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage as PricingUsage;

/**
 * Collects one Cloro observation: a consumer AI surface, a Google results
 * page, or a Google News page.
 *
 * Live Profiles make one synchronous /v1/monitor call and never retry it.
 * Standard Profiles submit one async task under an idempotency key derived
 * from the Run, so a lost acknowledgement is recovered from Cloro's 409
 * instead of creating and billing a second task.
 *
 * @phpstan-type Endpoint array{path: string, task: string, input: 'prompt'|'query', kind: 'surface'|'search'|'news', surface: ?string, credits: int, async: bool, options: list<string>, devices?: list<string>}
 */
readonly class CloroDriver implements DefinesSpendingBound, Driver
{
    private const string ORIGIN = 'https://api.cloro.dev';

    private const float CONNECT_TIMEOUT_SECONDS = 5.0;

    /** Cloro asks for a client timeout of at least 300 seconds on sync calls. */
    private const int SYNC_TIMEOUT_SECONDS = 330;

    private const int TASK_TIMEOUT_SECONDS = 30;

    private const int MAX_BODY_BYTES = 8_000_000;

    private const int MAX_PROMPT_CHARACTERS = 10_000;

    /** Bing puts the prompt in its page URL, so Cloro limits the encoded prompt. */
    private const int MAX_BING_ENCODED_PROMPT_BYTES = 3_500;

    /** The sync surcharge Cloro adds to every /v1/monitor call. */
    private const int SYNC_SURCHARGE_CREDITS = 2;

    /**
     * Async base credits from https://cloro.dev/docs/guides/providers (checked 2026-11-27).
     *
     * @var array<string, Endpoint>
     */
    private const array ENDPOINTS = [
        'chatgpt' => ['path' => 'chatgpt', 'task' => 'CHATGPT', 'input' => 'prompt', 'kind' => 'surface', 'surface' => 'chatgpt', 'credits' => 5, 'async' => true, 'options' => ['country']],
        'gemini' => ['path' => 'gemini', 'task' => 'GEMINI', 'input' => 'prompt', 'kind' => 'surface', 'surface' => 'gemini', 'credits' => 4, 'async' => true, 'options' => ['country']],
        'google-ai-mode' => ['path' => 'aimode', 'task' => 'AIMODE', 'input' => 'prompt', 'kind' => 'surface', 'surface' => 'google_ai_mode', 'credits' => 4, 'async' => true, 'options' => ['country', 'hl', 'location', 'device'], 'devices' => ['desktop', 'mobile']],
        'google-ai-overview' => ['path' => 'google', 'task' => 'GOOGLE', 'input' => 'query', 'kind' => 'surface', 'surface' => 'google_ai_overview', 'credits' => 5, 'async' => true, 'options' => ['country', 'hl', 'location', 'device'], 'devices' => ['desktop', 'mobile', 'ios', 'android']],
        'bing-ai-mode' => ['path' => 'bing/aimode', 'task' => 'BING_AIMODE', 'input' => 'prompt', 'kind' => 'surface', 'surface' => 'bing_ai_mode', 'credits' => 4, 'async' => true, 'options' => ['country']],
        'google-search' => ['path' => 'google', 'task' => 'GOOGLE', 'input' => 'query', 'kind' => 'search', 'surface' => null, 'credits' => 3, 'async' => false, 'options' => ['country', 'hl', 'location', 'device'], 'devices' => ['desktop', 'mobile', 'ios', 'android']],
        'google-news' => ['path' => 'google/news', 'task' => 'GOOGLE_NEWS', 'input' => 'query', 'kind' => 'news', 'surface' => null, 'credits' => 3, 'async' => false, 'options' => ['country', 'hl', 'device'], 'devices' => ['desktop', 'mobile', 'ios', 'android']],
    ];

    public function __construct(private CloroResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'country' => ['bail', 'required', 'string', 'regex:/^[A-Z]{2}$/D'],
            'hl' => ['bail', 'sometimes', 'string', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D'],
            'location' => ['bail', 'sometimes', 'string', 'min:1', 'max:255'],
            'device' => ['bail', 'sometimes', 'string', 'in:desktop,mobile,ios,android'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        [$sku, $endpoint, $live] = $this->descriptor($request->profile);
        $payload = $this->payload($request, $endpoint);

        if ($live) {
            return $this->runLive($request, $sku, $endpoint, $payload);
        }

        $taskId = $this->submit($request, $endpoint, $payload);
        $request->progress('Cloro accepted the task; waiting for its result.');
        for ($attempt = 0; ; $attempt++) {
            try {
                $result = $this->observe($request, $sku, $endpoint, $taskId);
            } catch (DriverException $exception) {
                if (! in_array($exception->errorCode, ['cloro.connection', 'cloro.rate_limited', 'cloro.unavailable'], true)) {
                    throw $exception;
                }
                $result = null;
            }
            if ($result !== null) {
                return $result;
            }
            $this->wait($request, $attempt);
        }
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        [$sku, $endpoint, $live] = $this->descriptor($request->profile);
        $credits = $endpoint['credits'] + ($live ? self::SYNC_SURCHARGE_CREDITS : 0);

        return SpendingBound::billableQuantities(
            new ModelIdentity('cloro', $sku),
            new PricingUsage(['credits' => $credits]),
        );
    }

    public function submitDurable(DriverRequest $request): DurableSubmission
    {
        [, $endpoint, $live] = $this->descriptor($request->profile);
        if ($live) {
            throw new DriverException('cloro.not_durable', 'Cloro Live Profiles run inline and cannot be submitted for later retrieval.', false);
        }

        return new DurableSubmission($this->submit($request, $endpoint, $this->payload($request, $endpoint)));
    }

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult
    {
        [$sku, $endpoint, $live] = $this->descriptor($request->profile);
        if ($live || ! Str::isUuid($reference)) {
            throw new DriverException('cloro.not_durable', 'This Cloro Profile has no durable task to retrieve.', false);
        }
        $this->credential($request->profile);

        return $this->observe($request, $sku, $endpoint, $reference);
    }

    /**
     * @param  Endpoint  $endpoint
     * @param  array<string, mixed>  $payload
     */
    private function runLive(DriverRequest $request, string $sku, array $endpoint, array $payload): ResearchResult
    {
        $remaining = $this->remainingSeconds($request);
        try {
            $response = $this->http($request, min(self::SYNC_TIMEOUT_SECONDS, $remaining))
                ->post(self::ORIGIN.'/v1/monitor/'.$endpoint['path'], $payload);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);
            $this->remainingSeconds($request);

            throw new DriverException('cloro.connection', 'Cloro could not be reached; the request was not retried.', false);
        }
        $request->captureResponse($response, Operation::Inline);
        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }
        $json = $this->json($response);
        if (($json['success'] ?? null) !== true || ! is_array($json['result'] ?? null) || array_is_list($json['result'])) {
            throw self::invalidResponse('Cloro returned an invalid response envelope.');
        }

        return $this->result($request, $sku, $endpoint, $json['result'], 'live', $this->number($response->header('X-Credits-Charged')));
    }

    /**
     * @param  Endpoint  $endpoint
     * @param  array<string, mixed>  $payload
     */
    private function submit(DriverRequest $request, array $endpoint, array $payload): string
    {
        $key = 'librarium-'.$request->requestId.'-'.$request->profile->id;
        $body = ['taskType' => $endpoint['task'], 'payload' => $payload, 'idempotencyKey' => $key];

        // The idempotency key makes a second attempt safe: Cloro answers a
        // reused key with 409 and the existing task instead of a new task.
        for ($attempt = 0; ; $attempt++) {
            $remaining = $this->remainingSeconds($request);
            try {
                $response = $this->http($request, min(self::TASK_TIMEOUT_SECONDS, $remaining))->post(self::ORIGIN.'/v1/async/task', $body);
            } catch (ConnectionException) {
                $request->captureNoResponse(Operation::Submit);
                if ($attempt > 0) {
                    throw new DriverException('cloro.ambiguous_submission', 'The Cloro task submission outcome is unknown.', false);
                }

                continue;
            }
            $request->captureResponse($response, Operation::Submit);
            if (! $response->serverError() || $attempt > 0) {
                break;
            }
        }

        if ($response->status() === 409) {
            $existing = data_get($this->json($response), 'error.details.taskId');
            if (data_get($this->json($response), 'error.code') !== 'RESOURCE_ALREADY_EXISTS' || ! is_string($existing) || ! Str::isUuid($existing)) {
                throw self::invalidResponse('Cloro returned an invalid idempotency conflict.');
            }

            return $existing;
        }
        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }
        $json = $this->json($response);
        $task = $json['task'] ?? null;
        if (($json['success'] ?? null) !== true || ! is_array($task)
            || ! is_string($task['id'] ?? null) || ! Str::isUuid($task['id'])
            || ($task['taskType'] ?? null) !== $endpoint['task']
            || ! in_array($task['idempotencyKey'] ?? $key, [$key], true)) {
            throw self::invalidResponse('Cloro returned an invalid task receipt.');
        }

        return $task['id'];
    }

    /** @param Endpoint $endpoint */
    private function observe(DriverRequest|RecoveryRequest $request, string $sku, array $endpoint, string $taskId): ?ResearchResult
    {
        $remaining = $this->remainingSeconds($request);
        try {
            $response = $this->http($request, min(self::TASK_TIMEOUT_SECONDS, $remaining))
                ->get(self::ORIGIN.'/v1/async/task/'.$taskId);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Retrieve);

            throw new DriverException('cloro.connection', 'Cloro could not be reached while retrieving the task.', false);
        }
        $request->captureResponse($response, Operation::Retrieve);

        if ($response->status() === 404) {
            throw new DriverException('cloro.task_expired', 'Cloro no longer has this task; it deletes finished tasks 24 hours after creation.', false);
        }
        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }
        $json = $this->json($response);
        $task = $json['task'] ?? null;
        $status = is_array($task) ? ($task['status'] ?? null) : null;
        if (! is_array($task) || ($task['id'] ?? null) !== $taskId || ($task['taskType'] ?? null) !== $endpoint['task']
            || ! in_array($status, ['QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED'], true)) {
            throw self::invalidResponse('Cloro returned a status for a different task.');
        }
        if (in_array($status, ['QUEUED', 'PROCESSING'], true)) {
            if ($request instanceof DriverRequest) {
                $request->progress('Cloro is still processing the task.');
            }

            return null;
        }
        $result = $json['response'] ?? null;
        if (! is_array($result) || array_is_list($result)) {
            throw self::invalidResponse('Cloro returned a finished task without a response.');
        }
        if ($status === 'FAILED') {
            throw match (data_get($result, 'error.code')) {
                'INSUFFICIENT_CREDITS' => new DriverException('cloro.insufficient_credits', 'The Cloro account ran out of credits before the task ran.', false),
                'BILLING_SUSPENDED' => new DriverException('cloro.billing_suspended', 'Cloro suspended the account for an overdue invoice.', false),
                default => new DriverException('cloro.task_failed', 'Cloro could not complete the task.', false),
            };
        }

        return $this->result($request, $sku, $endpoint, $result, 'standard', $this->number(data_get($json, 'credits.creditsCharged')));
    }

    /**
     * @param  Endpoint  $endpoint
     * @param  array<array-key, mixed>  $data
     */
    private function result(DriverRequest|RecoveryRequest $request, string $sku, array $endpoint, array $data, string $mode, int|float|null $credits): ResearchResult
    {
        $meta = array_filter([
            'endpoint' => $mode === 'live' ? '/v1/monitor/'.$endpoint['path'] : '/v1/async/task ('.$endpoint['task'].')',
            'mode' => $mode,
            'credits_charged' => $credits,
            'requested_controls' => (object) array_intersect_key($request->profile->options, array_flip($endpoint['options'])),
        ], static fn (mixed $value): bool => $value !== null);

        $result = match ($endpoint['kind']) {
            'surface' => $this->mapper->surface($request, $data, $sku, (string) $endpoint['surface'], (object) $meta),
            default => $this->mapper->search($request, $data, $endpoint['kind'] === 'news', (object) $meta),
        };

        return TerminalRedaction::guard($result, [(string) $request->profile->credential], self::invalidResponse('Cloro returned unsafe response content.'));
    }

    /**
     * @param  Endpoint  $endpoint
     * @return array<string, mixed>
     */
    private function payload(DriverRequest $request, array $endpoint): array
    {
        $this->credential($request->profile);
        $prompt = $request->prompt;
        if (trim($prompt) === '' || mb_strlen($prompt) > self::MAX_PROMPT_CHARACTERS
            || ($endpoint['path'] === 'bing/aimode' && strlen(urlencode($prompt)) > self::MAX_BING_ENCODED_PROMPT_BYTES)) {
            throw new DriverException('cloro.invalid_query', $endpoint['path'] === 'bing/aimode'
                ? 'The Bing AI Mode prompt must be 1 to 3,500 characters once URL-encoded.'
                : 'The Cloro prompt must contain 1 to 10,000 characters.', false);
        }

        $options = $request->profile->options;
        $country = $options['country'] ?? null;
        $device = $options['device'] ?? null;
        if (array_diff(array_keys($options), $endpoint['options']) !== []
            || ! is_string($country) || preg_match('/^[A-Z]{2}$/D', $country) !== 1
            || ($device !== null && ! in_array($device, $endpoint['devices'] ?? [], true))
            || (isset($options['hl']) && ! is_string($options['hl']))
            || (isset($options['location']) && (! is_string($options['location']) || trim($options['location']) === '' || mb_strlen($options['location']) > 255))) {
            throw new DriverException('cloro.invalid_options', 'The Cloro Profile received unsupported or invalid controls.', false);
        }

        $payload = [$endpoint['input'] => $prompt, ...$options];
        $include = match ($endpoint['kind']) {
            'surface' => $endpoint['surface'] === 'google_ai_overview' ? ['aioverview' => ['markdown' => true]] : ['markdown' => true],
            default => null,
        };
        if ($include !== null) {
            $payload['include'] = $include;
        }

        return $payload;
    }

    /** @return array{string, Endpoint, bool} */
    private function descriptor(Profile $profile): array
    {
        $id = $profile->id;
        $live = str_ends_with($id, '-live');
        $sku = substr($live ? substr($id, 0, -5) : $id, 6);
        $endpoint = str_starts_with($id, 'cloro-') ? (self::ENDPOINTS[$sku] ?? null) : null;
        if ($endpoint === null || ($live && ! $endpoint['async']) || $profile->provider !== 'cloro') {
            throw new DriverException('cloro.unsupported_profile', 'The requested Cloro Profile is not supported.', false);
        }

        return [$sku, $endpoint, $live || ! $endpoint['async']];
    }

    private function credential(Profile $profile): string
    {
        $credential = $profile->credential;
        if (! is_string($credential) || trim($credential) === '' || preg_match('/[\x00-\x20\x7f]/', $credential) === 1) {
            throw new DriverException('cloro.authentication', 'The Cloro provider is not configured.', false);
        }

        return $credential;
    }

    private function http(DriverRequest|RecoveryRequest $request, float|int $timeout): PendingRequest
    {
        return Http::withToken($this->credential($request->profile))
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $timeout))
            ->timeout($timeout);
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        if (strlen($response->body()) > self::MAX_BODY_BYTES) {
            throw self::invalidResponse('Cloro returned an oversized response.');
        }
        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw self::invalidResponse('Cloro returned a malformed response.');
        }

        return $json;
    }

    private function httpFailure(Response $response): DriverException
    {
        $json = $response->json();
        $code = is_array($json) ? data_get($json, 'error.code') : null;

        return match (true) {
            $response->status() === 401 => new DriverException('cloro.authentication', 'Cloro rejected the configured API key.', false),
            $response->status() === 403 && $code === 'BILLING_SUSPENDED' => new DriverException('cloro.billing_suspended', 'Cloro suspended the account for an overdue invoice.', false),
            $response->status() === 403 => new DriverException('cloro.insufficient_credits', 'The Cloro account has insufficient credits.', false),
            $response->status() === 429 => new DriverException('cloro.rate_limited', $code === 'CONCURRENCY_LIMIT_EXCEEDED'
                ? 'Every Cloro concurrency slot of the plan is in use.'
                : 'Cloro rate limited this request.'),
            $response->status() === 400 => new DriverException('cloro.invalid_request', 'Cloro rejected the request fields.', false),
            $response->serverError() => new DriverException('cloro.unavailable', 'Cloro is temporarily unavailable.'),
            default => new DriverException('cloro.provider', 'Cloro reported an error for this request.', false),
        };
    }

    private function number(mixed $value): int|float|null
    {
        if (is_string($value) && is_numeric($value)) {
            $value = filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : (float) $value;
        }

        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 ? $value : null;
    }

    private function wait(DriverRequest $request, int $attempt): void
    {
        Sleep::sleep(min($attempt === 0 ? 5 : 15, $this->remainingSeconds($request)));
        $this->remainingSeconds($request);
    }

    private function remainingSeconds(DriverRequest|RecoveryRequest $request): int
    {
        $remaining = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($remaining < 1) {
            throw new DriverException('cloro.deadline_exceeded', 'Cloro did not complete before the shared research deadline.', false);
        }

        return max(1, (int) floor($remaining));
    }

    public static function invalidResponse(string $message): DriverException
    {
        return new DriverException('cloro.invalid_response', $message, false);
    }
}
