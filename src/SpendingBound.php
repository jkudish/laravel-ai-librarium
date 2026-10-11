<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class SpendingBound implements Arrayable, JsonSerializable
{
    /**
     * @param  DateTimeImmutable|null  $pricedAt  When the bound was computed (admission time).
     * @param  DateTimeImmutable|null  $pricedUntil  The deadline of the exact request the bound covers.
     */
    private function __construct(
        public SpendingBoundKind $kind,
        public ?Money $maximumCost = null,
        public ?ModelIdentity $pricingIdentity = null,
        public ?Usage $quantities = null,
        public ?DateTimeImmutable $pricedAt = null,
        public ?DateTimeImmutable $pricedUntil = null,
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

    /** A copy bound to the interval the exact request can bill in: admission until its deadline. */
    public function pricedBetween(DateTimeImmutable $pricedAt, DateTimeImmutable $pricedUntil): self
    {
        return new self($this->kind, $this->maximumCost, $this->pricingIdentity, $this->quantities, $pricedAt, $pricedUntil);
    }

    /**
     * The maximum-cost observation for billable quantities, or null for the
     * other kinds. It carries the bound's admission time and deadline, so a
     * price with dated rate windows resolves each unit at its highest rate
     * in effect before the deadline.
     */
    public function pricingObservation(): ?PricingObservation
    {
        if ($this->pricingIdentity === null || $this->quantities === null) {
            return null;
        }

        return new PricingObservation(
            identity: $this->pricingIdentity,
            usage: $this->quantities,
            pricedAt: $this->pricedAt,
            pricedUntil: $this->pricedUntil,
        );
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
