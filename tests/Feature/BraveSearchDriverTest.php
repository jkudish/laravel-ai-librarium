<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveSearchDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileOptionValidator;
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

it('sends exactly one raw web search GET with only the query and Search credential', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($options['connect_timeout'])->toBe(3.0)
            ->and($options['timeout'])->toBe(20.0)
            ->and($options['allow_redirects'] ?? null)->toBeFalse();

        return Http::response(['web' => ['results' => []]]);
    });

    braveSearchDriver()->run(braveSearchRequest(prompt: 'PHP & Laravel / search?'));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.search.brave.com/res/v1/web/search?q=PHP%20%26%20Laravel%20%2F%20search%3F'
            && $request->hasHeader('X-Subscription-Token', 'testing-brave-search-secret')
            && $request->hasHeader('Accept', 'application/json')
            && ! $request->hasHeader('Authorization')
            && $request->data() === ['q' => 'PHP & Laravel / search?']
            && ! str_contains($request->url(), 'testing-brave-search-secret');
    });
    Http::assertSentCount(1);
});

it('accepts no provider options', function (): void {
    expect(app(ProfileOptionValidator::class)->errors(BraveSearchDriver::class, []))->toBe([])
        ->and(app(ProfileOptionValidator::class)->errors(BraveSearchDriver::class, ['count' => 1]))->not->toBe([]);
});

it('maps asymmetric web results without swapping titles snippets or URLs', function (): void {
    Http::fake(['https://api.search.brave.com/res/v1/web/search*' => Http::response([
        'query' => ['original' => 'ignored-provider-query'],
        'web' => ['results' => [
            [
                'title' => 'Alpha [title]',
                'url' => 'https://alpha.example/path_(one)',
                'description' => 'Alpha snippet with *markup*.',
            ],
            [
                'title' => 'Beta title',
                'url' => 'http://beta.example/two?next=a(b)',
                'description' => 'Distinct beta snippet.',
            ],
        ]],
    ])]);

    $result = braveSearchDriver()->run(braveSearchRequest());

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe(
            "- **[Alpha \\[title\\]](https://alpha.example/path_%28one%29)**\n"
            ."  Alpha snippet with \\*markup\\*\\.\n"
            ."- **[Beta title](http://beta.example/two?next=a%28b%29)**\n"
            .'  Distinct beta snippet\\.',
        )
        ->and($result->requestedProfile)->toBe('brave-search')
        ->and($result->provider)->toBe('brave-search')
        ->and($result->profile)->toBe('brave-search')
        ->and($result->model)->toBeNull()
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://alpha.example/path_(one)',
            'http://beta.example/two?next=a(b)',
        ])
        ->and($result->citations->pluck('source.title')->all())->toBe(['Alpha [title]', 'Beta title'])
        ->and($result->citations->pluck('excerpt')->all())->toBe([
            'Alpha snippet with *markup*.',
            'Distinct beta snippet.',
        ])
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported])
        ->and($result->usage)->toBeNull()
        ->and((array) $result->providerMeta)->toBe(['result_count' => 2]);
});

it('preserves URL-only partial results and omits missing optional fields', function (): void {
    Http::fake(['https://api.search.brave.com/res/v1/web/search*' => Http::response([
        'web' => ['results' => [
            ['url' => 'https://untitled.example/result'],
            ['url' => 'https://title-only.example/result', 'title' => 'Title only'],
            ['url' => 'https://snippet-only.example/result', 'description' => 'Snippet only'],
        ]],
    ])]);

    $result = braveSearchDriver()->run(braveSearchRequest());

    expect($result->content)->toBe(
        "- **[Untitled](https://untitled.example/result)**\n"
        ."- **[Title only](https://title-only.example/result)**\n"
        ."- **[Untitled](https://snippet-only.example/result)**\n"
        .'  Snippet only',
    )
        ->and($result->citations->pluck('source.title')->all())->toBe([null, 'Title only', null])
        ->and($result->citations->pluck('excerpt')->all())->toBe([null, null, 'Snippet only']);
});

it('rejects untrusted URLs while preserving duplicate valid search results', function (): void {
    Http::fake(['https://api.search.brave.com/res/v1/web/search*' => Http::response([
        'web' => ['results' => [
            ['title' => 'Keep first', 'url' => 'https://example.com/same'],
            ['title' => 'Keep duplicate', 'url' => 'https://example.com/same'],
            ['title' => 'Credentials', 'url' => 'https://user:pass@example.com/private'],
            ['title' => 'Script', 'url' => 'javascript:alert(1)'],
            ['title' => 'Relative', 'url' => '/relative'],
            ['title' => 'Whitespace', 'url' => ' https://example.com/padded'],
            ['title' => 'Missing URL', 'description' => 'must not render'],
            'not-an-object',
        ]],
    ])]);

    $result = braveSearchDriver()->run(braveSearchRequest());

    expect($result->content)->toBe(
        "- **[Keep first](https://example.com/same)**\n"
        .'- **[Keep duplicate](https://example.com/same)**',
    )
        ->and($result->citations)->toHaveCount(2)
        ->and((array) $result->providerMeta)->toBe(['result_count' => 2]);
});

it('maps absent web results to an empty SearchResult without inventing metering', function (array $payload): void {
    Http::fake(['https://api.search.brave.com/res/v1/web/search*' => Http::response($payload)]);

    $result = braveSearchDriver()->run(braveSearchRequest());

    expect($result->content)->toBe('No results found.')
        ->and($result->citations)->toBeEmpty()
        ->and($result->usage)->toBeNull()
        ->and((array) $result->providerMeta)->toBe(['result_count' => 0]);
})->with([
    'missing web object' => [['query' => ['original' => 'test']]],
    'missing results list' => [['web' => ['type' => 'search']]],
    'null results list' => [['web' => ['results' => null]]],
]);

it('rejects malformed successful responses safely', function (array|string|null $payload): void {
    Http::fake(['https://api.search.brave.com/res/v1/web/search*' => Http::response($payload)]);

    try {
        braveSearchDriver()->run(braveSearchRequest());
        throw new RuntimeException('The malformed Brave Search response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('brave_search.invalid_response')
            ->and($exception->getMessage())->toBe('Brave Search returned a malformed response.')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'non-json body' => ['provider-secret raw body'],
    'top-level list' => [[]],
    'results object' => [['web' => ['results' => ['url' => 'https://example.com']]]],
]);

it('rejects missing credentials incompatible profiles and expired deadlines before dispatch', function (DriverRequest $request, string $code): void {
    Http::fake();

    try {
        braveSearchDriver()->run($request);
        throw new RuntimeException('The Brave Search request should not start.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'missing Search credential' => fn () => [braveSearchRequest(credential: null), 'brave_search.authentication'],
    'Answers provider is distinct' => fn () => [braveSearchRequest(provider: 'brave'), 'brave_search.invalid_profile'],
    'Answers result semantics are distinct' => fn () => [braveSearchRequest(resultKind: ResultKind::GroundedAnswer), 'brave_search.invalid_profile'],
    'model is not accepted' => fn () => [braveSearchRequest(model: 'brave'), 'brave_search.invalid_profile'],
    'expired deadline' => fn () => [braveSearchRequest(deadline: CarbonImmutable::now()), 'brave_search.deadline_exceeded'],
]);

it('maps connection and HTTP failures to safe body-free exceptions', function (mixed $fake, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://api.search.brave.com/res/v1/web/search*' => $fake]);

    try {
        braveSearchDriver()->run(braveSearchRequest());
        throw new RuntimeException('The Brave Search request should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('testing-brave-search-secret');
    }
})->with([
    'connection' => [fn () => throw new ConnectionException('provider-secret transport detail'), 'brave_search.connection', true],
    'redirect' => [fn () => Http::response(['web' => ['results' => []]], 302, ['Location' => 'https://provider-secret.example']), 'brave_search.invalid_request', false],
    'invalid request' => [fn () => Http::response(['error' => ['detail' => 'provider-secret']], 422), 'brave_search.invalid_request', false],
    'authentication' => [fn () => Http::response(['error' => ['detail' => 'provider-secret']], 401), 'brave_search.authentication', true],
    'forbidden credential' => [fn () => Http::response(['error' => ['detail' => 'provider-secret']], 403), 'brave_search.authentication', true],
    'billing' => [fn () => Http::response(['error' => ['detail' => 'provider-secret']], 402), 'brave_search.billing', true],
    'timeout' => [fn () => Http::response(['error' => ['detail' => 'provider-secret']], 408), 'brave_search.deadline_exceeded', true],
    'rate limited' => [fn () => Http::response(['error' => ['detail' => 'provider-secret']], 429), 'brave_search.rate_limited', true],
    'unavailable' => [fn () => Http::response(['error' => ['detail' => 'provider-secret']], 503), 'brave_search.unavailable', true],
]);

it('discards late responses and maps deadline-exhausting connections', function (string $case): void {
    Http::fake(function () use ($case) {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));

        if ($case === 'connection') {
            throw new ConnectionException('provider-secret timeout detail');
        }

        return Http::response(['web' => ['results' => [['url' => 'https://provider-secret.example']]]]);
    });

    try {
        braveSearchDriver()->run(braveSearchRequest());
        throw new RuntimeException('The late Brave Search request should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('brave_search.deadline_exceeded')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with(['response', 'connection']);

it('allowlists terminal fields and never retains credentials or raw provider metadata', function (): void {
    Http::fake(['https://api.search.brave.com/res/v1/web/search*' => Http::response([
        'web' => ['results' => []],
        'api_key' => 'testing-brave-search-secret',
        'headers' => ['X-Subscription-Token' => 'testing-brave-search-secret'],
        'raw_body' => 'provider-secret raw body',
        'debug' => ['credential' => 'provider-secret'],
    ])]);

    $wire = json_encode(braveSearchDriver()->run(braveSearchRequest())->toArray(), JSON_THROW_ON_ERROR);

    expect($wire)->not->toContain('testing-brave-search-secret');
    expect($wire)->not->toContain('provider-secret');
    expect($wire)->not->toContain('api_key');
    expect($wire)->not->toContain('headers');
    expect($wire)->not->toContain('raw_body');
    expect($wire)->not->toContain('debug');
});

function braveSearchDriver(): BraveSearchDriver
{
    return app(BraveSearchDriver::class);
}

function braveSearchRequest(
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-brave-search-secret',
    string $prompt = 'asymmetric mapping query',
    string $provider = 'brave-search',
    ?string $model = null,
    ResultKind $resultKind = ResultKind::SearchResults,
): DriverRequest {
    /** @var Collection<int, Corpus> $corpora */
    $corpora = collect([Corpus::Web]);
    /** @var Collection<int, RetrievalMethod> $retrievalMethods */
    $retrievalMethods = collect([RetrievalMethod::SearchEndpoint]);

    $profile = new Profile(
        id: 'brave-search',
        driver: BraveSearchDriver::class,
        provider: $provider,
        model: $model,
        resultKind: $resultKind,
        grounding: GroundingPolicy::None,
        observation: ObservationMode::ApiOutput,
        corpora: $corpora,
        retrievalMethods: $retrievalMethods,
        prompt: '{{ query }}',
        enabled: true,
        options: [],
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-brave-search-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
