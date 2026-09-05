<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\ResearchCompiler;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeProfileDriver;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\CostCalculator;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\PriceDefinition;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\LaravelAiPricing\ValueObjects\PricingProvenance;
use Jkudish\LaravelAiPricing\ValueObjects\Rate;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-03T12:00:00Z');
    config()->set('librarium.profiles', []);
    config()->set('ai-pricing.prices', []);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('aggregates every primary and every reachable Profile in the global fallback reserve', function (): void {
    config()->set('librarium.profiles', [
        'primary-a' => pricingProfileConfig('provider-a'),
        'primary-b' => pricingProfileConfig('provider-b'),
        'fallback' => pricingProfileConfig('provider-fallback'),
    ]);
    configurePricingRate('provider-a:test-model', ['requests' => '0.01']);
    configurePricingRate('provider-b:test-model', ['requests' => '0.02']);
    configurePricingRate('provider-fallback:test-model', ['requests' => '0.03']);

    $preview = Librarium::query('Research this.')
        ->fanOut(['primary-a', 'primary-b'])
        ->fallbacks(['fallback'])
        ->preview();
    $fallback = $preview->pricingQuotes->last();

    expect($preview->pricingQuotes)->toHaveCount(3)
        ->and($preview->pricingQuotes->pluck('quote.completeness')->all())->toBe([
            CostCompleteness::Complete,
            CostCompleteness::Complete,
            CostCompleteness::Complete,
        ])
        ->and($fallback['role'])->toBe('fallback')
        ->and($fallback['reachable_from'])->toBe(['primary-a', 'primary-b'])
        ->and($fallback['maximum_invocations'])->toBe(1)
        ->and($preview->knownCostSubtotals->all())->toBe(['USD' => '0.06'])
        ->and($preview->maximumEstimatedCost)->toBe('0.06')
        ->and($preview->hardCostCeilingAvailable)->toBeTrue();

    Http::assertNothingSent();
});

it('aggregates known cost while preserving partial and unavailable quotes', function (): void {
    config()->set('librarium.profiles', [
        'complete' => pricingProfileConfig('complete-provider'),
        'partial' => pricingProfileConfig('partial-provider', ['requests' => 1, 'unknown_units' => 2]),
        'unavailable' => pricingProfileConfig('unavailable-provider'),
    ]);
    configurePricingRate('complete-provider:test-model', ['requests' => '0.01']);
    configurePricingRate('partial-provider:test-model', ['requests' => '0.02']);

    $preview = Librarium::query('Research this.')
        ->fanOut(['complete', 'partial', 'unavailable'])
        ->preview();

    expect($preview->pricingQuotes->pluck('quote.completeness')->all())->toBe([
        CostCompleteness::Complete,
        CostCompleteness::Partial,
        CostCompleteness::Unavailable,
    ])->and($preview->pricingQuotes[1]['quote']->missingUnits)->toBe(['unknown_units'])
        ->and($preview->knownCostSubtotals->all())->toBe(['USD' => '0.03'])
        ->and($preview->pricingStatus)->toBe('partial')
        ->and($preview->hardCostCeilingAvailable)->toBeFalse()
        ->and($preview->maximumEstimatedCost)->toBeNull()
        ->and($preview->currency)->toBeNull()
        ->and($preview->costEstimateComplete)->toBeFalse();

    Http::assertNothingSent();
});

it('emits a stable agent pricing schema for partial and unavailable quotes without dispatch', function (): void {
    config()->set('librarium.profiles', [
        'partial' => pricingProfileConfig('partial-provider', ['requests' => 1, 'unknown_units' => 2]),
        'unavailable' => pricingProfileConfig('unavailable-provider'),
    ]);
    configurePricingRate('partial-provider:test-model', ['requests' => '0.02']);
    $fake = Librarium::fake()->preventStrayQueries();

    $exit = Artisan::call('librarium:query', [
        'query' => 'Preview uncertainty.',
        '--agent' => true,
        '--profile' => ['partial', 'unavailable'],
    ]);
    $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output['pricing']['schema_version'])->toBe(1)
        ->and($output['pricing']['status'])->toBe('partial')
        ->and($output['pricing']['known_subtotals'])->toBe([['amount' => '0.02', 'currency' => 'USD']])
        ->and($output['pricing']['hard_ceiling_available'])->toBeFalse()
        ->and($output['pricing']['quotes'][0]['quote'])->toMatchArray([
            'completeness' => 'partial',
            'cost' => ['amount' => '0.02', 'currency' => 'USD'],
            'source' => 'configured',
            'missing_units' => ['unknown_units'],
        ])
        ->and($output['pricing']['quotes'][0]['freshness'])->toBe(['status' => 'current', 'retrieved_at' => null])
        ->and($output['pricing']['quotes'][1]['quote'])->toMatchArray([
            'completeness' => 'unavailable',
            'cost' => null,
            'source' => 'unavailable',
            'missing_units' => [],
        ])
        ->and($output['pricing']['quotes'][1]['freshness'])->toBe(['status' => 'unverified', 'retrieved_at' => null]);

    Http::assertNothingSent();
    $fake->assertNeverQueried()->assertNeverQueued();
});

it('rejects incomplete quotes as a hard ceiling before dispatch', function (): void {
    config()->set('librarium.profiles.incomplete', pricingProfileConfig(
        provider: 'incomplete-provider',
        usage: ['requests' => 1, 'unknown_units' => 2],
    ));
    configurePricingRate('incomplete-provider:test-model', ['requests' => '0.02']);
    $fake = Librarium::fake()->preventStrayQueries();

    expect(fn () => Librarium::query('Research this.')
        ->using('incomplete')
        ->maxCost('1.00')
        ->run())
        ->toThrow(ValidationException::class, 'complete, current, single-currency');

    Http::assertNothingSent();
    $fake->assertNeverQueried()->assertNeverQueued();
});

it('reports package source provenance and current freshness without HTTP', function (): void {
    config()->set('librarium.profiles.package-you', pricingProfileConfig(
        provider: 'you',
        pricingModel: 'answer',
    ));

    $preview = Librarium::query('What changed?')->using('package-you')->preview();
    $entry = $preview->pricingQuotes->sole();

    expect($entry['quote']->source)->toBe(PricingSource::ProviderNative)
        ->and($entry['quote']->provenance?->reference)->toBe('https://you.com/pricing')
        ->and($entry['quote']->provenance?->retrievedAt?->format('Y-m-d'))->toBe('2026-09-03')
        ->and($entry['freshness'])->toBe('current')
        ->and($preview->hardCostCeilingAvailable)->toBeTrue();

    Http::assertNothingSent();
});

it('keeps catalog synchronization explicit while preview resolution stays offline', function (): void {
    config()->set('librarium.profiles.openrouter-missing', pricingProfileConfig(
        provider: 'openrouter',
        pricingModel: 'not-in-the-reviewed-snapshot',
    ));
    $fake = Librarium::fake()->preventStrayQueries();

    $preview = Librarium::query('What changed?')->using('openrouter-missing')->preview();

    expect(config('ai-pricing.offline'))->toBeFalse()
        ->and(Artisan::all())->toHaveKey('ai:pricing:sync')
        ->and($preview->pricingStatus)->toBe('unavailable')
        ->and($preview->pricingQuotes->sole()['quote']->completeness)->toBe(CostCompleteness::Unavailable);

    Http::assertNothingSent();
    $fake->assertNeverQueried()->assertNeverQueued();
});

it('rejects a stale quote as a hard ceiling before dispatch', function (): void {
    config()->set('librarium.profiles.stale', pricingProfileConfig('stale-provider'));
    bindPricingResolver(function (PricingObservation $observation): CostQuote {
        $definition = new PriceDefinition(
            identity: $observation->identity,
            rates: ['requests' => new Rate('requests', '0.01')],
            source: PricingSource::ProviderNative,
            retrievedAt: CarbonImmutable::parse('2026-01-01T00:00:00Z'),
            sourceReference: 'https://pricing.example.test/catalog',
        );

        return (new CostCalculator)->calculate($observation->usage, $definition);
    });
    $fake = Librarium::fake()->preventStrayQueries();

    $preview = Librarium::query('Research this.')->using('stale')->preview();
    expect($preview->pricingQuotes->sole()['freshness'])->toBe('stale')
        ->and($preview->hardCostCeilingAvailable)->toBeFalse();

    expect(fn () => Librarium::query('Research this.')
        ->using('stale')
        ->maxCost('1.00')
        ->run())
        ->toThrow(ValidationException::class, 'complete, current, single-currency');

    Http::assertNothingSent();
    $fake->assertNeverQueried()->assertNeverQueued();
});

it('rejects mixed-currency quotes as a hard ceiling before dispatch', function (): void {
    config()->set('librarium.profiles', [
        'usd' => pricingProfileConfig('usd-provider'),
        'cad' => pricingProfileConfig('cad-provider'),
    ]);
    bindPricingResolver(fn (PricingObservation $observation): CostQuote => completeQuote(
        amount: '0.01',
        currency: $observation->identity->provider === 'usd-provider' ? 'USD' : 'CAD',
    ));
    $fake = Librarium::fake()->preventStrayQueries();

    $preview = Librarium::query('Research this.')->fanOut(['usd', 'cad'])->preview();
    expect($preview->knownCostSubtotals->all())->toBe(['CAD' => '0.01', 'USD' => '0.01'])
        ->and($preview->hardCostCeilingAvailable)->toBeFalse();

    expect(fn () => Librarium::query('Research this.')
        ->fanOut(['usd', 'cad'])
        ->maxCost('1.00')
        ->run())
        ->toThrow(ValidationException::class, 'complete, current, single-currency');

    Http::assertNothingSent();
    $fake->assertNeverQueried()->assertNeverQueued();
});

it('rejects non-conservative usage as a hard ceiling before dispatch', function (): void {
    config()->set('librarium.profiles.unbounded', pricingProfileConfig('unbounded-provider', conservative: false));
    configurePricingRate('unbounded-provider:test-model', ['requests' => '0.01']);
    $fake = Librarium::fake()->preventStrayQueries();

    $preview = Librarium::query('Research this.')->using('unbounded')->preview();
    expect($preview->pricingStatus)->toBe('complete')
        ->and($preview->knownCostSubtotals->all())->toBe(['USD' => '0.01'])
        ->and($preview->hardCostCeilingAvailable)->toBeFalse();

    expect(fn () => Librarium::query('Research this.')
        ->using('unbounded')
        ->maxCost('1.00')
        ->run())
        ->toThrow(ValidationException::class, 'conservative usage bounds');

    Http::assertNothingSent();
    $fake->assertNeverQueried()->assertNeverQueued();
});

it('summarizes many pricing uncertainties once in human output without dispatch', function (): void {
    config()->set('librarium.profiles', [
        'unknown-a' => pricingProfileConfig('unknown-a-provider'),
        'unknown-b' => pricingProfileConfig('unknown-b-provider'),
        'unknown-c' => pricingProfileConfig('unknown-c-provider'),
    ]);
    $fake = Librarium::fake()->preventStrayQueries();

    $exit = Artisan::call('librarium:query', [
        'query' => 'Preview uncertainty.',
        '--no-interaction' => true,
        '--profile' => ['unknown-a', 'unknown-b', 'unknown-c'],
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(substr_count($output, 'Pricing unavailable:'))->toBe(1)
        ->and($output)->toContain('incomplete pricing for unknown-a, unknown-b, unknown-c.')
        ->and($output)->toContain('No safe ceiling available.')
        ->and($output)->not->toContain('Sources:')
        ->and($output)->toContain('No research was dispatched.');

    Http::assertNothingSent();
    $fake->assertNeverQueried()->assertNeverQueued();
});

it('keeps provider-reported actual cost ahead of configured estimates', function (): void {
    $observation = new PricingObservation(
        identity: new ModelIdentity('actual-provider', 'test-model'),
        usage: new Usage(['requests' => 1]),
        providerReportedCost: new Money('0.42', 'USD'),
    );

    $quote = app(CostResolver::class)->resolve($observation);

    expect($quote->source)->toBe(PricingSource::ProviderReported)
        ->and($quote->cost?->toArray())->toBe(['amount' => '0.42', 'currency' => 'USD']);

    Http::assertNothingSent();
});

/**
 * @param  array<string, int|string>  $usage
 * @return array<string, mixed>
 */
function pricingProfileConfig(
    string $provider,
    array $usage = ['requests' => 1],
    bool $conservative = true,
    string $pricingModel = 'test-model',
): array {
    return [
        'driver' => FakeProfileDriver::class,
        'provider' => $provider,
        'model' => 'test-model',
        'result_kind' => ResultKind::GroundedAnswer->value,
        'grounding' => GroundingPolicy::Required->value,
        'observation' => ObservationMode::ApiOutput->value,
        'corpora' => [Corpus::Web->value],
        'retrieval_methods' => [RetrievalMethod::ModelSearchTool->value],
        'prompt' => 'Research carefully: {{ query }}',
        'enabled' => true,
        'options' => ['temperature' => 0.2],
        'pricing' => [
            'provider' => $provider,
            'model' => $pricingModel,
            'usage' => $usage,
            'conservative' => $conservative,
        ],
    ];
}

/** @param array<string, string> $rates */
function configurePricingRate(string $identity, array $rates): void
{
    $configured = [];
    foreach ($rates as $unit => $amount) {
        $configured[$unit] = ['amount' => $amount, 'currency' => 'USD'];
    }

    config()->set("ai-pricing.prices.{$identity}", $configured);
}

/** @param Closure(PricingObservation): CostQuote $resolve */
function bindPricingResolver(Closure $resolve): void
{
    $resolver = new class($resolve) implements CostResolver
    {
        /** @param Closure(PricingObservation): CostQuote $resolve */
        public function __construct(private readonly Closure $resolve) {}

        public function resolve(PricingObservation $observation): CostQuote
        {
            return ($this->resolve)($observation);
        }
    };

    app()->when(ResearchCompiler::class)
        ->needs(CostResolver::class)
        ->give(fn (): CostResolver => $resolver);
}

function completeQuote(string $amount, string $currency): CostQuote
{
    return new CostQuote(
        cost: new Money($amount, $currency),
        completeness: CostCompleteness::Complete,
        source: PricingSource::Configured,
        provenance: new PricingProvenance(PricingSource::Configured),
    );
}
