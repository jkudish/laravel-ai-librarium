<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final class Profile implements Arrayable, JsonSerializable
{
    /**
     * @param  Collection<int, Corpus>  $corpora
     * @param  Collection<int, RetrievalMethod>  $retrievalMethods
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $id,
        public readonly string $driver,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly ResultKind $resultKind,
        public readonly GroundingPolicy $grounding,
        public readonly ObservationMode $observation,
        public readonly Collection $corpora,
        public readonly Collection $retrievalMethods,
        public readonly string $prompt,
        public readonly bool $enabled,
        public readonly array $options,
        public readonly ?string $credential = null,
        public readonly ?ModelIdentity $pricingIdentity = null,
        public readonly ?Usage $pricingUsage = null,
        public readonly bool $pricingUsageConservative = false,
        public readonly bool $allowProviderRouting = false,
    ) {}

    public function renderPrompt(string $input): string
    {
        return str_replace('{{ query }}', $input, $this->prompt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'driver' => $this->driver,
            'provider' => $this->provider,
            'model' => $this->model,
            'result_kind' => $this->resultKind->value,
            'grounding' => $this->grounding->value,
            'observation' => $this->observation->value,
            'corpora' => $this->corpora->map(fn (Corpus $corpus): string => $corpus->value)->values()->all(),
            'retrieval_methods' => $this->retrievalMethods->map(fn (RetrievalMethod $method): string => $method->value)->values()->all(),
            'prompt' => $this->prompt,
            'enabled' => $this->enabled,
            'allow_provider_routing' => $this->allowProviderRouting,
            'options' => $this->options,
            'pricing' => $this->pricingIdentity === null || $this->pricingUsage === null ? null : [
                'identity' => $this->pricingIdentity->toArray(),
                'usage' => $this->pricingUsage->toArray(),
                'conservative' => $this->pricingUsageConservative,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
