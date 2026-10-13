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
final class ResearchAgent implements Agent, HasProviderOptions, HasTools
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

        /**
         * Configured OpenRouter routing and privacy controls. Unset
         * require_parameters and allow_fallbacks keep the exact-routing
         * defaults (true and false); data_collection and zdr are sent only
         * when configured.
         *
         * @var array<string, mixed>
         */
        private array $openRouterRouting = [],

        /**
         * Configured OpenRouter reasoning controls. Empty sends no reasoning
         * key, so the model default applies.
         *
         * @var array<string, mixed>
         */
        private array $openRouterReasoning = [],
        private ?string $anthropicEffort = null,
        private ?string $anthropicThinking = null,
        private ?string $openAiEffort = null,
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

        if ($driver === Lab::OpenAI->value && $this->openAiEffort !== null) {
            $options['reasoning'] = ['effort' => $this->openAiEffort];
        }

        if ($this->exactOpenRouterRouting && $driver === Lab::OpenRouter->value) {
            $routing = [];

            if ($this->pinsOpenRouterProvider && $this->pinnedOpenRouterRouting !== null) {
                $routing['order'] = $this->pinnedOpenRouterRouting['order'];
                $routing['ignore'] = $this->pinnedOpenRouterRouting['ignore'];
                $routing['max_price'] = $this->pinnedOpenRouterRouting['max_price'];
            } elseif (array_key_exists('provider_order', $this->openRouterRouting)) {
                $routing['order'] = $this->openRouterRouting['provider_order'];
            }

            $routing['require_parameters'] = $this->openRouterRouting['require_parameters'] ?? true;
            $routing['allow_fallbacks'] = $this->openRouterRouting['allow_fallbacks'] ?? false;

            foreach (['data_collection', 'zdr'] as $key) {
                if (array_key_exists($key, $this->openRouterRouting)) {
                    $routing[$key] = $this->openRouterRouting[$key];
                }
            }

            $options['provider'] = $routing;
        }

        if ($driver === Lab::OpenRouter->value) {
            if ($this->pinsOpenRouterProvider && $this->pinnedOpenRouterReasoning !== null) {
                $options['reasoning'] = $this->pinnedOpenRouterReasoning;
            } elseif ($this->openRouterReasoning !== []) {
                $reasoning = [];
                foreach (['reasoning_effort' => 'effort', 'reasoning_max_tokens' => 'max_tokens', 'reasoning_exclude' => 'exclude'] as $option => $key) {
                    if (array_key_exists($option, $this->openRouterReasoning)) {
                        $reasoning[$key] = $this->openRouterReasoning[$option];
                    }
                }

                $options['reasoning'] = $reasoning;
            }
        }

        if ($driver === Lab::Anthropic->value) {
            if ($this->anthropicThinking !== null) {
                $options['thinking'] = ['type' => $this->anthropicThinking];
            }

            if ($this->anthropicEffort !== null) {
                $options['output_config'] = ['effort' => $this->anthropicEffort];
            }
        }

        return $options;
    }
}
