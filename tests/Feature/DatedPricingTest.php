<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\ResearchCompiler;
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

it('prices every preview quote from admission until the run deadline', function (): void {
    CarbonImmutable::setTestNow('2026-12-31T23:30:00Z');
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
    app()->when(ResearchCompiler::class)->needs(CostResolver::class)->give(fn (): CostResolver => $resolver);

    $preview = Librarium::query('Compare grounded answers.')
        ->fanOut(['gemini-chat', 'claude-chat'])
        ->timeout(3600)
        ->preview();

    expect($observations)->toHaveCount(2);
    foreach ($observations as $observation) {
        expect($observation->pricedAt?->format(DATE_ATOM))->toBe('2026-12-31T23:30:00+00:00')
            ->and($observation->pricedUntil?->format(DATE_ATOM))->toBe('2027-01-01T00:30:00+00:00');
    }
    expect($preview->pricedAt?->format(DATE_ATOM))->toBe('2026-12-31T23:30:00+00:00')
        ->and($preview->pricedUntil?->format(DATE_ATOM))->toBe('2027-01-01T00:30:00+00:00');
});

it('quotes Gemini Flash at the higher 2027 rate only when the run deadline reaches it', function (string $now, int $timeout, string $expected): void {
    CarbonImmutable::setTestNow($now);

    $entry = Librarium::query('What changed?')->using('gemini-chat')->timeout($timeout)->preview()->pricingQuotes->sole();

    // 100,000 input, 100,000 output and 100 searches. Through 2026:
    // 0.075 + 0.375 + 1.4 = 1.85. From 2027-01-01T00:00Z: 0.15 + 0.75 + 1.4 = 2.3.
    expect($entry['identity']?->key())->toBe('gemini:gemini-3.8-flash-standard')
        ->and($entry['quote']->completeness)->toBe(CostCompleteness::Complete)
        ->and($entry['quote']->cost?->amount->isEqualTo($expected))->toBeTrue();
    Http::assertNothingSent();
})->with([
    'deadline before the change' => ['2026-12-31T22:00:00Z', 3600, '1.85'],
    'deadline exactly at the change' => ['2026-12-31T23:00:00Z', 3600, '1.85'],
    'deadline crosses the change' => ['2026-12-31T23:00:00Z', 3601, '2.3'],
    'admitted after the change' => ['2027-01-02T00:00:00Z', 60, '2.3'],
]);

it('reports the pricing interval and the windows that priced a quote in agent JSON', function (): void {
    CarbonImmutable::setTestNow('2026-12-31T23:00:00Z');
    $fake = Librarium::fake()->preventStrayQueries();

    Artisan::call('librarium:query', [
        'query' => 'What changed?',
        '--agent' => true,
        '--profile' => ['gemini-chat'],
        '--timeout' => '7000',
    ]);
    $pricing = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['pricing'];

    expect($pricing['priced_at'])->toBe('2026-12-31T23:00:00+00:00')
        ->and($pricing['priced_until'])->toBe('2027-01-01T00:56:40+00:00')
        ->and(BigDecimal::of($pricing['quotes'][0]['quote']['cost']['amount'])->isEqualTo('2.3'))->toBeTrue()
        ->and($pricing['quotes'][0]['quote']['provenance']['rate_windows'])->toBe([
            ['effective_from' => null, 'effective_until' => '2027-01-01T00:00:00+00:00'],
            ['effective_from' => '2027-01-01T00:00:00+00:00', 'effective_until' => null],
        ]);
    $fake->assertNeverQueried()->assertNeverQueued();
});

it('binds an exact spending bound to its admission time and deadline without changing its serialized form', function (): void {
    CarbonImmutable::setTestNow('2026-10-11T12:00:00Z');
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => false,
        'max_output_tokens' => 64_000,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    $bound = Librarium::query('Bound this direct Claude answer.')->using('claude-chat')->timeout(600)->spendingBound();
    $observation = $bound->pricingObservation();

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($bound->pricedAt?->format(DATE_ATOM))->toBe('2026-10-11T12:00:00+00:00')
        ->and($bound->pricedUntil?->format(DATE_ATOM))->toBe('2026-10-11T12:10:00+00:00')
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

    // 1M input and 1M output: 0.75 + 3.75 = 4.5 through 2026, 1.50 + 7.50 = 9 from 2027.
    $quote = (new CostCalculator)->calculate($observation->usage, $definition, $observation->pricedAt, $observation->pricedUntil);

    expect($quote->cost?->amount->isEqualTo($expected))->toBeTrue();
})->with([
    'deadline in 2026' => ['2026-12-31T23:59:59Z', '4.5'],
    'deadline in 2027' => ['2027-01-01T00:00:01Z', '9'],
]);

it('has no pricing observation for an unknown or native-ceiling bound', function (): void {
    expect(SpendingBound::unknown()->pricingObservation())->toBeNull();
});

it('still reports pricing configuration errors before timeout and TTL errors', function (): void {
    config()->set('librarium.pricing.max_age_days', 0);
    config()->set('librarium.status_ttl', 1);

    try {
        Librarium::query('What changed?')->using('gemini-chat')->preview();
        $this->fail('The preview should not compile.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['pricing.max_age_days']);
    }
});
