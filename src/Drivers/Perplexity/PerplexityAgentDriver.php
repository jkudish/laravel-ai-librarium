<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Perplexity;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

/**
 * Runs the supported Perplexity Agent API presets to a terminal result.
 *
 * Background response IDs and lifecycle state remain private to this Driver.
 */
final readonly class PerplexityAgentDriver implements Driver
{
    private const string ENDPOINT = 'https://api.perplexity.ai/v1/agent';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 30;

    private const int POLL_INTERVAL_SECONDS = 2;

    public function __construct(private PerplexityAgentResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $preset = $this->validateRequest($request);

        return $request->profile->id === 'perplexity-sonar-pro'
            ? $this->runInline($request, $preset)
            : $this->runBackground($request, $preset);
    }

    private function runInline(DriverRequest $request, string $preset): ResearchResult
    {
        $response = $this->submit($request, $preset, false);
        $status = $this->status($response);

        if ($status === 'completed') {
            $this->responseId($response);

            return $this->mapper->map($response, $request);
        }

        if (in_array($status, ['failed', 'incomplete', 'cancelled'], true)) {
            throw $this->terminalFailure($status, $response);
        }

        if (in_array($status, ['queued', 'in_progress', 'cancelling'], true)) {
            throw new DriverException(
                'perplexity.invalid_response',
                'Perplexity did not return a completed inline response.',
            );
        }

        throw $this->unknownState();
    }

    private function runBackground(DriverRequest $request, string $preset): ResearchResult
    {
        $submission = $this->submit($request, $preset, true);
        $responseId = $this->responseId($submission);
        $status = $this->status($submission);

        $this->assertKnownStatus($status);

        $request->progress('Perplexity is researching in the background.');

        if (! in_array($status, ['completed', 'failed', 'incomplete', 'cancelled'], true)) {
            $this->waitBeforePolling($request);
        }

        while (true) {
            $response = $this->retrieve($request, $responseId);

            if ($response === null) {
                $this->waitBeforePolling($request);

                continue;
            }

            $status = $this->status($response);

            if ($status === 'completed') {
                return $this->mapper->map($response, $request);
            }

            if (in_array($status, ['failed', 'incomplete', 'cancelled'], true)) {
                throw $this->terminalFailure($status, $response);
            }

            if (! in_array($status, ['queued', 'in_progress', 'cancelling'], true)) {
                throw $this->unknownState();
            }

            $this->waitBeforePolling($request);
        }
    }

    private function validateRequest(DriverRequest $request): string
    {
        $profile = $request->profile;
        $isInline = $profile->id === 'perplexity-sonar-pro'
            && $profile->resultKind === ResultKind::GroundedAnswer
            && $profile->retrievalMethods->values()->all() === [RetrievalMethod::ModelSearchTool];
        $isBackground = in_array($profile->id, ['perplexity-deep-research', 'perplexity-sonar-deep'], true)
            && $profile->resultKind === ResultKind::ResearchReport
            && $profile->retrievalMethods->values()->all() === [RetrievalMethod::ResearchAgent];

        if (
            $profile->provider !== 'perplexity'
            || (! $isInline && ! $isBackground)
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->model !== null
        ) {
            throw new DriverException(
                'perplexity.invalid_profile',
                'The Perplexity Agent Profile has incompatible provider, target, or provenance semantics.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException(
                'perplexity.authentication',
                'The Perplexity provider is not configured.',
            );
        }

        $this->remainingSeconds($request, 'The Perplexity request could not start before the research deadline.');

        return match ($profile->id) {
            'perplexity-sonar-pro' => 'low',
            'perplexity-deep-research' => 'medium',
            'perplexity-sonar-deep' => 'high',
            default => throw $this->unknownState(),
        };
    }

    /** @return array<array-key, mixed> */
    private function submit(DriverRequest $request, string $preset, bool $background): array
    {
        $payload = [
            'input' => $request->prompt,
            'preset' => $preset,
        ];

        if ($background) {
            $payload['background'] = true;
        }

        try {
            $response = $this->send($request, 'post', self::ENDPOINT, $payload);
        } catch (ConnectionException) {
            throw new DriverException(
                'perplexity.submit_connection',
                'The Perplexity submission did not return a response, so its outcome is unknown.',
                false,
            );
        }

        $this->remainingSeconds($request, 'Perplexity did not accept the request before the research deadline.');

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
                $request,
                'get',
                self::ENDPOINT.'/'.rawurlencode($responseId),
            );
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            return null;
        }

        $this->remainingSeconds($request, 'Perplexity did not complete before the research deadline.');

        if ($response->status() === 429 || $response->serverError()) {
            return null;
        }

        if ($response->failed()) {
            throw $this->httpFailure($response, true);
        }

        $payload = $this->json($response);

        if ($this->responseId($payload) !== $responseId) {
            throw new DriverException(
                'perplexity.invalid_response',
                'Perplexity returned a response for a different background request.',
            );
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function send(DriverRequest $request, string $method, string $url, array $payload = []): Response
    {
        $remaining = $this->remainingSeconds($request, 'Perplexity did not complete before the research deadline.');
        $pending = Http::withToken((string) $request->profile->credential)
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
            ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));

        return $method === 'post'
            ? $pending->post($url, $payload)
            : $pending->get($url);
    }

    private function waitBeforePolling(DriverRequest $request): void
    {
        $remaining = $this->remainingSeconds($request, 'Perplexity did not complete before the research deadline.');
        Sleep::for(min(self::POLL_INTERVAL_SECONDS, $remaining))->seconds();

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }
    }

    /** @param array<array-key, mixed> $response */
    private function responseId(array $response): string
    {
        $id = $this->str(data_get($response, 'id'));

        if ($id === null || strlen($id) > 255 || preg_match('/^resp_[A-Za-z0-9._-]+$/D', $id) !== 1) {
            throw new DriverException(
                'perplexity.invalid_response',
                'Perplexity returned an invalid Agent response identifier.',
            );
        }

        return $id;
    }

    /** @param array<array-key, mixed> $response */
    private function status(array $response): string
    {
        return $this->str(data_get($response, 'status')) ?? '';
    }

    private function assertKnownStatus(string $status): void
    {
        if (! in_array($status, ['queued', 'in_progress', 'cancelling', 'completed', 'failed', 'incomplete', 'cancelled'], true)) {
            throw $this->unknownState();
        }
    }

    private function unknownState(): DriverException
    {
        return new DriverException(
            'perplexity.invalid_response',
            'Perplexity returned an unrecognized Agent response state.',
        );
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json) || array_is_list($json)) {
            throw new DriverException(
                'perplexity.invalid_response',
                'Perplexity returned a malformed response.',
            );
        }

        return $json;
    }

    /** @param array<array-key, mixed> $response */
    private function terminalFailure(string $status, array $response): DriverException
    {
        if ($status === 'cancelled') {
            return new DriverException('perplexity.cancelled', 'The Perplexity Agent response was cancelled.');
        }

        if ($status === 'incomplete') {
            return new DriverException('perplexity.incomplete', 'Perplexity could not complete the Agent response.');
        }

        return match ($this->str(data_get($response, 'error.code'))) {
            'authentication_error', 'invalid_api_key', 'unauthorized' => new DriverException('perplexity.authentication', 'Perplexity rejected the configured credential.'),
            'permission_denied', 'forbidden', 'plan_required' => new DriverException('perplexity.plan_required', 'Perplexity denied this request for the configured plan.'),
            'billing_error', 'insufficient_credits', 'payment_required' => new DriverException('perplexity.billing', 'Perplexity could not bill this request.'),
            'rate_limit_error', 'rate_limit_exceeded' => new DriverException('perplexity.rate_limited', 'Perplexity rate limited this request.'),
            'invalid_request_error', 'validation_error', 'bad_request' => new DriverException('perplexity.invalid_request', 'Perplexity rejected this Agent request.', false),
            default => new DriverException('perplexity.agent_failed', 'Perplexity could not complete the Agent response.'),
        };
    }

    private function httpFailure(Response $response, bool $retrieval): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('perplexity.authentication', 'Perplexity rejected the configured credential.'),
            $response->status() === 402 => new DriverException('perplexity.billing', 'Perplexity could not bill this request.'),
            $response->status() === 403 => new DriverException('perplexity.plan_required', 'Perplexity denied this request for the configured plan.'),
            $response->status() === 404 && $retrieval => new DriverException('perplexity.response_missing', 'The Perplexity Agent response is no longer available.', false),
            $response->status() === 408 => new DriverException('perplexity.timeout', 'Perplexity timed out while processing this request.'),
            $response->status() === 429 => new DriverException('perplexity.rate_limited', 'Perplexity rate limited this request.'),
            $response->serverError() => new DriverException('perplexity.unavailable', 'Perplexity is temporarily unavailable.'),
            default => new DriverException('perplexity.invalid_request', 'Perplexity rejected this Agent request.', false),
        };
    }

    private function remainingSeconds(DriverRequest $request, string $message): int
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);

        if ($seconds < 1) {
            throw new DriverException('perplexity.deadline_exceeded', $message);
        }

        return max(1, (int) floor($seconds));
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'perplexity.deadline_exceeded',
            'Perplexity did not complete before the research deadline.',
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
