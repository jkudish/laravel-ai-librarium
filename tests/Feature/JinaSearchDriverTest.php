<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Jina\JinaSearchDriver;
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

it('has no profile options and sends the exact Jina Search request', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($options['allow_redirects'] ?? null)->toBeFalse();

        return Http::response(['data' => []]);
    });

    expect(JinaSearchDriver::profileOptionRules())->toBe([]);

    jinaSearchDriver()->run(jinaSearchRequest());

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://s.jina.ai/'
            && $request->hasHeader('Authorization', 'Bearer testing-jina-key')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === [
                'q' => 'best coffee in Vancouver',
                'num' => 10,
            ]
            && ! str_contains($request->url(), 'testing-jina-key');
    });
    Http::assertSentCount(1);
});

it('normalizes asymmetric Search results with direct provenance and no invented metering', function (): void {
    Http::fake(['https://s.jina.ai/' => Http::response([
        'code' => 200,
        'status' => 20000,
        'data' => [
            [
                'title' => "First [result]\n<script>",
                'url' => 'https://example.com/path_(one)?next=a(b)',
                'description' => str_repeat('D', 205),
                'content' => "<img src=\"https://tracker.example/pixel\">\n[unsafe](javascript:alert(1))",
                'usage' => ['tokens' => 12345],
            ],
            [
                'url' => 'http://example.org/untitled',
                'content' => 'Second result only.',
            ],
            [
                'title' => 'Description only',
                'url' => 'https://example.net/description',
                'description' => 'Third snippet.',
            ],
        ],
        'usage' => ['tokens' => 30000],
        'raw_body' => 'provider-secret raw response',
    ])]);

    $result = jinaSearchDriver()->run(jinaSearchRequest());

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe(
            "### [First \\[result\\] &lt;script&gt;](https://example.com/path_%28one%29?next=a%28b%29)\n"
            .'*'.str_repeat('D', 205)."*\n\n"
            .'&lt;img src\\=&quot;https://tracker\\.example/pixel&quot;&gt;'."\n"
            .'\\[unsafe\\]\\(javascript:alert\\(1\\)\\)'."\n\n"
            ."### [Untitled](http://example.org/untitled)\n\n"
            ."Second result only\\.\n\n"
            ."### [Description only](https://example.net/description)\n"
            ."*Third snippet\\.*\n",
        )
        ->and($result->requestedProfile)->toBe('jina-search')
        ->and($result->provider)->toBe('jina-search')
        ->and($result->profile)->toBe('jina-search')
        ->and($result->model)->toBeNull()
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->provenance->observedAt->toIso8601ZuluString())->toBe('2026-09-10T12:00:00Z')
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/path_(one)?next=a(b)',
            'http://example.org/untitled',
            'https://example.net/description',
        ])
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported])
        ->and($result->citations->first()->source->title)->toBe("First [result]\n<script>")
        ->and($result->citations->first()->excerpt)->toBe(str_repeat('D', 200))
        ->and($result->citations->get(1)->excerpt)->toBe('Second result only.')
        ->and($result->usage)->toBeNull()
        ->and($result->providerMeta)->toBeNull();

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('provider-secret')
        ->not->toContain('raw_body')
        ->not->toContain('12345')
        ->not->toContain('30000')
        ->not->toContain('testing-jina-key');
});

it('filters unsafe URL records without dereferencing provider URLs or disturbing valid duplicates', function (): void {
    Http::fake(['https://s.jina.ai/' => Http::response(['data' => [
        ['title' => 'Keep first', 'url' => 'https://example.com/a'],
        ['title' => 'Keep duplicate', 'url' => 'https://example.com/a'],
        ['title' => 'Credentials', 'url' => 'https://user:pass@example.com/private'],
        ['title' => 'Script', 'url' => 'javascript:alert(1)'],
        ['title' => 'Provider identifier', 'url' => 'provider-native:source/123'],
        ['title' => 'Relative', 'url' => '/relative'],
        ['title' => 'Whitespace', 'url' => ' https://example.com/padded'],
        ['title' => 'Missing URL'],
        'not-an-object',
    ]])]);

    $result = jinaSearchDriver()->run(jinaSearchRequest());

    expect($result->content)->toBe(
        "### [Keep first](https://example.com/a)\n\n"
        ."### [Keep duplicate](https://example.com/a)\n",
    )
        ->and($result->citations)->toHaveCount(2)
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/a',
            'https://example.com/a',
        ]);
    Http::assertSentCount(1);
});

it('maps empty or absent Search data without inventing results, citations, or usage', function (array $payload): void {
    Http::fake(['https://s.jina.ai/' => Http::response($payload)]);

    $result = jinaSearchDriver()->run(jinaSearchRequest());

    expect($result->content)->toBe('No results found.')
        ->and($result->citations)->toBeEmpty()
        ->and($result->usage)->toBeNull();
})->with([
    'empty data' => [['data' => []]],
    'absent optional data' => [['code' => 200, 'status' => 20000]],
]);

it('rejects malformed successful responses safely', function (mixed $body): void {
    Http::fake(['https://s.jina.ai/' => Http::response($body)]);

    try {
        jinaSearchDriver()->run(jinaSearchRequest());
        $this->fail('The malformed Jina Search response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('jina.invalid_response')
            ->and($exception->getMessage())->toBe('Jina Search returned a malformed response.')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'non-json body' => ['provider-secret malformed body'],
    'top-level list' => [[]],
    'data object' => [['data' => ['url' => 'https://example.com']]],
    'data scalar' => [['data' => 'provider-secret']],
]);

it('does not send a request without a credential or before an expired deadline', function (string $condition, string $code): void {
    Http::fake();

    $request = match ($condition) {
        'credential' => jinaSearchRequest(credential: null),
        'deadline' => jinaSearchRequest(deadline: CarbonImmutable::now()),
    };

    try {
        jinaSearchDriver()->run($request);
        $this->fail('The Jina Search request should not start.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'missing credential' => ['credential', 'jina.authentication'],
    'expired deadline' => ['deadline', 'jina.deadline_exceeded'],
]);

it('bounds request and connection timeouts to the exact remaining deadline', function (float $remaining, float $connectTimeout): void {
    $options = null;
    Http::globalMiddleware(function (callable $handler) use (&$options): Closure {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    Http::fake(['https://s.jina.ai/' => Http::response(['data' => []])]);

    jinaSearchDriver()->run(jinaSearchRequest(
        deadline: CarbonImmutable::now()->addMilliseconds((int) ($remaining * 1000)),
    ));

    expect($options)->toBeArray()
        ->and($options['timeout'] ?? null)->toBe($remaining)
        ->and($options['connect_timeout'] ?? null)->toBe($connectTimeout);
})->with([
    'longer than connection cap' => [12.345, 3.0],
    'shorter than connection cap' => [2.5, 2.5],
]);

it('rejects a response that arrives at the deadline before mapping it', function (): void {
    $request = jinaSearchRequest();
    Http::fake(function () use ($request) {
        CarbonImmutable::setTestNow($request->deadline);

        return Http::response(['data' => []]);
    });

    expect(fn () => jinaSearchDriver()->run($request))
        ->toThrow(DriverException::class, 'Jina Search did not respond before the research deadline.');
    Http::assertSentCount(1);
});

it('maps connection failures without exposing transport details', function (bool $exhaustDeadline, string $code): void {
    Http::fake(function () use ($exhaustDeadline): never {
        if ($exhaustDeadline) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
        }

        throw new ConnectionException('cURL testing-jina-key provider-secret details');
    });

    try {
        jinaSearchDriver()->run(jinaSearchRequest());
        $this->fail('The connection should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('testing-jina-key')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'connection failed within deadline' => [false, 'jina.connection'],
    'connection exhausted deadline' => [true, 'jina.deadline_exceeded'],
]);

it('maps HTTP failures to bounded body-free terminal exceptions', function (int $status, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://s.jina.ai/' => Http::response([
        'detail' => 'testing-jina-key provider-secret raw body',
    ], $status)]);

    try {
        jinaSearchDriver()->run(jinaSearchRequest());
        $this->fail('The Jina Search provider failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('testing-jina-key')
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and(strlen($exception->getMessage()))->toBeLessThanOrEqual(128);
    }
})->with([
    'bad request' => [400, 'jina.invalid_request', false],
    'authentication' => [401, 'jina.authentication', true],
    'insufficient balance' => [402, 'jina.insufficient_balance', true],
    'authorization' => [403, 'jina.authorization', true],
    'provider timeout' => [408, 'jina.deadline_exceeded', true],
    'rate limited' => [429, 'jina.rate_limited', true],
    'gateway timeout' => [504, 'jina.deadline_exceeded', true],
    'unavailable' => [503, 'jina.unavailable', true],
]);

function jinaSearchDriver(): JinaSearchDriver
{
    return app(JinaSearchDriver::class);
}

function jinaSearchRequest(
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-jina-key',
): DriverRequest {
    $profile = new Profile(
        id: 'jina-search',
        driver: JinaSearchDriver::class,
        provider: 'jina-search',
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
        prompt: 'best coffee in Vancouver',
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
