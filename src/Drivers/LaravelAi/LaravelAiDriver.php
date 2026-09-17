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

    /** @var list<string> */
    private const array OPENROUTER_SEARCH_ENGINES = ['exa', 'firecrawl', 'parallel', 'perplexity'];

    public function __construct(private ResearchResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'web_search' => ['sometimes', 'boolean'],
            'search_engine' => ['sometimes', 'string', 'in:'.implode(',', self::OPENROUTER_SEARCH_ENGINES)],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1', 'max:'.self::CLAUDE_SONNET_5_MAX_OUTPUT_TOKENS],
            'max_steps' => ['sometimes', 'integer', 'min:1'],
            'max_prompt_bytes' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        $options = $request->profile->options;
        $maxOutputTokens = $options['max_output_tokens'] ?? null;
        $maxSteps = $options['max_steps'] ?? null;
        $maxPromptBytes = $options['max_prompt_bytes'] ?? null;

        if ($request->profile->provider !== 'anthropic'
            || $request->profile->model !== 'claude-sonnet-5'
            || ($options['web_search'] ?? true) !== false
            || array_key_exists('search_engine', $options)
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
            new ModelIdentity('anthropic', 'claude-sonnet-5'),
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

        if ($searchEngine !== null && ! is_string($searchEngine)) {
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
        $agent = new ResearchAgent(
            usesWebSearch: $usesWebSearch,
            searchEngine: $searchEngine,
            exactOpenRouterRouting: $request->profile->provider === 'openrouter',
            maxOutputTokens: $maxOutputTokens,
            maxSteps: $maxSteps,
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
