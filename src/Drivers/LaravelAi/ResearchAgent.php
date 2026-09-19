<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\LaravelAi;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

/** @internal */
final readonly class ResearchAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * Routing for the bounded OpenRouter configuration: restrict to the
     * OpenAI default-tier endpoints serving openai/gpt-5.6-terra. Base slugs
     * do not match non-default service tiers, so order "openai" already
     * excludes flex-style tiers; the openai/flex ignore is explicit redundant
     * defense, not the pin itself.
     */
    private const array OPENROUTER_PINNED_PROVIDER_ORDER = ['openai'];

    private const array OPENROUTER_PINNED_PROVIDER_IGNORE = ['openai/flex'];

    /**
     * Base-price ceiling for the pinned bounded OpenRouter route, in USD per
     * million tokens. OpenRouter's OpenAPI schema types these as strings, and
     * the filter matches endpoints whose base prompt/completion price is at
     * or below the ceiling, failing the request when no endpoint qualifies.
     * This is a base-price routing filter, not an all-in spend cap.
     */
    private const array OPENROUTER_PINNED_PROVIDER_MAX_PRICE = ['prompt' => '2', 'completion' => '12'];

    public function __construct(
        private bool $usesWebSearch = true,
        private ?string $searchEngine = null,
        private ?string $searchMode = null,
        private bool $exactOpenRouterRouting = false,
        private ?int $maxOutputTokens = null,
        private ?int $maxSteps = null,
        private ?int $maxSearches = null,
        private ?int $maxToolCalls = null,
        private ?int $maxResults = null,
        private bool $pinsOpenRouterProvider = false,
    ) {}

    public function maxTokens(): ?int
    {
        return $this->maxOutputTokens;
    }

    public function maxSteps(): ?int
    {
        return $this->maxSteps;
    }

    public function instructions(): string
    {
        return $this->usesWebSearch
            ? 'Use web search to answer the user with current information. Cite the sources that support the answer.'
            : 'Answer the user directly and accurately.';
    }

    /** @return list<WebSearch> */
    public function tools(): iterable
    {
        if (! $this->usesWebSearch) {
            return [];
        }

        $search = new WebSearch;
        if ($this->maxSearches !== null) {
            $search->max($this->maxSearches);
        }

        if ($this->searchEngine !== null) {
            $search->withProviderOptions(array_filter([
                'engine' => $this->searchEngine,
                'mode' => $this->searchMode,
                'max_results' => $this->maxResults,
            ], static fn (mixed $value): bool => $value !== null));
        }

        return [$search];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;

        $options = [];

        if ($this->maxToolCalls !== null
            && in_array($driver, [Lab::OpenAI->value, Lab::OpenRouter->value], true)) {
            $options['max_tool_calls'] = $this->maxToolCalls;
        }

        if ($this->exactOpenRouterRouting && $driver === Lab::OpenRouter->value) {
            $routing = [];

            if ($this->pinsOpenRouterProvider) {
                $routing['order'] = self::OPENROUTER_PINNED_PROVIDER_ORDER;
                $routing['ignore'] = self::OPENROUTER_PINNED_PROVIDER_IGNORE;
                $routing['max_price'] = self::OPENROUTER_PINNED_PROVIDER_MAX_PRICE;
            }

            $routing['require_parameters'] = true;
            $routing['allow_fallbacks'] = false;

            $options['provider'] = $routing;
        }

        return $options;
    }
}
