<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Exa\ExaSearchDriver;
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
    CarbonImmutable::setTestNow('2026-08-20T12:00:00Z');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('sends the exact Exa search request with header authentication', function (): void {
    Http::fake(['https://api.exa.ai/search' => Http::response(['results' => []])]);

    exaSearchDriver()->run(exaSearchRequest());

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.exa.ai/search'
            && $request->hasHeader('x-api-key', 'testing-exa-key')
            && ! $request->hasHeader('Authorization')
            && $request->data() === [
                'query' => 'best pizza in Rome',
                'type' => 'auto',
                'numResults' => 10,
                'contents' => [
                    'text' => ['maxCharacters' => 2000],
                ],
            ]
            && ! str_contains($request->url(), 'testing-exa-key');
    });
    Http::assertSentCount(1);
});

it('normalizes Exa results into Markdown and provider-reported URL citations', function (): void {
    Http::fake(['https://api.exa.ai/search' => Http::response([
        'requestId' => 'exa-request-1',
        'results' => [
            [
                'title' => 'First result',
                'url' => 'https://example.com/first',
                'publishedDate' => '2026-08-19T10:20:30.000Z',
                'text' => str_repeat('A', 205),
            ],
            [
                'url' => 'https://example.org/untitled',
                'text' => 'Second result text.',
            ],
        ],
    ])]);

    $result = exaSearchDriver()->run(exaSearchRequest());

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe(
            "### [First result](https://example.com/first)\n"
            ."*Published: 2026-08-19T10:20:30.000Z*\n\n"
            .str_repeat('A', 205)."\n\n"
            ."### [Untitled](https://example.org/untitled)\n\n"
            ."Second result text.\n",
        )
        ->and($result->requestedProfile)->toBe('exa')
        ->and($result->provider)->toBe('exa')
        ->and($result->profile)->toBe('exa')
        ->and($result->model)->toBeNull()
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->retrievalMethods->map->value->all())->toBe(['search_endpoint'])
        ->and($result->provenance->corpora->map->value->all())->toBe(['web'])
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/first',
            'https://example.org/untitled',
        ])
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported])
        ->and($result->citations->first()->excerpt)->toBe(str_repeat('A', 200))
        ->and((array) $result->providerMeta)->toBe([
            'request_id' => 'exa-request-1',
            'result_count' => 2,
        ]);
});

it('maps an empty result list without inventing citations or usage', function (): void {
    Http::fake(['https://api.exa.ai/search' => Http::response(['results' => []])]);

    $result = exaSearchDriver()->run(exaSearchRequest());

    expect($result->content)->toBe('No results found.')
        ->and($result->citations)->toBeEmpty()
        ->and($result->usage)->toBeNull()
        ->and((array) $result->providerMeta)->toBe(['result_count' => 0]);
});

it('strictly filters invalid URLs and deduplicates results before rendering citations', function (): void {
    Http::fake(['https://api.exa.ai/search' => Http::response([
        'results' => [
            ['title' => 'Keep first', 'url' => 'https://example.com/a', 'text' => 'first'],
            ['title' => 'Drop duplicate', 'url' => 'https://example.com/a', 'text' => 'duplicate'],
            ['title' => 'Credentials', 'url' => 'https://user:pass@example.com/secret'],
            ['title' => 'Bad scheme', 'url' => 'ftp://example.com/file'],
            ['title' => 'Relative', 'url' => '/relative'],
            ['title' => 'Whitespace', 'url' => ' https://example.com/spaced'],
            ['title' => 'Missing host', 'url' => 'https:///missing-host'],
            ['title' => 'Missing URL'],
            'not-a-record',
        ],
    ])]);

    $result = exaSearchDriver()->run(exaSearchRequest());

    expect($result->content)->toBe("### [Keep first](https://example.com/a)\n\nfirst\n")
        ->and($result->content)->not->toContain('Drop duplicate')
        ->and($result->citations)->toHaveCount(1)
        ->and($result->citations->first()->source->url)->toBe('https://example.com/a')
        ->and((array) $result->providerMeta)->toBe(['result_count' => 8]);
});

it('maps only a reported non-negative dollar cost and invents no token usage', function (mixed $cost, ?string $expected): void {
    Http::fake(['https://api.exa.ai/search' => Http::response([
        'results' => [],
        'costDollars' => ['total' => $cost],
    ])]);

    $usage = exaSearchDriver()->run(exaSearchRequest())->usage;

    if ($expected === null) {
        expect($usage)->toBeNull();

        return;
    }

    expect($usage?->actualCost)->toBe($expected)
        ->and($usage?->currency)->toBe('USD')
        ->and($usage?->promptTokens)->toBeNull()
        ->and($usage?->completionTokens)->toBeNull()
        ->and($usage?->cacheWriteInputTokens)->toBeNull()
        ->and($usage?->cacheReadInputTokens)->toBeNull()
        ->and($usage?->reasoningTokens)->toBeNull()
        ->and($usage?->estimatedCost)->toBeNull();
})->with([
    'reported decimal' => [0.007, '0.007'],
    'reported decimal without binary float artifacts' => [0.1, '0.1'],
    'reported decimal using scientific JSON notation' => [0.0000001, '0.0000001'],
    'reported zero' => [0, '0'],
    'numeric string is not a provider number' => ['0.007', null],
    'negative number is invalid' => [-0.01, null],
    'more than eighteen decimal places cannot enter interchange' => [0.0000000000000000001, null],
    'missing total' => [null, null],
]);

it('rejects malformed successful responses safely', function (mixed $body): void {
    Http::fake(['https://api.exa.ai/search' => Http::response($body)]);

    try {
        exaSearchDriver()->run(exaSearchRequest());
        $this->fail('The malformed Exa response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('exa.invalid_response')
            ->and($exception->getMessage())->toBe('Exa returned a malformed response.');
    }
})->with([
    'non-json body' => ['provider-secret raw body'],
    'top-level list' => [[]],
    'missing results' => [['requestId' => 'request-only']],
    'results object' => [['results' => ['url' => 'https://example.com']]],
]);

it('rejects a missing credential and an expired deadline without a request', function (string $case): void {
    Http::fake();

    $request = $case === 'credential'
        ? exaSearchRequest(credential: null)
        : exaSearchRequest(deadline: CarbonImmutable::now()->subSecond());

    try {
        exaSearchDriver()->run($request);
        $this->fail('The Exa request should not start.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($case === 'credential' ? 'exa.authentication' : 'exa.deadline_exceeded');
    }

    Http::assertNothingSent();
})->with(['credential', 'deadline']);

it('allows a request with a subsecond deadline so its timeouts can use the exact remaining duration', function (): void {
    Http::fake(['https://api.exa.ai/search' => Http::response(['results' => []])]);

    $result = exaSearchDriver()->run(exaSearchRequest(deadline: CarbonImmutable::now()->addMilliseconds(250)));

    expect($result->content)->toBe('No results found.');
    Http::assertSentCount(1);
});

it('maps connection and deadline-exhausting failures without exposing transport details', function (bool $deadlineExpires, string $code): void {
    Http::fake(function () use ($deadlineExpires): never {
        if ($deadlineExpires) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));
        }

        throw new ConnectionException('cURL provider-secret connection details');
    });

    try {
        exaSearchDriver()->run(exaSearchRequest());
        $this->fail('The transport failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and(strlen($exception->getMessage()))->toBeLessThanOrEqual(128);
    }
})->with([
    'connection before deadline' => [false, 'exa.connection'],
    'connection at deadline' => [true, 'exa.deadline_exceeded'],
]);

it('maps provider failures to bounded body-free terminal exceptions', function (mixed $response, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://api.exa.ai/search' => $response]);

    try {
        exaSearchDriver()->run(exaSearchRequest());
        $this->fail('The Exa provider failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('raw body')
            ->and(strlen($exception->getMessage()))->toBeLessThanOrEqual(128);
    }
})->with([
    'authentication' => [fn () => Http::response(['error' => 'provider-secret raw body'], 401), 'exa.authentication', true],
    'insufficient credits' => [fn () => Http::response(['error' => 'provider-secret raw body'], 402), 'exa.insufficient_credits', true],
    'authorization' => [fn () => Http::response(['error' => 'provider-secret raw body'], 403), 'exa.authorization', true],
    'rate limit' => [fn () => Http::response(['error' => 'provider-secret raw body'], 429), 'exa.rate_limited', true],
    'unavailable' => [fn () => Http::response(['error' => 'provider-secret raw body'], 503), 'exa.unavailable', true],
    'invalid request' => [fn () => Http::response(['error' => 'provider-secret raw body'], 422), 'exa.invalid_request', false],
    'payload error' => [fn () => Http::response(['results' => [], 'error' => 'provider-secret raw body']), 'exa.provider', true],
]);

it('allowlists terminal metadata and excludes credentials and raw provider fields', function (): void {
    Http::fake(['https://api.exa.ai/search' => Http::response([
        'requestId' => 'exa-request-safe',
        'results' => [],
        'headers' => ['x-api-key' => 'provider-secret'],
        'rawBody' => 'provider-secret raw body',
        'debug' => ['query' => 'provider-secret'],
    ])]);

    $wire = json_encode(exaSearchDriver()->run(exaSearchRequest())->toArray(), JSON_THROW_ON_ERROR);

    expect($wire)->toContain('exa-request-safe')
        ->not->toContain('testing-exa-key')
        ->not->toContain('provider-secret')
        ->not->toContain('rawBody')
        ->not->toContain('headers')
        ->not->toContain('debug');
});

function exaSearchDriver(): ExaSearchDriver
{
    return app(ExaSearchDriver::class);
}

function exaSearchRequest(
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-exa-key',
    string $prompt = 'best pizza in Rome',
): DriverRequest {
    $profile = new Profile(
        id: 'exa',
        driver: ExaSearchDriver::class,
        provider: 'exa',
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

    return new DriverRequest(
        requestId: 'request-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
