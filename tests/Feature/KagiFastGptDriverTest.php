<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Kagi\KagiFastGptDriver;
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

it('sends exactly one Bot-authenticated POST with only the rendered query', function (): void {
    Http::fake(['https://kagi.com/api/v0/fastgpt' => kagiSuccessResponse()]);

    kagiDriver()->run(kagiRequest());

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://kagi.com/api/v0/fastgpt'
            && $request->hasHeader('Authorization', 'Bot testing-kagi-key')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['query' => 'What changed in PHP 8.4?']
            && ! str_contains($request->url(), 'testing-kagi-key');
    });
    Http::assertSentCount(1);
});

it('maps a grounded answer, validated provider references, provenance, and output-token usage', function (): void {
    Http::fake(['https://kagi.com/api/v0/fastgpt' => Http::response([
        'meta' => ['id' => 'kagi-request-1', 'node' => 'provider-secret-node'],
        'credential' => 'testing-kagi-key',
        'data' => [
            'output' => '  PHP 8.4 adds property hooks. [1]  ',
            'tokens' => 757,
            'references' => [
                ['title' => 'PHP 8.4', 'snippet' => str_repeat('x', 205), 'url' => 'https://www.php.net/releases/8.4/en.php'],
                ['title' => 'Duplicate', 'snippet' => 'duplicate', 'url' => 'https://www.php.net/releases/8.4/en.php'],
                ['title' => 'HTTP source', 'snippet' => 'A second source.', 'url' => 'http://example.com/php'],
                ['title' => 'Bad scheme', 'url' => 'javascript:alert(1)'],
                ['title' => 'Credentials', 'url' => 'https://user:password@example.com/private'],
                ['title' => 'Whitespace', 'url' => ' https://example.com/padded'],
                ['title' => 'Malformed', 'url' => 'not-a-url'],
                'not-an-object',
            ],
            'raw_body' => 'provider-secret-raw-body',
        ],
    ])]);

    $result = kagiDriver()->run(kagiRequest());

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe('PHP 8.4 adds property hooks. [1]')
        ->and($result->provider)->toBe('kagi-fastgpt')
        ->and($result->profile)->toBe('kagi-fastgpt')
        ->and($result->provenance->resultKind)->toBe(ResultKind::GroundedAnswer)
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://www.php.net/releases/8.4/en.php',
            'http://example.com/php',
        ])
        ->and($result->citations->first()->source->title)->toBe('PHP 8.4')
        ->and($result->citations->first()->excerpt)->toBe(str_repeat('x', 200))
        ->and($result->citations->first()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->usage?->completionTokens)->toBe(757)
        ->and($result->usage?->promptTokens)->toBeNull()
        ->and($result->usage?->estimatedCost)->toBeNull()
        ->and($result->usage?->actualCost)->toBeNull()
        ->and($result->providerMeta)->toBeNull();

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('provider-secret-node')
        ->not->toContain('provider-secret-raw-body')
        ->not->toContain('raw_body')
        ->not->toContain('kagi-request-1')
        ->not->toContain('testing-kagi-key');
});

it('omits usage when Kagi does not report tokens', function (): void {
    Http::fake(['https://kagi.com/api/v0/fastgpt' => kagiSuccessResponse(tokens: null)]);

    expect(kagiDriver()->run(kagiRequest())->usage)->toBeNull();
});

it('rejects empty, malformed, or ungrounded success payloads safely', function (mixed $payload): void {
    Http::fake(['https://kagi.com/api/v0/fastgpt' => Http::response($payload)]);

    expect(fn () => kagiDriver()->run(kagiRequest()))
        ->toThrow(DriverException::class, 'Kagi FastGPT returned an invalid grounded answer.');
})->with([
    'non-json body' => 'provider-secret malformed body',
    'top-level list' => [['data' => []]],
    'missing data' => [['meta' => ['id' => 'request-1']]],
    'list data' => [['data' => []]],
    'empty output' => [['data' => ['output' => ' ', 'references' => [['url' => 'https://example.com']]]]],
    'non-list references' => [['data' => ['output' => 'Answer', 'references' => ['url' => 'https://example.com']]]],
    'no valid grounded reference' => [['data' => ['output' => 'Answer', 'references' => [['url' => 'ftp://example.com/file']]]]],
    'malformed token usage' => [['data' => ['output' => 'Answer', 'tokens' => '757', 'references' => [['url' => 'https://example.com']]]]],
]);

it('maps a Kagi error envelope without surfacing provider error text', function (mixed $error): void {
    Http::fake(['https://kagi.com/api/v0/fastgpt' => Http::response([
        'error' => $error,
        'data' => ['output' => 'must not be used'],
    ])]);

    try {
        kagiDriver()->run(kagiRequest());
        $this->fail('The provider error envelope should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('kagi.provider')
            ->and($exception->getMessage())->toBe('Kagi FastGPT reported an error for this request.')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'documented list' => [[['code' => 1, 'msg' => 'provider-secret insufficient credit']]],
    'unexpected scalar' => ['provider-secret unexpected error'],
]);

it('maps HTTP statuses to safe namespaced errors without exposing response bodies', function (int $status, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://kagi.com/api/v0/fastgpt' => Http::response([
        'error' => [['msg' => 'provider-secret raw error']],
    ], $status)]);

    try {
        kagiDriver()->run(kagiRequest());
        $this->fail('The HTTP failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('raw error');
    }
})->with([
    'bad request' => [400, 'kagi.invalid_request', false],
    'unauthorized' => [401, 'kagi.authentication', true],
    'insufficient credit' => [402, 'kagi.insufficient_credit', true],
    'forbidden' => [403, 'kagi.authorization', true],
    'provider timeout' => [408, 'kagi.timeout', true],
    'rate limited' => [429, 'kagi.rate_limited', true],
    'server error' => [503, 'kagi.unavailable', true],
]);

it('does not send a request without a credential or before an expired deadline', function (string $condition, string $code): void {
    Http::fake();

    $request = match ($condition) {
        'credential' => kagiRequest(credential: null),
        'deadline' => kagiRequest(deadline: CarbonImmutable::now()),
    };

    try {
        kagiDriver()->run($request);
        $this->fail('The request should have failed before HTTP dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'missing credential' => ['credential', 'kagi.authentication'],
    'expired deadline' => ['deadline', 'kagi.deadline_exceeded'],
]);

it('bounds request and connection timeouts to the exact remaining deadline', function (float $remaining, float $connectTimeout): void {
    $options = null;
    Http::globalMiddleware(function (callable $handler) use (&$options): Closure {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    Http::fake(['https://kagi.com/api/v0/fastgpt' => kagiSuccessResponse()]);

    kagiDriver()->run(kagiRequest(deadline: CarbonImmutable::now()->addMilliseconds((int) ($remaining * 1000))));

    expect($options)->toBeArray()
        ->and($options['timeout'] ?? null)->toBe($remaining)
        ->and($options['connect_timeout'] ?? null)->toBe($connectTimeout);
})->with([
    'longer than the connection cap' => [12.345, 3.0],
    'shorter than the connection cap' => [2.5, 2.5],
]);

it('maps connection failures safely and recognizes a connection that exhausts the deadline', function (bool $exhaustDeadline, string $code): void {
    Http::fake(function () use ($exhaustDeadline): never {
        if ($exhaustDeadline) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
        }

        throw new ConnectionException('cURL provider-secret connection details');
    });

    try {
        kagiDriver()->run(kagiRequest());
        $this->fail('The connection should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'connection failed within deadline' => [false, 'kagi.connection'],
    'connection exhausted deadline' => [true, 'kagi.deadline_exceeded'],
]);

function kagiDriver(): KagiFastGptDriver
{
    return app(KagiFastGptDriver::class);
}

function kagiRequest(?CarbonImmutable $deadline = null, ?string $credential = 'testing-kagi-key'): DriverRequest
{
    $profile = new Profile(
        id: 'kagi-fastgpt',
        driver: KagiFastGptDriver::class,
        provider: 'kagi-fastgpt',
        model: null,
        resultKind: ResultKind::GroundedAnswer,
        grounding: GroundingPolicy::Required,
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
        prompt: 'What changed in PHP 8.4?',
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}

function kagiSuccessResponse(?int $tokens = 757): PromiseInterface
{
    $data = [
        'output' => 'PHP 8.4 adds property hooks.',
        'references' => [[
            'title' => 'PHP 8.4',
            'snippet' => 'Release notes.',
            'url' => 'https://www.php.net/releases/8.4/en.php',
        ]],
    ];
    if ($tokens !== null) {
        $data['tokens'] = $tokens;
    }

    return Http::response(['data' => $data]);
}
