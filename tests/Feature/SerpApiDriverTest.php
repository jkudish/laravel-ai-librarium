<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\SerpApi\SerpApiDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage as PricingUsage;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10T12:00:00Z');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('accepts no provider options and sends one exact synchronous Google search', function (): void {
    $transport = null;
    Http::globalMiddleware(function (callable $handler) use (&$transport): Closure {
        return function ($request, array $options) use ($handler, &$transport) {
            $transport = $options;

            return $handler($request, $options);
        };
    });
    Http::fake(['https://serpapi.com/search*' => Http::response(['organic_results' => []])]);

    serpApiDriver()->run(serpApiRequest(
        prompt: 'literal + %20 % café',
        deadline: CarbonImmutable::now()->addMilliseconds(2500),
    ));

    expect(SerpApiDriver::profileOptionRules())->toBe([])
        ->and($transport)->toBeArray()
        ->and($transport['connect_timeout'] ?? null)->toBe(2.5)
        ->and($transport['timeout'] ?? null)->toBe(2.5)
        ->and($transport['allow_redirects'] ?? null)->toBeFalse();
    Http::assertSent(function (Request $request): bool {
        $query = serpApiQuery($request);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://serpapi.com/search?')
            && $query === [
                'engine' => 'google',
                'q' => 'literal + %20 % café',
                'api_key' => 'testing-serpapi-key',
            ]
            && ! $request->hasHeader('Authorization');
    });
    Http::assertSentCount(1);
});

it('normalizes asymmetric organic results into safe Markdown and SearchResult semantics', function (): void {
    Http::fake(['https://serpapi.com/search*' => Http::response([
        'search_metadata' => ['id' => 'provider-secret-request-id'],
        'search_information' => ['total_results' => 987654321],
        'organic_results' => [
            [
                'position' => 7,
                'title' => "First [result]\n<script>",
                'link' => 'https://example.com/path_(one)',
                'snippet' => "A *provider* snippet.\n[active](https://tracker.example)",
            ],
            ['position' => 2, 'title' => 'Duplicate', 'link' => 'https://example.com/path_(one)', 'snippet' => 'duplicate'],
            ['position' => 11, 'link' => 'http://example.org/untitled'],
            ['position' => 4, 'title' => 'Credentials', 'link' => 'https://user:pass@example.com/private'],
            ['position' => 3, 'title' => 'Bad scheme', 'link' => 'javascript:alert(1)'],
            ['position' => 1, 'title' => 'Whitespace', 'link' => ' https://example.com/padded'],
            ['position' => 9, 'title' => 'Missing link'],
            'not-an-organic-result',
        ],
    ])]);

    $result = serpApiDriver()->run(serpApiRequest(requestedProfile: 'requested-serpapi'));

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toContain('### [First \\[result\\] &lt;script&gt;](https://example.com/path_%28one%29)')
        ->toContain('A \\*provider\\* snippet\\.')
        ->toContain('\\[active\\]\\(https://tracker\\.example\\)')
        ->toContain('### [Untitled](http://example.org/untitled)')
        ->not->toContain('Duplicate')
        ->not->toContain('user:pass')
        ->not->toContain('javascript:')
        ->and($result->requestedProfile)->toBe('requested-serpapi')
        ->and($result->provider)->toBe('serpapi')
        ->and($result->profile)->toBe('serpapi')
        ->and($result->model)->toBeNull()
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->provenance->collector)->toBeNull()
        ->and($result->provenance->surface)->toBeNull()
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/path_(one)',
            'http://example.org/untitled',
        ])
        ->and($result->citations->first()->source->title)->toBe("First [result]\n<script>")
        ->and($result->citations->first()->excerpt)->toBe("A *provider* snippet.\n[active](https://tracker.example)")
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported])
        ->and($result->usage)->toBeNull()
        ->and((array) $result->providerMeta)->toBe(['result_count' => 7]);

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('provider-secret-request-id')
        ->not->toContain('search_metadata')
        ->not->toContain('search_information')
        ->not->toContain('987654321')
        ->not->toContain('testing-serpapi-key');
});

it('maps missing or empty organic results to the TypeScript no-results content', function (mixed $body): void {
    Http::fake(['https://serpapi.com/search*' => Http::response($body)]);

    $result = serpApiDriver()->run(serpApiRequest());

    expect($result->content)->toBe('No results found.')
        ->and($result->citations)->toBeEmpty()
        ->and((array) $result->providerMeta)->toBe(['result_count' => 0]);
})->with([
    'field omitted' => ['{}'],
    'empty list' => [['organic_results' => []]],
]);

it('rejects malformed successful responses without retaining provider data', function (mixed $body): void {
    Http::fake(['https://serpapi.com/search*' => Http::response($body)]);

    try {
        serpApiDriver()->run(serpApiRequest());
        $this->fail('The malformed SerpAPI response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('serpapi.invalid_response')
            ->and($exception->getMessage())->toBe('SerpAPI returned a malformed search response.')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'non-json' => ['provider-secret malformed body'],
    'top-level list' => [['unexpected']],
    'organic results object' => [['organic_results' => ['link' => 'https://example.com']]],
]);

it('preserves the TypeScript adapter behavior for a top-level provider error', function (): void {
    Http::fake(['https://serpapi.com/search*' => Http::response([
        'search_metadata' => ['status' => 'Success'],
        'organic_results' => [],
        'error' => 'provider-secret Google returned no results',
    ])]);

    try {
        serpApiDriver()->run(serpApiRequest());
        $this->fail('The SerpAPI provider error should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('serpapi.provider')
            ->and($exception->getMessage())->toBe('SerpAPI reported an error for this request.')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
});

it('rejects a successful result that reflects the query credential into retained evidence', function (string $field): void {
    Http::fake(['https://serpapi.com/search*' => Http::response([
        'organic_results' => [[
            'title' => $field === 'title' ? 'Leaked testing-serpapi-key' : 'Safe title',
            'link' => $field === 'link' ? 'https://example.com/?key=testing-serpapi-key' : 'https://example.com/safe',
            'snippet' => $field === 'snippet' ? 'Leaked testing-serpapi-key' : 'Safe snippet',
        ]],
    ])]);

    try {
        serpApiDriver()->run(serpApiRequest());
        $this->fail('Credential-bearing evidence should fail closed.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('serpapi.invalid_response')
            ->and($exception->getMessage())->not->toContain('testing-serpapi-key');
    }
})->with(['title', 'link', 'snippet']);

it('maps documented HTTP statuses to bounded body-free errors', function (int $status, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://serpapi.com/search*' => Http::response([
        'error' => 'provider-secret raw response',
        'api_key' => 'testing-serpapi-key',
    ], $status)]);

    try {
        serpApiDriver()->run(serpApiRequest());
        $this->fail('The SerpAPI request should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('testing-serpapi-key')
            ->and(strlen($exception->getMessage()))->toBeLessThanOrEqual(128);
    }
})->with([
    'bad request' => [400, 'serpapi.invalid_request', false],
    'authentication' => [401, 'serpapi.authentication', true],
    'authorization' => [403, 'serpapi.authorization', true],
    'timeout' => [408, 'serpapi.timeout', true],
    'rate limit or exhausted searches' => [429, 'serpapi.rate_limited', true],
    'server error' => [503, 'serpapi.unavailable', true],
    'redirect is not followed' => [302, 'serpapi.invalid_request', false],
]);

it('rejects a missing credential or expired deadline before dispatch', function (string $condition, string $code): void {
    Http::fake();
    $request = match ($condition) {
        'credential' => serpApiRequest(credential: null),
        'deadline' => serpApiRequest(deadline: CarbonImmutable::now()),
    };

    try {
        serpApiDriver()->run($request);
        $this->fail('The SerpAPI request should not start.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'missing credential' => ['credential', 'serpapi.authentication'],
    'expired deadline' => ['deadline', 'serpapi.deadline_exceeded'],
]);

it('rejects responses arriving at the deadline and maps transport failures safely', function (string $condition, string $code): void {
    Http::fake(function () use ($condition) {
        if ($condition !== 'connection') {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
        }

        if (str_starts_with($condition, 'connection')) {
            if ($condition === 'connection after deadline') {
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
            }

            throw new ConnectionException('provider-secret https://serpapi.com/search?api_key=testing-serpapi-key');
        }

        return Http::response(['organic_results' => []]);
    });

    try {
        serpApiDriver()->run(serpApiRequest());
        $this->fail('The SerpAPI request should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('testing-serpapi-key');
    }
})->with([
    'response at deadline' => ['response at deadline', 'serpapi.deadline_exceeded'],
    'connection within deadline' => ['connection', 'serpapi.connection'],
    'connection after deadline' => ['connection after deadline', 'serpapi.deadline_exceeded'],
]);

it('meters one successful search and leaves pricing unavailable without an operator rate', function (): void {
    configureSerpApiProfile();

    $profile = app(ProfileCatalog::class)->find('serpapi')?->profile;
    expect($profile?->pricingIdentity?->key())->toBe('serpapi:search')
        ->and($profile?->pricingUsage?->toArray())->toBe(['successful_search_request' => '1'])
        ->and($profile?->pricingUsageConservative)->toBeTrue();

    $unconfigured = Librarium::query('pricing')->using('serpapi')->preview();
    expect($unconfigured->pricingStatus)->toBe('unavailable')
        ->and($unconfigured->hardCostCeilingAvailable)->toBeFalse()
        ->and($unconfigured->knownCostSubtotals)->toBeEmpty();

    Http::assertNothingSent();
});

it('quotes the metered search only from an operator-configured rate', function (): void {
    config()->set('ai-pricing.prices.serpapi:search', [
        'successful_search_request' => ['amount' => '0.00625', 'currency' => 'USD'],
    ]);
    configureSerpApiProfile();

    $configured = Librarium::query('pricing')->using('serpapi')->preview();
    expect($configured->pricingStatus)->toBe('complete')
        ->and($configured->maximumEstimatedCost)->toBe('0.00625')
        ->and($configured->pricingQuotes->sole()['quote']->source->value)->toBe('configured');

    Http::assertNothingSent();
});

function serpApiDriver(): SerpApiDriver
{
    return new SerpApiDriver;
}

function serpApiRequest(
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-serpapi-key',
    string $prompt = 'best pizza in Rome',
    string $requestedProfile = 'serpapi',
): DriverRequest {
    $profile = new Profile(
        id: 'serpapi',
        driver: SerpApiDriver::class,
        provider: 'serpapi',
        model: null,
        resultKind: ResultKind::SearchResults,
        grounding: GroundingPolicy::None,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::SearchEndpoint]),
        prompt: '{{ query }}',
        enabled: true,
        options: [],
        credential: $credential,
        pricingIdentity: new ModelIdentity('serpapi', 'search'),
        pricingUsage: new PricingUsage(['successful_search_request' => 1]),
        pricingUsageConservative: true,
    );

    $requested = clone $profile;
    if ($requestedProfile !== 'serpapi') {
        $requested = new Profile(
            id: $requestedProfile,
            driver: SerpApiDriver::class,
            provider: 'serpapi',
            model: null,
            resultKind: ResultKind::SearchResults,
            grounding: GroundingPolicy::None,
            observation: ObservationMode::ApiOutput,
            corpora: collect([Corpus::Web]),
            retrievalMethods: collect([RetrievalMethod::SearchEndpoint]),
            prompt: '{{ query }}',
            enabled: true,
            options: [],
        );
    }

    return new DriverRequest(
        requestId: 'request-serpapi-1',
        requestedProfile: $requested,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}

/** @return array<string, string> */
function serpApiQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

function configureSerpApiProfile(): void
{
    config()->set('librarium.profiles.serpapi', [
        'driver' => SerpApiDriver::class,
        'provider' => 'serpapi',
        'model' => null,
        'result_kind' => 'search_results',
        'grounding' => 'none',
        'observation' => 'api_output',
        'corpora' => ['web'],
        'retrieval_methods' => ['search_endpoint'],
        'prompt' => '{{ query }}',
        'enabled' => true,
        'options' => [],
        'credential' => 'testing-serpapi-key',
        'pricing' => [
            'provider' => 'serpapi',
            'model' => 'search',
            'usage' => ['successful_search_request' => 1],
            'conservative' => true,
        ],
    ]);
    app()->forgetInstance(ProfileCatalog::class);
}
