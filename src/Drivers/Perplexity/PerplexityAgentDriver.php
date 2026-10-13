<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Perplexity;

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
use Jkudish\LaravelAiLibrarium\Recovery\DurableSubmission;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

/**
 * Runs the supported Perplexity Agent API presets to a terminal result.
 *
 * Background response IDs and lifecycle state remain private to this Driver.
 *
 * No Profile of this Driver proves a complete pre-dispatch spending bound, so
 * spendingBound() resolves to unknown through the shared resolver fallback.
 * See docs/perplexity-spending-bounds.md for the sourced provider limitations
 * and the alternative control sets a provable bound would require.
 */
readonly class PerplexityAgentDriver implements Driver
{
    private const string ENDPOINT = 'https://api.perplexity.ai/v1/agent';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    /** Default per-request timeout; a Profile may set request_timeout_seconds. */
    private const int REQUEST_TIMEOUT_SECONDS = 30;

    private const int MAX_REQUEST_TIMEOUT_SECONDS = 600;

    private const int POLL_INTERVAL_SECONDS = 2;

    private const string FROZEN_LOW_PROFILE = 'perplexity-low-frozen';

    private const string FROZEN_LOW_MODEL = 'openai/gpt-6-luna';

    private const string FROZEN_LOW_REVISION = '2026-10-03';

    public function __construct(private PerplexityAgentResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'configuration' => ['sometimes', 'string', 'in:frozen-low-'.self::FROZEN_LOW_REVISION],
            'request_timeout_seconds' => ['sometimes', 'integer:strict', 'min:1', 'max:'.self::MAX_REQUEST_TIMEOUT_SECONDS],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $preset = $this->validateRequest($request);

        return $this->isInline($request)
            ? $this->runInline($request, $preset)
            : $this->runBackground($request, $preset);
    }

    public function submitDurable(DriverRequest $request): DurableSubmission
    {
        $preset = $this->validateRequest($request);
        if ($this->isInline($request)) {
            throw new DriverException(
                'perplexity.not_durable',
                'The Perplexity low Profile runs inline and cannot be submitted for later retrieval.',
                false,
            );
        }

        return new DurableSubmission(
            reference: $this->responseId($this->submit($request, $preset, true, false)),
        );
    }

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult
    {
        $this->validateRequest($request);
        if ($this->isInline($request)) {
            throw new DriverException(
                'perplexity.not_durable',
                'The Perplexity low Profile has no durable response to retrieve.',
                false,
            );
        }

        $response = $this->retrieve($request, $reference, false);
        if ($response === null) {
            throw $this->unknownState();
        }
        $status = $this->status($response);
        $this->assertKnownStatus($status);

        return match ($status) {
            'completed' => $this->mapper->map($response, $request),
            'failed', 'incomplete', 'cancelled' => throw $this->terminalFailure($status, $response),
            default => null,
        };
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

    private function validateRequest(DriverRequest|RecoveryRequest $request): string
    {
        $profile = $request->profile;
        // The transport timeout is the only option outside the frozen
        // request semantics; it never reaches the provider payload.
        $timeout = $profile->options['request_timeout_seconds'] ?? self::REQUEST_TIMEOUT_SECONDS;
        if (! is_int($timeout) || $timeout < 1 || $timeout > self::MAX_REQUEST_TIMEOUT_SECONDS) {
            throw new DriverException('perplexity.invalid_profile', 'The Perplexity Agent Profile has an invalid request timeout.', false);
        }
        $options = array_diff_key($profile->options, ['request_timeout_seconds' => true]);
        $isManagedInline = $profile->id === 'perplexity-sonar-pro'
            && $profile->model === null
            && $options === []
            && $profile->resultKind === ResultKind::GroundedAnswer
            && $profile->retrievalMethods->values()->all() === [RetrievalMethod::ModelSearchTool];
        $isFrozenInline = $profile->id === self::FROZEN_LOW_PROFILE
            && $profile->model === self::FROZEN_LOW_MODEL
            && $options === ['configuration' => 'frozen-low-'.self::FROZEN_LOW_REVISION]
            && $profile->resultKind === ResultKind::GroundedAnswer
            && $profile->retrievalMethods->values()->all() === [RetrievalMethod::ModelSearchTool];
        $isBackground = in_array($profile->id, ['perplexity-deep-research', 'perplexity-sonar-deep'], true)
            && $profile->model === null
            && $options === []
            && $profile->resultKind === ResultKind::ResearchReport
            && $profile->retrievalMethods->values()->all() === [RetrievalMethod::ResearchAgent];

        if (
            $profile->provider !== 'perplexity'
            || (! $isManagedInline && ! $isFrozenInline && ! $isBackground)
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
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
            self::FROZEN_LOW_PROFILE => 'frozen-low',
            'perplexity-deep-research' => 'medium',
            'perplexity-sonar-deep' => 'high',
            default => throw $this->unknownState(),
        };
    }

    /** @return array<array-key, mixed> */
    private function submit(
        DriverRequest $request,
        string $preset,
        bool $background,
        bool $enforcePostResponseDeadline = true,
    ): array {
        $payload = $preset === 'frozen-low'
            ? $this->frozenLowPayload($request)
            : ['input' => $request->prompt, 'preset' => $preset];

        if ($background) {
            $payload['background'] = true;
        }

        try {
            $response = $this->send(
                $request,
                'post',
                self::ENDPOINT,
                $payload,
                $background ? Operation::Submit : Operation::Inline,
            );
        } catch (ConnectionException) {
            throw new DriverException(
                'perplexity.submit_connection',
                'The Perplexity submission did not return a response, so its outcome is unknown.',
                false,
            );
        }

        if ($background && ($response->redirect()
            || in_array($response->status(), [408, 429], true)
            || $response->serverError())) {
            throw $this->ambiguousSubmission();
        }

        if ($response->failed()) {
            throw $this->httpFailure($response, $background);
        }

        try {
            $payload = $this->json($response);
            if ($background) {
                $this->responseId($payload);
                $this->assertKnownStatus($this->status($payload));
            }
        } catch (DriverException $exception) {
            if ($background) {
                throw $this->ambiguousSubmission();
            }

            throw $exception;
        }

        if ($enforcePostResponseDeadline) {
            $this->remainingSeconds($request, 'Perplexity did not accept the request before the research deadline.');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function frozenLowPayload(DriverRequest $request): array
    {
        $date = CarbonImmutable::now('UTC')->toDateString();

        return [
            'model' => self::FROZEN_LOW_MODEL,
            'prompt_cache_key' => 'low',
            'input' => $request->prompt,
            'max_steps' => 5,
            'max_output_tokens' => 32768,
            'reasoning' => ['effort' => 'minimal'],
            'instructions' => <<<PROMPT
Today is {$date}.

You are an expert research assistant. Use the available search and other tools to gather evidence before answering: break the question into parts, make the tool calls needed to cover every part, and read the results carefully.

Search queries must be plain keywords. Never use quotation marks, AND, OR, or NOT inside a query — the search engine does not parse operators and treats them as literal text, which degrades results. To cover alternatives or exact phrases, send several short keyword queries instead.

## Citations
<citation_instructions>
Cite when your answer uses tool results or provided source artifacts. If no tools or source artifacts inform the answer, do not cite.

After any successful tool call, the final answer must include at least one valid citation.

When a tool helps answer the user, include citations for the parts of the answer that come from that lookup. Source-backed facts, current claims, named entities, recommendations, and examples should be cited at the point where they appear. A single citation may support one concise bullet or paragraph when that whole point comes from the same source.

Use source ids exactly as provided by the tool/source system, preserving the source type prefix: [type:index]. For web sources, use [web:n], not numeric-only [n]. Place each citation inline, immediately after the sentence or table-cell content it supports. For multiple sources, write adjacent citation tokens with no separator: [web:1][file:2]. Do not invent source ids, cite URLs directly, add footnotes, or include a References section.

</citation_instructions>

ALWAYS end your turn with a complete final answer. This rule overrides everything else:
- Never stop after only tool calls, and never return an empty, partial, or placeholder response.
- Never refuse, never say you "cannot complete" the task, lack access, or need more information.
- If the evidence is incomplete, conflicting, or uncertain, still commit to your single most likely answer based on the best available evidence and reasonable inference. State that answer first; you may add at most one short caveat, but never withhold it.

Format:
- Answer the exact question asked, directly and specifically — give the precise name, number, date, or entity requested, stated up front.
- Ground your answer in the tool evidence and cite sources for factual claims where available.

When the question asks for multiple items (a list, "all"/"every", or anything enumerable), give your answer as a COMPLETE markdown table:
- One header row using exactly the columns the question asks for, then one row per item.
- Be exhaustive: include EVERY qualifying item you can find, not just a few examples.
- Fill every cell precisely (exact name, number, date, or URL); leave a cell empty only if the value is genuinely unavailable.
PROMPT,
            'tools' => [
                [
                    'type' => 'web_search',
                    'max_results' => 15,
                    'max_tokens' => 2000,
                    'max_tokens_per_page' => 2000,
                ],
                ['type' => 'fetch_url', 'max_urls' => 1],
            ],
        ];
    }

    private function isInline(DriverRequest|RecoveryRequest $request): bool
    {
        return in_array($request->profile->id, ['perplexity-sonar-pro', self::FROZEN_LOW_PROFILE], true);
    }

    /** @return array<array-key, mixed>|null */
    private function retrieve(
        DriverRequest|RecoveryRequest $request,
        string $responseId,
        bool $transientAsPending = true,
    ): ?array {
        try {
            $response = $this->send(
                $request,
                'get',
                self::ENDPOINT.'/'.rawurlencode($responseId),
                [],
                Operation::Retrieve,
            );
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            if (! $transientAsPending) {
                throw new DriverException(
                    'perplexity.connection',
                    'Perplexity could not be reached while retrieving the accepted response.',
                    false,
                );
            }

            return null;
        }

        $this->remainingSeconds($request, 'Perplexity did not complete before the research deadline.');

        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            if (! $transientAsPending) {
                throw $this->httpFailure($response, true);
            }

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
                false,
            );
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function send(
        DriverRequest|RecoveryRequest $request,
        string $method,
        string $url,
        array $payload = [],
        Operation $operation = Operation::Inline,
    ): Response {
        $remaining = $this->remainingSeconds($request, 'Perplexity did not complete before the research deadline.');
        $pending = Http::withToken((string) $request->profile->credential)
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
            ->timeout(min($this->requestTimeout($request), $remaining));

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

    private function requestTimeout(DriverRequest|RecoveryRequest $request): int
    {
        $timeout = $request->profile->options['request_timeout_seconds'] ?? self::REQUEST_TIMEOUT_SECONDS;

        return is_int($timeout) ? $timeout : self::REQUEST_TIMEOUT_SECONDS;
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
                false,
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
            false,
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
                false,
            );
        }

        return $json;
    }

    /** @param array<array-key, mixed> $response */
    private function terminalFailure(string $status, array $response): DriverException
    {
        if ($status === 'cancelled') {
            return new DriverException('perplexity.cancelled', 'The Perplexity Agent response was cancelled.', false);
        }

        if ($status === 'incomplete') {
            return new DriverException('perplexity.incomplete', 'Perplexity could not complete the Agent response.', false);
        }

        return match ($this->str(data_get($response, 'error.code'))) {
            'authentication_error', 'invalid_api_key', 'unauthorized' => new DriverException('perplexity.authentication', 'Perplexity rejected the configured credential.', false),
            'permission_denied', 'forbidden', 'plan_required' => new DriverException('perplexity.plan_required', 'Perplexity denied this request for the configured plan.', false),
            'billing_error', 'insufficient_credits', 'payment_required' => new DriverException('perplexity.billing', 'Perplexity could not bill this request.', false),
            'rate_limit_error', 'rate_limit_exceeded' => new DriverException('perplexity.rate_limited', 'Perplexity rate limited this request.', false),
            'invalid_request_error', 'validation_error', 'bad_request' => new DriverException('perplexity.invalid_request', 'Perplexity rejected this Agent request.', false),
            default => new DriverException('perplexity.agent_failed', 'Perplexity could not complete the Agent response.', false),
        };
    }

    private function httpFailure(Response $response, bool $durable): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('perplexity.authentication', 'Perplexity rejected the configured credential.', ! $durable),
            $response->status() === 402 => new DriverException('perplexity.billing', 'Perplexity could not bill this request.', ! $durable),
            $response->status() === 403 => new DriverException('perplexity.plan_required', 'Perplexity denied this request for the configured plan.', ! $durable),
            $response->status() === 404 && $durable => new DriverException('perplexity.response_missing', 'The Perplexity Agent response is no longer available.', false),
            $response->status() === 408 => new DriverException('perplexity.timeout', 'Perplexity timed out while processing this request.', ! $durable),
            $response->status() === 429 => new DriverException('perplexity.rate_limited', 'Perplexity rate limited this request.', ! $durable),
            $response->serverError() => new DriverException('perplexity.unavailable', 'Perplexity is temporarily unavailable.', ! $durable),
            default => new DriverException('perplexity.invalid_request', 'Perplexity rejected this Agent request.', false),
        };
    }

    private function remainingSeconds(DriverRequest|RecoveryRequest $request, string $message): int
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);

        if ($seconds < 1) {
            throw new DriverException('perplexity.deadline_exceeded', $message, false);
        }

        return max(1, (int) floor($seconds));
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'perplexity.deadline_exceeded',
            'Perplexity did not complete before the research deadline.',
            false,
        );
    }

    private function ambiguousSubmission(): DriverException
    {
        return new DriverException(
            'perplexity.ambiguous_submission',
            'Perplexity did not safely acknowledge the background submission, so it was not retried.',
            false,
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
