<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuSearchDriver;
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
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10T12:00:00Z');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('sends every accepted Search control and preserves specialized citation provenance', function (): void {
    $transport = null;
    Http::globalMiddleware(function (callable $handler) use (&$transport): Closure {
        return function ($request, array $options) use ($handler, &$transport) {
            $transport = $options;

            return $handler($request, $options);
        };
    });
    $options = [
        'max_results' => 7,
        'search_type' => 'proprietary',
        'max_price' => 20,
        'relevance_threshold' => 0.7,
        'included_sources' => ['valyu/valyu-arxiv'],
        'source_biases' => ['arxiv.org' => 5],
        'instructions' => 'Prioritize methods.',
        'is_tool_call' => false,
        'response_length' => 'medium',
        'start_date' => '2026-01-01',
        'end_date' => '2026-08-01',
        'country_code' => 'CA',
    ];
    Http::fake(['https://api.valyu.ai/v1/search' => Http::response([
        'success' => true,
        'tx_id' => 'private-transaction-handle',
        'results' => [[
            'id' => 'valyu/valyu-arxiv/2401.1',
            'title' => 'Paper',
            'url' => 'https://arxiv.org/abs/2401.1',
            'content' => 'Asymmetric evidence.',
            'source' => 'valyu/valyu-arxiv',
            'source_type' => 'paper',
            'publication_date' => '2026-08-01',
        ]],
        'results_by_source' => ['proprietary' => 1],
        'total_deduction_dollars' => 0.004,
        'total_characters' => 20,
        'warnings' => ['One provider warning.'],
    ], 206)]);

    $result = app(ValyuSearchDriver::class)->run(valyuSearchRequest(options: $options, corpora: [Corpus::Specialized]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.valyu.ai/v1/search'
        && $request->hasHeader('X-API-Key', 'testing-valyu-key')
        && ! $request->hasHeader('Authorization')
        && $request->data() === [
            'query' => 'quantum methods',
            'max_num_results' => 7,
            'search_type' => 'proprietary',
            'max_price' => 20,
            'relevance_threshold' => 0.7,
            'included_sources' => ['valyu/valyu-arxiv'],
            'source_biases' => ['arxiv.org' => 5],
            'instructions' => 'Prioritize methods.',
            'is_tool_call' => false,
            'response_length' => 'medium',
            'start_date' => '2026-01-01',
            'end_date' => '2026-08-01',
            'country_code' => 'CA',
        ]);
    Http::assertSentCount(1);

    expect($transport)->toBeArray()
        ->and($transport['allow_redirects'] ?? null)->toBeFalse()
        ->and($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toContain('Asymmetric evidence\\.')
        ->and($result->provider)->toBe('valyu')
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Specialized])
        ->and($result->citations)->toHaveCount(1)
        ->and($result->citations->sole()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->sole()->source->kind)->toBe(SourceKind::WebPage)
        ->and($result->citations->sole()->source->providerReference)->toBe('valyu/valyu-arxiv/2401.1')
        ->and($result->citations->sole()->source->publisher)->toBe('valyu/valyu-arxiv')
        ->and($result->usage?->actualCost)->toBe('0.004')
        ->and($result->usage?->currency)->toBe('USD')
        ->and((array) $result->providerMeta)->toBe([
            'result_count' => 1,
            'reported_result_count' => 1,
            'warning_count' => 1,
        ]);

    expect(json_encode($result->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('private-transaction-handle')
        ->not->toContain('testing-valyu-key')
        ->not->toContain('results_by_source');
});

it('maps web, news, all-corpus, and fast provenance without promoting categories to evidence', function (array $options, array $corpora, SourceKind $kind): void {
    Http::fake(['https://api.valyu.ai/v1/search' => Http::response([
        'success' => true,
        'results' => [[
            'url' => 'https://example.com/result',
            'title' => 'Result',
            'source_type' => 'news',
        ]],
    ])]);

    $result = app(ValyuSearchDriver::class)->run(valyuSearchRequest(options: $options, corpora: $corpora));

    expect($result->provenance->corpora->all())->toBe($corpora)
        ->and($result->citations->sole()->source->kind)->toBe($kind)
        ->and($result->citations)->toHaveCount(1);
})->with([
    'all' => [[], [Corpus::Web, Corpus::Specialized], SourceKind::NewsArticle],
    'web' => [['search_type' => 'web'], [Corpus::Web], SourceKind::NewsArticle],
    'news' => [['search_type' => 'news'], [Corpus::News], SourceKind::NewsArticle],
    'fast all' => [['fast_mode' => true], [Corpus::Web], SourceKind::NewsArticle],
]);

it('normalizes URL-only results, filters credential-bearing URLs, and invents no usage', function (): void {
    Http::fake(['https://api.valyu.ai/v1/search' => Http::response([
        'success' => true,
        'results' => [
            ['url' => 'https://example.com/kept', 'title' => 'Kept', 'content' => 'Do not render'],
            ['url' => 'https://example.com/kept', 'title' => 'Duplicate'],
            ['url' => 'https://storage.example/file?token=secret', 'title' => 'Signed'],
            ['url' => 'https://storage.example/file?apiKey=secret', 'title' => 'Signed'],
            ['url' => 'https://storage.example/file?AWSAccessKeyId=secret', 'title' => 'Signed'],
            ['url' => 'https://storage.example/file?redirect%5Btoken%5D=secret', 'title' => 'Signed'],
            ['url' => 'https://user:pass@example.com/private', 'title' => 'Credentials'],
            ['url' => 'javascript:alert(1)', 'title' => 'Unsafe'],
        ],
        'total_deduction_dollars' => '0.00',
    ])]);

    $result = app(ValyuSearchDriver::class)->run(valyuSearchRequest(
        options: ['search_type' => 'web', 'url_only' => true],
        corpora: [Corpus::Web],
    ));

    expect($result->content)->toBe('### [Kept](https://example.com/kept)')
        ->and($result->content)->not->toContain('Do not render')
        ->and($result->citations)->toHaveCount(1)
        ->and($result->usage)->toBeNull()
        ->and((array) $result->providerMeta)->toMatchArray(['reported_result_count' => 8, 'result_count' => 1]);
});

it('validates Search option shape through the shared Profile validator', function (array $options): void {
    expect(app(ProfileOptionValidator::class)->errors(ValyuSearchDriver::class, $options))->not->toBeEmpty();
})->with([
    'unknown option' => [['unknown' => true]],
    'too many results' => [['max_results' => 21]],
    'bad response length' => [['response_length' => 0]],
    'bad date' => [['start_date' => '2026-02-30']],
    'both source filters' => [['included_sources' => ['a'], 'excluded_sources' => ['b']]],
    'bad source bias' => [['source_biases' => ['example.com' => 6]]],
    'bad country' => [['country_code' => 'ZZ']],
]);

it('rejects incompatible Search requests before dispatch', function (DriverRequest $request, string $code): void {
    Http::fake();

    expect(fn () => app(ValyuSearchDriver::class)->run($request))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code && ! $exception->fallbackAllowed);

    Http::assertNothingSent();
})->with([
    'blank query' => fn () => [valyuSearchRequest(prompt: '  '), 'valyu_search.invalid_request'],
    'missing credential' => fn () => [valyuSearchRequest(credential: null), 'valyu_search.authentication'],
    'corpus drift' => fn () => [valyuSearchRequest(corpora: [Corpus::Web]), 'valyu_search.invalid_profile'],
    'grounding drift' => fn () => [valyuSearchRequest(grounding: GroundingPolicy::Required), 'valyu_search.invalid_profile'],
]);

it('maps malformed and failed Search responses to body-free errors', function (mixed $response, string $code): void {
    Http::fake(['https://api.valyu.ai/v1/search' => $response]);

    expect(fn () => app(ValyuSearchDriver::class)->run(valyuSearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code
            && ! str_contains($exception->getMessage(), 'provider-secret')
            && ! str_contains($exception->getMessage(), 'testing-valyu-key'));
})->with([
    'malformed body' => [fn () => Http::response('provider-secret'), 'valyu_search.invalid_response'],
    'missing results' => [fn () => Http::response(['success' => true]), 'valyu_search.invalid_response'],
    'provider failure' => [fn () => Http::response(['success' => false, 'error' => 'provider-secret']), 'valyu_search.provider'],
    'authentication' => [fn () => Http::response(['error' => 'provider-secret'], 401), 'valyu_search.authentication'],
    'rate limit' => [fn () => Http::response(['error' => 'provider-secret'], 429), 'valyu_search.rate_limited'],
    'server error' => [fn () => Http::response(['error' => 'provider-secret'], 503), 'valyu_search.unavailable'],
]);

it('rejects Search evidence that reflects the configured credential', function (string $field): void {
    Http::fake(['https://api.valyu.ai/v1/search' => Http::response([
        'success' => true,
        'results' => [[
            'url' => $field === 'url' ? 'https://example.com/testing-valyu-key' : 'https://example.com/result',
            'title' => $field === 'title' ? 'testing-valyu-key' : 'Result',
            'content' => $field === 'content' ? 'testing-valyu-key' : 'Evidence',
        ]],
    ])]);

    expect(fn () => app(ValyuSearchDriver::class)->run(valyuSearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'valyu_search.invalid_response'
            && ! str_contains($exception->getMessage(), 'testing-valyu-key'));
})->with(['url', 'title', 'content']);

it('uses the shared deadline and redacts connection details', function (bool $expires, string $code): void {
    Http::fake(function () use ($expires): never {
        if ($expires) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        }

        throw new ConnectionException('provider-secret testing-valyu-key');
    });

    expect(fn () => app(ValyuSearchDriver::class)->run(valyuSearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code
            && ! str_contains($exception->getMessage(), 'provider-secret'));
})->with([
    'connection before deadline' => [false, 'valyu_search.connection'],
    'connection at deadline' => [true, 'valyu_search.deadline_exceeded'],
]);

/** @param array<string, mixed> $options
 * @param  list<Corpus>  $corpora
 */
function valyuSearchRequest(
    array $options = [],
    array $corpora = [Corpus::Web, Corpus::Specialized],
    string $prompt = 'quantum methods',
    ?string $credential = 'testing-valyu-key',
    GroundingPolicy $grounding = GroundingPolicy::None,
    ?CarbonImmutable $deadline = null,
): DriverRequest {
    $profile = new Profile(
        id: 'valyu-search',
        driver: ValyuSearchDriver::class,
        provider: 'valyu',
        model: null,
        resultKind: ResultKind::SearchResults,
        grounding: $grounding,
        observation: ObservationMode::ApiOutput,
        corpora: collect($corpora),
        retrievalMethods: collect([RetrievalMethod::SearchEndpoint]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-search',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
