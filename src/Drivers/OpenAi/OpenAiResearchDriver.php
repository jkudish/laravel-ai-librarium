<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\OpenAi;

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
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use Throwable;

/**
 * Runs one OpenAI Responses API web-research job to a terminal result.
 *
 * The provider response ID, background lifecycle, and webhook reconciliation
 * remain private to this Driver.
 */
final readonly class OpenAiResearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.openai.com/v1/responses';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 20;

    private const int POLL_INTERVAL_SECONDS = 2;

    public function __construct(
        private OpenAiResearchResultMapper $mapper,
        private WebhookSignalStore $webhooks,
    ) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateRequest($request);
        $response = $this->submit($request);
        $responseId = $this->responseId($response);

        try {
            $this->webhooks->bind(
                $request->profile->provider,
                $request->requestId,
                $request->profile->id,
                $responseId,
            );
        } catch (Throwable) {
            // Polling is authoritative; webhook correlation is only an optimization.
        }

        $request->progress('OpenAI is researching in the background.');
        $usedWebhookSignal = false;
        $isSubmissionResponse = true;

        while (true) {
            $status = $this->status($response);

            if (in_array($status, ['completed', 'failed', 'cancelled', 'incomplete'], true)) {
                if ($isSubmissionResponse) {
                    $retrieved = $this->retrieve($request, $responseId);
                    if ($retrieved === null) {
                        $this->waitBeforePolling($request);

                        continue;
                    }

                    $response = $retrieved;
                    $isSubmissionResponse = false;

                    continue;
                }

                if ($status === 'completed') {
                    return $this->mapper->map($response, $request);
                }

                throw $this->terminalFailure($status);
            }

            if (! in_array($status, ['queued', 'in_progress'], true)) {
                throw new DriverException(
                    'openai.invalid_response',
                    'OpenAI returned an unrecognized research state.',
                );
            }

            if (! $usedWebhookSignal && $this->hasWebhookSignal($request)) {
                $usedWebhookSignal = true;
                $retrieved = $this->retrieve($request, $responseId);
                if ($retrieved !== null) {
                    $response = $retrieved;
                    $isSubmissionResponse = false;
                }

                continue;
            }

            $this->waitBeforePolling($request);
            $retrieved = $this->retrieve($request, $responseId);
            if ($retrieved !== null) {
                $response = $retrieved;
                $isSubmissionResponse = false;
            }
        }
    }

    private function hasWebhookSignal(DriverRequest $request): bool
    {
        try {
            return $this->webhooks->get($request->requestId, $request->profile->id) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function validateRequest(DriverRequest $request): void
    {
        $profile = $request->profile;

        if (
            $profile->provider !== 'openai'
            || $profile->resultKind !== ResultKind::ResearchReport
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::ResearchAgent]
        ) {
            throw new DriverException(
                'openai.invalid_profile',
                'The OpenAI research Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        if (blank($request->profile->credential)) {
            throw new DriverException(
                'openai.authentication',
                'The OpenAI provider is not configured.',
            );
        }

        if (blank($request->profile->model)) {
            throw new DriverException(
                'openai.invalid_profile',
                'The OpenAI research Profile must configure a model.',
                false,
            );
        }

        $this->remainingSeconds($request, 'The OpenAI research request could not start before the research deadline.');
    }

    /** @return array<array-key, mixed> */
    private function submit(DriverRequest $request): array
    {
        $response = $this->send(
            request: $request,
            method: 'post',
            url: self::ENDPOINT,
            payload: [
                'model' => $request->profile->model,
                'input' => $request->prompt,
                'background' => true,
                'reasoning' => ['effort' => 'xhigh'],
                'tools' => [[
                    'type' => 'web_search',
                    'return_token_budget' => 'unlimited',
                ]],
                'tool_choice' => 'required',
                'include' => ['web_search_call.action.sources'],
            ],
            operation: Operation::Submit,
        );
        $this->remainingSeconds($request, 'OpenAI did not accept the research request before the research deadline.');

        if ($response->failed()) {
            throw $this->httpFailure($response, false);
        }

        return $this->json($response);
    }

    /** @return array<array-key, mixed>|null */
    private function retrieve(DriverRequest $request, string $responseId): ?array
    {
        try {
            $response = $this->send(
                request: $request,
                method: 'get',
                url: self::ENDPOINT.'/'.rawurlencode($responseId),
                operation: Operation::Retrieve,
            );
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            return null;
        }

        $this->remainingSeconds($request, 'OpenAI did not complete before the research deadline.');

        if ($response->status() === 429 || $response->serverError()) {
            return null;
        }

        if ($response->failed()) {
            throw $this->httpFailure($response, true);
        }

        $payload = $this->json($response);

        if ($this->responseId($payload) !== $responseId) {
            throw new DriverException(
                'openai.invalid_response',
                'OpenAI returned a response for a different background request.',
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(
        DriverRequest $request,
        string $method,
        string $url,
        array $payload = [],
        Operation $operation = Operation::Inline,
    ): Response {
        $remaining = $this->remainingSeconds($request, 'OpenAI did not complete before the research deadline.');

        $pending = Http::withToken((string) $request->profile->credential)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
            ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));

        if ($method === 'post') {
            try {
                $response = $pending->post($url, $payload);
            } catch (ConnectionException) {
                $request->captureNoResponse($operation);

                throw new DriverException(
                    'openai.submit_connection',
                    'The OpenAI background request did not return a response.',
                    false,
                );
            }
        } else {
            try {
                $response = $pending->get($url);
            } catch (ConnectionException $exception) {
                $request->captureNoResponse($operation);

                throw $exception;
            }
        }

        $request->captureResponse($response, $operation);

        return $response;
    }

    private function waitBeforePolling(DriverRequest $request): void
    {
        $remaining = $this->remainingSeconds($request, 'OpenAI did not complete before the research deadline.');
        $delay = min(self::POLL_INTERVAL_SECONDS, $remaining);

        Sleep::for($delay)->seconds();

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }
    }

    /** @param array<array-key, mixed> $response */
    private function responseId(array $response): string
    {
        $id = $this->str(data_get($response, 'id'));
        if ($id === null || ! str_starts_with($id, 'resp_')) {
            throw new DriverException(
                'openai.invalid_response',
                'OpenAI returned an invalid background response.',
            );
        }

        return $id;
    }

    /** @param array<array-key, mixed> $response */
    private function status(array $response): string
    {
        return $this->str(data_get($response, 'status')) ?? '';
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw new DriverException(
                'openai.invalid_response',
                'OpenAI returned a malformed response.',
            );
        }

        return $json;
    }

    private function terminalFailure(string $status): DriverException
    {
        return match ($status) {
            'incomplete' => new DriverException('openai.incomplete', 'OpenAI could not complete the research report.'),
            'cancelled' => new DriverException('openai.cancelled', 'The OpenAI research report was cancelled.'),
            default => new DriverException('openai.research_failed', 'OpenAI could not complete the research report.'),
        };
    }

    private function httpFailure(Response $response, bool $retrieval): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('openai.authentication', 'OpenAI rejected the configured credential.'),
            $response->status() === 403 => new DriverException('openai.authorization', 'OpenAI denied this request.'),
            $response->status() === 404 && $retrieval => new DriverException('openai.response_missing', 'The OpenAI research response is no longer available.', false),
            $response->status() === 429 => new DriverException('openai.rate_limited', 'OpenAI rate limited this request.'),
            $response->serverError() => new DriverException('openai.unavailable', 'OpenAI is temporarily unavailable.', $retrieval),
            default => new DriverException('openai.invalid_request', 'OpenAI rejected this research request.', false),
        };
    }

    private function remainingSeconds(DriverRequest $request, string $message): int
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds < 1) {
            throw new DriverException('openai.deadline_exceeded', $message);
        }

        return max(1, (int) floor($seconds));
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'openai.deadline_exceeded',
            'OpenAI did not complete before the research deadline.',
        );
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
