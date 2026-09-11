<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\SerpBase\SerpBaseDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\ProfileOptionValidator;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage as PricingUsage;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11T12:00:00Z');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('posts one search with exact endpoint options and a header-only credential', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($options['connect_timeout'])->toBe(2.5)
            ->and($options['timeout'])->toBe(2.5)
            ->and($options['allow_redirects'] ?? null)->toBeFalse();

        return Http::response(serpBaseEnvelope('search'));
    });

    serpBaseDriver()->run(serpBaseRequest(
        options: ['hl' => 'fr-CA', 'gl' => 'ca', 'page' => 3, 'device' => 'mobile', 'include_rich_results' => true],
        prompt: 'literal + %20 café',
        deadline: CarbonImmutable::now()->addMilliseconds(2500),
    ));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.serpbase.dev/google/search'
        && $request->hasHeader('X-API-Key', 'testing-serpbase-key')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->data() === [
            'q' => 'literal + %20 café',
            'hl' => 'fr-CA',
            'gl' => 'ca',
            'page' => 3,
            'device' => 'mobile',
        ]
        && ! str_contains($request->url(), 'testing-serpbase-key'));
    Http::assertSentCount(1);
});

it('validates shared options and rejects endpoint-incompatible or malformed values before dispatch', function (): void {
    $validator = app(ProfileOptionValidator::class);
    expect($validator->errors(SerpBaseDriver::class, [
        'hl' => 'en',
        'gl' => 'us',
        'page' => 1,
        'device' => 'default',
        'include_rich_results' => false,
    ]))->toBe([])
        ->and($validator->errors(SerpBaseDriver::class, ['device' => 'tablet']))->not->toBe([])
        ->and($validator->errors(SerpBaseDriver::class, ['page' => 0]))->not->toBe([])
        ->and($validator->errors(SerpBaseDriver::class, ['gl' => '   ']))->not->toBe([])
        ->and($validator->errors(SerpBaseDriver::class, ['gl' => 'country-name']))->toBe([]);

    Http::fake();
    foreach ([
        serpBaseRequest('news', ['device' => 'mobile']),
        serpBaseRequest('news', ['include_rich_results' => true]),
        serpBaseRequest('search', ['unknown' => true]),
        serpBaseRequest('search', [], prompt: '   '),
    ] as $request) {
        expect(fn () => serpBaseDriver()->run($request))
            ->toThrow(DriverException::class, 'invalid');
    }
    Http::assertNothingSent();
});

it('preserves organic response order and reported rank while deduplicating safe evidence', function (): void {
    Http::fake(['https://api.serpbase.dev/google/search' => Http::response(serpBaseEnvelope('search', [
        'organic' => [
            ['rank' => 7, 'title' => 'First [result]', 'link' => 'https://one.example/path_(a)', 'snippet' => 'First *snippet*.'],
            ['rank' => 2, 'title' => 'Second', 'link' => 'https://two.example/result', 'snippet' => 'Second snippet.'],
            ['rank' => 1, 'title' => 'Duplicate', 'link' => 'https://one.example/path_(a)'],
        ],
        'credits_charged' => 0.5,
    ]))]);

    $result = serpBaseDriver()->run(serpBaseRequest(requestedProfile: 'requested-search'));

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toStartWith('### 7. [First \\[result\\]](https://one.example/path_%28a%29)')
        ->toContain('First \\*snippet\\*\\.')
        ->toContain('### 2. [Second](https://two.example/result)')
        ->not->toContain('Duplicate')
        ->and($result->requestedProfile)->toBe('requested-search')
        ->and($result->provider)->toBe('serpbase')
        ->and($result->profile)->toBe('serpbase-search')
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://one.example/path_(a)',
            'https://two.example/result',
        ])
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported])
        ->and((array) $result->providerMeta)->toBe(['result_count' => 2, 'credits_charged' => 0.5]);
});

it('keeps rich modules off by default even when the provider returns them', function (): void {
    Http::fake(['https://api.serpbase.dev/google/search' => Http::response(serpBaseEnvelope('search', [
        'organic' => [],
        'featured_snippet' => ['unconfirmed' => 'hidden featured'],
        'people_also_ask' => [['question' => 'hidden question', 'link' => 'https://paa.example']],
        'top_stories' => [['unconfirmed' => 'hidden story']],
        'knowledge_graph' => ['unconfirmed' => 'hidden graph'],
        'related_searches' => ['hidden suggestion'],
    ]))]);

    $result = serpBaseDriver()->run(serpBaseRequest());

    expect($result->content)->toBe('No results found.')
        ->and($result->citations)->toBeEmpty();
});

it('projects confirmed PAA and suggestions while keeping synthetic undocumented modules opaque', function (): void {
    Http::fake(['https://api.serpbase.dev/google/search' => Http::response(serpBaseEnvelope('search', [
        'organic' => [['rank' => 1, 'title' => 'Organic', 'link' => 'https://same.example']],
        // Synthetic shapes: SerpBase has not published field-level schemas for these modules.
        'featured_snippet' => [
            'unconfirmed_text' => 'Featured ``` text',
            'url' => 'https://opaque-featured.example',
            'thumbnail_url' => 'https://images.example/secret.jpg',
            'api_token' => 'must-not-render',
        ],
        'people_also_ask' => [
            ['question' => 'Confirmed question?', 'snippet' => 'Confirmed snippet.', 'link' => 'https://paa.example/source', 'displayed_link' => 'paa.example'],
            ['question' => 'Duplicate organic?', 'snippet' => 'Still rendered.', 'link' => 'https://same.example'],
            ['question' => 'Unsafe source?', 'link' => 'https://user:pass@example.com/private'],
        ],
        'top_stories' => [['unconfirmed_story' => 'Story text', 'link' => 'https://opaque-story.example']],
        'knowledge_graph' => ['unconfirmed_name' => 'Entity', 'nested' => ['fact' => 'Value']],
        'related_searches' => ['alpha suggestion', 'https://suggestion.example/not-evidence'],
    ]))]);

    $result = serpBaseDriver()->run(serpBaseRequest(options: ['include_rich_results' => true]));

    expect($result->content)
        ->toContain('## Featured Snippet')
        ->toContain('"unconfirmed_text": "Featured \\u0060\\u0060\\u0060 text"')
        ->toContain('"url": "https://opaque-featured.example"')
        ->not->toContain('thumbnail_url')
        ->not->toContain('must-not-render')
        ->toContain('## People Also Ask')
        ->toContain('[Confirmed question?](https://paa.example/source)')
        ->toContain('Confirmed snippet\\.')
        ->toContain('paa\\.example')
        ->toContain('## Top Stories')
        ->toContain('"link": "https://opaque-story.example"')
        ->toContain('## Knowledge Graph')
        ->toContain('"unconfirmed_name": "Entity"')
        ->toContain('## Related Searches')
        ->toContain('alpha suggestion')
        ->toContain('https://suggestion\\.example/not\\-evidence')
        ->not->toContain('user:pass')
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://same.example',
            'https://paa.example/source',
        ]);
});

it('treats absent rich modules as normal and rejects malformed documented module containers', function (array $extra, bool $valid): void {
    Http::fake(['https://api.serpbase.dev/google/search' => Http::response(serpBaseEnvelope('search', ['organic' => [], ...$extra]))]);
    $run = fn () => serpBaseDriver()->run(serpBaseRequest(options: ['include_rich_results' => true]));

    if ($valid) {
        expect($run()->content)->toBe('No results found.');
    } else {
        expect($run)->toThrow(DriverException::class, 'malformed');
    }
})->with([
    'all absent' => [[], true],
    'featured unknown shape is omitted' => [['featured_snippet' => []], true],
    'PAA unknown shape is omitted' => [['people_also_ask' => ['question' => 'wrong']], true],
    'stories unknown shape is omitted' => [['top_stories' => ['story' => 'wrong']], true],
    'knowledge graph unknown shape is omitted' => [['knowledge_graph' => []], true],
    'malformed suggestions are omitted' => [['related_searches' => [['query' => 'wrong']]], true],
]);

it('fails closed when rich keys or values reflect the credential', function (array $module): void {
    Http::fake(['https://api.serpbase.dev/google/search' => Http::response(serpBaseEnvelope('search', [
        'organic' => [],
        ...$module,
    ]))]);

    expect(fn () => serpBaseDriver()->run(serpBaseRequest(options: ['include_rich_results' => true])))
        ->toThrow(DriverException::class, 'malformed');
})->with([
    'reflected value' => [['knowledge_graph' => ['private' => 'testing-serpbase-key']]],
    'reflected key' => [['featured_snippet' => ['testing-serpbase-key' => 'private']]],
]);

it('bounds synthetic rich collections and opaque keys and values', function (): void {
    $paa = [];
    $related = ['duplicate', 'duplicate'];
    for ($index = 1; $index <= 21; $index++) {
        $paa[] = ['question' => "Question {$index}?", 'link' => "https://paa.example/{$index}"];
        $related[] = "Suggestion {$index}";
    }

    Http::fake(['https://api.serpbase.dev/google/search' => Http::response(serpBaseEnvelope('search', [
        'organic' => [],
        'people_also_ask' => $paa,
        'related_searches' => $related,
        'knowledge_graph' => [
            '__proto__' => 'hidden',
            'constructor' => 'hidden',
            'prototype' => 'hidden',
            str_repeat('k', 2_100).'KEY_END' => 'bounded key',
            'deep' => ['one' => ['two' => ['three' => ['four' => 'hidden']]]],
            'long' => str_repeat('a', 2100).'END',
        ],
        'top_stories' => array_fill(0, 20, ['text' => str_repeat('b', 2100)]),
    ]))]);

    $result = serpBaseDriver()->run(serpBaseRequest(options: ['include_rich_results' => true]));
    preg_match('/## Top Stories\n\n```json\n.*?\n```/s', $result->content, $topStories);

    expect($result->citations)->toHaveCount(20)
        ->and($result->content)->toContain('Question 20?')
        ->not->toContain('Question 21?')
        ->not->toContain('Suggestion 19')
        ->not->toContain('__proto__')
        ->not->toContain('constructor')
        ->not->toContain('prototype')
        ->not->toContain('KEY_END')
        ->not->toContain('END')
        ->toContain('[truncated]')
        ->and(substr_count($result->content, '- duplicate'))->toBe(1)
        ->and(strlen($topStories[0] ?? ''))->toBeLessThanOrEqual(8000);
});

it('posts news without search-only controls and maps documented article fields', function (): void {
    Http::fake(['https://api.serpbase.dev/google/news' => Http::response(serpBaseEnvelope('news', [
        'news' => [
            ['rank' => 4, 'title' => 'Later article', 'link' => 'https://news.example/later', 'source' => 'News Co', 'time' => '2 hours ago', 'snippet' => 'Later summary.'],
            ['rank' => 1, 'title' => 'Earlier article', 'link' => 'https://news.example/earlier', 'source' => 'Wire', 'published_at' => '2026-09-11', 'snippet' => 'Earlier summary.'],
        ],
    ]))]);

    $result = serpBaseDriver()->run(serpBaseRequest('news', ['hl' => 'en', 'gl' => 'gb', 'page' => 2]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.serpbase.dev/google/news'
        && $request->data() === ['q' => 'latest Laravel release', 'hl' => 'en', 'gl' => 'gb', 'page' => 2]);
    Http::assertSentCount(1);
    expect($result->content)->toStartWith('### 4. [Later article](https://news.example/later)')
        ->toContain('*News Co · 2 hours ago*')
        ->toContain('### 1. [Earlier article](https://news.example/earlier)')
        ->and($result->citations->pluck('source.publisher')->all())->toBe(['News Co', 'Wire'])
        ->and($result->citations->pluck('source.kind')->unique()->all())->toBe([SourceKind::NewsArticle])
        ->and($result->provenance->corpora->all())->toBe([Corpus::News])
        ->and($result->profile)->toBe('serpbase-news');
});

it('distinguishes valid empty results from malformed successful responses', function (mixed $body, bool $valid, string $kind = 'search'): void {
    $body = $body instanceof Closure ? $body() : $body;
    Http::fake(["https://api.serpbase.dev/google/{$kind}" => Http::response($body)]);
    $run = fn () => serpBaseDriver()->run(serpBaseRequest($kind));

    if ($valid) {
        expect($run()->content)->toBe('No results found.');
    } else {
        expect($run)->toThrow(DriverException::class, 'malformed');
    }
})->with([
    'organic absent' => [fn () => serpBaseEnvelope('search'), true],
    'organic empty' => [fn () => serpBaseEnvelope('search', ['organic' => []]), true],
    'organic null' => [fn () => serpBaseEnvelope('search', ['organic' => null]), false],
    'news absent' => [fn () => serpBaseEnvelope('news'), true, 'news'],
    'news empty' => [fn () => serpBaseEnvelope('news', ['news' => []]), true, 'news'],
    'news null' => [fn () => serpBaseEnvelope('news', ['news' => null]), false, 'news'],
    'non-json' => ['provider-secret raw body', false],
    'top-level list' => [['unexpected'], false],
    'missing status' => [['organic' => []], false],
    'missing required success metadata' => [['status' => 0, 'organic' => []], false],
    'organic object' => [fn () => serpBaseEnvelope('search', ['organic' => ['link' => 'https://example.com']]), false],
    'wrong response type' => [fn () => serpBaseEnvelope('news', ['organic' => []]), false],
    'malformed item' => [fn () => serpBaseEnvelope('search', ['organic' => ['not-an-object']]), false],
    'missing rank' => [fn () => serpBaseEnvelope('search', ['organic' => [['title' => 'Title', 'link' => 'https://example.com']]]), false],
    'fractional rank' => [fn () => serpBaseEnvelope('search', ['organic' => [['rank' => 1.5, 'title' => 'Title', 'link' => 'https://example.com']]]), false],
    'empty title' => [fn () => serpBaseEnvelope('search', ['organic' => [['rank' => 1, 'title' => ' ', 'link' => 'https://example.com']]]), false],
    'missing link does not fall back to url' => [fn () => serpBaseEnvelope('search', ['organic' => [['rank' => 1, 'title' => 'Title', 'url' => 'https://example.com']]]), false],
    'unsafe link' => [fn () => serpBaseEnvelope('search', ['organic' => [['rank' => 1, 'title' => 'Title', 'link' => 'https://user:pass@example.com']]]), false],
    'credential reflected in title' => [fn () => serpBaseEnvelope('search', ['organic' => [['rank' => 1, 'title' => 'testing-serpbase-key', 'link' => 'https://example.com']]]), false],
    'credential reflected in item key' => [fn () => serpBaseEnvelope('search', ['organic' => [['rank' => 1, 'title' => 'Title', 'link' => 'https://example.com', 'testing-serpbase-key' => 'value']]]), false],
    'credential reflected outside projected fields' => [fn () => serpBaseEnvelope('search', ['organic' => [], 'unknown' => ['testing-serpbase-key' => 'value']]), false],
]);

it('maps documented business statuses to bounded body-free errors', function (int $status, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://api.serpbase.dev/google/search' => Http::response([
        'status' => $status,
        'error' => 'provider-secret testing-serpbase-key',
        'request_id' => 'provider-secret-id',
        'elapsed_ms' => 0,
        'credits_charged' => 0,
    ])]);

    try {
        serpBaseDriver()->run(serpBaseRequest());
        $this->fail('The SerpBase business error should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->not->toContain('testing-serpbase-key');
    }
})->with([
    'invalid request' => [1000, 'serpbase.invalid_request', false],
    'authentication' => [1001, 'serpbase.authentication', true],
    'not found' => [1004, 'serpbase.not_found', false],
    'insufficient credits' => [1020, 'serpbase.quota_exceeded', true],
    'rate limited' => [1029, 'serpbase.rate_limited', true],
    'internal error' => [1500, 'serpbase.unavailable', true],
    'upstream failed' => [1502, 'serpbase.unavailable', true],
    'service unavailable' => [1503, 'serpbase.unavailable', true],
    'upstream timeout' => [1504, 'serpbase.deadline_exceeded', true],
    'unknown business error' => [1999, 'serpbase.provider', true],
]);

it('rejects missing credentials and expired deadlines without dispatch', function (DriverRequest $request, string $code): void {
    Http::fake();
    try {
        serpBaseDriver()->run($request);
        $this->fail('The SerpBase request should not start.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
    Http::assertNothingSent();
})->with([
    'missing credential' => fn () => [serpBaseRequest(credential: null), 'serpbase.authentication'],
    'expired deadline' => fn () => [serpBaseRequest(deadline: CarbonImmutable::now()), 'serpbase.deadline_exceeded'],
]);

it('discards late responses and redacts connection failures', function (string $condition): void {
    Http::fake(function () use ($condition) {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
        if ($condition === 'connection') {
            throw new ConnectionException('provider-secret testing-serpbase-key');
        }

        return Http::response(serpBaseEnvelope('search'));
    });

    try {
        serpBaseDriver()->run(serpBaseRequest());
        $this->fail('The late SerpBase request should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('serpbase.deadline_exceeded')
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->not->toContain('testing-serpbase-key');
    }
})->with(['response', 'connection']);

it('keeps pricing unavailable without an account-configured credit price', function (string $profileId, string $identity): void {
    $profile = app(ProfileCatalog::class)->find($profileId)?->profile;
    expect($profile?->pricingIdentity?->key())->toBe($identity)
        ->and($profile?->pricingUsage?->toArray())->toBe(['credits' => '1'])
        ->and($profile?->pricingUsageConservative)->toBeTrue();

    $preview = Librarium::query('pricing')->using($profileId)->preview();
    expect($preview->pricingStatus)->toBe('unavailable')
        ->and($preview->preflightCostCheckAvailable)->toBeFalse();
    Http::assertNothingSent();
})->with([
    'search' => ['serpbase-search', 'serpbase:search'],
    'news' => ['serpbase-news', 'serpbase:news'],
]);

it('uses account-configured credit prices rather than a public headline minimum', function (string $profileId, string $identity): void {
    config()->set("ai-pricing.prices.{$identity}", [
        'credits' => ['amount' => '0.00047', 'currency' => 'USD'],
    ]);

    $configured = Librarium::query('pricing')->using($profileId)->preview();
    expect($configured->pricingStatus)->toBe('complete')
        ->and($configured->maximumEstimatedCost)->toBe('0.00047')
        ->and($configured->pricingQuotes->sole()['quote']->source->value)->toBe('configured');
    Http::assertNothingSent();
})->with([
    'search' => ['serpbase-search', 'serpbase:search'],
    'news' => ['serpbase-news', 'serpbase:news'],
]);

function serpBaseDriver(): SerpBaseDriver
{
    return new SerpBaseDriver;
}

function serpBaseRequest(
    string $kind = 'search',
    array $options = [],
    ?string $credential = 'testing-serpbase-key',
    string $prompt = 'latest Laravel release',
    ?CarbonImmutable $deadline = null,
    ?string $requestedProfile = null,
): DriverRequest {
    $id = 'serpbase-'.$kind;
    $profile = new Profile(
        id: $id,
        driver: SerpBaseDriver::class,
        provider: 'serpbase',
        model: null,
        resultKind: ResultKind::SearchResults,
        grounding: GroundingPolicy::None,
        observation: ObservationMode::ApiOutput,
        corpora: collect([$kind === 'news' ? Corpus::News : Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::SearchEndpoint]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
        pricingIdentity: new ModelIdentity('serpbase', $kind),
        pricingUsage: new PricingUsage(['credits' => 1]),
        pricingUsageConservative: true,
    );

    $requested = clone $profile;
    if ($requestedProfile !== null) {
        $requested = new Profile(
            id: $requestedProfile,
            driver: $profile->driver,
            provider: $profile->provider,
            model: $profile->model,
            resultKind: $profile->resultKind,
            grounding: $profile->grounding,
            observation: $profile->observation,
            corpora: $profile->corpora,
            retrievalMethods: $profile->retrievalMethods,
            prompt: $profile->prompt,
            enabled: $profile->enabled,
            options: $profile->options,
        );
    }

    return new DriverRequest(
        requestId: 'request-serpbase-1',
        requestedProfile: $requested,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}

/** @param array<string, mixed> $extra */
function serpBaseEnvelope(string $kind, array $extra = []): array
{
    return [
        'status' => 0,
        'request_id' => 'sb-request-1',
        'elapsed_ms' => 125,
        'credits_charged' => 1,
        'search_type' => $kind,
        'query' => 'latest Laravel release',
        'page' => 1,
        ...$extra,
    ];
}
