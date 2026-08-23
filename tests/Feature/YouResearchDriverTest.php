<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouResearchDriver;
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
    Sleep::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('uses only the durable standard-effort Research endpoint and maps a distinct report', function (): void {
    Http::fake(['https://api.you.com/v1/research*' => Http::sequence()
        ->push(youResearchSubmission(), 202)
        ->push(youResearchTask('running'))
        ->push(youResearchTask('completed', youResearchSuccess()))
        ->push(youResearchTask('completed', youResearchSuccess()))]);

    $result = app(YouResearchDriver::class)->run(youResearchRequest());

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.you.com/v1/research'
        && $request->hasHeader('X-API-Key', 'testing-you-key')
        && $request->data() === [
            'input' => 'Research this deeply.',
            'research_effort' => 'standard',
            'background' => true,
        ]
        && ! str_contains($request->url(), '/answer'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.you.com/v1/research/'.youResearchTaskId()
        && $request->hasHeader('X-API-Key', 'testing-you-key'));
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(2);

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe('A multi-step report [[1]].')
        ->and($result->provider)->toBe('you-research')
        ->and($result->profile)->toBe('you-research')
        ->and($result->provenance->resultKind)->toBe(ResultKind::ResearchReport)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ResearchAgent])
        ->and($result->citations->sole()->source->url)->toBe('https://research.example/source')
        ->and($result->citations->sole()->excerpt)->toBe(str_repeat('s', 200))
        ->and(data_get($result->providerMeta, 'you-com:research'))->toBe([
            'research_effort' => 'standard',
            'execution' => 'background',
            'resumability' => 'durable_provider_task',
            'sources' => [[
                'url' => 'https://research.example/source',
                'title' => 'Research source',
                'snippets' => [str_repeat('s', 250), 'Second excerpt'],
            ]],
            'warnings' => ['One source was inaccessible.'],
        ]);

    expect(json_encode($result->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain(youResearchTaskId())
        ->not->toContain('testing-you-key');
});

it('uses the remaining request-wide deadline for every Research lifecycle call', function (): void {
    $options = [];
    Http::globalMiddleware(function (callable $handler) use (&$options): Closure {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options[] = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    Http::fake(['https://api.you.com/v1/research*' => Http::sequence()
        ->push(youResearchSubmission(), 202)
        ->push(youResearchTask('completed', youResearchSuccess()))
        ->push(youResearchTask('completed', youResearchSuccess()))]);

    app(YouResearchDriver::class)->run(youResearchRequest(deadline: CarbonImmutable::now()->addMilliseconds(2345)));

    expect($options)->toHaveCount(3);
    foreach ($options as $requestOptions) {
        expect($requestOptions['timeout'] ?? null)->toBe(2.345)
            ->and($requestOptions['connect_timeout'] ?? null)->toBe(2.345);
    }
});

it('rejects any effort drift, blank or overlong input, missing credentials, and expired deadlines before HTTP', function (DriverRequest $request, string $code): void {
    Http::fake();

    expect(fn () => app(YouResearchDriver::class)->run($request))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code);

    Http::assertNothingSent();
})->with([
    'effort drift' => fn () => [youResearchRequest(options: ['research_effort' => 'deep']), 'you_research.invalid_request'],
    'unknown option' => fn () => [youResearchRequest(options: ['research_effort' => 'standard', 'unknown' => true]), 'you_research.invalid_request'],
    'blank input' => fn () => [youResearchRequest(prompt: '   '), 'you_research.invalid_request'],
    'overlong input' => fn () => [youResearchRequest(prompt: str_repeat('x', 40_001)), 'you_research.invalid_request'],
    'missing credential' => fn () => [youResearchRequest(credential: null), 'you_research.authentication'],
    'expired deadline' => fn () => [youResearchRequest(deadline: CarbonImmutable::now()), 'you_research.deadline_exceeded'],
]);

it('fails closed on malformed Research task responses', function (mixed $payload): void {
    $sequence = Http::sequence()->push(youResearchSubmission(), 202)->push($payload);
    if (is_array($payload) && data_get($payload, 'status') === 'completed') {
        $sequence->push($payload);
    }
    Http::fake(['https://api.you.com/v1/research*' => $sequence]);

    expect(fn () => app(YouResearchDriver::class)->run(youResearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'you_research.invalid_response'
            && ! str_contains($exception->getMessage(), 'provider-secret'));
})->with([
    'non-object' => ['provider-secret'],
    'wrong task id' => [youResearchTask('running', id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')],
    'blank content' => [youResearchTask('completed', ['output' => ['content' => ' ', 'content_type' => 'text', 'sources' => []], 'warnings' => []])],
    'structured content unsupported by standard Profile' => [youResearchTask('completed', ['output' => ['content' => ['report' => true], 'content_type' => 'object', 'sources' => []], 'warnings' => []])],
    'malformed source' => [youResearchTask('completed', ['output' => ['content' => 'Report', 'content_type' => 'text', 'sources' => [['url' => 'bad']]], 'warnings' => []])],
]);

it('maps terminal background task failures without exposing provider errors', function (string $status): void {
    $failed = youResearchTask($status);
    $failed['error'] = 'provider-secret testing-you-key';
    Http::fake(['https://api.you.com/v1/research*' => Http::sequence()
        ->push(youResearchSubmission(), 202)
        ->push($failed)]);

    expect(fn () => app(YouResearchDriver::class)->run(youResearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'you_research.failed'
            && ! str_contains($exception->getMessage(), 'provider-secret')
            && ! str_contains($exception->getMessage(), 'testing-you-key'));
})->with(['failed', 'cancelled']);

it('maps Research HTTP and connection failures to safe profile-specific errors', function (int|string $failure, string $code): void {
    if (is_int($failure)) {
        Http::fake(['https://api.you.com/v1/research' => Http::response(['detail' => 'provider-secret testing-you-key'], $failure)]);
    } else {
        Http::fake(static fn (): never => throw new ConnectionException('provider-secret testing-you-key'));
    }

    expect(fn () => app(YouResearchDriver::class)->run(youResearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code
            && ! str_contains($exception->getMessage(), 'provider-secret')
            && ! str_contains($exception->getMessage(), 'testing-you-key'));

    Http::assertSentCount(is_int($failure) ? 1 : 0);
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
function youResearchSuccess(): array
{
    return [
        'output' => [
            'content' => 'A multi-step report [[1]].',
            'content_type' => 'text',
            'sources' => [[
                'url' => 'https://research.example/source',
                'title' => 'Research source',
                'snippets' => [str_repeat('s', 250), 'Second excerpt'],
            ]],
        ],
        'warnings' => ['One source was inaccessible.'],
    ];
}

/** @return array<string, mixed> */
function youResearchSubmission(): array
{
    return [
        'task_id' => youResearchTaskId(),
        'type' => 'research',
        'status' => 'queued',
        'created_at' => '2026-08-23T12:00:00Z',
    ];
}

/** @param array<string, mixed>|null $result */
function youResearchTask(string $status, ?array $result = null, ?string $id = null): array
{
    return [
        'id' => $id ?? youResearchTaskId(),
        'task_type' => 'research',
        'status' => $status,
        'created_at' => '2026-08-23T12:00:00Z',
        'updated_at' => '2026-08-23T12:01:00Z',
        'completed_at' => $status === 'completed' ? '2026-08-23T12:01:00Z' : null,
        'error' => null,
        'result' => $result,
    ];
}

function youResearchTaskId(): string
{
    return '123e4567-e89b-42d3-a456-426614174000';
}

/** @param array<string, mixed> $options */
function youResearchRequest(
    string $prompt = 'Research this deeply.',
    array $options = ['research_effort' => 'standard'],
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-you-key',
): DriverRequest {
    $profile = new Profile(
        id: 'you-research',
        driver: YouResearchDriver::class,
        provider: 'you-research',
        model: null,
        resultKind: ResultKind::ResearchReport,
        grounding: GroundingPolicy::Required,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::ResearchAgent]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-research',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
