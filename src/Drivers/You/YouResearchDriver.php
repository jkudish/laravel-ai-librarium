<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\You;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

/** Laravel HTTP Driver for durable, standard-effort You.com Research tasks. */
final readonly class YouResearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.you.com/v1/research';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const int REQUEST_TIMEOUT_SECONDS = 30;

    private const int POLL_INTERVAL_SECONDS = 1;

    public function __construct(private YouResearchResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'research_effort' => ['required', 'string', 'in:standard'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $credential = $this->preflight($request);
        $taskId = $this->submit($request, $credential);
        $request->progress('You.com is researching in the background.');

        while (true) {
            $this->waitBeforePolling($request);
            $task = $this->task($request, $credential, $taskId);
            $status = data_get($task, 'status');

            if ($status === 'completed') {
                $terminal = $this->task($request, $credential, $taskId, Operation::Retrieve);
                if (data_get($terminal, 'status') !== 'completed') {
                    throw $this->invalidResponse();
                }

                return $this->mapper->map($request, $terminal, true);
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                throw new DriverException(
                    'you_research.failed',
                    "You.com Research ended with status {$status}.",
                    false,
                );
            }

            if (! in_array($status, ['queued', 'running'], true)) {
                throw $this->invalidResponse();
            }
        }
    }

    private function preflight(DriverRequest $request): string
    {
        $profile = $request->profile;
        if ($profile->provider !== 'you-research'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::ResearchReport
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::ResearchAgent]
        ) {
            throw new DriverException(
                'you_research.invalid_profile',
                'The You.com Research Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        if ($profile->options !== ['research_effort' => 'standard']) {
            throw new DriverException(
                'you_research.invalid_request',
                'The You.com Research Profile requires standard effort.',
                false,
            );
        }

        if (trim($request->prompt) === '' || mb_strlen($request->prompt) > 40_000) {
            throw new DriverException(
                'you_research.invalid_request',
                'The You.com Research input is invalid.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException(
                'you_research.authentication',
                'The You.com Research provider is not configured.',
                false,
            );
        }

        $this->remainingSeconds($request);

        return (string) $profile->credential;
    }

    private function submit(DriverRequest $request, string $credential): string
    {
        $response = $this->send($request, $credential, 'post', self::ENDPOINT, [
            'input' => $request->prompt,
            'research_effort' => 'standard',
            'background' => true,
        ], Operation::Submit);

        if (! in_array($response->status(), [200, 201, 202], true)) {
            throw $this->httpFailure($response);
        }

        $payload = $this->json($response);
        $taskId = data_get($payload, 'task_id');
        if (! is_string($taskId) || ! $this->isUuid($taskId)
            || data_get($payload, 'type') !== 'research'
            || data_get($payload, 'status') !== 'queued') {
            throw $this->invalidResponse();
        }

        return $taskId;
    }

    /** @return array<array-key, mixed> */
    private function task(
        DriverRequest $request,
        string $credential,
        string $taskId,
        Operation $operation = Operation::Poll,
    ): array {
        $response = $this->send(
            $request,
            $credential,
            'get',
            self::ENDPOINT.'/'.rawurlencode($taskId),
            [],
            $operation,
        );
        if ($response->failed()) {
            throw $this->httpFailure($response);
        }

        $payload = $this->json($response);
        if (data_get($payload, 'id') !== $taskId
            || data_get($payload, 'task_type') !== 'research'
            || ! in_array(data_get($payload, 'status'), ['queued', 'running', 'completed', 'failed', 'cancelled'], true)) {
            throw $this->invalidResponse();
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function send(
        DriverRequest $request,
        string $credential,
        string $method,
        string $url,
        array $payload = [],
        Operation $operation = Operation::Inline,
    ): Response {
        $remaining = $this->remainingSeconds($request);

        try {
            $pending = Http::withHeaders(['X-API-Key' => $credential])
                ->withoutRedirecting()
                ->acceptJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));

            $response = $method === 'post'
                ? $pending->post($url, $payload)
                : $pending->get($url);
        } catch (ConnectionException) {
            $request->captureNoResponse($operation);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new DriverException(
                'you_research.connection',
                'You.com Research could not be reached.',
                false,
            );
        }

        $request->captureResponse($response, $operation);
        $this->remainingSeconds($request);

        return $response;
    }

    private function remainingSeconds(DriverRequest $request): float
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
            'you_research.deadline_exceeded',
            'You.com Research did not complete before the research deadline.',
            false,
        );
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('you_research.authentication', 'You.com Research rejected the configured credential.', false),
            $response->status() === 402 => new DriverException('you_research.insufficient_credit', 'You.com Research could not run because the account has insufficient API credit.', false),
            $response->status() === 403 => new DriverException('you_research.authorization', 'You.com Research denied this request.', false),
            $response->status() === 408 => new DriverException('you_research.timeout', 'You.com Research timed out while processing this request.', false),
            $response->status() === 429 => new DriverException('you_research.rate_limited', 'You.com Research rate limited this request.', false),
            $response->serverError() => new DriverException('you_research.unavailable', 'You.com Research is temporarily unavailable.', false),
            default => new DriverException('you_research.invalid_request', 'You.com Research rejected this request.', false),
        };
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        return $json;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'you_research.invalid_response',
            'You.com Research returned an invalid research report.',
            false,
        );
    }
}
