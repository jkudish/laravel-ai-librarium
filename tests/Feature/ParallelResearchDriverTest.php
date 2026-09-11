<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\Parallel\ParallelResearchDriver;
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

it('submits, polls, retrieves, and normalizes one durable Research report', function (): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push(parallelResearchRun('running'))
        ->push(parallelResearchRun('completed'))
        ->push(parallelResearchResult())]);
    $progress = [];

    $result = app(ParallelResearchDriver::class)->run(parallelResearchRequest(
        options: [
            'include_domains' => ['Docs.Example.com/php'],
            'exclude_domains' => ['blocked.example'],
            'location' => 'us',
        ],
        progress: $progress,
    ));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.parallel.ai/v1/tasks/runs'
        && $request->hasHeader('x-api-key', 'testing-parallel-key')
        && ! $request->hasHeader('Idempotency-Key')
        && $request->data() === [
            'input' => 'Research current Parallel APIs deeply.',
            'processor' => 'pro',
            'task_spec' => ['output_schema' => [
                'type' => 'text',
                'description' => 'A thorough cited research report that directly answers the request.',
            ]],
            'source_policy' => [
                'include_domains' => ['docs.example.com/php'],
                'exclude_domains' => ['blocked.example'],
            ],
            'advanced_settings' => ['location' => 'US'],
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.parallel.ai/v1/tasks/runs/'.parallelResearchRunId());
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.parallel.ai/v1/tasks/runs/'.parallelResearchRunId().'/result?timeout=25');
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(2);

    expect($progress)->toBe(['Parallel is researching in the background.'])
        ->and($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe('A researched report [[1]].')
        ->and($result->requestedProfile)->toBe('parallel-research')
        ->and($result->provider)->toBe('parallel')
        ->and($result->profile)->toBe('parallel-research')
        ->and($result->model)->toBe('pro')
        ->and($result->provenance->resultKind)->toBe(ResultKind::ResearchReport)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ResearchAgent])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->citations)->toHaveCount(1)
        ->and($result->citations->sole()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->sole()->source->url)->toBe('https://example.com/source')
        ->and($result->citations->sole()->excerpt)->toBe('First supporting excerpt.')
        ->and($result->usage)->toBeNull()
        ->and(data_get($result->providerMeta, 'parallel:research'))->toBe([
            'processor' => 'pro',
            'execution' => 'background',
            'resumability' => 'durable_provider_task',
            'metering' => ['unit' => 'processor_request', 'count' => 1],
            'basis' => [[
                'field' => 'output',
                'reasoning' => 'Compared independent sources.',
                'confidence' => 'medium',
                'citations' => [
                    [
                        'url' => 'https://example.com/source',
                        'title' => 'First source',
                        'excerpts' => ['First supporting excerpt.', 'Second supporting excerpt.'],
                    ],
                    [
                        'url' => 'https://example.com/source',
                        'title' => 'Duplicate source',
                        'excerpts' => ['Duplicate excerpt.'],
                    ],
                ],
            ]],
        ]);

    expect(json_encode($result->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain(parallelResearchRunId())
        ->not->toContain('unsafe.example')
        ->not->toContain('signed.example')
        ->not->toContain('signed-secret')
        ->not->toContain('provider-secret')
        ->not->toContain('testing-parallel-key');
});

it('retries only transient status and result retrieval, never the submission', function (): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push(['error' => ['message' => 'temporary']], 503)
        ->push(parallelResearchRun('completed'))
        ->push(['error' => ['message' => 'still active']], 408)
        ->push(parallelResearchResult())]);

    $result = app(ParallelResearchDriver::class)->run(parallelResearchRequest());

    expect($result->content)->toBe('A researched report [[1]].');
    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    expect(Http::recorded()->filter(fn (array $record): bool => $record[0]->method() === 'POST'))->toHaveCount(1);
    Sleep::assertSleptTimes(3);
});

it('maps every terminal Research status without retrieving or exposing provider errors', function (string $status, string $code): void {
    $terminal = parallelResearchRun($status);
    $terminal['error'] = ['message' => 'provider-secret testing-parallel-key'];
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push($terminal)]);

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('testing-parallel-key');
        });
    Http::assertSentCount(2);
})->with([
    'failed' => ['failed', 'parallel.research_failed'],
    'cancelled' => ['cancelled', 'parallel.cancelled'],
]);

it('fails an action-required run immediately without fallback or result retrieval', function (): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push(parallelResearchRun('action_required'))]);

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('parallel.action_required')
                ->and($exception->fallbackAllowed)->toBeFalse();
        });

    expect(Http::recorded()->map(fn (array $record): string => $record[0]->method())->all())->toBe(['POST', 'GET']);
});

it('fails at the shared deadline without another poll or result retrieval', function (): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs' => Http::response(parallelResearchRun('queued'), 202)]);
    Sleep::whenFakingSleep(static function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
    });

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest(
        deadline: CarbonImmutable::now()->addSecond(),
    )))->toThrow(function (DriverException $exception): void {
        expect($exception->errorCode)->toBe('parallel.deadline_exceeded')
            ->and($exception->fallbackAllowed)->toBeFalse();
    });
    Http::assertSentCount(1);
});

it('never retries an ambiguous Research submission', function (string $failure): void {
    $attempts = 0;
    Http::fake(function () use ($failure, &$attempts) {
        $attempts++;
        if ($failure === 'connection') {
            throw new ConnectionException('provider-secret testing-parallel-key');
        }

        return Http::response('', 302, ['Location' => 'https://elsewhere.example']);
    });

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('parallel.ambiguous_submission')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret');
        });
    expect($attempts)->toBe(1);
})->with(['connection', 'redirect']);

it('does not retry an invalid or failed submission response', function (mixed $response, int $status, string $code): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs' => Http::response($response, $status)]);

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret');
        });
    Http::assertSentCount(1);
})->with([
    'malformed accepted receipt' => [['run_id' => parallelResearchRunId(), 'status' => 'queued', 'processor' => 'ultra'], 202, 'parallel.ambiguous_submission'],
    'authentication' => [['error' => ['message' => 'provider-secret']], 401, 'parallel.authentication'],
    'credit' => [['error' => ['message' => 'provider-secret']], 402, 'parallel.insufficient_credit'],
    'ambiguous rate limit' => [['error' => ['message' => 'provider-secret']], 429, 'parallel.ambiguous_submission'],
    'ambiguous unavailable' => [['error' => ['message' => 'provider-secret']], 503, 'parallel.ambiguous_submission'],
    'invalid request' => [['error' => ['message' => 'provider-secret']], 422, 'parallel.invalid_request'],
]);

it('makes every known-handle HTTP failure non-fallback', function (int $status, string $code): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push(['error' => ['message' => 'provider-secret']], $status)]);

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret');
        });

    expect(Http::recorded()->filter(fn (array $record): bool => $record[0]->method() === 'POST'))->toHaveCount(1);
})->with([
    'authentication' => [401, 'parallel.authentication'],
    'missing run' => [404, 'parallel.run_missing'],
    'invalid request' => [422, 'parallel.invalid_request'],
]);

it('rejects invalid profile targets, options, input, credentials, and deadlines before submission', function (DriverRequest $request, string $code): void {
    Http::fake();

    expect(fn () => app(ParallelResearchDriver::class)->run($request))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code);
        });
    Http::assertNothingSent();
})->with([
    'wrong profile' => fn () => [parallelResearchRequest(profileId: 'parallel-chat'), 'parallel.invalid_profile'],
    'wrong provider' => fn () => [parallelResearchRequest(provider: 'parallel-research'), 'parallel.invalid_profile'],
    'unsupported processor' => fn () => [parallelResearchRequest(model: 'pro-fast'), 'parallel.invalid_profile'],
    'wrong result kind' => fn () => [parallelResearchRequest(resultKind: ResultKind::GroundedAnswer), 'parallel.invalid_profile'],
    'unknown option' => fn () => [parallelResearchRequest(options: ['processor' => 'pro']), 'parallel.invalid_options'],
    'invalid source' => fn () => [parallelResearchRequest(options: ['include_domains' => ['https://example.com']]), 'parallel.invalid_options'],
    'blank input' => fn () => [parallelResearchRequest(prompt: '   '), 'parallel.invalid_request'],
    'missing credential' => fn () => [parallelResearchRequest(credential: null), 'parallel.authentication'],
    'expired deadline' => fn () => [parallelResearchRequest(deadline: CarbonImmutable::now()), 'parallel.deadline_exceeded'],
]);

it('fails closed on mismatched lifecycle identities and malformed results', function (array $status, ?array $result): void {
    $sequence = Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push($status);
    if ($result !== null) {
        $sequence->push($result);
    }
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => $sequence]);

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('parallel.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse();
        });
})->with([
    'mismatched status id' => [[...parallelResearchRun('running'), 'run_id' => 'trun_other'], null],
    'unknown status' => [[...parallelResearchRun('running'), 'status' => 'unknown'], null],
    'mismatched result id' => [parallelResearchRun('completed'), parallelResearchResult(runId: 'trun_other')],
    'structured output' => [parallelResearchRun('completed'), parallelResearchResult(output: ['type' => 'json', 'content' => ['report' => true], 'basis' => []])],
    'blank report' => [parallelResearchRun('completed'), parallelResearchResult(output: ['type' => 'text', 'content' => ' ', 'basis' => []])],
]);

it('rejects credentials and private handles reflected into retained Research output', function (string $private, string $field): void {
    $result = parallelResearchResult();
    if ($field === 'content') {
        $result['output']['content'] = "Report containing {$private}";
    } else {
        $result['output']['basis'][0]['reasoning'] = "Reasoning containing {$private}";
    }
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push(parallelResearchRun('completed'))
        ->push($result)]);

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest()))
        ->toThrow(function (DriverException $exception) use ($private): void {
            expect($exception->errorCode)->toBe('parallel.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain($private);
        });
})->with([
    'credential in report' => ['testing-parallel-key', 'content'],
    'private handle in basis' => [parallelResearchRunId(), 'basis'],
]);

/** @return array<string, mixed> */
function parallelResearchRun(string $status): array
{
    return [
        'run_id' => parallelResearchRunId(),
        'interaction_id' => 'interaction-private',
        'status' => $status,
        'is_active' => in_array($status, ['queued', 'running', 'cancelling'], true),
        'processor' => 'pro',
        'created_at' => '2026-09-10T12:00:00Z',
        'modified_at' => '2026-09-10T12:01:00Z',
    ];
}

/**
 * @param  array<string, mixed>|null  $output
 * @return array<string, mixed>
 */
function parallelResearchResult(?array $output = null, ?string $runId = null): array
{
    return [
        'run' => [
            ...parallelResearchRun('completed'),
            'run_id' => $runId ?? parallelResearchRunId(),
        ],
        'output' => $output ?? [
            'type' => 'text',
            'content' => 'A researched report [[1]].',
            'basis' => [[
                'field' => 'output',
                'reasoning' => 'Compared independent sources.',
                'confidence' => 'medium',
                'citations' => [
                    [
                        'url' => 'javascript:alert(1)',
                        'title' => 'provider-secret',
                        'excerpts' => ['provider-secret'],
                    ],
                    [
                        'url' => 'https://user:pass@unsafe.example',
                        'title' => 'provider-secret',
                        'excerpts' => ['provider-secret'],
                    ],
                    [
                        'url' => 'https://signed.example/report?X-Goog-Signature=signed-secret',
                        'title' => 'provider-secret',
                        'excerpts' => ['provider-secret'],
                    ],
                    [
                        'url' => 'https://example.com/source',
                        'title' => 'First source',
                        'excerpts' => ['First supporting excerpt.', 'Second supporting excerpt.'],
                    ],
                    [
                        'url' => 'https://example.com/source',
                        'title' => 'Duplicate source',
                        'excerpts' => ['Duplicate excerpt.'],
                    ],
                ],
            ]],
            'mcp_tool_calls' => [['arguments' => 'provider-secret']],
        ],
        'raw' => ['credential' => 'testing-parallel-key'],
    ];
}

/** @param array<string, mixed> $options
 * @param  list<string>  $progress
 */
function parallelResearchRequest(
    string $profileId = 'parallel-research',
    string $provider = 'parallel',
    ?string $model = 'pro',
    ResultKind $resultKind = ResultKind::ResearchReport,
    array $options = [],
    string $prompt = 'Research current Parallel APIs deeply.',
    ?string $credential = 'testing-parallel-key',
    ?CarbonImmutable $deadline = null,
    array &$progress = [],
): DriverRequest {
    $profile = new Profile(
        id: $profileId,
        driver: ParallelResearchDriver::class,
        provider: $provider,
        model: $model,
        resultKind: $resultKind,
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
        requestId: 'parallel-research-request',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static function (string $message) use (&$progress): void {
            $progress[] = $message;
        },
    );
}

function parallelResearchRunId(): string
{
    return 'trun_1234567890abcdef';
}
