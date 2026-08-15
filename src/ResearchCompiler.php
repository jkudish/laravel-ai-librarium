<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Execution\ProfileCompatibility;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

final readonly class ResearchCompiler
{
    public function __construct(
        private ProfileCatalog $catalog,
        private ConfigRepository $config,
        private ProfileCompatibility $compatibility,
    ) {}

    public function compile(PendingResearch $research): ResearchPlan
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

        $all = $profiles->concat($fallbacks);
        [$estimatedCost, $currency, $complete, $warnings] = $this->estimate($all, $research->maximumCost());
        $prompts = $all->mapWithKeys(fn (Profile $profile): array => [
            $profile->id => $profile->renderPrompt($research->input),
        ]);

        $configuredTimeout = $this->config->get('librarium.timeout', 6900);
        $timeout = $research->timeoutSeconds() ?? (is_int($configuredTimeout) ? $configuredTimeout : 6900);
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

        return new ResearchPlan(
            requestId: $research->requestId,
            preview: new ResearchPreview(
                input: $research->input,
                profiles: $profiles,
                fallbacks: $fallbacks,
                prompts: $prompts,
                unavailable: collect($unavailable),
                warnings: collect($warnings),
                maximumEstimatedCost: $estimatedCost,
                currency: $currency,
                costEstimateComplete: $complete,
            ),
            timeout: $timeout,
            jobTimeout: $jobTimeout,
        );
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
     * @param  array{amount: string, currency: string}|null  $maximum
     * @return array{string|null, string|null, bool, list<string>}
     */
    private function estimate(Collection $profiles, ?array $maximum): array
    {
        $complete = $profiles->every(fn (Profile $profile): bool => $profile->estimatedCostMicros !== null && $profile->currency !== null);
        $currencies = $profiles
            ->map(fn (Profile $profile): ?string => $profile->currency)
            ->filter(fn (?string $currency): bool => $currency !== null)
            ->unique()
            ->values();
        $currency = $currencies->count() === 1 ? $currencies->first() : null;
        $singleCurrency = is_string($currency) ? $currency : null;
        $warnings = [];

        if (! $complete) {
            $warnings[] = 'The cost estimate is incomplete because one or more Profiles have no network-free estimate.';
        }

        if ($currencies->count() > 1) {
            $warnings[] = 'The cost estimate spans multiple currencies and cannot be totaled.';
        }

        $totalMicros = $complete && $singleCurrency !== null
            ? $profiles->sum(fn (Profile $profile): int => $profile->estimatedCostMicros ?? 0)
            : null;

        if ($maximum !== null) {
            if (! $complete || $singleCurrency === null) {
                throw ValidationException::withMessages([
                    'maxCost' => 'A hard cost ceiling requires a complete network-free estimate in one currency.',
                ]);
            }

            if ($singleCurrency !== $maximum['currency']) {
                throw ValidationException::withMessages([
                    'maxCost.currency' => "The estimate uses {$singleCurrency}, not {$maximum['currency']}.",
                ]);
            }

            if ($totalMicros !== null && $totalMicros > $this->micros($maximum['amount'])) {
                throw ValidationException::withMessages([
                    'maxCost' => 'The maximum estimated cost exceeds the configured hard ceiling.',
                ]);
            }
        }

        return [
            $totalMicros === null ? null : $this->formatMicros($totalMicros),
            $singleCurrency,
            $complete && $singleCurrency !== null,
            $warnings,
        ];
    }

    private function micros(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 1_000_000) + (int) str_pad(substr($fraction, 0, 6), 6, '0');
    }

    private function formatMicros(int $micros): string
    {
        $amount = intdiv($micros, 1_000_000).'.'.str_pad((string) ($micros % 1_000_000), 6, '0', STR_PAD_LEFT);

        return rtrim(rtrim($amount, '0'), '.');
    }
}
