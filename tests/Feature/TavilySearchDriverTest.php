<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Tavily\TavilySearchDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10T12:00:00Z');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('sends the exact Tavily advanced search request with documented bearer authentication', function (): void {
    Http::fake(['https://api.tavily.com/search' => Http::response(['results' => []])]);

    tavilySearchDriver()->run(tavilySearchRequest());

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.tavily.com/search'
            && $request->hasHeader('Authorization', 'Bearer testing-tavily-key')
            && $request->data() === [
                'query' => 'best ramen in Vancouver',
                'search_depth' => 'advanced',
                'include_answer' => true,
                'max_results' => 10,
            ]
            && ! array_key_exists('api_key', $request->data())
            && ! str_contains($request->url(), 'testing-tavily-key');
    });
    Http::assertSentCount(1);
    expect(TavilySearchDriver::profileOptionRules())->toBe([]);
});

it('normalizes an asymmetric answer and result list with exact profile provenance', function (): void {
    Http::fake(['https://api.tavily.com/search' => Http::response([
        'answer' => 'A concise answer with 7 details.',
        'results' => [
            [
                'title' => 'First [result]',
                'url' => 'https://example.com/first_(one)',
                'content' => str_repeat('A', 205),
                'score' => 0.91,
            ],
            [
                'url' => 'http://example.org/untitled',
                'content' => 'A shorter second snippet.',
                'score' => 0.42,
            ],
        ],
        'usage' => ['credits' => 2],
        'request_id' => 'provider-request-secret',
    ])]);

    $result = tavilySearchDriver()->run(tavilySearchRequest());

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe(
            "## Answer\n\n"
            .'A concise answer with 7 details\\.'."\n\n"
            ."## Search Results\n\n"
            ."### [First \\[result\\]](https://example.com/first_%28one%29)\n"
            .str_repeat('A', 205)."\n\n"
            ."### [Untitled](http://example.org/untitled)\n"
            .'A shorter second snippet\\.'."\n",
        )
        ->and($result->requestedProfile)->toBe('requested-search')
        ->and($result->provider)->toBe('tavily')
        ->and($result->profile)->toBe('tavily-search')
        ->and($result->model)->toBeNull()
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->retrievalMethods->map->value->all())->toBe(['search_endpoint'])
        ->and($result->provenance->corpora->map->value->all())->toBe(['web'])
        ->and($result->provenance->observedAt->toIso8601ZuluString())->toBe('2026-09-10T12:00:00Z')
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/first_(one)',
            'http://example.org/untitled',
        ])
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported])
        ->and($result->citations->first()->source->title)->toBe('First [result]')
        ->and($result->citations->first()->excerpt)->toBe(str_repeat('A', 200))
        ->and($result->usage)->toBeNull()
        ->and((array) $result->providerMeta)->toBe(['result_count' => 2, 'credits' => 2]);
});

it('keeps answer-only, results-only, and empty Tavily successes semantically distinct', function (array $body, string $content, int $citations): void {
    Http::fake(['https://api.tavily.com/search' => Http::response($body)]);

    $result = tavilySearchDriver()->run(tavilySearchRequest());

    expect($result->content)->toBe($content)
        ->and($result->citations)->toHaveCount($citations)
        ->and($result->usage)->toBeNull();
})->with([
    'answer only' => [
        ['answer' => 'Only answer', 'results' => []],
        "## Answer\n\nOnly answer\n",
        0,
    ],
    'results only' => [
        ['results' => [['title' => 'Only result', 'url' => 'https://example.com/only']]],
        "## Search Results\n\n### [Only result](https://example.com/only)\n",
        1,
    ],
    'empty' => [
        ['results' => []],
        '',
        0,
    ],
]);

it('treats provider text and URLs as untrusted while retaining canonical citation URLs', function (): void {
    Http::fake(['https://api.tavily.com/search' => Http::response([
        'answer' => '<script>alert(1)</script> [answer](https://evil.example)',
        'results' => [
            [
                'title' => "Unsafe\n![pixel](https://tracker.example/pixel)",
                'url' => 'https://example.com/path_(one)?next=a(b)',
                'content' => '<img src="https://tracker.example/pixel"> # heading',
            ],
            ['title' => 'Duplicate', 'url' => 'https://example.com/path_(one)?next=a(b)'],
            ['title' => 'Credentials', 'url' => 'https://user:pass@example.com/secret'],
            ['title' => 'Script', 'url' => 'javascript:alert(1)'],
            ['title' => 'Whitespace', 'url' => ' https://example.com/spaced'],
            ['title' => 'Missing URL'],
            'not-a-record',
        ],
    ])]);

    $result = tavilySearchDriver()->run(tavilySearchRequest());

    expect($result->content)
        ->toContain('&lt;script&gt;alert\\(1\\)&lt;/script&gt; \\[answer\\]\\(https://evil\\.example\\)')
        ->toContain('### [Unsafe \\!\\[pixel\\]\\(https://tracker\\.example/pixel\\)](https://example.com/path_%28one%29?next=a%28b%29)')
        ->toContain('&lt;img src\\=&quot;https://tracker\\.example/pixel&quot;&gt; \\# heading')
        ->not->toContain('<script>')
        ->not->toContain('<img')
        ->not->toContain('Duplicate')
        ->not->toContain('Credentials')
        ->and($result->citations)->toHaveCount(1)
        ->and($result->citations->sole()->source->url)->toBe('https://example.com/path_(one)?next=a(b)')
        ->and((array) $result->providerMeta)->toBe(['result_count' => 6]);
});

it('reports only valid provider credit metering without inventing token or dollar usage', function (mixed $credits, ?int $expected): void {
    Http::fake(['https://api.tavily.com/search' => Http::response([
        'results' => [],
        'usage' => ['credits' => $credits],
    ])]);

    $result = tavilySearchDriver()->run(tavilySearchRequest());

    expect($result->usage)->toBeNull()
        ->and((array) $result->providerMeta)->toBe(array_filter(
            ['result_count' => 0, 'credits' => $expected],
            static fn (mixed $value): bool => $value !== null,
        ));
})->with([
    'advanced search credits' => [2, 2],
    'zero is valid provider metering' => [0, 0],
    'numeric string is not a provider integer' => ['2', null],
    'fraction is invalid' => [1.5, null],
    'negative is invalid' => [-1, null],
    'missing is omitted' => [null, null],
]);

it('rejects malformed successful responses safely', function (mixed $body): void {
    Http::fake(['https://api.tavily.com/search' => Http::response($body)]);

    expect(fn () => tavilySearchDriver()->run(tavilySearchRequest()))
        ->toThrow(DriverException::class, 'Tavily returned a malformed response.');
})->with([
    'non-json body' => ['provider-secret raw body'],
    'top-level list' => [[]],
    'missing results' => [['answer' => 'answer only field']],
    'results object' => [['results' => ['url' => 'https://example.com']]],
]);

it('rejects a missing credential and an expired deadline without a request', function (string $case): void {
    Http::fake();
    $request = $case === 'credential'
        ? tavilySearchRequest(credential: null)
        : tavilySearchRequest(deadline: CarbonImmutable::now()->subSecond());

    try {
        tavilySearchDriver()->run($request);
        $this->fail('The Tavily request should not start.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($case === 'credential' ? 'tavily.authentication' : 'tavily.deadline_exceeded');
    }

    Http::assertNothingSent();
})->with(['credential', 'deadline']);

it('rejects a response that arrives at the deadline before mapping it', function (): void {
    $deadline = CarbonImmutable::now()->addSeconds(2);
    Http::fake(function () use ($deadline) {
        CarbonImmutable::setTestNow($deadline);

        return Http::response(['results' => []]);
    });

    expect(fn () => tavilySearchDriver()->run(tavilySearchRequest(deadline: $deadline)))
        ->toThrow(DriverException::class, 'Tavily did not respond before the research deadline.');
    Http::assertSentCount(1);
});

it('maps connection failures without exposing transport details', function (bool $deadlineExpires, string $code): void {
    Http::fake(function () use ($deadlineExpires): never {
        if ($deadlineExpires) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));
        }

        throw new ConnectionException('cURL testing-tavily-key provider-secret details');
    });

    try {
        tavilySearchDriver()->run(tavilySearchRequest());
        $this->fail('The transport failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('testing-tavily-key')
            ->not->toContain('provider-secret');
    }
})->with([
    'connection before deadline' => [false, 'tavily.connection'],
    'connection at deadline' => [true, 'tavily.deadline_exceeded'],
]);

it('maps documented and defensive HTTP failures to bounded body-free errors', function (int $status, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://api.tavily.com/search' => Http::response([
        'detail' => ['error' => 'testing-tavily-key provider-secret raw body'],
    ], $status)]);

    try {
        tavilySearchDriver()->run(tavilySearchRequest());
        $this->fail('The provider failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('testing-tavily-key')
            ->not->toContain('provider-secret')
            ->not->toContain('raw body')
            ->and(strlen($exception->getMessage()))->toBeLessThanOrEqual(128);
    }
})->with([
    'timeout' => [408, 'tavily.timeout', true],
    'authentication' => [401, 'tavily.authentication', true],
    'authorization' => [403, 'tavily.authorization', true],
    'plan limit' => [432, 'tavily.quota_exceeded', true],
    'pay as you go limit' => [433, 'tavily.quota_exceeded', true],
    'rate limit' => [429, 'tavily.rate_limited', true],
    'unavailable' => [500, 'tavily.unavailable', true],
    'invalid request' => [400, 'tavily.invalid_request', false],
]);

it('redacts payload errors and allowlists terminal provider metadata', function (): void {
    Http::fakeSequence()
        ->push(['results' => [], 'error' => 'testing-tavily-key provider-secret raw body'])
        ->push([
            'results' => [],
            'usage' => ['credits' => 2, 'account' => 'provider-secret'],
            'request_id' => 'provider-secret',
            'headers' => ['Authorization' => 'Bearer testing-tavily-key'],
            'debug' => ['raw_body' => 'provider-secret'],
        ]);

    try {
        tavilySearchDriver()->run(tavilySearchRequest());
        $this->fail('The payload error should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('tavily.provider')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }

    $wire = json_encode(tavilySearchDriver()->run(tavilySearchRequest())->toArray(), JSON_THROW_ON_ERROR);

    expect($wire)->toContain('"credits":2')
        ->not->toContain('testing-tavily-key')
        ->not->toContain('provider-secret')
        ->not->toContain('raw_body')
        ->not->toContain('headers')
        ->not->toContain('request_id');
    Http::assertSentCount(2);
});

function tavilySearchDriver(): TavilySearchDriver
{
    return app(TavilySearchDriver::class);
}

function tavilySearchRequest(
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-tavily-key',
): DriverRequest {
    $requested = tavilyProfile('requested-search', $credential);
    $effective = tavilyProfile('tavily-search', $credential);

    return new DriverRequest(
        requestId: 'request-1',
        requestedProfile: $requested,
        profile: $effective,
        prompt: 'best ramen in Vancouver',
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}

function tavilyProfile(string $id, ?string $credential): Profile
{
    return new Profile(
        id: $id,
        driver: TavilySearchDriver::class,
        provider: 'tavily',
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
    );
}
