<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\LaravelAi;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

final readonly class LaravelAiDriver implements DefinesSpendingBound, Driver
{
    private const int CLAUDE_SONNET_5_CONTEXT_TOKENS = 1_000_000;

    private const int CLAUDE_SONNET_5_MAX_OUTPUT_TOKENS = 128_000;

    /**
     * The OpenAI Responses API rejects max_output_tokens below 16 for every
     * model, including gpt-5.6-luna.
     */
    private const int OPENAI_RESPONSES_MIN_OUTPUT_TOKENS = 16;

    /**
     * OpenRouter's server-tool budget: "Each tool call the model makes ...
     * consumes one step; when the budget is exhausted, the model is asked to
     * produce its final answer." max_tool_calls defaults to 30 and 30 is the
     * documented maximum, so a budgeted request performs at most 30 tool-call
     * steps plus one final model completion.
     */
    private const int OPENROUTER_SERVER_TOOL_BUDGET_MAXIMUM = 30;

    /**
     * Context length of the pinned OpenAI endpoint serving
     * openai/gpt-5.6-terra per OpenRouter's endpoint catalog.
     */
    private const int OPENROUTER_OPENAI_GPT_5_6_TERRA_CONTEXT_TOKENS = 1_050_000;

    /**
     * Per-completion output maximum of the pinned OpenAI endpoint serving
     * openai/gpt-5.6-terra per OpenRouter's endpoint catalog. Forwarding the
     * request's max_tokens to every internal loop completion is not
     * documented, so the bound uses the model maximum per completion.
     */
    private const int OPENROUTER_OPENAI_GPT_5_6_TERRA_MAX_COMPLETION_TOKENS = 128_000;

    private const int OPENROUTER_EXA_MAX_RESULTS_WITHOUT_ADDITIONAL_RESULT_FEES = 10;

    private const int OPENROUTER_EXA_MAX_RESULTS_LIMIT = 25;

    /** @var list<string> */
    private const array OPENROUTER_SEARCH_ENGINES = ['exa', 'firecrawl', 'parallel', 'perplexity'];

    /** @var list<string> */
    private const array MAX_SEARCHES_PROVIDERS = ['anthropic', 'openrouter'];

    /** @var list<string> */
    private const array MAX_TOOL_CALLS_PROVIDERS = ['openai', 'openrouter'];

    /**
     * The only OpenRouter model whose grounded bound is proven. The endpoint
     * pin applies to exactly this model so a budget control on any other
     * OpenRouter model never reroutes it to OpenAI.
     */
    private const string OPENROUTER_BOUNDED_MODEL = 'openai/gpt-5.6-terra';

    /**
     * The exact canonical revision the pinned bounded route must serve. The
     * request keeps the moving alias — OpenRouter's public catalog does not
     * serve the dated slug as a requestable model, so sending it would be an
     * invented control — and OpenRouter reports the concrete serving revision
     * in the response's model field, which the pinned route checks exactly.
     */
    private const string OPENROUTER_BOUNDED_MODEL_REVISION = 'openai/gpt-5.6-terra-20260709';

    /**
     * The Exa web-search modes OpenRouter documents for the mode parameter.
     * The bounded request pins "auto", the documented default, so the bound's
     * per-request Exa fee basis stays the reviewed one; other modes price
     * differently and fail the bound closed.
     *
     * @var list<string>
     */
    private const array OPENROUTER_EXA_SEARCH_MODES = ['instant', 'fast', 'auto', 'deep-lite', 'deep', 'deep-reasoning'];

    public function __construct(private ResearchResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'web_search' => ['sometimes', 'boolean'],
            'search_engine' => ['sometimes', 'string', 'in:'.implode(',', self::OPENROUTER_SEARCH_ENGINES)],
            'search_mode' => ['sometimes', 'string', 'in:'.implode(',', self::OPENROUTER_EXA_SEARCH_MODES)],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1', 'max:'.self::CLAUDE_SONNET_5_MAX_OUTPUT_TOKENS],
            'max_steps' => ['sometimes', 'integer', 'min:1'],
            'max_prompt_bytes' => ['sometimes', 'integer', 'min:1'],
            'max_searches' => ['sometimes', 'integer', 'min:1'],
            'max_tool_calls' => ['sometimes', 'integer', 'min:1'],
            'max_results' => ['sometimes', 'integer', 'min:1', 'max:'.self::OPENROUTER_EXA_MAX_RESULTS_LIMIT],
        ];
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        $options = $request->profile->options;

        if ($request->profile->provider === 'openrouter'
            && $request->profile->model === self::OPENROUTER_BOUNDED_MODEL) {
            return $this->openRouterGroundedSpendingBound($request, $options);
        }

        if ($request->profile->provider === 'anthropic'
            && $request->profile->model === 'claude-sonnet-5'
            && ($options['web_search'] ?? true) === false) {
            return $this->noSearchClaudeSpendingBound($request, $options);
        }

        return SpendingBound::unknown();
    }

    /**
     * The one grounded route with a complete official quantity authority:
     * OpenRouter's server-tool budget bounds the agent loop itself. "Each tool
     * call the model makes ... consumes one step; when the budget is
     * exhausted, the model is asked to produce its final answer," and
     * max_tool_calls defaults to 30 with 30 as the documented maximum, so the
     * request performs at most budget + 1 model completions regardless of
     * whether individual searches succeed, error, or are attempted past a
     * per-tool cap. Every other grounded route stays unknown: Anthropic's
     * max_uses returns max_uses_exceeded error results to the internal model
     * (undocumented per-turn iteration cap) and OpenAI's max_tool_calls only
     * ignores further attempts ("Any further attempts to call a tool by the
     * model will be ignored"), which does not prove inference stops, so no
     * multiple of the context window bounds their internal iterations.
     *
     * @param  array<string, mixed>  $options
     */
    private function openRouterGroundedSpendingBound(DriverRequest $request, array $options): SpendingBound
    {
        $maxToolCalls = $options['max_tool_calls'] ?? null;
        $maxResults = $options['max_results'] ?? null;
        $maxSteps = $options['max_steps'] ?? null;
        $maxPromptBytes = $options['max_prompt_bytes'] ?? null;

        if (($options['web_search'] ?? null) !== true
            || ($options['search_engine'] ?? null) !== 'exa'
            || ($options['search_mode'] ?? null) !== 'auto'
            || array_key_exists('max_searches', $options)
            || ! is_int($maxToolCalls)
            || $maxToolCalls < 1
            || $maxToolCalls > self::OPENROUTER_SERVER_TOOL_BUDGET_MAXIMUM
            || ! is_int($maxResults)
            || $maxResults < 1
            || $maxResults > self::OPENROUTER_EXA_MAX_RESULTS_WITHOUT_ADDITIONAL_RESULT_FEES
            || $maxSteps !== 1
            || ! is_int($maxPromptBytes)
            || $maxPromptBytes < 1
            || strlen($request->prompt) > $maxPromptBytes) {
            return SpendingBound::unknown();
        }

        // A configured max_searches alongside the budget is ambiguous: after
        // max_uses is reached, further search calls "return an error result
        // instead of executing," and the docs do not state whether those
        // failed attempts still consume budget steps, so the loop length
        // cannot be derived. The check above fails such configurations closed
        // instead of assuming an interpretation.

        $completions = $maxToolCalls + 1;

        // The request restricts routing to the OpenAI default-tier endpoints
        // serving this model: base slugs do not match non-default service
        // tiers, so order "openai" excludes flex-style tiers, and the
        // openai/flex ignore is explicit redundant defense. provider.max_price
        // is additionally emitted as a base-price routing filter over those
        // endpoints — it restricts which endpoints may serve the request and
        // fails the request when none qualifies; it is not an all-in spend
        // cap, and the worst-case quantities below do not shrink because of
        // it. The current single default-tier endpoint and its rates are
        // catalog snapshot facts the pricing owner reverifies. The identity
        // names the worst-case billing basis: every completion conservatively
        // billed at the >272K-prompt override rates, which no aggregate
        // model rate satisfies, so rate resolution fails closed until the
        // registry serves this exact identity. The search unit is
        // engine-specific because the catalog's model-level web_search rate
        // is the native passthrough fee, not Exa's per-request engine fee.
        //
        // Input tokens bill as exclusive partitions — uncached input,
        // cache-read, and cache-write — and OpenAI automatic caching can
        // bill cache writes without any opt-in, so the request cannot be
        // proven cache-write-free. The bound therefore reserves the entire
        // worst-case input quantity in the most expensive partition
        // (cache-write at the compounded 1.25x long-context premium, $5/M),
        // which dominates every possible actual partition mix without
        // counting any token twice.
        return SpendingBound::billableQuantities(
            new ModelIdentity('openrouter', 'openai/gpt-5.6-terra-272k'),
            new Usage([
                'cache_write_input_tokens' => $completions * self::OPENROUTER_OPENAI_GPT_5_6_TERRA_CONTEXT_TOKENS,
                'output_tokens' => $completions * self::OPENROUTER_OPENAI_GPT_5_6_TERRA_MAX_COMPLETION_TOKENS,
                'exa_search_requests' => $maxToolCalls,
            ]),
        );
    }

    /**
     * The preserved no-search Claude bound: one exact request, no server
     * tools, so the context window itself caps input and max_tokens caps
     * output.
     *
     * @param  array<string, mixed>  $options
     */
    private function noSearchClaudeSpendingBound(DriverRequest $request, array $options): SpendingBound
    {
        $maxOutputTokens = $options['max_output_tokens'] ?? null;
        $maxSteps = $options['max_steps'] ?? null;
        $maxPromptBytes = $options['max_prompt_bytes'] ?? null;

        if (array_key_exists('search_engine', $options)
            || ! is_int($maxOutputTokens)
            || $maxOutputTokens < 1
            || $maxOutputTokens > self::CLAUDE_SONNET_5_MAX_OUTPUT_TOKENS
            || $maxSteps !== 1
            || ! is_int($maxPromptBytes)
            || $maxPromptBytes < 1
            || strlen($request->prompt) > $maxPromptBytes) {
            return SpendingBound::unknown();
        }

        return SpendingBound::billableQuantities(
            new ModelIdentity('anthropic', 'claude-sonnet-5-global'),
            new Usage([
                'input_tokens' => self::CLAUDE_SONNET_5_CONTEXT_TOKENS,
                'output_tokens' => $maxOutputTokens,
            ]),
        );
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $model = $request->profile->model;
        if ($model === null) {
            throw new DriverException(
                errorCode: 'laravel_ai.model_required',
                message: 'The Laravel AI Driver requires an exact model.',
                fallbackAllowed: false,
            );
        }

        $usesWebSearch = data_get($request->profile->options, 'web_search', true);
        $searchEngine = data_get($request->profile->options, 'search_engine');
        $searchMode = data_get($request->profile->options, 'search_mode');
        $maxOutputTokens = data_get($request->profile->options, 'max_output_tokens');
        $maxSteps = data_get($request->profile->options, 'max_steps');
        $maxPromptBytes = data_get($request->profile->options, 'max_prompt_bytes');
        if (! is_bool($usesWebSearch)) {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The Laravel AI Driver received invalid Profile options.',
                fallbackAllowed: false,
            );
        }

        if (($searchEngine !== null && ! is_string($searchEngine))
            || ($searchMode !== null && ! is_string($searchMode))) {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The Laravel AI Driver received invalid Profile options.',
                fallbackAllowed: false,
            );
        }

        if (($maxOutputTokens !== null && (! is_int($maxOutputTokens) || $maxOutputTokens < 1 || $maxOutputTokens > self::CLAUDE_SONNET_5_MAX_OUTPUT_TOKENS))
            || ($maxSteps !== null && (! is_int($maxSteps) || $maxSteps < 1))
            || ($maxPromptBytes !== null && (! is_int($maxPromptBytes) || $maxPromptBytes < 1))) {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The Laravel AI Driver received invalid Profile options.',
                fallbackAllowed: false,
            );
        }

        $maxSearches = data_get($request->profile->options, 'max_searches');
        $maxToolCalls = data_get($request->profile->options, 'max_tool_calls');
        $maxResults = data_get($request->profile->options, 'max_results');

        if (($maxSearches !== null && (! is_int($maxSearches) || $maxSearches < 1))
            || ($maxToolCalls !== null && (! is_int($maxToolCalls) || $maxToolCalls < 1))
            || ($maxResults !== null && (! is_int($maxResults) || $maxResults < 1 || $maxResults > self::OPENROUTER_EXA_MAX_RESULTS_LIMIT))
            || ($request->profile->provider === 'openai' && $maxOutputTokens !== null && $maxOutputTokens < self::OPENAI_RESPONSES_MIN_OUTPUT_TOKENS)
            || ($request->profile->provider === 'openrouter' && $maxToolCalls !== null && $maxToolCalls > self::OPENROUTER_SERVER_TOOL_BUDGET_MAXIMUM)) {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The Laravel AI Driver received invalid Profile options.',
                fallbackAllowed: false,
            );
        }

        if (($maxSearches !== null || $maxToolCalls !== null || $maxResults !== null || $searchMode !== null) && ! $usesWebSearch) {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'Grounded search caps require web search to be enabled.',
                fallbackAllowed: false,
            );
        }

        if ($maxSearches !== null && ! in_array($request->profile->provider, self::MAX_SEARCHES_PROVIDERS, true)) {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The max_searches control is supported only by Anthropic and OpenRouter Profiles.',
                fallbackAllowed: false,
            );
        }

        if ($maxToolCalls !== null && ! in_array($request->profile->provider, self::MAX_TOOL_CALLS_PROVIDERS, true)) {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The max_tool_calls control is supported only by OpenAI and OpenRouter Profiles.',
                fallbackAllowed: false,
            );
        }

        if ($maxResults !== null && $request->profile->provider !== 'openrouter') {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The max_results control is supported only by OpenRouter Profiles.',
                fallbackAllowed: false,
            );
        }

        if ($searchMode !== null && $request->profile->provider !== 'openrouter') {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The search_mode control is supported only by OpenRouter Profiles.',
                fallbackAllowed: false,
            );
        }

        if ($searchMode !== null && $searchEngine !== 'exa') {
            throw new DriverException(
                errorCode: 'laravel_ai.invalid_options',
                message: 'The search_mode control applies only to the Exa search engine.',
                fallbackAllowed: false,
            );
        }

        if (is_int($maxPromptBytes) && strlen($request->prompt) > $maxPromptBytes) {
            throw new DriverException(
                errorCode: 'laravel_ai.prompt_too_long',
                message: 'The rendered prompt exceeds the configured byte limit.',
                fallbackAllowed: false,
            );
        }

        if ($request->profile->provider === 'openrouter') {
            if ($usesWebSearch && ! in_array($searchEngine, self::OPENROUTER_SEARCH_ENGINES, true)) {
                throw new DriverException(
                    errorCode: 'laravel_ai.search_engine_required',
                    message: 'OpenRouter web search requires an exact supported search engine.',
                    fallbackAllowed: false,
                );
            }
        } elseif ($searchEngine !== null) {
            throw new DriverException(
                errorCode: 'laravel_ai.search_engine_unsupported',
                message: 'The search_engine option is supported only by OpenRouter Profiles.',
                fallbackAllowed: false,
            );
        }

        if ($request->profile->grounding === GroundingPolicy::Required && ! $usesWebSearch) {
            throw new DriverException(
                errorCode: 'laravel_ai.web_search_required',
                message: 'Required-grounding Profiles must enable web search.',
                fallbackAllowed: false,
            );
        }

        $timeout = $this->remainingSeconds($request);
        $pinsOpenRouterProvider = $request->profile->provider === 'openrouter'
            && $request->profile->model === self::OPENROUTER_BOUNDED_MODEL
            && $maxToolCalls !== null;
        $agent = new ResearchAgent(
            usesWebSearch: $usesWebSearch,
            searchEngine: $searchEngine,
            searchMode: $searchMode,
            exactOpenRouterRouting: $request->profile->provider === 'openrouter',
            maxOutputTokens: $maxOutputTokens,
            maxSteps: $maxSteps,
            maxSearches: $maxSearches,
            maxToolCalls: $maxToolCalls,
            maxResults: $maxResults,
            pinsOpenRouterProvider: $pinsOpenRouterProvider,
        );

        try {
            $response = $agent->prompt(
                $request->prompt,
                provider: $request->profile->provider,
                model: $model,
                timeout: $timeout,
            );
        } catch (RateLimitedException $exception) {
            $this->captureException($request, $exception);

            throw new DriverException('laravel_ai.rate_limited', 'The AI provider rate limited the research request.');
        } catch (ProviderOverloadedException $exception) {
            $this->captureException($request, $exception);

            throw new DriverException('laravel_ai.provider_unavailable', 'The AI provider is temporarily unavailable.');
        } catch (InsufficientCreditsException $exception) {
            $this->captureException($request, $exception);

            throw new DriverException('laravel_ai.insufficient_credits', 'The AI provider could not run the request because its quota is exhausted.');
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException('librarium.timeout', 'The shared research deadline was reached.', false);
            }

            throw new DriverException('laravel_ai.network', 'The AI provider could not be reached.');
        } catch (RequestException $exception) {
            $request->captureResponse($exception->response, Operation::Inline);

            throw $this->requestFailure($exception);
        } catch (ValidationException) {
            throw new DriverException('laravel_ai.validation', 'The AI provider request failed validation.', false);
        } catch (AiException $exception) {
            $this->captureException($request, $exception);

            throw new DriverException('laravel_ai.provider', 'The AI provider could not complete the research request.');
        }

        if ($pinsOpenRouterProvider) {
            $this->assertPinnedModelRevision($request, $response);
        }

        $this->captureResponse($request, $response);
        $result = $this->mapper->map($response, $request, $usesWebSearch);

        if ($request->profile->grounding === GroundingPolicy::Required && $result->citations->isEmpty()) {
            throw new DriverException(
                'laravel_ai.ungrounded_response',
                'The AI provider returned no evidence for the grounded response.',
            );
        }

        return $result;
    }

    /**
     * Fail-closed response-side model-revision binding for the pinned bounded
     * route. OpenRouter resolves the requested moving alias to the concrete
     * revision that served the request and reports it in the response's model
     * field, so alias movement — or a pinned endpoint serving another
     * revision — is a loud rejection instead of silent drift from the
     * reviewed spending contract. The dated revision slug itself is not
     * provably requestable, so the request keeps the alias and the binding
     * lives on the response.
     */
    private function assertPinnedModelRevision(DriverRequest $request, AgentResponse $response): void
    {
        if (data_get($response, 'meta.model') !== self::OPENROUTER_BOUNDED_MODEL_REVISION) {
            $this->captureResponse($request, $response);

            throw new DriverException(
                errorCode: 'laravel_ai.model_revision_drift',
                message: 'The pinned OpenRouter model served a revision other than the reviewed exact revision.',
                fallbackAllowed: false,
            );
        }
    }

    private function captureResponse(DriverRequest $request, AgentResponse $response): void
    {
        $captured = 0;
        $missing = 0;

        if ($response->steps->isNotEmpty()) {
            foreach ($response->steps as $step) {
                if ($step->raw instanceof Response) {
                    $request->captureResponse($step->raw, Operation::Inline);
                    $captured++;
                } else {
                    $missing++;
                }
            }
        } elseif ($response->raw instanceof Response) {
            $request->captureResponse($response->raw, Operation::Inline);
            $captured++;
        }

        if ($captured > 0 && $missing > 0) {
            $request->markCapturePartial();
        }
    }

    private function captureException(DriverRequest $request, Throwable $exception): void
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException) {
                $request->captureResponse($cause->response, Operation::Inline);

                return;
            }

            if ($cause instanceof ConnectionException) {
                $request->captureNoResponse(Operation::Inline);

                return;
            }
        }

        $request->captureNoResponse(Operation::Inline);
    }

    private function remainingSeconds(DriverRequest $request): int
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds <= 0) {
            throw new DriverException(
                errorCode: 'librarium.timeout',
                message: 'The shared research deadline was reached.',
                fallbackAllowed: false,
            );
        }

        return max(1, (int) ceil($seconds));
    }

    private function requestFailure(RequestException $exception): DriverException
    {
        $status = $exception->response->status();

        return match (true) {
            $status === 401 => new DriverException('laravel_ai.authentication', 'The AI provider rejected its configured credentials.'),
            $status === 403 => new DriverException('laravel_ai.authorization', 'The AI provider denied this research request.'),
            $status === 408 => new DriverException('laravel_ai.timeout', 'The AI provider timed out before completing the request.'),
            $status === 429 => new DriverException('laravel_ai.rate_limited', 'The AI provider rate limited the research request.'),
            $status >= 500 => new DriverException('laravel_ai.provider_unavailable', 'The AI provider is temporarily unavailable.'),
            default => new DriverException('laravel_ai.invalid_request', 'The AI provider rejected the research request.', false),
        };
    }
}
