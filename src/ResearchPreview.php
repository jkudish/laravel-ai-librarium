<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Support\Collection;

final class ResearchPreview
{
    /**
     * @param  Collection<int, Profile>  $profiles
     * @param  Collection<int, Profile>  $fallbacks
     * @param  Collection<string, string>  $prompts
     * @param  Collection<string, list<string>>  $unavailable
     * @param  Collection<int, string>  $warnings
     */
    public function __construct(
        public readonly string $input,
        public readonly Collection $profiles,
        public readonly Collection $fallbacks,
        public readonly Collection $prompts,
        public readonly Collection $unavailable,
        public readonly Collection $warnings,
        public readonly ?string $maximumEstimatedCost,
        public readonly ?string $currency,
        public readonly bool $costEstimateComplete,
    ) {}
}
