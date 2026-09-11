<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Perplexity\PerplexitySearchDriver;
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
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10T12:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('sends one direct raw Search request with bearer credentials and defaults', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($options['allow_redirects'] ?? null)->toBeFalse();

        return Http::response(perplexitySearchResponse([]));
    });

    perplexitySearchDriver()->run(perplexitySearchRequest());

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.perplexity.ai/search'
            && $request->hasHeader('Authorization', 'Bearer testing-perplexity-key')
            && $request->data() === ['query' => 'current Laravel AI news', 'max_results' => 10]
            && ! str_contains($request->url(), 'testing-perplexity-key');
    });
    Http::assertSentCount(1);
});

it('maps all supported options to one bounded multi-query request', function (): void {
    Http::fake(['https://api.perplexity.ai/search' => Http::response(perplexitySearchResponse([]))]);

    perplexitySearchDriver()->run(perplexitySearchRequest(options: [
        'max_results' => 20,
        'country' => ' ca ',
        'search_language_filter' => [' EN ', 'fr'],
        'search_domain_allowlist' => [' docs.perplexity.ai ', 'example.test'],
        'max_tokens' => 50_000,
        'max_tokens_per_page' => 4_096,
        'additional_queries' => ['  another perspective  ', 'current Laravel AI news', 'another perspective', 'third'],
    ]));

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'query' => ['current Laravel AI news', 'another perspective', 'third'],
        'max_results' => 20,
        'country' => 'CA',
        'max_tokens' => 50_000,
        'max_tokens_per_page' => 4_096,
        'search_language_filter' => ['en', 'fr'],
        'search_domain_filter' => ['docs.perplexity.ai', 'example.test'],
    ]);
    Http::assertSentCount(1);
});

it('maps context size and denylist through their distinct Search API fields', function (): void {
    Http::fake(['https://api.perplexity.ai/search' => Http::response(perplexitySearchResponse([]))]);

    perplexitySearchDriver()->run(perplexitySearchRequest(options: [
        'search_context_size' => 'high',
        'search_domain_denylist' => [' spam.example ', 'ads.example'],
    ]));

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'query' => 'current Laravel AI news',
        'max_results' => 10,
        'search_context_size' => 'high',
        'search_domain_filter' => ['-spam.example', '-ads.example'],
    ]);
});

it('normalizes ordered raw results asymmetrically and reports no invented metering', function (): void {
    Http::fake(['https://api.perplexity.ai/search' => Http::response(perplexitySearchResponse([
        [
            'title' => 'First [result] <script>',
            'url' => 'https://www.example.com/article?utm_source=private',
            'snippet' => str_repeat('A', 550),
            'date' => '2026-09-09',
            'last_updated' => '2026-09-10',
            'signed_url' => 'https://private.example/signed?token=secret',
        ],
        [
            'title' => 'Duplicate',
            'url' => 'http://example.com/article',
            'snippet' => 'must be removed',
        ],
        [
            'url' => 'https://example.org/untitled',
            'snippet' => 'Second result.',
        ],
        ['title' => 'Unsafe', 'url' => 'file:///etc/passwd', 'snippet' => 'private'],
        'not-an-object',
    ]))]);

    $result = perplexitySearchDriver()->run(perplexitySearchRequest(options: ['max_results' => 5]));

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toContain('First \\[result\\] &lt;script&gt;')
        ->and($result->content)->toContain('- **[Untitled](https://example.org/untitled)**')
        ->and($result->content)->not->toContain('Duplicate')
        ->and($result->citations)->toHaveCount(2)
        ->and($result->citations->first()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->first()->source->kind)->toBe(SourceKind::WebPage)
        ->and($result->citations->first()->source->url)->toBe('https://www.example.com/article?utm_source=private')
        ->and(mb_strlen((string) $result->citations->first()->excerpt))->toBe(500)
        ->and($result->requestedProfile)->toBe('perplexity-search')
        ->and($result->provider)->toBe('perplexity')
        ->and($result->profile)->toBe('perplexity-search')
        ->and($result->model)->toBeNull()
        ->and($result->usage)->toBeNull()
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and((array) $result->providerMeta)->toBe(['result_count' => 2]);

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('search_private_1')
        ->not->toContain('testing-perplexity-key')
        ->not->toContain('signed_url')
        ->not->toContain('private.example')
        ->not->toContain('token=secret')
        ->not->toContain('/etc/passwd');
});

it('preserves legacy no-options snippet limits and empty results', function (): void {
    Http::fakeSequence()
        ->push(perplexitySearchResponse([[
            'url' => 'https://example.test/result',
            'title' => 'Result',
            'snippet' => str_repeat('x', 350),
        ]]))
        ->push(perplexitySearchResponse([]));

    $result = perplexitySearchDriver()->run(perplexitySearchRequest());
    $empty = perplexitySearchDriver()->run(perplexitySearchRequest());

    expect(mb_substr((string) $result->content, -300))->toBe(str_repeat('x', 300))
        ->and($result->citations->sole()->excerpt)->toBe(str_repeat('x', 200))
        ->and($empty->content)->toBe('No results found.')
        ->and($empty->citations)->toBeEmpty()
        ->and($empty->usage)->toBeNull();
});

it('rejects invalid local options and profile semantics before HTTP', function (array $options, array $overrides, string $code): void {
    Http::fake();

    try {
        perplexitySearchDriver()->run(perplexitySearchRequest(...['options' => $options, ...$overrides]));
        $this->fail('Invalid local Search input must not reach Perplexity.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('testing-perplexity-key');
    }

    Http::assertNothingSent();
})->with([
    'missing credentials' => [[], ['credential' => null], 'perplexity_search.authentication'],
    'empty query' => [[], ['prompt' => ' '], 'perplexity_search.invalid_request'],
    'unknown option' => [['unknown' => true], [], 'perplexity_search.invalid_request'],
    'too many results' => [['max_results' => 21], [], 'perplexity_search.invalid_request'],
    'invalid country' => [['country' => 'ZZ'], [], 'perplexity_search.invalid_request'],
    'invalid language' => [['search_language_filter' => ['zz']], [], 'perplexity_search.invalid_request'],
    'domain modes conflict' => [['search_domain_allowlist' => ['allow.test'], 'search_domain_denylist' => ['deny.test']], [], 'perplexity_search.invalid_request'],
    'context and token conflict' => [['search_context_size' => 'low', 'max_tokens' => 10], [], 'perplexity_search.invalid_request'],
    'too many queries' => [['additional_queries' => ['a', 'b', 'c', 'd', 'e']], [], 'perplexity_search.invalid_request'],
    'wrong result kind' => [[], ['resultKind' => ResultKind::GroundedAnswer], 'perplexity_search.invalid_profile'],
    'wrong grounding' => [[], ['grounding' => GroundingPolicy::Required], 'perplexity_search.invalid_profile'],
]);

it('maps Search HTTP and transport failures without retaining response bodies', function (mixed $response, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://api.perplexity.ai/search' => $response]);

    try {
        perplexitySearchDriver()->run(perplexitySearchRequest());
        $this->fail('The Search failure must be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('private.example');
    }

    Http::assertSentCount(1);
})->with([
    'authentication' => [fn () => Http::response(['error' => 'Bearer provider-secret'], 401), 'perplexity_search.authentication', true],
    'redirect' => [fn () => Http::response(['results' => []], 302, ['Location' => 'https://private.example']), 'perplexity_search.invalid_request', false],
    'billing' => [fn () => Http::response(['error' => 'provider-secret'], 402), 'perplexity_search.billing', true],
    'plan' => [fn () => Http::response(['url' => 'https://private.example'], 403), 'perplexity_search.plan_required', true],
    'timeout' => [fn () => Http::response(['error' => 'provider-secret'], 408), 'perplexity_search.timeout', true],
    'rate limit' => [fn () => Http::response(['error' => 'provider-secret'], 429), 'perplexity_search.rate_limited', true],
    'unavailable' => [fn () => Http::response(['error' => 'provider-secret'], 503), 'perplexity_search.unavailable', true],
    'invalid request' => [fn () => Http::response(['error' => 'provider-secret'], 422), 'perplexity_search.invalid_request', false],
]);

it('maps malformed responses, connection failures, and deadline exhaustion safely', function (): void {
    Http::fake(['https://api.perplexity.ai/search' => Http::response(['id' => 'search_private_1'])]);
    expect(fn () => perplexitySearchDriver()->run(perplexitySearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('perplexity_search.invalid_response');
        });

    Http::fake(fn (): never => throw new ConnectionException('Bearer provider-secret https://private.example'));
    expect(fn () => perplexitySearchDriver()->run(perplexitySearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('perplexity_search.connection')
                ->and($exception->getMessage())->not->toContain('provider-secret');
        });

    Http::fake();
    expect(fn () => perplexitySearchDriver()->run(perplexitySearchRequest(deadline: CarbonImmutable::now())))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('perplexity_search.deadline_exceeded');
        });
    Http::assertNothingSent();
});

function perplexitySearchDriver(): PerplexitySearchDriver
{
    return new PerplexitySearchDriver;
}

function perplexitySearchRequest(
    array $options = [],
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-perplexity-key',
    string $prompt = 'current Laravel AI news',
    ResultKind $resultKind = ResultKind::SearchResults,
    GroundingPolicy $grounding = GroundingPolicy::None,
): DriverRequest {
    $profile = new Profile(
        id: 'perplexity-search',
        driver: PerplexitySearchDriver::class,
        provider: 'perplexity',
        model: null,
        resultKind: $resultKind,
        grounding: $grounding,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::SearchEndpoint]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}

/** @param list<mixed> $results */
function perplexitySearchResponse(array $results): array
{
    return [
        'id' => 'search_private_1',
        'results' => $results,
        'server_time' => 'provider-secret',
        'raw' => ['authorization' => 'Bearer testing-perplexity-key'],
    ];
}
