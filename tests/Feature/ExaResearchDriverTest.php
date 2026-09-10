<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\Exa\ExaResearchDriver;
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
    Sleep::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('submits, polls a known private handle, and freshly retrieves the completed report', function (): void {
    $transports = [];
    Http::globalMiddleware(function (callable $handler) use (&$transports): Closure {
        return function ($request, array $options) use ($handler, &$transports) {
            $transports[] = $options;

            return $handler($request, $options);
        };
    });
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('running'))
        ->push(exaResearchRun('queued'))
        ->push(exaResearchRun('completed', output: ['text' => 'stale poll report', 'structured' => null, 'grounding' => []]))
        ->push(exaResearchCompletedRun())]);

    $result = exaResearchDriver()->run(exaResearchRequest(options: [
        'effort' => 'max',
        'system_prompt' => 'Prefer primary sources.',
        'output_schema' => [
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
        ],
        'max_cost_dollars' => 1.75,
    ]));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.exa.ai/agent/runs'
            && $request->hasHeader('x-api-key', 'testing-exa-key')
            && ! $request->hasHeader('Authorization')
            && $request->data() === [
                'query' => 'Compare durable research systems.',
                'effort' => 'max',
                'systemPrompt' => 'Prefer primary sources.',
                'outputSchema' => [
                    'type' => 'object',
                    'properties' => ['answer' => ['type' => 'string']],
                ],
                'budget' => ['maxCostDollars' => 1.75],
            ];
    });
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.exa.ai/agent/runs/'.exaResearchRunId()
        && $request->hasHeader('x-api-key', 'testing-exa-key'));
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(2);

    expect($transports)->toHaveCount(4)
        ->and(collect($transports)->every(fn (array $transport): bool => ($transport['allow_redirects'] ?? null) === false))->toBeTrue()
        ->and($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe('Authoritative final report [[1]] and [[2]].')
        ->and($result->requestedProfile)->toBe('exa-research')
        ->and($result->provider)->toBe('exa')
        ->and($result->profile)->toBe('exa-research')
        ->and($result->model)->toBeNull()
        ->and($result->provenance->resultKind)->toBe(ResultKind::ResearchReport)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ResearchAgent])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->completedAt->toISOString())->toBe('2026-09-10T12:03:04.000000Z')
        ->and($result->citations)->toHaveCount(2)
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://one.example/evidence',
            'https://two.example/evidence',
        ])
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported])
        ->and($result->citations->first()->source->title)->toBe('Primary source')
        ->and($result->citations->first()->excerpt)->toBe(str_repeat('e', 200))
        ->and($result->usage?->actualCost)->toBe('0.37')
        ->and($result->usage?->currency)->toBe('USD')
        ->and(data_get($result->providerMeta, 'exa:research'))->toBe([
            'execution' => 'background',
            'resumability' => 'durable_provider_task',
            'effort' => 'max',
            'stop_reason' => 'schema_satisfied',
            'source_count' => 2,
            'usage' => [
                'agent_compute_units' => 3,
                'searches' => 7,
                'emails' => 0,
                'phone_numbers' => 0,
            ],
            'cost_breakdown_usd' => [
                'total' => 0.37,
                'emails' => 0,
                'phone_numbers' => 0,
                'agent_compute' => 0.29,
                'search' => 0.08,
            ],
        ]);
});

it('uses the remaining request-wide deadline for every lifecycle call', function (): void {
    $requestOptions = [];
    Http::globalMiddleware(function (callable $handler) use (&$requestOptions): Closure {
        return function ($request, array $options) use ($handler, &$requestOptions) {
            $requestOptions[] = $options;

            return $handler($request, $options);
        };
    });
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('completed'))
        ->push(exaResearchCompletedRun())]);

    exaResearchDriver()->run(exaResearchRequest(deadline: CarbonImmutable::now()->addMilliseconds(2345)));

    expect($requestOptions)->toHaveCount(2);
    foreach ($requestOptions as $options) {
        expect($options['timeout'] ?? null)->toBe(2.345)
            ->and($options['connect_timeout'] ?? null)->toBe(2.345);
    }
});

it('retrieves an immediately completed submission once before delivery', function (): void {
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('completed', output: ['text' => 'submission copy', 'structured' => null, 'grounding' => []]))
        ->push(exaResearchCompletedRun())]);

    $result = exaResearchDriver()->run(exaResearchRequest());

    expect($result->content)->toBe('Authoritative final report [[1]] and [[2]].');
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

it('normalizes non-empty structured output when no report text is present', function (): void {
    $terminal = exaResearchCompletedRun();
    $terminal['output']['text'] = '   ';
    $terminal['output']['structured'] = [
        'conclusion' => 'Asymmetric structured report',
        'score' => 7,
    ];
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('completed'))
        ->push($terminal)]);

    $result = exaResearchDriver()->run(exaResearchRequest(options: [
        'output_schema' => ['type' => 'object'],
    ]));

    expect($result->contentFormat)->toBe(ContentFormat::Json)
        ->and((array) $result->content)->toBe([
            'conclusion' => 'Asymmetric structured report',
            'score' => 7,
        ]);
});

it('rejects incompatible profile semantics before dispatch', function (array $overrides): void {
    Http::fake();

    expect(fn () => exaResearchDriver()->run(exaResearchRequest(...$overrides)))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'exa_research.invalid_profile'
            && ! $exception->fallbackAllowed);

    Http::assertNothingSent();
})->with([
    'provider' => [['provider' => 'exa-research']],
    'model' => [['model' => 'agent']],
    'result kind' => [['resultKind' => ResultKind::SearchResults]],
    'grounding' => [['grounding' => GroundingPolicy::None]],
    'observation' => [['observation' => ObservationMode::SurfaceSnapshot]],
    'corpus' => [['corpora' => [Corpus::News]]],
    'retrieval method' => [['retrievalMethods' => [RetrievalMethod::SearchEndpoint]]],
]);

it('rejects invalid credentials, input, options, and deadlines before dispatch', function (array $overrides, string $code): void {
    Http::fake();

    expect(fn () => exaResearchDriver()->run(exaResearchRequest(...$overrides)))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code
            && ! $exception->fallbackAllowed);

    Http::assertNothingSent();
})->with([
    'missing credential' => [['credential' => null], 'exa_research.authentication'],
    'blank prompt' => [['prompt' => '   '], 'exa_research.invalid_request'],
    'unknown option' => [['options' => ['unknown' => true]], 'exa_research.invalid_request'],
    'invalid effort' => [['options' => ['effort' => 'extreme']], 'exa_research.invalid_request'],
    'blank system prompt' => [['options' => ['system_prompt' => '  ']], 'exa_research.invalid_request'],
    'list output schema' => [['options' => ['output_schema' => [['type' => 'string']]]], 'exa_research.invalid_request'],
    'empty output schema' => [['options' => ['output_schema' => []]], 'exa_research.invalid_request'],
    'non-JSON output schema' => [['options' => ['output_schema' => ['bad' => new stdClass]]], 'exa_research.invalid_request'],
    'string cost' => [['options' => ['max_cost_dollars' => '1.00']], 'exa_research.invalid_request'],
    'non-positive cost' => [['options' => ['max_cost_dollars' => 0]], 'exa_research.invalid_request'],
    'budget on fixed effort' => [['options' => ['effort' => 'high', 'max_cost_dollars' => 1]], 'exa_research.invalid_request'],
    'expired deadline' => [['deadline' => CarbonImmutable::now()], 'exa_research.deadline_exceeded'],
]);

it('maps terminal provider failure without exposing provider error details', function (string $status): void {
    $failed = exaResearchRun($status);
    $failed['error'] = ['message' => 'provider-secret testing-exa-key '.exaResearchRunId()];
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('running'))
        ->push($failed)]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'exa_research.failed'
            && ! $exception->fallbackAllowed
            && ! str_contains($exception->getMessage(), 'provider-secret')
            && ! str_contains($exception->getMessage(), 'testing-exa-key')
            && ! str_contains($exception->getMessage(), exaResearchRunId()));
})->with(['failed', 'cancelled']);

it('times out a still-running task at the request-wide deadline', function (): void {
    Sleep::syncWithCarbon();
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('running'))]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest(
        deadline: CarbonImmutable::now()->addMilliseconds(250),
    )))->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'exa_research.deadline_exceeded'
        && ! $exception->fallbackAllowed);

    Http::assertSentCount(1);
    Sleep::assertSleptTimes(1);
});

it('never retries an ambiguous submission connection failure', function (): void {
    $attempts = 0;
    Http::fake(function (Request $request) use (&$attempts): never {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.exa.ai/agent/runs');
        $attempts++;

        throw new ConnectionException('provider-secret testing-exa-key');
    });

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'exa_research.submit_connection'
            && ! $exception->fallbackAllowed
            && $exception->getMessage() === 'The Exa Research submission did not return a response.'
            && ! str_contains($exception->getMessage(), 'provider-secret'));

    expect($attempts)->toBe(1);
});

it('maps submission and retrieval HTTP failures to safe non-fallback errors', function (int $status, string $code): void {
    Http::fake(['https://api.exa.ai/agent/runs' => Http::response([
        'error' => ['message' => 'provider-secret testing-exa-key'],
        'rawBody' => 'provider-secret',
    ], $status)]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code
            && ! $exception->fallbackAllowed
            && ! str_contains($exception->getMessage(), 'provider-secret')
            && ! str_contains($exception->getMessage(), 'testing-exa-key'));

    Http::assertSentCount(1);
})->with([
    'bad request' => [400, 'exa_research.invalid_request'],
    'authentication' => [401, 'exa_research.authentication'],
    'credits' => [402, 'exa_research.insufficient_credits'],
    'authorization' => [403, 'exa_research.authorization'],
    'timeout' => [408, 'exa_research.timeout'],
    'rate limit' => [429, 'exa_research.rate_limited'],
    'gateway timeout' => [504, 'exa_research.timeout'],
    'unavailable' => [503, 'exa_research.unavailable'],
]);

it('fails closed on malformed completion and mismatched retrieval handles', function (array $terminal): void {
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('completed'))
        ->push($terminal)]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'exa_research.invalid_response'
            && ! $exception->fallbackAllowed
            && ! str_contains($exception->getMessage(), 'provider-secret')
            && ! str_contains($exception->getMessage(), 'signed.example')
            && ! str_contains($exception->getMessage(), 'signed-secret'));
})->with([
    'different handle' => [exaResearchCompletedRun(id: 'agent_run_different')],
    'completion regressed' => [exaResearchRun('running')],
    'missing creation timestamp' => [[...exaResearchCompletedRun(), 'createdAt' => null]],
    'empty output' => [exaResearchRun('completed', output: ['text' => ' ', 'structured' => [], 'grounding' => []], completedAt: '2026-09-10T12:03:04Z')],
    'invalid citation URL' => [exaResearchRun('completed', output: [
        'text' => 'Report',
        'structured' => null,
        'grounding' => [['citations' => [['url' => 'https://user:pass@example.com/private']]]],
    ], completedAt: '2026-09-10T12:03:04Z')],
    'signed citation URL' => [exaResearchRun('completed', output: [
        'text' => 'Report',
        'structured' => null,
        'grounding' => [['citations' => [['url' => 'https://signed.example/report?X-Goog-Signature=signed-secret']]]],
    ], completedAt: '2026-09-10T12:03:04Z')],
    'invalid usage' => [[...exaResearchCompletedRun(), 'usage' => ['searches' => -1]]],
    'cost missing total' => [[...exaResearchCompletedRun(), 'costDollars' => ['search' => 0.08]]],
    'invalid stop reason' => [[...exaResearchCompletedRun(), 'stopReason' => 'provider-secret']],
]);

it('allowlists terminal values and excludes the credential, private handle, and raw provider fields', function (): void {
    $terminal = exaResearchCompletedRun();
    $terminal['request'] = ['query' => 'provider-secret', 'metadata' => ['api_key' => 'testing-exa-key']];
    $terminal['headers'] = ['x-api-key' => 'testing-exa-key'];
    $terminal['rawBody'] = 'provider-secret raw response';
    $terminal['usage']['unknownPrivateCounter'] = 999;
    $terminal['costDollars']['unknownPrivateCost'] = 999;
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('completed'))
        ->push($terminal)]);

    $wire = json_encode(exaResearchDriver()->run(exaResearchRequest())->toArray(), JSON_THROW_ON_ERROR);

    expect($wire)->not->toContain('testing-exa-key')
        ->not->toContain('provider-secret')
        ->not->toContain(exaResearchRunId())
        ->not->toContain('rawBody')
        ->not->toContain('headers')
        ->not->toContain('unknownPrivateCounter')
        ->not->toContain('unknownPrivateCost');
});

function exaResearchDriver(): ExaResearchDriver
{
    return app(ExaResearchDriver::class);
}

/** @return array<string, mixed> */
function exaResearchCompletedRun(?string $id = null): array
{
    return exaResearchRun(
        status: 'completed',
        output: [
            'text' => 'Authoritative final report [[1]] and [[2]].',
            'structured' => null,
            'grounding' => [
                ['field' => 'output.text', 'citations' => [[
                    'url' => 'https://one.example/evidence',
                    'title' => 'Primary source',
                    'text' => str_repeat('e', 230),
                ]]],
                ['field' => 'output.text', 'citations' => [
                    ['url' => 'https://one.example/evidence', 'title' => 'Duplicate'],
                    ['url' => 'https://two.example/evidence'],
                ]],
            ],
        ],
        completedAt: '2026-09-10T12:03:04Z',
        id: $id,
        extra: [
            'stopReason' => 'schema_satisfied',
            'usage' => [
                'agentComputeUnits' => 3,
                'searches' => 7,
                'emails' => 0,
                'phoneNumbers' => 0,
            ],
            'costDollars' => [
                'total' => 0.37,
                'agentCompute' => 0.29,
                'search' => 0.08,
                'emails' => 0,
                'phoneNumbers' => 0,
            ],
        ],
    );
}

/**
 * @param  array<string, mixed>|null  $output
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function exaResearchRun(
    string $status,
    ?array $output = null,
    ?string $completedAt = null,
    ?string $id = null,
    array $extra = [],
): array {
    return [
        'id' => $id ?? exaResearchRunId(),
        'object' => 'agent_run',
        'status' => $status,
        'stopReason' => null,
        'createdAt' => '2026-09-10T12:00:01Z',
        'completedAt' => $completedAt,
        'output' => $output ?? ['text' => '', 'structured' => null, 'grounding' => []],
        'usage' => ['agentComputeUnits' => 1, 'searches' => 2],
        'costDollars' => ['total' => 0.11, 'agentCompute' => 0.09, 'search' => 0.02],
        ...$extra,
    ];
}

function exaResearchRunId(): string
{
    return 'agent_run_01k4x7m9p2q5r8s1t3v6w9y2z4';
}

/** @param array<string, mixed> $options */
function exaResearchRequest(
    string $prompt = 'Compare durable research systems.',
    array $options = [],
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-exa-key',
    string $provider = 'exa',
    ?string $model = null,
    ResultKind $resultKind = ResultKind::ResearchReport,
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ObservationMode $observation = ObservationMode::ApiOutput,
    array $corpora = [Corpus::Web],
    array $retrievalMethods = [RetrievalMethod::ResearchAgent],
): DriverRequest {
    $profile = new Profile(
        id: 'exa-research',
        driver: ExaResearchDriver::class,
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
        requestId: 'request-exa-research',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
