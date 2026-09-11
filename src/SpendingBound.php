<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Support\Arrayable;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class SpendingBound implements Arrayable, JsonSerializable
{
    private function __construct(
        public SpendingBoundKind $kind,
        public ?Money $maximumCost = null,
        public ?ModelIdentity $pricingIdentity = null,
        public ?Usage $quantities = null,
    ) {}

    public static function nativeMonetaryCeiling(Money $maximumCost): self
    {
        return new self(
            kind: SpendingBoundKind::NativeMonetaryCeiling,
            maximumCost: $maximumCost,
        );
    }

    public static function billableQuantities(ModelIdentity $pricingIdentity, Usage $quantities): self
    {
        return new self(
            kind: SpendingBoundKind::BillableQuantities,
            pricingIdentity: $pricingIdentity,
            quantities: $quantities,
        );
    }

    public static function unknown(): self
    {
        return new self(SpendingBoundKind::Unknown);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind->value,
            'maximum_cost' => $this->maximumCost?->toArray(),
            'pricing_identity' => $this->pricingIdentity?->toArray(),
            'quantities' => $this->quantities?->toArray(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
