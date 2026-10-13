<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\CostCalculator;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Sources\PackagePricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('prices dry-run admission from now through the run deadline', function (): void {
    CarbonImmutable::setTestNow('2026-12-31T23:30:00Z');
    config(['librarium.timeout' => 3600]);
    $observations = [];
    $resolver = new class($observations) implements CostResolver
    {
        /** @param list<PricingObservation> $observations */
        public function __construct(public array &$observations) {}

        public function resolve(PricingObservation $observation): CostQuote
        {
            $this->observations[] = $observation;

            return CostQuote::unavailable();
        }
    };
    app()->when(LibrariumManager::class)->needs(CostResolver::class)->give(fn (): CostResolver => $resolver);

    $run = Librarium::research('Compare search results.')->only(['exa', 'tavily'])->dryRun();

    expect($observations)->toHaveCount(2);
    foreach ($observations as $observation) {
        expect($observation->pricedAt?->format(DATE_ATOM))->toBe('2026-12-31T23:30:00+00:00')
            ->and($observation->pricedUntil?->format(DATE_ATOM))->toBe('2027-01-01T00:30:00+00:00');
    }
    expect($run->toArray()['cost']['estimated'])->toBeNull();
    Http::assertNothingSent();
});

it('prices the retained Gemini usage basis at the highest rate through its deadline', function (string $now, int $timeout, string $expected): void {
    $profile = app(ProfileCatalog::class)->find('gemini-chat')->profile;
    $quote = app(CostResolver::class)->resolve(new PricingObservation(
        $profile->pricingIdentity, $profile->pricingUsage,
        pricedAt: CarbonImmutable::parse($now), pricedUntil: CarbonImmutable::parse($now)->addSeconds($timeout),
    ));

    // 100k input, 100k output, 100 searches: 1.85 in 2026, 2.3 in 2027.
    expect($quote->completeness)->toBe(CostCompleteness::Complete)
        ->and($quote->cost?->amount->isEqualTo($expected))->toBeTrue();
    Http::assertNothingSent();
})->with([
    'before change' => ['2026-12-31T22:00:00Z', 3600, '1.85'],
    'exact boundary' => ['2026-12-31T23:00:00Z', 3600, '1.85'],
    'across boundary' => ['2026-12-31T23:00:00Z', 3601, '2.3'],
    'after change' => ['2027-01-02T00:00:00Z', 60, '2.3'],
]);

it('binds an exact driver bound to a pricing interval without changing its serialized form', function (): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => false, 'max_output_tokens' => 64_000, 'max_steps' => 1, 'max_prompt_bytes' => 4096,
    ]);
    $bound = variantBound('claude-chat', 'Bound this direct answer.')->pricedBetween(
        CarbonImmutable::parse('2026-10-11T12:00:00Z'), CarbonImmutable::parse('2026-10-11T12:10:00Z'),
    );
    $observation = $bound->pricingObservation();

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($observation?->identity->key())->toBe('anthropic:claude-sonnet-5-5-global-standard')
        ->and($observation?->usage->toArray())->toBe(['input_tokens' => '1000000', 'output_tokens' => '64000'])
        ->and($observation?->pricedAt)->toEqual($bound->pricedAt)
        ->and($observation?->pricedUntil)->toEqual($bound->pricedUntil)
        ->and(array_keys($bound->toArray()))->toBe(['kind', 'pricing_identity', 'quantities']);
    Http::assertNothingSent();
});

it('prices a windowed spending bound at the highest rate before its deadline', function (string $until, string $expected): void {
    $bound = SpendingBound::billableQuantities(
        new ModelIdentity('gemini', 'gemini-3.8-flash-standard'),
        new Usage(['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000]),
    )->pricedBetween(CarbonImmutable::parse('2026-12-31T22:00:00Z'), CarbonImmutable::parse($until));
    $observation = $bound->pricingObservation();
    $definition = app(PackagePricingSource::class)->find($observation->identity);
    $quote = (new CostCalculator)->calculate($observation->usage, $definition, $observation->pricedAt, $observation->pricedUntil);

    expect($quote->cost?->amount->isEqualTo($expected))->toBeTrue();
})->with([
    '2026 deadline' => ['2026-12-31T23:59:59Z', '4.5'],
    '2027 deadline' => ['2027-01-01T00:00:01Z', '9'],
]);

it('has no pricing observation for an unknown bound', function (): void {
    expect(SpendingBound::unknown()->pricingObservation())->toBeNull();
});
