<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\ResearchCompiler;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;

beforeEach(function (): void {
    foreach (array_keys(config('librarium.profiles')) as $profile) {
        config()->set("librarium.profiles.{$profile}.enabled", true);
        config()->set("librarium.profiles.{$profile}.credential", 'testing-bound-key');
    }

    foreach ([
        'dataforseo-chatgpt',
        'dataforseo-chatgpt-live',
        'dataforseo-gemini',
        'dataforseo-gemini-live',
        'dataforseo-google-ai-mode',
        'dataforseo-google-ai-mode-live',
    ] as $profile) {
        $options = config("librarium.profiles.{$profile}.options");
        $options['location_code'] = 2840;
        $options['language_code'] = 'en';
        config()->set("librarium.profiles.{$profile}.options", $options);
    }
});

it('returns complete fixed billable quantities without consulting pricing or making HTTP requests', function (string $profile, string $identity, array $quantities): void {
    $bound = Librarium::query('An exact request')->using($profile)->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($bound->pricingIdentity?->key())->toBe($identity)
        ->and($bound->quantities?->toArray())->toBe($quantities)
        ->and($bound->maximumCost)->toBeNull()
        ->and($bound->toArray())->toBe([
            'kind' => 'billable_quantities',
            'pricing_identity' => [
                'provider' => str($identity)->before(':')->toString(),
                'model' => str($identity)->after(':')->toString(),
            ],
            'quantities' => $quantities,
        ]);
    Http::assertNothingSent();
})->with([
    'DataForSEO ChatGPT Standard task' => ['dataforseo-chatgpt', 'dataforseo:chatgpt-llm-scraper-standard', ['requests' => '1']],
    'DataForSEO ChatGPT Live task' => ['dataforseo-chatgpt-live', 'dataforseo:chatgpt-llm-scraper-live', ['requests' => '1']],
    'DataForSEO Gemini Standard task' => ['dataforseo-gemini', 'dataforseo:gemini-llm-scraper-standard', ['requests' => '1']],
    'DataForSEO Gemini Live task' => ['dataforseo-gemini-live', 'dataforseo:gemini-llm-scraper-live', ['requests' => '1']],
    'DataForSEO AI Mode Standard task' => ['dataforseo-google-ai-mode', 'dataforseo:google-ai-mode-standard', ['requests' => '1']],
    'DataForSEO AI Mode Live task' => ['dataforseo-google-ai-mode-live', 'dataforseo:google-ai-mode-live', ['requests' => '1']],
    'Exa Search request without add-ons' => ['exa', 'exa:search', ['requests' => '1', 'additional_results' => '0', 'summary_pages' => '0']],
    'Kagi FastGPT query' => ['kagi-fastgpt', 'kagi:fastgpt', ['uncached_queries' => '1']],
    'Brave Search request' => ['brave-search', 'brave:search', ['requests' => '1']],
    'Tavily advanced search credits' => ['tavily-search', 'tavily:search', ['credits' => '2']],
    'Perplexity default Search request' => ['perplexity-search', 'perplexity:search', ['requests' => '1']],
    'SerpAPI successful request' => ['serpapi', 'serpapi:search', ['successful_search_request' => '1']],
    'SerpBase Search credit' => ['serpbase-search', 'serpbase:search', ['credits' => '1']],
    'SerpBase News credit' => ['serpbase-news', 'serpbase:news', ['credits' => '1']],
    'SearchAPI Google request' => ['searchapi', 'searchapi:search', ['successful_search_request' => '1']],
    'SearchAPI ChatGPT request' => ['searchapi-chatgpt', 'searchapi:search', ['successful_search_request' => '1']],
    'SearchAPI Gemini request' => ['searchapi-gemini', 'searchapi:search', ['successful_search_request' => '1']],
    'SearchAPI Perplexity request' => ['searchapi-perplexity', 'searchapi:search', ['successful_search_request' => '1']],
    'SearchAPI AI Mode request' => ['searchapi-google-ai-mode', 'searchapi:search', ['successful_search_request' => '1']],
    'SearchAPI Copilot request' => ['searchapi-bing-copilot', 'searchapi:search', ['successful_search_request' => '1']],
    'SearchAPI AI Overview maximum' => ['searchapi-google-ai-overview', 'searchapi:search', ['successful_search_request' => '2']],
]);

it('returns the exact explicit Exa native ceiling independently of pricing estimates', function (string $effort, int|float $maximum, string $expected): void {
    config()->set('librarium.profiles.exa-research.options', [
        'effort' => $effort,
        'max_cost_dollars' => $maximum,
    ]);

    $bound = Librarium::query('Bound this run')->using('exa-research')->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::NativeMonetaryCeiling)
        ->and((string) $bound->maximumCost?->amount)->toBe($expected)
        ->and($bound->maximumCost?->currency)->toBe('USD')
        ->and($bound->pricingIdentity)->toBeNull()
        ->and($bound->quantities)->toBeNull()
        ->and($bound->toArray())->toBe([
            'kind' => 'native_monetary_ceiling',
            'maximum_cost' => ['amount' => $expected, 'currency' => 'USD'],
        ]);
    Http::assertNothingSent();
})->with([
    'minimum auto cap' => ['auto', 1, '1'],
    'fractional max cap' => ['max', 17.25, '17.25'],
    'maximum auto cap' => ['auto', 100, '100'],
]);

it('returns unknown when no driver proves every billed dimension', function (string $profile): void {
    $bound = Librarium::query('Do not estimate')->using($profile)->spendingBound();

    expect($bound->toArray())->toBe(['kind' => 'unknown'])
        ->and($bound->maximumCost)->toBeNull()
        ->and($bound->pricingIdentity)->toBeNull()
        ->and($bound->quantities)->toBeNull();
    Http::assertNothingSent();
})->with([
    'Exa auto without an explicit cap' => 'exa-research',
    'model and search-tool dimensions' => 'gemini-grounded',
    'provider-managed research task' => 'parallel-research',
    'partial retrieval ceiling' => 'valyu-search',
    'unproven token conversion' => 'jina-search',
]);

it('classifies every shipped Profile as known or deliberately unknown', function (): void {
    $known = collect(config('librarium.profiles'))
        ->keys()
        ->filter(fn (string $profile): bool => Librarium::query('inventory')->using($profile)->spendingBound()->kind !== SpendingBoundKind::Unknown)
        ->values();

    expect(config('librarium.profiles'))->toHaveCount(47)
        ->and($known)->toHaveCount(21)
        ->and($known->all())->toBe([
            'dataforseo-chatgpt',
            'dataforseo-chatgpt-live',
            'dataforseo-gemini',
            'dataforseo-gemini-live',
            'dataforseo-google-ai-mode',
            'dataforseo-google-ai-mode-live',
            'exa',
            'kagi-fastgpt',
            'searchapi',
            'searchapi-chatgpt',
            'searchapi-gemini',
            'searchapi-perplexity',
            'searchapi-google-ai-mode',
            'searchapi-bing-copilot',
            'searchapi-google-ai-overview',
            'brave-search',
            'tavily-search',
            'perplexity-search',
            'serpapi',
            'serpbase-search',
            'serpbase-news',
        ]);
    Http::assertNothingSent();
});

it('fails closed for ambiguous selections and fallback execution paths', function (Closure $research): void {
    expect(fn () => $research()->spendingBound())
        ->toThrow(ValidationException::class, 'using() with one exact Profile and no fallbacks');
    Http::assertNothingSent();
})->with([
    'default group' => fn () => Librarium::query('ambiguous'),
    'named group' => fn () => Librarium::query('ambiguous')->group('quick'),
    'fan out' => fn () => Librarium::query('ambiguous')->fanOut(['exa', 'brave-search']),
    'fallback' => fn () => Librarium::query('ambiguous')->using('exa')->fallbacks(['brave-search']),
]);

it('does not resolve pricing or apply maxCost estimates while compiling an exact spending bound', function (): void {
    $resolver = new class implements CostResolver
    {
        public int $calls = 0;

        public function resolve(PricingObservation $observation): CostQuote
        {
            $this->calls++;

            throw new LogicException('Spending-bound compilation must not resolve rates.');
        }
    };
    app()->when(ResearchCompiler::class)
        ->needs(CostResolver::class)
        ->give(fn (): CostResolver => $resolver);

    $bound = Librarium::query('No rate lookup')
        ->using('exa')
        ->maxCost('0.000001')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($resolver->calls)->toBe(0);
    Http::assertNothingSent();
});

it('returns unknown for an unsupported driver without constructing it', function (): void {
    UnsupportedSpendingBoundDriver::$constructed = 0;
    config()->set('librarium.profiles', [
        'unsupported-bound' => [
            'driver' => UnsupportedSpendingBoundDriver::class,
            'provider' => 'unsupported',
            'model' => null,
            'result_kind' => ResultKind::SearchResults->value,
            'grounding' => GroundingPolicy::None->value,
            'observation' => ObservationMode::ApiOutput->value,
            'corpora' => [Corpus::Web->value],
            'retrieval_methods' => [RetrievalMethod::SearchEndpoint->value],
            'prompt' => '{{ query }}',
            'enabled' => true,
            'options' => [],
        ],
    ]);

    $bound = Librarium::query('Do not construct')->using('unsupported-bound')->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and(UnsupportedSpendingBoundDriver::$constructed)->toBe(0);
    Http::assertNothingSent();
});

it('treats a bound as a snapshot and requires revalidation after controls change', function (): void {
    config()->set('librarium.profiles.exa-research.options', [
        'effort' => 'auto',
        'max_cost_dollars' => 3,
    ]);
    $research = Librarium::query('Snapshot controls')->using('exa-research');
    $original = $research->spendingBound();

    config()->set('librarium.profiles.exa-research.options.max_cost_dollars', 8);
    $updated = $research->spendingBound();
    $research->fallbacks(['brave-search']);

    expect((string) $original->maximumCost?->amount)->toBe('3')
        ->and((string) $updated->maximumCost?->amount)->toBe('8')
        ->and(fn () => $research->spendingBound())
        ->toThrow(ValidationException::class, 'using() with one exact Profile and no fallbacks');
    Http::assertNothingSent();
});

it('passes the compiled exact prompt, profile, request ID, and timeout to the optional driver capability', function (): void {
    config()->set('librarium.profiles', [
        'spending-probe' => [
            'driver' => SpendingBoundProbeDriver::class,
            'provider' => 'probe',
            'model' => null,
            'result_kind' => ResultKind::SearchResults->value,
            'grounding' => GroundingPolicy::None->value,
            'observation' => ObservationMode::ApiOutput->value,
            'corpora' => [Corpus::Web->value],
            'retrieval_methods' => [RetrievalMethod::SearchEndpoint->value],
            'prompt' => 'Rendered: {{ query }}',
            'enabled' => true,
            'options' => ['mode' => 'exact'],
        ],
    ]);

    $pending = Librarium::query('asymmetric input')->using('spending-probe')->timeout(37);
    $bound = $pending->spendingBound();
    $request = SpendingBoundProbeDriver::$request;

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($request)->not->toBeNull()
        ->and($request?->requestId)->toBe($pending->requestId)
        ->and($request?->requestedProfile)->toBe($request?->profile)
        ->and($request?->profile->id)->toBe('spending-probe')
        ->and($request?->profile->options)->toBe(['mode' => 'exact'])
        ->and($request?->prompt)->toBe('Rendered: asymmetric input')
        ->and($request?->deadline->diffInSeconds(now()))->toBeBetween(-38, -36);
    Http::assertNothingSent();
});

it('keeps the two-request SearchAPI bound when the second request fails without fabricating actual usage', function (): void {
    $bound = Librarium::query('overview')->using('searchapi-google-ai-overview')->spendingBound();
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['engine'] ?? null) === 'google'
            ? Http::response(['ai_overview' => ['page_token' => 'next']])
            : Http::response(['error' => 'failed second request'], 500);
    });

    $response = Librarium::query('overview')->using('searchapi-google-ai-overview')->run();

    expect($bound->quantities?->toArray())->toBe(['successful_search_request' => '2'])
        ->and($response->results)->toBeEmpty()
        ->and($response->usage)->toBeNull();
    Http::assertSentCount(2);
});

final class SpendingBoundProbeDriver implements DefinesSpendingBound, Driver
{
    public static ?DriverRequest $request = null;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return ['mode' => ['required', 'string', 'in:exact']];
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        self::$request = $request;

        return SpendingBound::unknown();
    }

    public function run(DriverRequest $request): ResearchResult
    {
        throw new LogicException('The spending-bound probe never executes research.');
    }
}

final class UnsupportedSpendingBoundDriver implements Driver
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        throw new LogicException('The unsupported spending-bound driver never executes research.');
    }
}
