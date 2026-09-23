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
        /**
         * Exact-route provider controls for the pinned bounded OpenRouter
         * configuration, resolved by the driver from its per-model source of
         * truth. Routing for the bounded routes restricts to the OpenAI
         * default-tier endpoints serving the exact bounded model. Base slugs
         * do not match non-default service tiers, so order "openai" already
         * excludes flex- and fast-style tiers; the model's explicit ignore
         * list is redundant defense, not the pin itself.
         *
         * @var array{order: list<string>, ignore: list<string>, max_price: array{prompt: string, completion: string}}|null
         */
        private ?array $pinnedOpenRouterRouting = null,

        /**
         * Reviewed reasoning controls emitted verbatim at the top level of the
         * OpenRouter chat-completions body for the pinned bounded model that
         * declares them. The Laravel AI SDK performs no reasoning-effort enum
         * mapping — providerOptions are merged into the outbound body as-is —
         * so the reviewed effort reaches the provider unmodified or the test
         * suite fails. Null (the historical Terra route and every unpinned
         * route) emits no reasoning key at all.
         *
         * @var array{effort: string, exclude: bool}|null
         */
        private ?array $pinnedOpenRouterReasoning = null,
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

            if ($this->pinsOpenRouterProvider && $this->pinnedOpenRouterRouting !== null) {
                $routing['order'] = $this->pinnedOpenRouterRouting['order'];
                $routing['ignore'] = $this->pinnedOpenRouterRouting['ignore'];
                $routing['max_price'] = $this->pinnedOpenRouterRouting['max_price'];
            }

            $routing['require_parameters'] = true;
            $routing['allow_fallbacks'] = false;

            $options['provider'] = $routing;
        }

        if ($this->pinsOpenRouterProvider
            && $this->pinnedOpenRouterReasoning !== null
            && $driver === Lab::OpenRouter->value) {
            $options['reasoning'] = $this->pinnedOpenRouterReasoning;
        }

        return $options;
    }
}
