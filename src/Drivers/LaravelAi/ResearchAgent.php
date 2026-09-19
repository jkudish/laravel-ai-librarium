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
        private bool $exactOpenRouterRouting = false,
        private ?int $maxOutputTokens = null,
        private ?int $maxSteps = null,
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
        if ($this->searchEngine !== null) {
            $search->withProviderOptions(['engine' => $this->searchEngine]);
        }

        return [$search];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;
        if (! $this->exactOpenRouterRouting || $driver !== Lab::OpenRouter->value) {
            return [];
        }

        return [
            'provider' => [
                'require_parameters' => true,
                'allow_fallbacks' => false,
            ],
        ];
    }
}
