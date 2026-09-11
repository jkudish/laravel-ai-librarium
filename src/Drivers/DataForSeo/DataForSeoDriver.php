<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\DataForSeo;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Recovery\DurableSubmission;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\Usage;

/**
 * Executes one fixed DataForSEO task for one consumer surface.
 *
 * Standard submission is intentionally never retried. Its private task ID is
 * used only for bounded retrieval polling during this process.
 */
final readonly class DataForSeoDriver implements DurableDriver
{
    private const string ORIGIN = 'https://api.dataforseo.com';

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int REQUEST_TIMEOUT_SECONDS = 125;

    /**
     * @var array<string, array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}>
     */
    private const array PROFILES = [
        'dataforseo-chatgpt' => ['family' => 'ai_optimization/chat_gpt/llm_scraper', 'surface' => 'chatgpt', 'mode' => 'standard', 'api' => 'ai_optimization', 'se' => 'chat_gpt', 'se_aliases' => ['chatgpt'], 'se_type' => 'llm_scraper', 'max_chars' => 2000],
        'dataforseo-chatgpt-live' => ['family' => 'ai_optimization/chat_gpt/llm_scraper', 'surface' => 'chatgpt', 'mode' => 'live', 'api' => 'ai_optimization', 'se' => 'chat_gpt', 'se_aliases' => ['chatgpt'], 'se_type' => 'llm_scraper', 'max_chars' => 2000],
        'dataforseo-gemini' => ['family' => 'ai_optimization/gemini/llm_scraper', 'surface' => 'gemini', 'mode' => 'standard', 'api' => 'ai_optimization', 'se' => 'gemini', 'se_type' => 'llm_scraper', 'max_chars' => 2000],
        'dataforseo-gemini-live' => ['family' => 'ai_optimization/gemini/llm_scraper', 'surface' => 'gemini', 'mode' => 'live', 'api' => 'ai_optimization', 'se' => 'gemini', 'se_type' => 'llm_scraper', 'max_chars' => 2000],
        'dataforseo-google-ai-mode' => ['family' => 'serp/google/ai_mode', 'surface' => 'google_ai_mode', 'mode' => 'standard', 'api' => 'serp', 'se' => 'google', 'se_type' => 'ai_mode', 'max_chars' => 700],
        'dataforseo-google-ai-mode-live' => ['family' => 'serp/google/ai_mode', 'surface' => 'google_ai_mode', 'mode' => 'live', 'api' => 'serp', 'se' => 'google', 'se_type' => 'ai_mode', 'max_chars' => 700],
    ];

    public function __construct(private DataForSeoResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'location_code' => ['required', 'integer', 'min:1'],
            'language_code' => ['required', 'string', 'min:1', 'max:32', 'regex:/^[a-z][a-z0-9_-]*$/i'],
            'force_web_search' => ['sometimes', 'boolean'],
            'device' => ['sometimes', 'string', 'in:desktop,mobile'],
            'os' => ['sometimes', 'string', 'in:windows,macos,android,ios'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $descriptor = $this->descriptor($request->profile);
        $credentials = $this->credentials($request->profile);

        $task = $this->task($request, $descriptor);

        return $descriptor['mode'] === 'live'
            ? $this->runLive($request, $descriptor, $credentials, $task)
            : $this->runStandard($request, $descriptor, $credentials, $task);
    }

    public function submitDurable(DriverRequest $request): DurableSubmission
    {
        $descriptor = $this->descriptor($request->profile);
        if ($descriptor['mode'] !== 'standard') {
            throw new DriverException(
                'dataforseo.not_durable',
                'DataForSEO Live Profiles run inline and cannot be submitted for later retrieval.',
                false,
            );
        }
        $credentials = $this->credentials($request->profile);
        $created = $this->submitStandard($request, $descriptor, $credentials, $this->task($request, $descriptor));

        return new DurableSubmission(
            reference: $this->taskId($created),
            usage: ($cost = $this->decimal($created['cost'] ?? null)) === null
                ? null
                : new Usage(actualCost: $cost, currency: 'USD'),
        );
    }

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult
    {
        $descriptor = $this->descriptor($request->profile);
        if ($descriptor['mode'] !== 'standard') {
            throw new DriverException(
                'dataforseo.not_durable',
                'DataForSEO Live Profiles have no durable task to retrieve.',
                false,
            );
        }
        $credentials = $this->credentials($request->profile);
        $this->controls($request->profile, $descriptor);
        $operation = "task_get/advanced/{$reference}";

        try {
            $response = $this->get($request, $descriptor['family'], $operation, $credentials);
        } catch (ConnectionException) {
            throw new DriverException(
                'dataforseo.connection',
                'DataForSEO could not be reached while retrieving the accepted task.',
                false,
            );
        }
        if ($this->retryableRetrieval($response)) {
            throw new DriverException(
                'dataforseo.unavailable',
                'DataForSEO temporarily could not return the accepted task.',
                false,
            );
        }

        $receipt = $this->receipt($response, $descriptor, $operation, $request, null, $reference);
        $status = $receipt['status_code'] ?? null;
        if (in_array($status, [40601, 40602], true)) {
            if (($receipt['result_count'] ?? null) !== 0 || ($receipt['result'] ?? null) !== null) {
                throw self::invalidResponse('DataForSEO returned a contradictory pending task receipt.');
            }

            return null;
        }
        if ($status !== 20000) {
            throw $this->taskFailure($status);
        }

        return $this->mapper->result(
            request: $request,
            result: $this->completedResult($receipt, $request),
            receipt: $receipt,
            surface: $descriptor['surface'],
            family: $descriptor['family'],
            mode: 'standard',
            actualCost: $request->submittedUsage?->actualCost,
        );
    }

    /**
     * @param  array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}  $descriptor
     * @param  array{string, string}  $credentials
     * @param  array<string, int|string|bool>  $task
     */
    private function runLive(DriverRequest $request, array $descriptor, array $credentials, array $task): ResearchResult
    {
        $operation = 'live/advanced';
        $response = $this->post($request, $descriptor['family'], $operation, $credentials, $task);
        $receipt = $this->receipt($response, $descriptor, $operation, $request, 20000);
        $result = $this->completedResult($receipt, $request);

        return $this->mapper->result(
            request: $request,
            result: $result,
            receipt: $receipt,
            surface: $descriptor['surface'],
            family: $descriptor['family'],
            mode: 'live',
            actualCost: $this->decimal($receipt['cost'] ?? null),
        );
    }

    /**
     * @param  array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}  $descriptor
     * @param  array{string, string}  $credentials
     * @param  array<string, int|string|bool>  $task
     */
    private function runStandard(DriverRequest $request, array $descriptor, array $credentials, array $task): ResearchResult
    {
        $created = $this->submitStandard($request, $descriptor, $credentials, $task);
        $id = $this->taskId($created);

        $request->progress('DataForSEO accepted the task; waiting for its result.');
        $attempt = 0;
        while (true) {
            $operation = "task_get/advanced/{$id}";
            try {
                $response = $this->get($request, $descriptor['family'], $operation, $credentials);
                if ($this->retryableRetrieval($response)) {
                    $this->wait($request, $attempt++);

                    continue;
                }
                $receipt = $this->receipt($response, $descriptor, $operation, $request, null, $id);
            } catch (ConnectionException) {
                $this->wait($request, $attempt++);

                continue;
            }

            $status = $receipt['status_code'] ?? null;
            if (in_array($status, [40601, 40602], true)) {
                if (($receipt['result_count'] ?? null) !== 0 || ($receipt['result'] ?? null) !== null) {
                    throw self::invalidResponse('DataForSEO returned a contradictory pending task receipt.');
                }

                $request->progress('DataForSEO is still processing the task.');
                $this->wait($request, $attempt++);

                continue;
            }
            if ($status !== 20000) {
                throw $this->taskFailure($status);
            }

            return $this->mapper->result(
                request: $request,
                result: $this->completedResult($receipt, $request),
                receipt: $receipt,
                surface: $descriptor['surface'],
                family: $descriptor['family'],
                mode: 'standard',
                actualCost: $this->decimal($created['cost'] ?? null),
            );
        }
    }

    /**
     * @param  array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}  $descriptor
     * @return array<string, int|string|bool>
     */
    private function task(DriverRequest $request, array $descriptor): array
    {
        if ($request->prompt === '' || mb_strlen($request->prompt) > $descriptor['max_chars']) {
            throw new DriverException('dataforseo.invalid_query', "The DataForSEO query must contain 1 to {$descriptor['max_chars']} characters.", false);
        }

        return [
            'keyword' => rawurlencode($request->prompt),
            ...$this->controls($request->profile, $descriptor),
        ];
    }

    /**
     * @param  array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}  $descriptor
     * @return array<string, int|string|bool>
     */
    private function controls(Profile $profile, array $descriptor): array
    {
        $options = $profile->options;
        $location = $options['location_code'] ?? null;
        $language = $options['language_code'] ?? null;
        if (! is_int($location) || $location < 1 || ! is_string($language) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/iD', $language) !== 1) {
            throw new DriverException('dataforseo.invalid_options', 'The DataForSEO profile received invalid location or language controls.', false);
        }

        $task = [
            'location_code' => $location,
            'language_code' => $language,
        ];

        foreach (['force_web_search', 'device', 'os'] as $key) {
            if (array_key_exists($key, $options)) {
                $value = $options[$key];
                if (! is_bool($value) && ! is_int($value) && ! is_string($value)) {
                    throw new DriverException('dataforseo.invalid_options', 'The DataForSEO profile received an invalid control value.', false);
                }
                $task[$key] = $value;
            }
        }

        if ($descriptor['surface'] === 'chatgpt') {
            if (array_key_exists('device', $task) || array_key_exists('os', $task) || ! is_bool($task['force_web_search'] ?? null)) {
                throw new DriverException('dataforseo.invalid_options', 'The ChatGPT DataForSEO profile received unsupported controls.', false);
            }
        } elseif ($descriptor['surface'] === 'gemini') {
            if (array_key_exists('force_web_search', $task) || array_key_exists('device', $task) || array_key_exists('os', $task)) {
                throw new DriverException('dataforseo.invalid_options', 'The Gemini DataForSEO profile received unsupported controls.', false);
            }
        } else {
            if (array_key_exists('force_web_search', $task) || ! $this->validDevice($task['device'] ?? null, $task['os'] ?? null)) {
                throw new DriverException('dataforseo.invalid_options', 'The Google AI Mode DataForSEO profile received incompatible device controls.', false);
            }
        }

        return $task;
    }

    /**
     * @param  array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}  $descriptor
     * @param  array{string, string}  $credentials
     * @param  array<string, int|string|bool>  $task
     * @return array<string, mixed>
     */
    private function submitStandard(
        DriverRequest $request,
        array $descriptor,
        array $credentials,
        array $task,
    ): array {
        $created = $this->receipt(
            $this->post($request, $descriptor['family'], 'task_post', $credentials, $task),
            $descriptor,
            'task_post',
            $request,
            20100,
        );
        if (($created['result_count'] ?? null) !== 0 || ($created['result'] ?? null) !== null) {
            throw self::invalidResponse('DataForSEO returned a contradictory task creation receipt.');
        }

        $this->taskId($created);

        return $created;
    }

    /** @param array<string, mixed> $receipt */
    private function taskId(array $receipt): string
    {
        $id = $receipt['id'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw self::invalidResponse('DataForSEO returned an invalid task identifier.');
        }

        return $id;
    }

    /**
     * @return array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}
     */
    private function descriptor(Profile $profile): array
    {
        return self::PROFILES[$profile->id]
            ?? throw new DriverException('dataforseo.unsupported_profile', 'The requested DataForSEO profile is not supported.', false);
    }

    /** @return array{string, string} */
    private function credentials(Profile $profile): array
    {
        return DataForSeoCredential::decode($profile->credential)
            ?? throw new DriverException('dataforseo.authentication', 'The DataForSEO provider is not configured.', false);
    }

    private function validDevice(mixed $device, mixed $os): bool
    {
        if ($device === null && $os === null) {
            return true;
        }
        if (! is_string($device) || ! is_string($os)) {
            return false;
        }

        return ($device === 'desktop' && in_array($os, ['windows', 'macos'], true))
            || ($device === 'mobile' && in_array($os, ['android', 'ios'], true));
    }

    /**
     * @param  array{string, string}  $credentials
     * @param  array<string, int|string|bool>  $task
     */
    private function post(DriverRequest $request, string $family, string $operation, array $credentials, array $task): Response
    {
        $captureOperation = $operation === 'live/advanced' ? Operation::Inline : Operation::Submit;

        try {
            $response = $this->pending($request, $credentials)->post($this->url($family, $operation), [$task]);
        } catch (ConnectionException) {
            $request->captureNoResponse($captureOperation);

            throw new DriverException('dataforseo.ambiguous_submission', 'The DataForSEO submission outcome is unknown and was not retried.', false);
        }

        $request->captureResponse($response, $captureOperation);

        if ($response->status() === 401) {
            throw new DriverException('dataforseo.authentication', 'DataForSEO rejected the configured credentials.', false);
        }
        if ($response->failed() || $response->redirect()) {
            throw new DriverException('dataforseo.ambiguous_submission', 'DataForSEO did not safely acknowledge the submission; it was not retried.', false);
        }

        return $response;
    }

    /** @param array{string, string} $credentials */
    private function get(DriverRequest|RecoveryRequest $request, string $family, string $operation, array $credentials): Response
    {
        try {
            $response = $this->pending($request, $credentials)->get($this->url($family, $operation));
        } catch (ConnectionException $exception) {
            $request->captureNoResponse(Operation::Retrieve);

            throw $exception;
        }

        $request->captureResponse($response, Operation::Retrieve);

        return $response;
    }

    /** @param array{string, string} $credentials */
    private function pending(DriverRequest|RecoveryRequest $request, array $credentials): PendingRequest
    {
        $remaining = $this->remainingSeconds($request);

        return Http::withBasicAuth($credentials[0], $credentials[1])
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
            ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));
    }

    /**
     * @param  array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}  $descriptor
     * @return array<string, mixed>
     */
    private function receipt(
        Response $response,
        array $descriptor,
        string $operation,
        DriverRequest|RecoveryRequest $request,
        ?int $expectedStatus,
        ?string $expectedId = null,
    ): array {
        if ($response->status() === 401) {
            throw new DriverException('dataforseo.authentication', 'DataForSEO rejected the configured credentials.', false);
        }
        if ($response->failed() || $response->redirect()) {
            throw new DriverException('dataforseo.retrieval_failed', 'DataForSEO could not safely return the task result.', false);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)
            || ($json['status_code'] ?? null) !== 20000
            || ($json['tasks_count'] ?? null) !== 1
            || ($json['tasks_error'] ?? null) !== 0
            || ! is_array($json['tasks'] ?? null)
            || count($json['tasks']) !== 1
            || ! is_array($json['tasks'][0] ?? null)
            || array_is_list($json['tasks'][0])) {
            throw self::invalidResponse('DataForSEO returned an invalid response envelope.');
        }

        $credentials = DataForSeoCredential::decode($request->profile->credential);
        if ($credentials !== null) {
            $secrets = [...$credentials, (string) $request->profile->credential, base64_encode(implode(':', $credentials))];
            array_walk_recursive($json, static function (mixed $value) use ($secrets): void {
                if (is_string($value)) {
                    foreach ($secrets as $secret) {
                        if (str_contains($value, $secret)) {
                            throw self::invalidResponse('DataForSEO returned unsafe response content.');
                        }
                    }
                }
            });
        }

        /** @var array<string, mixed> $receipt */
        $receipt = $json['tasks'][0];
        $id = $receipt['id'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id) || ($expectedId !== null && $id !== $expectedId)) {
            throw self::invalidResponse('DataForSEO returned a mismatched task identifier.');
        }
        if ($expectedStatus !== null && ($receipt['status_code'] ?? null) !== $expectedStatus) {
            throw $this->taskFailure($receipt['status_code'] ?? null);
        }

        $this->assertPath($receipt['path'] ?? null, $descriptor['family'], $operation, $expectedId);
        $this->assertIdentity($receipt['data'] ?? null, $descriptor, $operation, $request);
        $receipt['_api_version'] = $json['version'] ?? null;

        return $receipt;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    private function completedResult(array $receipt, DriverRequest|RecoveryRequest $request): array
    {
        $results = $receipt['result'] ?? null;
        if (($receipt['result_count'] ?? null) !== 1 || ! is_array($results) || count($results) !== 1
            || ! is_array($results[0] ?? null) || array_is_list($results[0])) {
            throw self::invalidResponse('DataForSEO returned an invalid completed task result.');
        }

        /** @var array<string, mixed> $result */
        $result = $results[0];
        $expectedKeyword = $request instanceof DriverRequest
            ? $request->prompt
            : data_get($receipt, 'data.keyword');
        if (! is_string($expectedKeyword) || trim($expectedKeyword) === ''
            || ($result['keyword'] ?? null) !== $expectedKeyword
            || ($result['location_code'] ?? null) !== data_get($request->profile->options, 'location_code')
            || ($result['language_code'] ?? null) !== data_get($request->profile->options, 'language_code')) {
            throw self::invalidResponse('DataForSEO returned a result for different request controls.');
        }

        return $result;
    }

    /**
     * @param  array{family: string, surface: string, mode: 'standard'|'live', api: string, se: string, se_aliases?: list<string>, se_type?: string, max_chars: int}  $descriptor
     */
    private function assertIdentity(
        mixed $data,
        array $descriptor,
        string $operation,
        DriverRequest|RecoveryRequest $request,
    ): void {
        $function = match (true) {
            $descriptor['api'] === 'ai_optimization'
                && ! ($descriptor['surface'] === 'chatgpt' && $operation === 'task_post') => 'llm_scraper',
            $operation === 'task_post' => 'task_post',
            str_starts_with($operation, 'task_get/') => 'task_get',
            default => 'live',
        };
        $keyword = is_array($data) ? ($data['keyword'] ?? null) : null;
        if (! is_array($data) || array_is_list($data)
            || ($data['api'] ?? null) !== $descriptor['api']
            || ($data['function'] ?? null) !== $function
            || ! in_array($data['se'] ?? null, [$descriptor['se'], ...($descriptor['se_aliases'] ?? [])], true)
            || (($descriptor['api'] === 'serp' || array_key_exists('se_type', $data))
                && ($data['se_type'] ?? null) !== ($descriptor['se_type'] ?? null))
            || ! is_string($keyword)
            || trim($keyword) === ''
            || mb_strlen($keyword) > $descriptor['max_chars']
            || ($request instanceof DriverRequest && $keyword !== $request->prompt)
            || ($data['location_code'] ?? null) !== data_get($request->profile->options, 'location_code')
            || ($data['language_code'] ?? null) !== data_get($request->profile->options, 'language_code')) {
            throw self::invalidResponse('DataForSEO returned a receipt for a different task family or request.');
        }

        foreach (['force_web_search', 'device', 'os'] as $control) {
            if (array_key_exists($control, $request->profile->options)
                && array_key_exists($control, $data)
                && ($data[$control] ?? null) !== $request->profile->options[$control]) {
                throw self::invalidResponse('DataForSEO returned a receipt with mismatched request controls.');
            }
        }
    }

    private function assertPath(mixed $path, string $family, string $operation, ?string $id): void
    {
        $expected = ['v3', ...explode('/', $family), ...explode('/', $operation)];
        $accepted = [$expected];
        if ($family === 'ai_optimization/chat_gpt/llm_scraper') {
            $documentedAlias = $expected;
            $documentedAlias[2] = 'chatgpt';
            $accepted[] = $documentedAlias;
        }
        if ($id !== null && end($expected) === $id) {
            foreach ($accepted as $candidate) {
                $withoutId = $candidate;
                array_pop($withoutId);
                $accepted[] = $withoutId;
            }
        }

        if (! is_array($path) || ! array_is_list($path) || ! in_array($path, $accepted, true)) {
            throw self::invalidResponse('DataForSEO returned a receipt from an unexpected endpoint path.');
        }
    }

    private function retryableRetrieval(Response $response): bool
    {
        if ($response->status() === 429 || $response->serverError()) {
            return true;
        }

        $json = $response->json();

        return is_array($json) && ! array_is_list($json) && ($json['status_code'] ?? null) === 50000;
    }

    private function wait(DriverRequest $request, int $attempt): void
    {
        $remaining = $this->remainingSeconds($request);
        $seconds = min(($attempt === 0 ? 15 : 30) - random_int(0, 5), $remaining);
        Sleep::sleep($seconds);
        $this->remainingSeconds($request);
    }

    private function remainingSeconds(DriverRequest|RecoveryRequest $request): int
    {
        $remaining = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($remaining < 1) {
            throw new DriverException('dataforseo.deadline_exceeded', 'DataForSEO did not complete before the shared research deadline.', false);
        }

        return max(1, (int) floor($remaining));
    }

    private function taskFailure(mixed $status): DriverException
    {
        return match ($status) {
            40400, 40401 => new DriverException('dataforseo.task_not_found', 'DataForSEO no longer has the submitted task result.', false),
            default => new DriverException('dataforseo.provider', 'DataForSEO rejected or could not complete the task.', false),
        };
    }

    private function decimal(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,18})?$/D', $value) === 1) {
            return $value;
        }
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        return is_string($encoded) && preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,18})?$/D', $encoded) === 1 ? $encoded : null;
    }

    private function url(string $family, string $operation): string
    {
        return self::ORIGIN."/v3/{$family}/{$operation}";
    }

    public static function invalidResponse(string $message): DriverException
    {
        return new DriverException('dataforseo.invalid_response', $message, false);
    }
}
