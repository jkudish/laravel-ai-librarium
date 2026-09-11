<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\SearchApi\SearchApiDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-16T12:00:00Z');

    collect(config('librarium.profiles'))
        ->filter(fn (array $profile): bool => data_get($profile, 'driver') === SearchApiDriver::class)
        ->keys()
        ->each(fn (string $id) => config()->set("librarium.profiles.{$id}.credential", 'testing-searchapi-key'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('registers the seven SearchAPI Profiles with explicit configured-only pricing assumptions', function (): void {
    $profiles = app(ProfileCatalog::class)->all()
        ->filter(fn ($item): bool => $item->profile->driver === SearchApiDriver::class)
        ->mapWithKeys(fn ($item, string $id): array => [$id => [
            $item->profile->pricingIdentity?->key(),
            $item->profile->pricingUsage?->toArray(),
            $item->profile->pricingUsageConservative,
        ]]);

    expect($profiles->all())->toBe([
        'searchapi' => ['searchapi:search', ['successful_search_request' => '1'], true],
        'searchapi-chatgpt' => ['searchapi:search', ['successful_search_request' => '1'], true],
        'searchapi-gemini' => ['searchapi:search', ['successful_search_request' => '1'], true],
        'searchapi-perplexity' => ['searchapi:search', ['successful_search_request' => '1'], true],
        'searchapi-google-ai-mode' => ['searchapi:search', ['successful_search_request' => '1'], true],
        'searchapi-bing-copilot' => ['searchapi:search', ['successful_search_request' => '1'], true],
        'searchapi-google-ai-overview' => ['searchapi:search', ['successful_search_request' => '2'], true],
    ]);
});

it('declares canonical surface semantics for all six SearchAPI surface Profiles', function (): void {
    $profiles = app(ProfileCatalog::class)->group('visibility')['profiles']
        ->filter(fn ($profile): bool => $profile->driver === SearchApiDriver::class)
        ->filter(fn ($profile): bool => $profile->observation === ObservationMode::SurfaceSnapshot);

    expect($profiles)->toHaveCount(6);
    $profiles->each(function ($profile): void {
        expect($profile->resultKind)->toBe(ResultKind::SurfaceObservation)
            ->and($profile->observation)->toBe(ObservationMode::SurfaceSnapshot)
            ->and($profile->retrievalMethods->map->value->all())->toBe(['surface_collector']);
    });
});

it('keeps SearchAPI pricing unavailable until the operator configures its rate', function (): void {
    $preview = Librarium::query('What changed?')->using('searchapi')->preview();

    expect($preview->pricingStatus)->toBe('unavailable')
        ->and($preview->preflightCostCheckAvailable)->toBeFalse()
        ->and($preview->knownCostSubtotals)->toBeEmpty()
        ->and($preview->pricingQuotes->sole()['quote']->completeness->value)->toBe('unavailable');

    Http::assertNothingSent();
});

it('quotes SearchAPI offline after the operator configures its request rate', function (string $profile, string $cost): void {
    config()->set('ai-pricing.prices.searchapi:search', [
        'successful_search_request' => ['amount' => '0.004', 'currency' => 'USD'],
    ]);

    $preview = Librarium::query('What changed?')->using($profile)->preview();

    expect($preview->pricingStatus)->toBe('complete')
        ->and($preview->maximumEstimatedCost)->toBe($cost)
        ->and($preview->preflightCostCheckAvailable)->toBeTrue()
        ->and($preview->pricingQuotes->sole()['quote']->source->value)->toBe('configured');

    Http::assertNothingSent();
})->with([
    'one SearchAPI request' => ['searchapi', '0.004'],
    'two SearchAPI requests' => ['searchapi-google-ai-overview', '0.008'],
]);

it('sends one bearer-authenticated GET with the built-in engine and query and no URL credential', function (string $profile, string $engine): void {
    Http::fake(['https://www.searchapi.io/*' => Http::response([
        'organic_results' => [['title' => 'T', 'link' => 'https://example.com/a', 'snippet' => 'S']],
        'markdown' => '[Example](https://example.com/a) is useful.',
        'reference_links' => [['title' => 'Example', 'url' => 'https://example.com/a']],
    ])]);

    searchApiDriver()->run(searchApiRequest($profile));

    Http::assertSent(function (Request $request) use ($engine): bool {
        $query = searchApiQuery($request);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://www.searchapi.io/api/v1/search')
            && ($query['engine'] ?? null) === $engine
            && ($query['q'] ?? null) === 'best pizza in Rome'
            && $request->hasHeader('Authorization', 'Bearer testing-searchapi-key')
            && ! str_contains($request->url(), 'testing-searchapi-key')
            && ! str_contains($request->url(), 'api_key');
    });
    Http::assertSentCount(1);
})->with([
    'raw google' => ['searchapi', 'google'],
    'chatgpt' => ['searchapi-chatgpt', 'chatgpt'],
    'gemini' => ['searchapi-gemini', 'gemini'],
    'perplexity' => ['searchapi-perplexity', 'perplexity'],
    'google ai mode' => ['searchapi-google-ai-mode', 'google_ai_mode'],
    'bing copilot' => ['searchapi-bing-copilot', 'bing_copilot'],
]);

it('normalizes a raw Google search into Markdown evidence sections with filtered, deduplicated citations', function (): void {
    Http::fake(['https://www.searchapi.io/*' => Http::response([
        'search_metadata' => ['id' => 'sm-9'],
        'organic_results' => [
            ['title' => 'First', 'link' => 'https://example.com/first', 'snippet' => 'First snippet'],
            ['title' => 'Duplicate', 'link' => 'https://example.com/first', 'snippet' => 'dup'],
            ['title' => 'Navigation', 'link' => 'https://www.google.com/search?q=x', 'snippet' => 'nav'],
        ],
        'ai_overview' => [
            'markdown' => 'AI overview text.',
            'reference_links' => [['title' => 'Reference', 'link' => 'https://example.org/ref']],
        ],
        'top_stories' => [['title' => 'Story', 'link' => 'https://news.example.com/s', 'source' => 'Example News', 'date' => '2026-08-01']],
        'knowledge_graph' => ['source' => ['title' => 'Knowledge', 'link' => 'https://kg.example.com/x']],
    ])]);

    $result = searchApiDriver()->run(searchApiRequest('searchapi'));

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toContain('### [First](https://example.com/first)')
        ->toContain('## AI Overview')
        ->toContain('AI overview text.')
        ->toContain('## Top Stories')
        ->toContain('## Knowledge Graph Sources')
        ->not->toContain('https://www.google.com/search?q=x')
        ->and($result->provider)->toBe('searchapi')
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->corpora->map->value->all())->toBe(['web'])
        ->and($result->provenance->retrievalMethods->map->value->all())->toBe(['search_endpoint'])
        ->and($result->provenance->collector)->toBeNull()
        ->and($result->provenance->surface)->toBeNull()
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/first',
            'https://example.org/ref',
            'https://news.example.com/s',
            'https://kg.example.com/x',
        ])
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported])
        ->and((array) $result->providerMeta)->toBe(['request_id' => 'sm-9', 'result_count' => 3]);
});

it('maps a surface answer from provider Markdown with collector-extracted citations and the reported model', function (): void {
    Http::fake(['https://www.searchapi.io/*' => Http::response([
        'search_metadata' => ['id' => 'sm-2'],
        'markdown' => 'ChatGPT [says](https://example.com/answer) hello.',
        'reference_links' => [
            ['title' => 'Answer', 'url' => 'https://example.com/answer', 'snippet' => 'snip'],
            ['title' => 'Duplicate', 'url' => 'https://example.com/answer'],
            ['title' => 'Bad scheme', 'url' => 'ftp://example.com/x'],
        ],
        'response_metadata' => ['model' => 'gpt-observed'],
        'organic_results' => [['title' => 'leak', 'link' => 'https://leak.example.com', 'snippet' => 'x']],
    ])]);

    $result = searchApiDriver()->run(searchApiRequest('searchapi-chatgpt'));

    expect($result->content)->toBe('ChatGPT [says](https://example.com/answer) hello.')
        ->and($result->provider)->toBe('searchapi')
        ->and($result->model)->toBe('gpt-observed')
        ->and($result->provenance->resultKind)->toBe(ResultKind::SurfaceObservation)
        ->and($result->provenance->retrievalMethods->map->value->all())->toBe(['surface_collector'])
        ->and($result->provenance->corpora->map->value->all())->toBe(['web'])
        ->and($result->provenance->observationMode)->toBe(ObservationMode::SurfaceSnapshot)
        ->and($result->provenance->collector)->toBe('searchapi')
        ->and($result->provenance->surface)->toBe('chatgpt')
        ->and($result->citations)->toHaveCount(1)
        ->and($result->citations->first()->derivation)->toBe(CitationDerivation::CollectorExtracted)
        ->and($result->citations->first()->source->url)->toBe('https://example.com/answer')
        ->and($result->citations->first()->excerpt)->toBe('snip');

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('leak.example.com')
        ->not->toContain('organic_results')
        ->not->toContain('response_metadata');

    Http::assertSent(fn (Request $request): bool => (searchApiQuery($request)['web_search'] ?? null) === 'true'
        && (searchApiQuery($request)['engine'] ?? null) === 'chatgpt');
});

it('falls back to typed text blocks when the surface Markdown is incomplete and reports no model when absent', function (): void {
    Http::fake(['https://www.searchapi.io/*' => Http::response([
        'markdown' => 'See [broken](https://example.com/x',
        'text_blocks' => [
            ['type' => 'header', 'answer' => 'Overview'],
            ['type' => 'future_block', 'answer' => 'A concise answer.'],
            ['type' => 'unordered_list', 'items' => [
                ['answer' => 'Point one'],
                ['answer' => 'Point two'],
            ]],
        ],
        'reference_links' => [],
    ])]);

    $result = searchApiDriver()->run(searchApiRequest('searchapi-gemini'));

    expect($result->content)->toContain('### Overview')
        ->toContain('A concise answer.')
        ->toContain('- Point one')
        ->toContain('- Point two')
        ->not->toContain('broken')
        ->and($result->model)->toBeNull()
        ->and($result->citations)->toBeEmpty()
        ->and($result->provenance->surface)->toBe('gemini');
});

it('fails an AI Mode answer with no AI content and never uses organic or local results', function (): void {
    Http::fake(['https://www.searchapi.io/*' => Http::response([
        'local_results' => [['title' => 'Local', 'link' => 'https://maps.example.com']],
        'web_results' => [['title' => 'Web', 'link' => 'https://web.example.com']],
        'markdown' => '',
        'text_blocks' => [],
    ])]);

    try {
        searchApiDriver()->run(searchApiRequest('searchapi-google-ai-mode'));
        $this->fail('An AI Mode response without AI content should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('searchapi.invalid_response');
    }
});

it('runs Google AI Overview as exactly two calls, passing zero retention on both and returning the stage-two answer', function (): void {
    config()->set('librarium.profiles.searchapi-google-ai-overview.options.zero_retention', true);

    Http::fake(function (Request $request) {
        return match (searchApiQuery($request)['engine'] ?? null) {
            'google' => Http::response(['ai_overview' => ['page_token' => 'token-abc']]),
            'google_ai_overview' => Http::response([
                'markdown' => 'Overview answer [source](https://example.com/o).',
                'reference_links' => [['title' => 'Source', 'url' => 'https://example.com/o']],
            ]),
            default => Http::response([], 500),
        };
    });

    $result = searchApiDriver()->run(searchApiRequest('searchapi-google-ai-overview'));

    expect($result->content)->toBe('Overview answer [source](https://example.com/o).')
        ->and($result->provenance->resultKind)->toBe(ResultKind::SurfaceObservation)
        ->and($result->provenance->retrievalMethods->map->value->all())->toBe(['surface_collector'])
        ->and($result->provenance->observationMode)->toBe(ObservationMode::SurfaceSnapshot)
        ->and($result->provenance->collector)->toBe('searchapi')
        ->and($result->provenance->surface)->toBe('google_ai_overview')
        ->and($result->citations->pluck('source.url')->all())->toBe(['https://example.com/o']);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => (searchApiQuery($request)['engine'] ?? null) === 'google'
        && (searchApiQuery($request)['zero_retention'] ?? null) === 'true');
    Http::assertSent(fn (Request $request): bool => (searchApiQuery($request)['engine'] ?? null) === 'google_ai_overview'
        && (searchApiQuery($request)['page_token'] ?? null) === 'token-abc'
        && (searchApiQuery($request)['zero_retention'] ?? null) === 'true');
});

it('stops after stage one of Google AI Overview when the page token is missing', function (): void {
    Http::fake(['https://www.searchapi.io/*' => Http::response(['ai_overview' => ['markdown' => 'no token here']])]);

    try {
        searchApiDriver()->run(searchApiRequest('searchapi-google-ai-overview'));
        $this->fail('A missing page token should stop the overview workflow.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('searchapi.invalid_response');
    }

    Http::assertSentCount(1);
});

it('respects the shared deadline and never starts the second overview call after time runs out', function (): void {
    Http::fake(function (Request $request) {
        if ((searchApiQuery($request)['engine'] ?? null) === 'google') {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(4));

            return Http::response(['ai_overview' => ['page_token' => 'token-abc']]);
        }

        return Http::response(['markdown' => 'unreached']);
    });

    try {
        searchApiDriver()->run(searchApiRequest('searchapi-google-ai-overview', CarbonImmutable::now()->addSeconds(3)));
        $this->fail('The second overview call should not start after the deadline.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('searchapi.deadline_exceeded');
    }

    Http::assertSentCount(1);
});

it('fails closed when a required zero-retention request is not supported', function (): void {
    config()->set('librarium.profiles.searchapi.options.zero_retention', true);
    Http::fake(['https://www.searchapi.io/*' => Http::response(
        ['error' => 'Zero retention is only available on enterprise plans.'],
        403,
    )]);

    try {
        searchApiDriver()->run(searchApiRequest('searchapi'));
        $this->fail('An unsupported zero-retention request must fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('searchapi.zero_retention_unsupported')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }
});

it('makes no request when the credential is missing or the options are invalid', function (string $mutation): void {
    Http::fake();

    if ($mutation === 'missing credential') {
        config()->set('librarium.profiles.searchapi.credential', null);
        $expected = 'searchapi.authentication';
    } else {
        config()->set('librarium.profiles.searchapi.options.zero_retention', 'yes');
        $expected = 'searchapi.invalid_options';
    }

    try {
        searchApiDriver()->run(searchApiRequest('searchapi'));
        $this->fail('The request should be rejected before any HTTP call.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($expected);
    }

    Http::assertNothingSent();
})->with(['missing credential', 'invalid options']);

it('maps expected provider failures to safe namespaced errors without leaking the response body', function (mixed $fake, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://www.searchapi.io/*' => $fake]);

    try {
        searchApiDriver()->run(searchApiRequest('searchapi'));
        $this->fail('The provider failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'unauthorized' => [fn () => Http::response(['secret' => 'provider-secret'], 401), 'searchapi.authentication', true],
    'forbidden' => [fn () => Http::response(['secret' => 'provider-secret'], 403), 'searchapi.authorization', true],
    'rate limited' => [fn () => Http::response(['secret' => 'provider-secret'], 429), 'searchapi.rate_limited', true],
    'server error' => [fn () => Http::response(['secret' => 'provider-secret'], 503), 'searchapi.unavailable', true],
    'malformed body' => [fn () => Http::response('provider-secret not json', 200), 'searchapi.invalid_response', true],
    'payload error' => [fn () => Http::response(['error' => 'provider-secret'], 200), 'searchapi.provider', true],
]);

it('maps a connection failure to a safe transient error', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error: provider-secret unreachable'));

    try {
        searchApiDriver()->run(searchApiRequest('searchapi'));
        $this->fail('A connection failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('searchapi.connection')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
});

it('rejects an expired deadline before making any request', function (): void {
    Http::fake();

    try {
        searchApiDriver()->run(searchApiRequest('searchapi', CarbonImmutable::now()->subSecond()));
        $this->fail('An expired deadline should be rejected.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('searchapi.deadline_exceeded');
    }

    Http::assertNothingSent();
});

it('registers the SearchAPI visibility surfaces in catalog order and keeps the raw search profile eligible', function (): void {
    $catalog = app(ProfileCatalog::class);

    expect($catalog->find('searchapi')?->isEligible())->toBeTrue()
        ->and($catalog->group('visibility')['profiles']->pluck('id')->all())->toBe([
            'searchapi-chatgpt',
            'searchapi-gemini',
            'searchapi-perplexity',
            'searchapi-google-ai-mode',
            'searchapi-bing-copilot',
            'searchapi-google-ai-overview',
            'perplexity-sonar-pro',
            'gemini-grounded',
            'grok',
        ]);
});

function searchApiDriver(): SearchApiDriver
{
    return app(SearchApiDriver::class);
}

function searchApiRequest(string $profileId, ?CarbonImmutable $deadline = null, string $prompt = 'best pizza in Rome'): DriverRequest
{
    $profile = app(ProfileCatalog::class)->find($profileId)?->profile;
    if ($profile === null) {
        throw new RuntimeException("Missing SearchAPI test profile [{$profileId}].");
    }

    return new DriverRequest(
        requestId: 'request-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}

/** @return array<string, string> */
function searchApiQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}
