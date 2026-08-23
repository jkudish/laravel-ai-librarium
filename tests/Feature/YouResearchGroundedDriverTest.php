<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouResearchGroundedDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23T12:00:00Z');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('uses one synchronous standard-effort Research request without background execution', function (): void {
    Http::fake(['https://api.you.com/v1/research' => Http::response(youResearchGroundedSuccess())]);

    $result = app(YouResearchGroundedDriver::class)->run(youResearchGroundedRequest());

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.you.com/v1/research'
        && $request->hasHeader('X-API-Key', 'testing-you-key')
        && $request->data() === [
            'input' => 'Research this with a grounded answer.',
            'research_effort' => 'standard',
        ]
        && ! array_key_exists('background', $request->data()));
    Http::assertSentCount(1);

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe('A grounded Research answer [[1]].')
        ->and($result->provider)->toBe('you-research')
        ->and($result->profile)->toBe('you-research-grounded')
        ->and($result->provenance->resultKind)->toBe(ResultKind::GroundedAnswer)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->citations->sole()->source->url)->toBe('https://research.example/grounded')
        ->and(data_get($result->providerMeta, 'you-com:research'))->toBe([
            'research_effort' => 'standard',
            'execution' => 'inline',
            'sources' => [[
                'url' => 'https://research.example/grounded',
                'title' => 'Grounded source',
                'snippets' => ['Grounding excerpt.'],
            ]],
            'warnings' => [],
        ]);
});

it('accepts a synchronous Research answer with no sources', function (): void {
    $success = youResearchGroundedSuccess();
    data_set($success, 'output.sources', []);
    unset($success['warnings']);
    Http::fake(['https://api.you.com/v1/research' => Http::response($success)]);

    $result = app(YouResearchGroundedDriver::class)->run(youResearchGroundedRequest());

    expect($result->citations)->toBeEmpty()
        ->and(data_get($result->providerMeta, 'you-com:research.sources'))->toBe([])
        ->and(data_get($result->providerMeta, 'you-com:research.warnings'))->toBe([]);
    Http::assertSentCount(1);
});

it('accepts a synchronous Research answer when sources are omitted', function (): void {
    $success = youResearchGroundedSuccess();
    unset($success['output']['sources']);
    Http::fake(['https://api.you.com/v1/research' => Http::response($success)]);

    $result = app(YouResearchGroundedDriver::class)->run(youResearchGroundedRequest());

    expect($result->citations)->toBeEmpty()
        ->and(data_get($result->providerMeta, 'you-com:research.sources'))->toBe([]);
    Http::assertSentCount(1);
});

it('rejects invalid local input and incompatible grounded Research semantics before HTTP', function (array $overrides, string $code): void {
    Http::fake();

    expect(fn () => app(YouResearchGroundedDriver::class)->run(youResearchGroundedRequest(...$overrides)))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code
            && ! $exception->fallbackAllowed);

    Http::assertNothingSent();
})->with([
    'effort drift' => [['options' => ['research_effort' => 'deep']], 'you_research.invalid_request'],
    'unknown option' => [['options' => ['research_effort' => 'standard', 'unknown' => true]], 'you_research.invalid_request'],
    'blank input' => [['prompt' => '   '], 'you_research.invalid_request'],
    'overlong input' => [['prompt' => str_repeat('x', 40_001)], 'you_research.invalid_request'],
    'missing credential' => [['credential' => null], 'you_research.authentication'],
    'expired deadline' => [['deadline' => CarbonImmutable::parse('2026-08-23T12:00:00Z')], 'you_research.deadline_exceeded'],
    'provider' => [['provider' => 'you-answer'], 'you_research.invalid_profile'],
    'model' => [['model' => 'provider-selected'], 'you_research.invalid_profile'],
    'result kind' => [['resultKind' => ResultKind::ResearchReport], 'you_research.invalid_profile'],
    'grounding' => [['grounding' => GroundingPolicy::Optional], 'you_research.invalid_profile'],
    'observation' => [['observation' => ObservationMode::SurfaceSnapshot], 'you_research.invalid_profile'],
    'corpus' => [['corpora' => [Corpus::News]], 'you_research.invalid_profile'],
    'retrieval method' => [['retrievalMethods' => [RetrievalMethod::ResearchAgent]], 'you_research.invalid_profile'],
]);

it('uses and enforces the one request-wide deadline', function (): void {
    $requestOptions = null;
    Http::globalMiddleware(function (callable $handler) use (&$requestOptions): Closure {
        return function ($request, array $options) use ($handler, &$requestOptions) {
            $requestOptions = $options;

            return $handler($request, $options);
        };
    });
    Http::fake(['https://api.you.com/v1/research' => Http::response(youResearchGroundedSuccess())]);

    app(YouResearchGroundedDriver::class)->run(youResearchGroundedRequest(
        deadline: CarbonImmutable::now()->addMilliseconds(2345),
    ));

    expect($requestOptions['timeout'] ?? null)->toBe(2.345)
        ->and($requestOptions['connect_timeout'] ?? null)->toBe(2.345);

    $deadline = CarbonImmutable::now()->addSecond();
    Http::fake(function () use ($deadline): array {
        CarbonImmutable::setTestNow($deadline);

        return youResearchGroundedSuccess();
    });

    expect(fn () => app(YouResearchGroundedDriver::class)->run(youResearchGroundedRequest(deadline: $deadline)))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'you_research.deadline_exceeded');
});

it('fails closed on malformed synchronous Research responses', function (mixed $payload): void {
    Http::fake(['https://api.you.com/v1/research' => Http::response($payload)]);

    expect(fn () => app(YouResearchGroundedDriver::class)->run(youResearchGroundedRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'you_research.invalid_response'
            && ! str_contains($exception->getMessage(), 'provider-secret'));
})->with([
    'non-object' => ['provider-secret'],
    'blank content' => [['output' => ['content' => ' ', 'content_type' => 'text', 'sources' => []], 'warnings' => []]],
    'structured content' => [['output' => ['content' => ['secret' => true], 'content_type' => 'object', 'sources' => []], 'warnings' => []]],
    'non-array sources' => [['output' => ['content' => 'Answer', 'content_type' => 'text', 'sources' => 'provider-secret'], 'warnings' => []]],
    'malformed source' => [['output' => ['content' => 'Answer', 'content_type' => 'text', 'sources' => [['url' => 'bad']]], 'warnings' => []]],
]);

it('maps synchronous Research HTTP and connection failures to safe errors', function (int|string $failure, string $code): void {
    if (is_int($failure)) {
        Http::fake(['https://api.you.com/v1/research' => Http::response(['detail' => 'provider-secret testing-you-key'], $failure)]);
    } else {
        Http::fake(static fn (): never => throw new ConnectionException('provider-secret testing-you-key'));
    }

    expect(fn () => app(YouResearchGroundedDriver::class)->run(youResearchGroundedRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code
            && ! str_contains($exception->getMessage(), 'provider-secret')
            && ! str_contains($exception->getMessage(), 'testing-you-key'));
})->with([
    'bad request' => [422, 'you_research.invalid_request'],
    'authentication' => [401, 'you_research.authentication'],
    'credit' => [402, 'you_research.insufficient_credit'],
    'authorization' => [403, 'you_research.authorization'],
    'timeout' => [408, 'you_research.timeout'],
    'rate limit' => [429, 'you_research.rate_limited'],
    'server error' => [503, 'you_research.unavailable'],
    'connection' => ['connection', 'you_research.connection'],
]);

/** @return array<string, mixed> */
function youResearchGroundedSuccess(): array
{
    return [
        'output' => [
            'content' => 'A grounded Research answer [[1]].',
            'content_type' => 'text',
            'sources' => [[
                'url' => 'https://research.example/grounded',
                'title' => 'Grounded source',
                'snippets' => ['Grounding excerpt.'],
            ]],
        ],
        'warnings' => [],
    ];
}

/**
 * @param  array<string, mixed>  $options
 * @param  list<Corpus>  $corpora
 * @param  list<RetrievalMethod>  $retrievalMethods
 */
function youResearchGroundedRequest(
    string $prompt = 'Research this with a grounded answer.',
    array $options = ['research_effort' => 'standard'],
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-you-key',
    string $provider = 'you-research',
    ?string $model = null,
    ResultKind $resultKind = ResultKind::GroundedAnswer,
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ObservationMode $observation = ObservationMode::ApiOutput,
    array $corpora = [Corpus::Web],
    array $retrievalMethods = [RetrievalMethod::SearchEndpoint],
): DriverRequest {
    $profile = new Profile(
        id: 'you-research-grounded',
        driver: YouResearchGroundedDriver::class,
        provider: $provider,
        model: $model,
        resultKind: $resultKind,
        grounding: $grounding,
        observation: $observation,
        corpora: collect($corpora),
        retrievalMethods: collect($retrievalMethods),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-research-grounded',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
