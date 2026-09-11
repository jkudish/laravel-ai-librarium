<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Gemini;

use Carbon\CarbonImmutable;
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

/** Runs one durable Gemini Deep Research interaction to a terminal report. */
final readonly class GeminiDeepDriver implements DurableDriver
{
    private const string ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    private const string API_REVISION = '2026-05-20';

    /** @var list<string> */
    private const array AGENTS = [
        'deep-research-preview-04-2026',
        'deep-research-max-preview-04-2026',
    ];

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const int SUBMIT_TIMEOUT_SECONDS = 30;

    private const int POLL_TIMEOUT_SECONDS = 15;

    private const int RETRIEVE_TIMEOUT_SECONDS = 30;

    private const int POLL_INTERVAL_SECONDS = 15;

    public function __construct(private GeminiDeepResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $credential = $this->preflight($request);
        $submission = $this->submit($request, $credential);
        $interactionId = $this->interactionId($submission);
        $request->progress('Gemini is researching in the background.');

        $observed = $submission;
        while (true) {
            $status = $this->status($observed);

            if ($status === 'completed') {
                $terminal = $this->retrieve($request, $credential, $interactionId);
                if ($terminal === null) {
                    $this->waitBeforePolling($request);
                    $observed = $this->poll($request, $credential, $interactionId) ?? $observed;

                    continue;
                }

                $terminalStatus = $this->status($terminal);
                if ($terminalStatus === 'completed') {
                    return TerminalRedaction::guard(
                        $this->mapper->map($terminal, $request),
                        [$credential, $interactionId],
                        $this->invalidResponse(),
                    );
                }

                $observed = $terminal;

                continue;
            }

            if ($status === 'cancelled') {
                throw new DriverException(
                    'gemini_deep.cancelled',
                    'The Gemini Deep Research interaction was cancelled.',
                    false,
                );
            }

            if ($status === 'budget_exceeded') {
                throw new DriverException(
                    'gemini_deep.budget_exceeded',
                    'Gemini Deep Research stopped after exceeding its token budget.',
                    false,
                );
            }

            // `failed` and `incomplete` are deliberately provisional. The
            // provider has returned completed output after either observation
            // for the same immutable interaction, so the absolute deadline—not
            // those reversible observations—bounds polling.
            $this->waitBeforePolling($request);
            $observed = $this->poll($request, $credential, $interactionId) ?? $observed;
        }
    }

    public function submitDurable(DriverRequest $request): DurableSubmission
    {
        $credential = $this->preflight($request);

        return new DurableSubmission(
            reference: $this->interactionId($this->submit($request, $credential, false)),
        );
    }

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult
    {
        $credential = $this->preflight($request);
        $interaction = $this->get(
            $request,
            $credential,
            $reference,
            self::RETRIEVE_TIMEOUT_SECONDS,
            Operation::Retrieve,
            false,
        );
        if ($interaction === null) {
            throw $this->invalidResponse();
        }

        return match ($this->status($interaction)) {
            'completed' => TerminalRedaction::guard(
                $this->mapper->map($interaction, $request),
                [$credential, $reference],
                $this->invalidResponse(),
            ),
            'cancelled' => throw new DriverException(
                'gemini_deep.cancelled',
                'The Gemini Deep Research interaction was cancelled.',
                false,
            ),
            'budget_exceeded' => throw new DriverException(
                'gemini_deep.budget_exceeded',
                'Gemini Deep Research stopped after exceeding its token budget.',
                false,
            ),
            default => null,
        };
    }

    private function preflight(DriverRequest|RecoveryRequest $request): string
    {
        $profile = $request->profile;
        if ($profile->id !== 'gemini-deep-research'
            || $profile->provider !== 'gemini-deep'
            || ! in_array($profile->model, self::AGENTS, true)
            || $profile->resultKind !== ResultKind::ResearchReport
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::ResearchAgent]
        ) {
            throw new DriverException(
                'gemini_deep.invalid_profile',
                'The Gemini Deep Research Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        if ($profile->options !== []
            || ($request instanceof DriverRequest && trim($request->prompt) === '')) {
            throw new DriverException(
                'gemini_deep.invalid_request',
                'The Gemini Deep Research request is invalid.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException(
                'gemini_deep.authentication',
                'The Gemini Deep Research provider is not configured.',
                false,
            );
        }

        $this->remainingSeconds($request);

        return (string) $profile->credential;
    }

    /** @return array<array-key, mixed> */
    private function submit(DriverRequest $request, string $credential, bool $enforcePostResponseDeadline = true): array
    {
        try {
            $response = $this->send(
                request: $request,
                credential: $credential,
                method: 'post',
                url: self::ENDPOINT,
                timeout: self::SUBMIT_TIMEOUT_SECONDS,
                payload: [
                    'input' => $request->prompt,
                    'agent' => $request->profile->model,
                    'background' => true,
                    'agent_config' => [
                        'type' => 'deep-research',
                        'thinking_summaries' => 'none',
                        'visualization' => 'off',
                    ],
                    'tools' => [['type' => 'google_search']],
                ],
                operation: Operation::Submit,
            );
        } catch (ConnectionException) {
            // The paid background interaction may exist even though its handle
            // was not received. Retrying could create and charge a duplicate.
            throw new DriverException(
                'gemini_deep.submit_connection',
                'The Gemini Deep Research submission did not return a response and cannot be retried safely.',
                false,
            );
        }

        if (! in_array($response->status(), [200, 201], true)) {
            throw $this->submissionFailure($response);
        }

        try {
            $payload = $this->json($response);
            $this->interactionId($payload);
            $this->status($payload);
        } catch (DriverException) {
            throw $this->ambiguousSubmission();
        }

        // Preserve the validated private handle before enforcing the
        // post-response deadline; an unparseable success remains ambiguous.
        if ($enforcePostResponseDeadline) {
            $this->remainingSeconds($request);
        }

        return $payload;
    }

    /** @return array<array-key, mixed>|null */
    private function poll(DriverRequest $request, string $credential, string $interactionId): ?array
    {
        return $this->get($request, $credential, $interactionId, self::POLL_TIMEOUT_SECONDS, Operation::Poll);
    }

    /** @return array<array-key, mixed>|null */
    private function retrieve(DriverRequest $request, string $credential, string $interactionId): ?array
    {
        return $this->get($request, $credential, $interactionId, self::RETRIEVE_TIMEOUT_SECONDS, Operation::Retrieve);
    }

    /** @return array<array-key, mixed>|null */
    private function get(
        DriverRequest|RecoveryRequest $request,
        string $credential,
        string $interactionId,
        int $timeout,
        Operation $operation,
        bool $transientAsPending = true,
    ): ?array {
        try {
            $response = $this->send(
                request: $request,
                credential: $credential,
                method: 'get',
                url: self::ENDPOINT.'/'.rawurlencode($interactionId),
                timeout: $timeout,
                operation: $operation,
            );
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            if (! $transientAsPending) {
                throw new DriverException(
                    'gemini_deep.connection',
                    'Gemini Deep Research could not be reached while retrieving the accepted interaction.',
                    false,
                );
            }

            return null;
        }

        $this->remainingSeconds($request);
        if ($response->status() === 408 || $response->status() === 429 || $response->serverError()) {
            if (! $transientAsPending) {
                throw $this->httpFailure($response, true);
            }

            return null;
        }
        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response, true);
        }

        $payload = $this->json($response);
        if ($this->interactionId($payload) !== $interactionId) {
            throw $this->invalidResponse();
        }
        $this->status($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function send(
        DriverRequest|RecoveryRequest $request,
        string $credential,
        string $method,
        string $url,
        int $timeout,
        array $payload = [],
        Operation $operation = Operation::Inline,
    ): Response {
        $remaining = $this->remainingSeconds($request);
        $pending = Http::withHeaders([
            'x-goog-api-key' => $credential,
            'Api-Revision' => self::API_REVISION,
        ])
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
            ->timeout(min($timeout, $remaining));

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

    private function waitBeforePolling(DriverRequest $request): void
    {
        $remaining = $this->remainingSeconds($request);
        Sleep::for(min(self::POLL_INTERVAL_SECONDS, $remaining))->seconds();

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }
    }

    private function remainingSeconds(DriverRequest|RecoveryRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds < 0.001) {
            throw $this->deadlineExceeded();
        }

        return $seconds;
    }

    /** @param array<array-key, mixed> $payload */
    private function interactionId(array $payload): string
    {
        $id = data_get($payload, 'id');
        if (! is_string($id)
            || trim($id) !== $id
            || $id === ''
            || strlen($id) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw $this->invalidResponse();
        }

        return $id;
    }

    /** @param array<array-key, mixed> $payload */
    private function status(array $payload): string
    {
        $status = data_get($payload, 'status');
        if (! is_string($status) || trim($status) !== $status || $status === '') {
            throw $this->invalidResponse();
        }

        return $status;
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

    private function httpFailure(Response $response, bool $retrieval): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('gemini_deep.authentication', 'Gemini Deep Research rejected the configured credential.', false),
            $response->status() === 403 => new DriverException('gemini_deep.authorization', 'Gemini Deep Research denied this request.', false),
            $response->status() === 404 && $retrieval => new DriverException('gemini_deep.interaction_missing', 'The Gemini Deep Research interaction is no longer available.', false),
            $response->status() === 408 => new DriverException('gemini_deep.timeout', 'Gemini Deep Research timed out while processing this request.', false),
            $response->status() === 429 => new DriverException('gemini_deep.rate_limited', 'Gemini Deep Research rate limited this request.', false),
            $response->serverError() => new DriverException('gemini_deep.unavailable', 'Gemini Deep Research is temporarily unavailable.', false),
            default => new DriverException('gemini_deep.invalid_request', 'Gemini Deep Research rejected this request.', false),
        };
    }

    private function submissionFailure(Response $response): DriverException
    {
        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            return $this->ambiguousSubmission();
        }

        return $this->httpFailure($response, false);
    }

    private function ambiguousSubmission(): DriverException
    {
        return new DriverException(
            'gemini_deep.ambiguous_submission',
            'Gemini Deep Research did not safely acknowledge the paid submission; it was not retried.',
            false,
        );
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'gemini_deep.deadline_exceeded',
            'Gemini Deep Research did not complete before the research deadline.',
            false,
        );
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'gemini_deep.invalid_response',
            'Gemini Deep Research returned an invalid interaction response.',
            false,
        );
    }
}
