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
use Jkudish\LaravelAiLibrarium\ProfileOptionValidator;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;

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

    $driver = exaResearchDriver();
    $request = exaResearchRequest(options: [
        'effort' => 'max',
        'system_prompt' => 'Prefer primary sources.',
        'output_schema' => [
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
        ],
        'max_cost_dollars' => 1.75,
    ]);
    $bound = $driver->spendingBound($request);
    $result = $driver->run($request);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.exa.ai/agent/runs'
            && $request->hasHeader('x-api-key', 'testing-exa-key')
            && $request->hasHeader('Exa-Beta', 'agent-max-effort-2026-07-27')
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

    expect($bound->kind)->toBe(SpendingBoundKind::NativeMonetaryCeiling)
        ->and((string) $bound->maximumCost?->amount)->toBe('1.75')
        ->and($bound->maximumCost?->currency)->toBe('USD')
        ->and($transports)->toHaveCount(4)
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
        ->and($result->provenance->observationMode)->toBe(ObservationMode::ApiOutput)
        ->and($result->toArray()['provenance']['observation_mode'])->toBe('api_output')
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

it('preserves API-output provenance through durable retrieval and serialization', function (): void {
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('running'))
        ->push(exaResearchCompletedRun())]);
    $driver = exaResearchDriver();
    $driverRequest = exaResearchRequest(options: ['effort' => 'auto', 'max_cost_dollars' => 9.5]);
    $submission = $driver->submitDurable($driverRequest);
    $result = $driver->retrieveDurable(new RecoveryRequest(
        requestId: $driverRequest->requestId,
        requestedProfile: $driverRequest->requestedProfile,
        profile: $driverRequest->profile,
        deadline: $driverRequest->deadline,
        responseCallback: static fn (): null => null,
        noResponseCallback: static fn (): null => null,
    ), $submission->reference);

    expect($result)->not->toBeNull()
        ->and($result?->provenance->observationMode)->toBe(ObservationMode::ApiOutput)
        ->and($result?->toArray()['provenance']['observation_mode'])->toBe('api_output');
    Http::assertSentCount(2);
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

    $driver = exaResearchDriver();
    $request = exaResearchRequest();
    $bound = $driver->spendingBound($request);
    $result = $driver->run($request);

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($result->content)->toBe('Authoritative final report [[1]] and [[2]].');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && ! array_key_exists('budget', $request->data())
        && ! $request->hasHeader('Exa-Beta'));
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

it('retries known-handle connection and transient HTTP failures without resubmitting', function (): void {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;

        return match ($calls) {
            1 => Http::response(exaResearchRun('running')),
            2 => throw new ConnectionException('provider-secret testing-exa-key'),
            3 => Http::response(['error' => 'provider-secret'], 408),
            4 => Http::response(['error' => 'provider-secret'], 429),
            5 => Http::response(['error' => 'provider-secret'], 503),
            default => Http::response(exaResearchCompletedRun()),
        };
    });

    $result = exaResearchDriver()->run(exaResearchRequest());

    expect($result->content)->toContain('Authoritative final report')
        ->and($calls)->toBe(7)
        ->and(Http::recorded()->filter(fn (array $record): bool => $record[0]->method() === 'POST'))->toHaveCount(1);
    Sleep::assertSleptTimes(5);
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

it('preserves JSON object and array distinctions in structured output', function (): void {
    $terminal = exaResearchCompletedRun();
    $terminal['output']['text'] = '';
    $terminal['output']['structured'] = (object) [
        'empty_object' => (object) [],
        'numeric_key_object' => (object) ['0' => 'value'],
        'empty_array' => [],
    ];
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('completed'))
        ->push(json_encode($terminal, JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json'])]);

    $result = exaResearchDriver()->run(exaResearchRequest());

    expect(json_encode($result->content, JSON_THROW_ON_ERROR))->toBe(
        '{"empty_object":{},"numeric_key_object":{"0":"value"},"empty_array":[]}'
    );
});

it('rejects incompatible profile semantics before dispatch', function (array $overrides): void {
    Http::fake();

    expect(fn () => exaResearchDriver()->run(exaResearchRequest(...$overrides)))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('exa_research.invalid_profile')
                ->and($exception->fallbackAllowed)->toBeFalse();
        });

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
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse();
        });

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
    'below provider minimum' => [['options' => ['max_cost_dollars' => 0.99]], 'exa_research.invalid_request'],
    'above provider maximum' => [['options' => ['max_cost_dollars' => 100.01]], 'exa_research.invalid_request'],
    'budget on fixed effort' => [['options' => ['effort' => 'high', 'max_cost_dollars' => 1]], 'exa_research.invalid_request'],
    'expired deadline' => [['deadline' => CarbonImmutable::parse('2026-09-10T11:59:59Z')], 'exa_research.deadline_exceeded'],
]);

it('rejects configured options that runtime preflight would reject', function (array $options): void {
    $errors = app(ProfileOptionValidator::class)->errors(ExaResearchDriver::class, $options);

    expect($errors)->not->toBeEmpty();
})->with([
    'string budget' => [['max_cost_dollars' => '1.00']],
    'non-finite budget' => [['max_cost_dollars' => INF]],
    'budget with fixed effort' => [['effort' => 'high', 'max_cost_dollars' => 1]],
]);

it('maps terminal provider failure without exposing provider error details', function (string $status): void {
    $failed = exaResearchRun($status);
    $failed['error'] = ['message' => 'provider-secret testing-exa-key '.exaResearchRunId()];
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('running'))
        ->push($failed)]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('exa_research.failed')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('testing-exa-key')
                ->not->toContain(exaResearchRunId());
        });
})->with(['failed', 'cancelled']);

it('times out a still-running task at the request-wide deadline', function (): void {
    Sleep::syncWithCarbon();
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('running'))]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest(
        deadline: CarbonImmutable::now()->addMilliseconds(250),
    )))->toThrow(function (DriverException $exception): void {
        expect($exception->errorCode)->toBe('exa_research.deadline_exceeded')
            ->and($exception->fallbackAllowed)->toBeFalse();
    });

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
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('exa_research.submit_connection')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->toBe('The Exa Research submission did not return a response.')
                ->not->toContain('provider-secret');
        });

    expect($attempts)->toBe(1);
});

it('validates an accepted private handle before rejecting a late submission response', function (): void {
    $deadline = CarbonImmutable::now()->addSecond();
    Http::fake(function () use ($deadline) {
        CarbonImmutable::setTestNow($deadline);

        return Http::response(exaResearchRun('running'));
    });

    expect(fn () => exaResearchDriver()->run(exaResearchRequest(deadline: $deadline)))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('exa_research.deadline_exceeded')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain(exaResearchRunId());
        });

    Http::assertSentCount(1);
});

it('maps submission and retrieval HTTP failures to safe non-fallback errors', function (int $status, string $code): void {
    Http::fake(['https://api.exa.ai/agent/runs' => Http::response([
        'error' => ['message' => 'provider-secret testing-exa-key'],
        'rawBody' => 'provider-secret',
    ], $status)]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('testing-exa-key');
        });

    Http::assertSentCount(1);
})->with([
    'bad request' => [400, 'exa_research.invalid_request'],
    'authentication' => [401, 'exa_research.authentication'],
    'credits' => [402, 'exa_research.insufficient_credits'],
    'authorization' => [403, 'exa_research.authorization'],
    'timeout' => [408, 'exa_research.ambiguous_submission'],
    'rate limit' => [429, 'exa_research.ambiguous_submission'],
    'gateway timeout' => [504, 'exa_research.ambiguous_submission'],
    'unavailable' => [503, 'exa_research.ambiguous_submission'],
]);

it('treats a malformed successful submission receipt as ambiguous and never resubmits', function (): void {
    Http::fake(['https://api.exa.ai/agent/runs' => Http::response([
        'id' => exaResearchRunId(),
        'status' => 'running',
        'rawBody' => 'provider-secret',
    ])]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('exa_research.ambiguous_submission')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain(exaResearchRunId())
                ->not->toContain('provider-secret');
        });

    Http::assertSentCount(1);
});

it('fails closed on malformed completion and mismatched retrieval handles', function (array $terminal): void {
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('completed'))
        ->push($terminal)]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('exa_research.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('signed.example')
                ->not->toContain('signed-secret');
        });
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

it('rejects credentials and private handles reflected into retained terminal fields', function (string $private, string $field): void {
    $terminal = exaResearchCompletedRun();
    if ($field === 'text') {
        $terminal['output']['text'] = "Report containing {$private}";
    } else {
        $terminal['output']['grounding'][0]['citations'][0]['title'] = "Source {$private}";
    }
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(exaResearchRun('completed'))
        ->push($terminal)]);

    expect(fn () => exaResearchDriver()->run(exaResearchRequest()))
        ->toThrow(function (DriverException $exception) use ($private): void {
            expect($exception->errorCode)->toBe('exa_research.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain($private);
        });
})->with([
    'credential in report text' => ['testing-exa-key', 'text'],
    'private handle in citation title' => [exaResearchRunId(), 'citation'],
]);

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
