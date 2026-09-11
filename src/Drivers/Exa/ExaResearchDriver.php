<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Exa;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Recovery\DurableSubmission;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use stdClass;
use Throwable;

/** Runs one durable Exa Agent task for the exact exa/research Profile. */
final readonly class ExaResearchDriver implements DurableDriver
{
    private const string ENDPOINT = 'https://api.exa.ai/agent/runs';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const int REQUEST_TIMEOUT_SECONDS = 30;

    private const int POLL_INTERVAL_SECONDS = 1;

    /** @var list<string> */
    private const array EFFORTS = ['minimal', 'low', 'medium', 'high', 'xhigh', 'auto', 'max'];

    /** @var list<string> */
    private const array STATUSES = ['queued', 'running', 'completed', 'failed', 'cancelled'];

    public function __construct(private ExaResearchResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'effort' => ['sometimes', 'string', 'in:'.implode(',', self::EFFORTS)],
            'system_prompt' => ['sometimes', 'string', 'min:1'],
            'output_schema' => ['sometimes', 'array', 'min:1'],
            'max_cost_dollars' => [
                'sometimes',
                'prohibited_if:options.effort,minimal,low,medium,high,xhigh',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ((! is_int($value) && ! is_float($value))
                        || $value <= 0
                        || (is_float($value) && ! is_finite($value))) {
                        $fail("The {$attribute} field must be a finite positive number.");
                    }
                },
            ],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $credential = $this->preflight($request);
        $run = $this->submit($request, $credential);
        $runId = $this->runId($run);
        $request->progress('Exa is researching in the background.');

        while (in_array($this->status($run), ['queued', 'running'], true)) {
            $this->waitBeforePolling($request);
            $run = $this->retrieveRun($request, $credential, $runId);
        }

        $status = $this->status($run);
        if (in_array($status, ['failed', 'cancelled'], true)) {
            throw new DriverException(
                'exa_research.failed',
                "Exa Research ended with status {$status}.",
                false,
            );
        }

        if ($status !== 'completed') {
            throw $this->invalidResponse();
        }

        // A fresh retrieval is authoritative and makes completion delivery safe
        // when a create or polling response was observed more than once.
        $terminal = $this->retrieveRun($request, $credential, $runId, Operation::Retrieve);
        if ($this->status($terminal) !== 'completed') {
            throw $this->invalidResponse();
        }

        return TerminalRedaction::guard(
            $this->mapper->map($request, $terminal),
            [$credential, $runId],
            $this->invalidResponse(),
        );
    }

    public function submitDurable(DriverRequest $request): DurableSubmission
    {
        $credential = $this->preflight($request);

        return new DurableSubmission(
            reference: $this->runId($this->submit($request, $credential, false)),
        );
    }

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult
    {
        $credential = $this->preflight($request);

        try {
            $response = $this->send(
                $request,
                $credential,
                'get',
                self::ENDPOINT.'/'.rawurlencode($reference),
                [],
                Operation::Retrieve,
            );
        } catch (ConnectionException) {
            throw new DriverException(
                'exa_research.connection',
                'Exa Research could not be reached while retrieving the accepted run.',
                false,
            );
        }

        if ($response->status() !== 200) {
            throw $this->httpFailure($response);
        }

        $run = $this->runPayload($response);
        if ($this->runId($run) !== $reference) {
            throw $this->invalidResponse();
        }

        return match ($this->status($run)) {
            'queued', 'running' => null,
            'completed' => TerminalRedaction::guard(
                $this->mapper->map($request, $run),
                [$credential, $reference],
                $this->invalidResponse(),
            ),
            'failed', 'cancelled' => throw new DriverException(
                'exa_research.failed',
                'Exa Research ended without a completed result.',
                false,
            ),
            default => throw $this->invalidResponse(),
        };
    }

    private function preflight(DriverRequest|RecoveryRequest $request): string
    {
        $profile = $request->profile;
        if ($profile->id !== 'exa-research'
            || $profile->provider !== 'exa'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::ResearchReport
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::ResearchAgent]
        ) {
            throw new DriverException(
                'exa_research.invalid_profile',
                'The Exa Research Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        if (! $this->validOptions($profile->options)
            || ($request instanceof DriverRequest && trim($request->prompt) === '')) {
            throw new DriverException(
                'exa_research.invalid_request',
                'The Exa Research query or options are invalid.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException(
                'exa_research.authentication',
                'The Exa Research provider is not configured.',
                false,
            );
        }

        $this->remainingSeconds($request);

        return (string) $profile->credential;
    }

    /** @param array<string, mixed> $options */
    private function validOptions(array $options): bool
    {
        if (array_diff(array_keys($options), ['effort', 'system_prompt', 'output_schema', 'max_cost_dollars']) !== []) {
            return false;
        }

        $effort = $options['effort'] ?? 'auto';
        if (! is_string($effort) || ! in_array($effort, self::EFFORTS, true)) {
            return false;
        }

        if (array_key_exists('system_prompt', $options)
            && (! is_string($options['system_prompt']) || trim($options['system_prompt']) === '')) {
            return false;
        }

        if (array_key_exists('output_schema', $options)
            && (! is_array($options['output_schema'])
                || array_is_list($options['output_schema'])
                || ! $this->isJsonValue($options['output_schema']))) {
            return false;
        }

        if (! array_key_exists('max_cost_dollars', $options)) {
            return true;
        }

        $cost = $options['max_cost_dollars'];

        return (is_int($cost) || is_float($cost))
            && $cost > 0
            && (! is_float($cost) || is_finite($cost))
            && in_array($effort, ['auto', 'max'], true);
    }

    private function isJsonValue(mixed $value): bool
    {
        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        if (! is_array($value)) {
            return false;
        }

        return array_all($value, fn (mixed $item): bool => $this->isJsonValue($item));
    }

    /** @return array<array-key, mixed> */
    private function submit(DriverRequest $request, string $credential, bool $enforcePostResponseDeadline = true): array
    {
        $options = $request->profile->options;
        $payload = ['query' => $request->prompt];
        if (isset($options['effort'])) {
            $payload['effort'] = $options['effort'];
        }
        if (isset($options['system_prompt'])) {
            $payload['systemPrompt'] = $options['system_prompt'];
        }
        if (isset($options['output_schema'])) {
            $payload['outputSchema'] = $options['output_schema'];
        }
        if (isset($options['max_cost_dollars'])) {
            $payload['budget'] = ['maxCostDollars' => $options['max_cost_dollars']];
        }

        try {
            $response = $this->send($request, $credential, 'post', self::ENDPOINT, $payload, Operation::Submit);
        } catch (ConnectionException) {
            throw new DriverException(
                'exa_research.submit_connection',
                'The Exa Research submission did not return a response.',
                false,
            );
        }

        if ($response->status() !== 200) {
            throw $this->submissionFailure($response);
        }

        try {
            $run = $this->runPayload($response);
        } catch (DriverException) {
            throw $this->ambiguousSubmission();
        }

        // Validate the paid run handle before enforcing the post-response
        // deadline. A successful but malformed receipt is still ambiguous.
        if ($enforcePostResponseDeadline) {
            $this->remainingSeconds($request);
        }

        return $run;
    }

    /** @return array<array-key, mixed> */
    private function retrieveRun(
        DriverRequest $request,
        string $credential,
        string $runId,
        Operation $operation = Operation::Poll,
    ): array {
        while (true) {
            try {
                $response = $this->send(
                    $request,
                    $credential,
                    'get',
                    self::ENDPOINT.'/'.rawurlencode($runId),
                    [],
                    $operation,
                );
            } catch (ConnectionException) {
                if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                    throw $this->deadlineExceeded();
                }

                $this->waitBeforePolling($request);

                continue;
            }

            $this->remainingSeconds($request);
            if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
                $this->waitBeforePolling($request);

                continue;
            }

            if ($response->status() !== 200) {
                throw $this->httpFailure($response);
            }

            $payload = $this->runPayload($response);
            if ($this->runId($payload) !== $runId) {
                throw $this->invalidResponse();
            }

            return $payload;
        }
    }

    /** @param array<string, mixed> $payload */
    private function send(
        DriverRequest|RecoveryRequest $request,
        string $credential,
        string $method,
        string $url,
        array $payload = [],
        Operation $operation = Operation::Inline,
    ): Response {
        $remaining = $this->remainingSeconds($request);
        $pending = Http::withHeaders(['x-api-key' => $credential])
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
            ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));

        try {
            $response = $method === 'post'
                ? $pending->post($url, $payload)
                : $pending->get($url);
        } catch (ConnectionException $exception) {
            $request->captureNoResponse($operation);

            throw $exception;
        }

        $request->captureResponse($response, $operation);

        return $response;
    }

    /** @return array<array-key, mixed> */
    private function runPayload(Response $response): array
    {
        $payload = $response->json();
        if (! is_array($payload) || array_is_list($payload)) {
            throw $this->invalidResponse();
        }

        $output = $payload['output'] ?? null;
        if (is_array($output) && ! array_is_list($output) && array_key_exists('structured', $output)) {
            $wire = $response->object();
            if (! $wire instanceof stdClass
                || ! isset($wire->output)
                || ! $wire->output instanceof stdClass
                || ! property_exists($wire->output, 'structured')) {
                throw $this->invalidResponse();
            }

            $output['structured'] = $wire->output->structured;
            $payload['output'] = $output;
        }

        $this->runId($payload);
        $this->status($payload);

        if (array_key_exists('object', $payload) && data_get($payload, 'object') !== 'agent_run') {
            throw $this->invalidResponse();
        }

        $this->validateTimestamp(data_get($payload, 'createdAt'));

        return $payload;
    }

    private function validateTimestamp(mixed $value): void
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw $this->invalidResponse();
        }

        try {
            CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw $this->invalidResponse();
        }
    }

    /** @param array<array-key, mixed> $run */
    private function runId(array $run): string
    {
        $id = data_get($run, 'id');
        if (! is_string($id)
            || strlen($id) > 200
            || preg_match('/^[A-Za-z0-9_.:-]+$/', $id) !== 1) {
            throw $this->invalidResponse();
        }

        return $id;
    }

    /** @param array<array-key, mixed> $run */
    private function status(array $run): string
    {
        $status = data_get($run, 'status');
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            throw $this->invalidResponse();
        }

        return $status;
    }

    private function remainingSeconds(DriverRequest|RecoveryRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds < 0.001) {
            throw $this->deadlineExceeded();
        }

        return $seconds;
    }

    private function waitBeforePolling(DriverRequest $request): void
    {
        $remaining = $this->remainingSeconds($request);
        Sleep::for(min(self::POLL_INTERVAL_SECONDS, $remaining))->seconds();

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'exa_research.deadline_exceeded',
            'Exa Research did not complete before the research deadline.',
            false,
        );
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('exa_research.authentication', 'Exa Research rejected the configured credential.', false),
            $response->status() === 402 => new DriverException('exa_research.insufficient_credits', 'The Exa account has insufficient credits.', false),
            $response->status() === 403 => new DriverException('exa_research.authorization', 'Exa Research denied this request.', false),
            in_array($response->status(), [408, 504], true) => new DriverException('exa_research.timeout', 'Exa Research timed out while processing this request.', false),
            $response->status() === 429 => new DriverException('exa_research.rate_limited', 'Exa Research rate limited this request.', false),
            $response->serverError() => new DriverException('exa_research.unavailable', 'Exa Research is temporarily unavailable.', false),
            default => new DriverException('exa_research.invalid_request', 'Exa Research rejected this request.', false),
        };
    }

    private function submissionFailure(Response $response): DriverException
    {
        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            return $this->ambiguousSubmission();
        }

        return $this->httpFailure($response);
    }

    private function ambiguousSubmission(): DriverException
    {
        return new DriverException(
            'exa_research.ambiguous_submission',
            'Exa Research did not safely acknowledge the paid submission; it was not retried.',
            false,
        );
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'exa_research.invalid_response',
            'Exa Research returned an invalid Agent run.',
            false,
        );
    }
}
