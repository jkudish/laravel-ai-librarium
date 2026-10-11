<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

final class ResearchPreview
{
    public readonly bool $preflightCostCheckAvailable;

    /**
     * @deprecated Use $preflightCostCheckAvailable. This compatibility alias
     *             does not indicate a provider-enforced spending ceiling.
     */
    public readonly bool $hardCostCeilingAvailable;

    /**
     * @param  Collection<int, Profile>  $profiles
     * @param  Collection<int, Profile>  $fallbacks
     * @param  Collection<string, string>  $prompts
     * @param  Collection<string, list<string>>  $unavailable
     * @param  Collection<int, string>  $warnings
     * @param  Collection<int, array{
     *     profile: string,
     *     role: string,
     *     reachable_from: list<string>,
     *     maximum_invocations: int,
     *     identity: ModelIdentity|null,
     *     usage: Usage|null,
     *     conservative: bool,
     *     quote: CostQuote,
     *     freshness: string
     * }>  $pricingQuotes
     * @param  Collection<string, covariant (non-empty-string|numeric-string)>  $knownCostSubtotals
     */
    public function __construct(
        public readonly string $input,
        public readonly Collection $profiles,
        public readonly Collection $fallbacks,
        public readonly Collection $prompts,
        public readonly Collection $unavailable,
        public readonly Collection $warnings,
        public readonly Collection $pricingQuotes,
        public readonly Collection $knownCostSubtotals,
        public readonly string $pricingStatus,
        bool $hardCostCeilingAvailable,
        public readonly ?string $maximumEstimatedCost,
        public readonly ?string $currency,
        public readonly bool $costEstimateComplete,
        /** When the quotes were priced: compile (admission) time. Null when pricing was not resolved. */
        public readonly ?CarbonImmutable $pricedAt = null,
        /** The run deadline the quotes cover; dated rate windows price at their highest rate up to it. */
        public readonly ?CarbonImmutable $pricedUntil = null,
    ) {
        $this->preflightCostCheckAvailable = $hardCostCeilingAvailable;
        $this->hardCostCeilingAvailable = $hardCostCeilingAvailable;
    }
}
