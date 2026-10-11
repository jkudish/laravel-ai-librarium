<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\DeclaresDeadline;
use Jkudish\LaravelAiLibrarium\Execution\ProfileCompatibility;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

final readonly class ResearchCompiler
{
    public function __construct(
        private ProfileCatalog $catalog,
        private ConfigRepository $config,
        private ProfileCompatibility $compatibility,
        private CostResolver $costResolver,
    ) {}

    public function compile(PendingResearch $research): ResearchPlan
    {
        return $this->compileRequest($research, true);
    }

    public function compileSpendingBoundRequest(PendingResearch $research): ResearchPlan
    {
        return $this->compileRequest($research, false);
    }

    private function compileRequest(PendingResearch $research, bool $resolvePricing): ResearchPlan
    {
        $selected = $this->primaries($research);
        $profiles = $selected['profiles'];
        $unavailable = $selected['unavailable'];

        if ($profiles->isEmpty()) {
            throw ValidationException::withMessages([
                'selector' => 'No eligible Profiles matched the research request.',
            ]);
        }

        $fallbacks = $this->explicitProfiles($research->fallbackProfiles(), 'fallbacks');
        $primaryIds = $profiles->map(fn (Profile $profile): string => $profile->id)->all();
        $fallbackIds = $fallbacks->map(fn (Profile $profile): string => $profile->id)->all();
        $duplicates = array_values(array_intersect($primaryIds, $fallbackIds));
        if ($duplicates !== []) {
            throw ValidationException::withMessages([
                'fallbacks' => 'Fallbacks may not repeat primary Profiles: '.implode(', ', $duplicates).'.',
            ]);
        }

        $incompatibleFallbacks = $fallbacks
            ->reject(fn (Profile $fallback): bool => $profiles->contains(
                fn (Profile $primary): bool => $this->compatibility->allows($primary, $fallback),
            ))
            ->map(fn (Profile $fallback): string => $fallback->id)
            ->values()
            ->all();
        if ($incompatibleFallbacks !== []) {
            throw ValidationException::withMessages([
                'fallbacks' => 'Fallback Profiles must be compatible with at least one primary Profile: '.implode(', ', $incompatibleFallbacks).'.',
            ]);
        }

        // Dated rate windows price an estimate at the highest rate in effect
        // between admission and the run's deadline, so a run that crosses a
        // price change is never quoted at the earlier rate alone. Pricing is
        // still checked before the timeout, so an out-of-range timeout only
        // clamps the interval here and fails its own validation below.
        $configuredTimeout = $this->config->get('librarium.timeout', 6900);
        $timeout = $research->timeoutSeconds() ?? (is_int($configuredTimeout) ? $configuredTimeout : 6900);
        $pricedAt = CarbonImmutable::now();
        $pricedUntil = $pricedAt->addSeconds(max(0, $timeout));
        $pricing = $resolvePricing
            ? $this->pricing($profiles, $fallbacks, $research->maximumCost(), $pricedAt, $pricedUntil)
            : null;
        $all = $profiles->concat($fallbacks);
        $prompts = $all->mapWithKeys(fn (Profile $profile): array => [
            $profile->id => $profile->renderPrompt($research->input),
        ]);

        if ($timeout < 1 || $timeout > 86400) {
            throw ValidationException::withMessages(['timeout' => 'The configured timeout must be between 1 and 86400 seconds.']);
        }

        $configuredJobTimeout = $this->config->get('librarium.queue.timeout', 7200);
        $jobTimeout = is_int($configuredJobTimeout) ? $configuredJobTimeout : 7200;
        if ($jobTimeout < 2 || $jobTimeout > 86400) {
            throw ValidationException::withMessages(['queue.timeout' => 'The configured queue timeout must be between 2 and 86400 seconds.']);
        }
        if ($timeout >= $jobTimeout) {
            throw ValidationException::withMessages(['timeout' => "The research timeout must be less than the queue timeout of {$jobTimeout} seconds."]);
        }

        $this->validateTransientTtl('status_ttl', $jobTimeout);
        $this->validateTransientTtl('webhook_signal_ttl', $jobTimeout);

        /** @var Collection<int, string> $warnings */
        $warnings = collect($this->deadlineWarnings($all, $timeout, $jobTimeout, $research->timeoutSeconds() !== null));
        $pricingQuotes = $pricing === null ? collect($this->emptyPricingQuotes()) : $pricing['quotes'];
        $knownCostSubtotals = $pricing === null ? collect($this->emptyKnownCostSubtotals()) : $pricing['known_subtotals'];

        return new ResearchPlan(
            requestId: $research->requestId,
            preview: new ResearchPreview(
                input: $research->input,
                profiles: $profiles,
                fallbacks: $fallbacks,
                prompts: $prompts,
                unavailable: collect($unavailable),
                warnings: $warnings,
                pricingQuotes: $pricingQuotes,
                knownCostSubtotals: $knownCostSubtotals,
                pricingStatus: $pricing['status'] ?? CostCompleteness::Unavailable->value,
                hardCostCeilingAvailable: $pricing['preflight_cost_check_available'] ?? false,
                maximumEstimatedCost: $pricing['maximum_estimated_cost'] ?? null,
                currency: $pricing['currency'] ?? null,
                costEstimateComplete: $pricing['preflight_cost_check_available'] ?? false,
                pricedAt: $pricing === null ? null : $pricedAt,
                pricedUntil: $pricing === null ? null : $pricedUntil,
            ),
            timeout: $timeout,
            jobTimeout: $jobTimeout,
        );
    }

    /**
     * A Driver that would stop polling at the shared timeout before its own
     * deadline abandons a provider task that may still be billed, so the plan
     * names every setting to raise and the value it needs.
     *
     * @param  Collection<int, Profile>  $profiles
     * @return list<string>
     */
    private function deadlineWarnings(Collection $profiles, int $timeout, int $jobTimeout, bool $explicitTimeout): array
    {
        $warnings = [];
        foreach ($profiles as $profile) {
            $deadline = is_a($profile->driver, DeclaresDeadline::class, true)
                ? $profile->driver::deadlineSeconds($profile)
                : null;
            if ($deadline === null || $deadline <= $timeout) {
                continue;
            }

            $settings = [($explicitTimeout ? 'the requested timeout (--timeout or timeout())' : 'librarium.timeout')." to at least {$deadline}"];
            $queueTimeout = max($jobTimeout, $deadline + 1);
            if ($jobTimeout <= $deadline) {
                $settings[] = "librarium.queue.timeout to at least {$queueTimeout}";
            }
            foreach (['status_ttl', 'webhook_signal_ttl'] as $key) {
                $ttl = $this->config->get('librarium.'.$key, 10800);
                if (! is_int($ttl) || $ttl <= $queueTimeout) {
                    $settings[] = "librarium.{$key} to at least ".($queueTimeout + 1);
                }
            }

            $warnings[] = "Profile [{$profile->id}] waits up to {$deadline} seconds for its provider task, but the research timeout is {$timeout} seconds, "
                .'so it would stop waiting while the provider may still finish and bill the task. Raise '.implode(', ', $settings)
                .", or set the Profile's options.request_timeout_seconds to at most {$timeout}.";
        }

        return $warnings;
    }

    private function validateTransientTtl(string $key, int $timeout): void
    {
        $ttl = $this->config->get('librarium.'.$key, 10800);

        if (! is_int($ttl) || $ttl <= $timeout) {
            throw ValidationException::withMessages([
                $key => "The configured {$key} must be an integer greater than the selected timeout of {$timeout} seconds.",
            ]);
        }
    }

    /**
     * @return array<int, array{
     *     profile: string,
     *     role: string,
     *     reachable_from: list<string>,
     *     maximum_invocations: int,
     *     identity: ModelIdentity|null,
     *     usage: Usage|null,
     *     conservative: bool,
     *     quote: CostQuote,
     *     freshness: string
     * }>
     */
    private function emptyPricingQuotes(): array
    {
        return [];
    }

    /** @return array<string, covariant (non-empty-string|numeric-string)> */
    private function emptyKnownCostSubtotals(): array
    {
        return [];
    }

    /**
     * @return array{profiles: Collection<int, Profile>, unavailable: array<string, list<string>>}
     */
    private function primaries(PendingResearch $research): array
    {
        $selector = $research->selector();
        $kind = $selector['kind'];
        $value = $selector['value'];

        if ($kind === null) {
            $default = $this->config->get('librarium.default_group', 'quick');
            if (! is_string($default) || $default === '') {
                throw ValidationException::withMessages(['default_group' => 'The default group must be a non-empty string.']);
            }

            return $this->catalog->group($default);
        }

        if ($kind === 'using' && is_string($value)) {
            return ['profiles' => $this->explicitProfiles([$value], 'using'), 'unavailable' => []];
        }

        if ($kind === 'fanOut' && is_array($value)) {
            /** @var list<string> $value */
            return ['profiles' => $this->explicitProfiles($value, 'fanOut'), 'unavailable' => []];
        }

        if ($kind === 'group' && is_string($value)) {
            return $this->catalog->group($value);
        }

        if ($kind === 'matching' && is_array($value)) {
            /** @var ResultKind|null $resultKind */
            $resultKind = $value['resultKind'] ?? null;
            /** @var GroundingPolicy|null $grounding */
            $grounding = $value['grounding'] ?? null;
            /** @var ObservationMode|null $observation */
            $observation = $value['observation'] ?? null;
            /** @var list<Corpus> $corpora */
            $corpora = $value['corpora'] ?? [];
            /** @var list<RetrievalMethod> $retrievalMethods */
            $retrievalMethods = $value['retrievalMethods'] ?? [];

            return $this->catalog->matching($resultKind, $grounding, $observation, $corpora, $retrievalMethods);
        }

        throw ValidationException::withMessages(['selector' => 'The research selector is invalid.']);
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, Profile>
     */
    private function explicitProfiles(array $ids, string $path): Collection
    {
        $resolved = $this->catalog->resolve($ids);
        if ($resolved['unavailable'] !== []) {
            $messages = collect($resolved['unavailable'])
                ->map(fn (array $reasons, string $profile): string => "{$profile}: ".implode(' ', $reasons))
                ->values()
                ->all();

            throw ValidationException::withMessages([$path => $messages]);
        }

        return $resolved['profiles'];
    }

    /**
     * @param  Collection<int, Profile>  $profiles
     * @param  Collection<int, Profile>  $fallbacks
     * @param  array{amount: string, currency: string}|null  $maximum
     * @return array{
     *     quotes: Collection<int, array{
     *         profile: string,
     *         role: string,
     *         reachable_from: list<string>,
     *         maximum_invocations: int,
     *         identity: ModelIdentity|null,
     *         usage: Usage|null,
     *         conservative: bool,
     *         quote: CostQuote,
     *         freshness: string
     *     }>,
     *     known_subtotals: Collection<string, covariant (non-empty-string|numeric-string)>,
     *     status: string,
     *     preflight_cost_check_available: bool,
     *     maximum_estimated_cost: string|null,
     *     currency: string|null
     * }
     */
    private function pricing(
        Collection $profiles,
        Collection $fallbacks,
        ?array $maximum,
        CarbonImmutable $pricedAt,
        CarbonImmutable $pricedUntil,
    ): array {
        $maxAgeDays = $this->config->get('librarium.pricing.max_age_days', 30);
        if (! is_int($maxAgeDays) || $maxAgeDays < 1 || $maxAgeDays > 3650) {
            throw ValidationException::withMessages([
                'pricing.max_age_days' => 'The pricing max age must be between 1 and 3650 days.',
            ]);
        }

        $cutoff = $pricedAt->subDays($maxAgeDays);
        $quotes = $profiles->map(fn (Profile $profile): array => $this->pricingEntry(
            profile: $profile,
            role: 'primary',
            reachableFrom: [$profile->id],
            cutoff: $cutoff,
            pricedAt: $pricedAt,
            pricedUntil: $pricedUntil,
        ));

        foreach ($fallbacks as $fallback) {
            $reachableFrom = $profiles
                ->filter(fn (Profile $primary): bool => $this->compatibility->allows($primary, $fallback))
                ->map(fn (Profile $primary): string => $primary->id)
                ->values()
                ->all();
            $reachableFrom = array_values($reachableFrom);
            $quotes->push($this->pricingEntry(
                profile: $fallback,
                role: 'fallback',
                reachableFrom: $reachableFrom,
                cutoff: $cutoff,
                pricedAt: $pricedAt,
                pricedUntil: $pricedUntil,
            ));
        }

        /** @var array<string, Money> $subtotals */
        $subtotals = [];
        foreach ($quotes as $entry) {
            $cost = $entry['quote']->cost;
            if ($cost === null) {
                continue;
            }

            $amount = $cost->amount->multipliedBy($entry['maximum_invocations']);
            $line = new Money($amount, $cost->currency);
            $subtotals[$cost->currency] = isset($subtotals[$cost->currency])
                ? $subtotals[$cost->currency]->plus($line)
                : $line;
        }
        ksort($subtotals);

        $knownSubtotals = collect($subtotals)
            ->map(fn (Money $subtotal): string => (string) $subtotal->amount);
        $quotesComplete = $quotes->every(
            fn (array $entry): bool => $entry['quote']->completeness === CostCompleteness::Complete
                && $entry['quote']->cost !== null,
        );
        $fresh = $quotes->every(fn (array $entry): bool => $entry['freshness'] === 'current');
        $conservative = $quotes->every(fn (array $entry): bool => $entry['conservative']);
        $singleCurrency = count($subtotals) === 1;
        $preflightCostCheckAvailable = $quotesComplete && $fresh && $conservative && $singleCurrency;
        $currency = $singleCurrency ? array_key_first($subtotals) : null;
        $status = $quotesComplete
            ? CostCompleteness::Complete->value
            : ($subtotals === [] ? CostCompleteness::Unavailable->value : CostCompleteness::Partial->value);

        if ($maximum !== null) {
            if (! $preflightCostCheckAvailable) {
                throw ValidationException::withMessages([
                    'maxCost' => 'A maximum-cost preflight check requires complete, current, single-currency quotes from configured conservative usage estimates for every primary and reachable fallback.',
                ]);
            }

            if ($currency !== $maximum['currency']) {
                throw ValidationException::withMessages([
                    'maxCost.currency' => "The conservative quote uses {$currency}, not {$maximum['currency']}.",
                ]);
            }

            if ($subtotals[$currency]->amount->isGreaterThan($maximum['amount'])) {
                throw ValidationException::withMessages([
                    'maxCost' => 'The conservative pre-dispatch estimate exceeds the configured maximum cost.',
                ]);
            }
        }

        return [
            'quotes' => $quotes,
            'known_subtotals' => $knownSubtotals,
            'status' => $status,
            'preflight_cost_check_available' => $preflightCostCheckAvailable,
            'maximum_estimated_cost' => $preflightCostCheckAvailable
                ? (string) $subtotals[$currency]->amount
                : null,
            'currency' => $preflightCostCheckAvailable ? $currency : null,
        ];
    }

    /**
     * @param  list<string>  $reachableFrom
     * @return array{
     *     profile: string,
     *     role: string,
     *     reachable_from: list<string>,
     *     maximum_invocations: int,
     *     identity: ModelIdentity|null,
     *     usage: Usage|null,
     *     conservative: bool,
     *     quote: CostQuote,
     *     freshness: string
     * }
     */
    private function pricingEntry(
        Profile $profile,
        string $role,
        array $reachableFrom,
        CarbonImmutable $cutoff,
        CarbonImmutable $pricedAt,
        CarbonImmutable $pricedUntil,
    ): array {
        $quote = $profile->pricingIdentity === null || $profile->pricingUsage === null
            ? CostQuote::unavailable()
            : $this->costResolver->resolve(new PricingObservation(
                identity: $profile->pricingIdentity,
                usage: $profile->pricingUsage,
                pricedAt: $pricedAt,
                pricedUntil: $pricedUntil,
            ));

        return [
            'profile' => $profile->id,
            'role' => $role,
            'reachable_from' => $reachableFrom,
            'maximum_invocations' => 1,
            'identity' => $profile->pricingIdentity,
            'usage' => $profile->pricingUsage,
            'conservative' => $profile->pricingUsageConservative,
            'quote' => $quote,
            'freshness' => $this->freshness($quote, $cutoff),
        ];
    }

    private function freshness(CostQuote $quote, CarbonImmutable $cutoff): string
    {
        if (in_array($quote->source, [PricingSource::Configured, PricingSource::ProviderReported], true)) {
            return 'current';
        }

        $retrievedAt = $quote->provenance?->retrievedAt;
        if ($retrievedAt === null) {
            return 'unverified';
        }

        return $retrievedAt < $cutoff ? 'stale' : 'current';
    }
}
