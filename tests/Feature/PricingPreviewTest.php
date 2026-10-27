<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeProfileDriver;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\LaravelAiPricing\ValueObjects\PricingProvenance;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10T12:00:00Z');
    config(['ai-pricing.prices' => []]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('admits affordable sources greedily in catalog order rather than request order', function (): void {
    foreach (['first' => '0.04', 'second' => '0.08', 'third' => '0.02'] as $source => $price) {
        config(['librarium.sources.'.$source => pricingSourceConfig($source),
            'ai-pricing.prices.'.$source.':test-model' => ['requests' => ['amount' => $price, 'currency' => 'USD']],
        ]);
    }
    $plan = Librarium::research('Research')->only(['third', 'second', 'first'])->maxCost('0.06')->dryRun()->toArray();
    expect(array_column($plan['results'], 'source'))->toBe(['first', 'third'])
        ->and($plan['cost']['estimated'])->toBe('0.06')
        ->and($plan['skipped'])->toBe([['source' => 'second', 'reason' => 'over_budget']]);
    Http::assertNothingSent();
});

it('does not admit partial, stale, unverified, foreign-currency or nonconservative estimates', function (string $case): void {
    config(['librarium.sources.probe' => pricingSourceConfig('probe', $case !== 'nonconservative')]);
    bindRunPricing(fn (): CostQuote => new CostQuote(
        cost: new Money('0.02', $case === 'currency' ? 'CAD' : 'USD'),
        completeness: $case === 'partial' ? CostCompleteness::Partial : CostCompleteness::Complete,
        source: $case === 'stale' || $case === 'unverified' ? PricingSource::ProviderNative : PricingSource::Configured,
        provenance: new PricingProvenance(PricingSource::ProviderNative, retrievedAt: $case === 'stale' ? CarbonImmutable::parse('2026-01-01') : null),
    ));
    $plan = Librarium::research('Research')->only(['probe'])->maxCost('1')->dryRun()->toArray();
    expect($plan['results'])->toBe([])
        ->and($plan['skipped'])->toBe([['source' => 'probe', 'reason' => 'unpriced']]);
    $uncapped = Librarium::research('Research')->only(['probe'])->dryRun()->toArray();
    expect(array_column($uncapped['results'], 'source'))->toBe(['probe'])
        ->and($uncapped['cost']['estimated'])->toBeNull();
    Http::assertNothingSent();
})->with(['partial', 'stale', 'unverified', 'currency', 'nonconservative']);

it('preserves unknown aggregate estimates instead of presenting a partial subtotal as the total', function (): void {
    config(['librarium.sources.known' => pricingSourceConfig('known'), 'librarium.sources.unknown' => pricingSourceConfig('unknown')]);
    config(['ai-pricing.prices.known:test-model' => ['requests' => ['amount' => '0.03', 'currency' => 'USD']]]);
    $plan = Librarium::research('Research')->only(['known', 'unknown'])->dryRun()->toArray();
    expect($plan['cost']['estimated'])->toBeNull()
        ->and($plan['results'][0]['cost']['estimated'])->toBe('0.03')
        ->and($plan['results'][1]['cost']['estimated'])->toBeNull();
    Http::assertNothingSent();
});

it('keeps catalog synchronization explicit even when the host permits online pricing', function (): void {
    config(['librarium.sources.probe' => pricingSourceConfig('openrouter')]);
    $plan = Librarium::research('Research')->only(['probe'])->dryRun();
    expect(config('ai-pricing.offline'))->toBeFalse()
        ->and(Artisan::all())->toHaveKey('ai:pricing:sync')
        ->and($plan->toArray()['cost']['estimated'])->toBeNull();
    Http::assertNothingSent();
});

it('keeps provider-reported actual cost ahead of configured estimates', function (): void {
    $quote = app(CostResolver::class)->resolve(new PricingObservation(
        new ModelIdentity('actual-provider', 'test-model'), new Usage(['requests' => 1]),
        providerReportedCost: new Money('0.42', 'USD'),
    ));
    expect($quote->source)->toBe(PricingSource::ProviderReported)
        ->and($quote->cost?->toArray())->toBe(['amount' => '0.42', 'currency' => 'USD']);
    Http::assertNothingSent();
});

/** @return array<string, mixed> */
function pricingSourceConfig(string $provider, bool $conservative = true): array
{
    return ['key' => 'placeholder', 'quick' => [
        'driver' => FakeProfileDriver::class, 'provider' => $provider, 'model' => 'test-model',
        'result_kind' => 'grounded_answer', 'grounding' => 'required', 'observation' => 'api_output',
        'corpora' => ['web'], 'retrieval_methods' => ['model_search_tool'], 'prompt' => '{{ query }}',
        'pricing' => ['provider' => $provider, 'model' => 'test-model', 'usage' => ['requests' => 1], 'conservative' => $conservative],
    ]];
}

/** @param Closure(PricingObservation): CostQuote $resolve */
function bindRunPricing(Closure $resolve): void
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
    app()->when(LibrariumManager::class)->needs(CostResolver::class)->give(fn (): CostResolver => $resolver);
}
