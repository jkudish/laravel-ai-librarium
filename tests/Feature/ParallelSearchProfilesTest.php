<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Parallel\ParallelSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Parallel\ParallelTurboDriver;
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

it('sends Search controls and maps ranked evidence asymmetrically without synthesizing an answer', function (): void {
    Http::fake(['https://api.parallel.ai/v1/search' => Http::response(parallelSearchResponse())]);

    $result = app(ParallelSearchDriver::class)->run(parallelSearchRequest(options: [
        'objective' => 'A self-contained objective.',
        'search_queries' => ['first query', 'second query'],
        'mode' => 'advanced',
        'max_chars_total' => 500,
        'max_chars_per_result' => 120,
        'max_results' => 2,
        'location' => 'ca',
        'source_policy' => [
            'include_domains' => ['Docs.Example.com/php'],
            'exclude_domains' => ['blocked.example'],
            'after_date' => '2026-01-01',
        ],
        'fetch_policy' => [
            'max_age_seconds' => 600,
            'timeout_seconds' => 2.5,
            'disable_cache_fallback' => true,
        ],
    ]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.parallel.ai/v1/search'
        && $request->hasHeader('x-api-key', 'testing-parallel-key')
        && ! $request->hasHeader('Authorization')
        && $request->data() === [
            'objective' => 'A self-contained objective.',
            'search_queries' => ['first query', 'second query'],
            'mode' => 'advanced',
            'max_chars_total' => 500,
            'advanced_settings' => [
                'source_policy' => [
                    'include_domains' => ['docs.example.com/php'],
                    'exclude_domains' => ['blocked.example'],
                    'after_date' => '2026-01-01',
                ],
                'fetch_policy' => [
                    'max_age_seconds' => 600,
                    'timeout_seconds' => 2.5,
                    'disable_cache_fallback' => true,
                ],
                'excerpt_settings' => ['max_chars_per_result' => 120],
                'location' => 'CA',
                'max_results' => 2,
            ],
        ]);
    Http::assertSentCount(1);

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toContain('### 1. First \\[result\\] &lt;script&gt;')
        ->and($result->content)->toContain('First evidence\\.')
        ->and($result->content)->not->toContain('Second evidence must not replace the first.')
        ->and($result->requestedProfile)->toBe('parallel-search')
        ->and($result->provider)->toBe('parallel')
        ->and($result->profile)->toBe('parallel-search')
        ->and($result->model)->toBeNull()
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->citations)->toHaveCount(2)
        ->and($result->citations->first()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->first()->excerpt)->toBe('First evidence.')
        ->and($result->usage)->toBeNull()
        ->and(data_get($result->providerMeta, 'parallel:search'))->toBe([
            'mode' => 'advanced',
            'result_count' => 2,
            'warning_count' => 1,
            'metering' => [['name' => 'sku_search', 'count' => 1]],
        ]);

    expect(json_encode($result->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('search_private_1')
        ->not->toContain('session_private_1')
        ->not->toContain('provider-secret')
        ->not->toContain('testing-parallel-key');
});

it('locks Turbo to mode turbo while keeping its exact profile identity', function (): void {
    Http::fake(['https://api.parallel.ai/v1/search' => Http::response(parallelSearchResponse())]);

    $result = app(ParallelTurboDriver::class)->run(parallelSearchRequest(
        profileId: 'parallel-turbo',
        options: ['max_results' => 1],
    ));

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'objective' => 'Find current Parallel API information.',
        'search_queries' => ['Find current Parallel API information.'],
        'mode' => 'turbo',
        'advanced_settings' => ['max_results' => 1],
    ]);
    expect($result->profile)->toBe('parallel-turbo')
        ->and($result->provider)->toBe('parallel')
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->citations)->toHaveCount(2)
        ->and($result->usage)->toBeNull()
        ->and(data_get($result->providerMeta, 'parallel:search'))->toBe([
            'mode' => 'turbo',
            'result_count' => 2,
            'warning_count' => 1,
            'metering' => [['name' => 'sku_search', 'count' => 1]],
        ]);
    expect(json_encode($result->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('search_private_1')
        ->not->toContain('session_private_1')
        ->not->toContain('provider-secret')
        ->not->toContain('testing-parallel-key');
});

it('accepts source paths for Search but rejects paths and mode overrides for Turbo before HTTP', function (): void {
    Http::fake(['https://api.parallel.ai/v1/search' => Http::response(parallelSearchResponse(results: []))]);
    app(ParallelSearchDriver::class)->run(parallelSearchRequest(options: [
        'source_policy' => ['include_domains' => ['docs.example.com/php']],
    ]));

    foreach ([
        ['source_policy' => ['include_domains' => ['docs.example.com/php']]],
        ['mode' => 'advanced'],
        ['max_results' => 11],
    ] as $options) {
        expect(fn () => app(ParallelTurboDriver::class)->run(parallelSearchRequest(
            profileId: 'parallel-turbo',
            options: $options,
        )))->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('parallel.invalid_options')
                ->and($exception->fallbackAllowed)->toBeFalse();
        });
    }

    Http::assertSentCount(1);
});

it('rejects credentials, profile drift, unsupported controls, and expired deadlines before HTTP', function (DriverRequest $request, string $code, bool $fallbackAllowed): void {
    Http::fake();

    expect(fn () => app(ParallelSearchDriver::class)->run($request))
        ->toThrow(function (DriverException $exception) use ($code, $fallbackAllowed): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBe($fallbackAllowed);
        });
    Http::assertNothingSent();
})->with([
    'missing credential' => fn () => [parallelSearchRequest(credential: null), 'parallel.authentication', true],
    'Turbo missing credential' => fn () => [parallelSearchRequest(profileId: 'parallel-turbo', credential: null), 'parallel.authentication', true],
    'wrong provider' => fn () => [parallelSearchRequest(provider: 'parallel-search'), 'parallel.invalid_profile', false],
    'wrong result kind' => fn () => [parallelSearchRequest(resultKind: ResultKind::GroundedAnswer), 'parallel.invalid_profile', false],
    'unknown option' => fn () => [parallelSearchRequest(options: ['unknown' => true]), 'parallel.invalid_options', false],
    'combined source limit' => fn () => [parallelSearchRequest(options: ['source_policy' => [
        'include_domains' => array_fill(0, 200, 'example.com'),
        'exclude_domains' => ['blocked.example'],
    ]]), 'parallel.invalid_options', false],
    'expired deadline' => fn () => [parallelSearchRequest(deadline: CarbonImmutable::now()), 'parallel.deadline_exceeded', false],
]);

it('fails closed on malformed successful Search payloads', function (mixed $payload): void {
    Http::fake(['https://api.parallel.ai/v1/search' => Http::response($payload)]);

    expect(fn () => app(ParallelSearchDriver::class)->run(parallelSearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('parallel.invalid_response')
                ->and($exception->getMessage())->not->toContain('provider-secret');
        });
})->with([
    'non-object' => ['provider-secret'],
    'missing identity' => [['results' => []]],
    'result without excerpts' => [parallelSearchResponse(results: [['url' => 'https://example.com']])],
    'unsafe result URL' => [parallelSearchResponse(results: [['url' => 'https://user:pass@example.com', 'excerpts' => []]])],
    'signed result URL' => [parallelSearchResponse(results: [['url' => 'https://signed.example/report?X-Goog-Signature=signed-secret', 'excerpts' => []]])],
    'invalid metering' => [parallelSearchResponse(usage: [['name' => 'sku_search', 'count' => -1]])],
]);

it('maps HTTP and connection failures to bounded body-free errors', function (int|string $failure, string $code, bool $fallbackAllowed): void {
    Http::fake(static function () use ($failure) {
        if ($failure === 'connection') {
            throw new ConnectionException('provider-secret testing-parallel-key');
        }

        return Http::response(['error' => ['message' => 'provider-secret testing-parallel-key']], $failure);
    });

    expect(fn () => app(ParallelSearchDriver::class)->run(parallelSearchRequest()))
        ->toThrow(function (DriverException $exception) use ($code, $fallbackAllowed): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('testing-parallel-key');
        });
})->with([
    'authentication' => [401, 'parallel.authentication', true],
    'credit' => [402, 'parallel.insufficient_credit', true],
    'authorization' => [403, 'parallel.authorization', true],
    'timeout' => [408, 'parallel.timeout', true],
    'rate limit' => [429, 'parallel.rate_limited', true],
    'unavailable' => [503, 'parallel.unavailable', true],
    'invalid request' => [422, 'parallel.invalid_request', false],
    'connection' => ['connection', 'parallel.connection', true],
]);

it('labels omitted Search mode as provider default rather than a concrete billable mode', function (): void {
    Http::fake(['https://api.parallel.ai/v1/search' => Http::response(parallelSearchResponse())]);

    $result = app(ParallelSearchDriver::class)->run(parallelSearchRequest());

    expect(data_get($result->providerMeta, 'parallel:search.mode'))->toBe('provider_default');
});

/**
 * @param  list<array<string, mixed>>|null  $results
 * @param  list<array<string, mixed>>|null  $usage
 * @return array<string, mixed>
 */
function parallelSearchResponse(?array $results = null, ?array $usage = null): array
{
    return [
        'search_id' => 'search_private_1',
        'session_id' => 'session_private_1',
        'results' => $results ?? [
            [
                'url' => 'https://example.com/first_(one)',
                'title' => 'First [result] <script>',
                'publish_date' => '2026-09-09',
                'excerpts' => ['First evidence.', 'Second evidence must not replace the first.'],
            ],
            [
                'url' => 'https://example.org/second',
                'title' => null,
                'publish_date' => null,
                'excerpts' => [],
            ],
        ],
        'warnings' => [[
            'type' => 'warning',
            'message' => 'provider-secret is counted but not retained',
            'detail' => ['raw' => 'provider-secret'],
        ]],
        'usage' => $usage ?? [['name' => 'sku_search', 'count' => 1]],
        'raw' => ['credential' => 'testing-parallel-key'],
    ];
}

/** @param array<string, mixed> $options */
function parallelSearchRequest(
    string $profileId = 'parallel-search',
    array $options = [],
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-parallel-key',
    string $provider = 'parallel',
    ResultKind $resultKind = ResultKind::SearchResults,
): DriverRequest {
    $profile = new Profile(
        id: $profileId,
        driver: $profileId === 'parallel-turbo' ? ParallelTurboDriver::class : ParallelSearchDriver::class,
        provider: $provider,
        model: null,
        resultKind: $resultKind,
        grounding: GroundingPolicy::None,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::SearchEndpoint]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'parallel-search-request',
        requestedProfile: $profile,
        profile: $profile,
        prompt: 'Find current Parallel API information.',
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
