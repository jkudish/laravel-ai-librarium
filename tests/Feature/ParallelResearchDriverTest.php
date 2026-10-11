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
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\ProfileOptionValidator;
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
    'invalid Task option' => fn () => [parallelResearchRequest(options: ['metadata' => ['nested' => ['a' => 'b']]]), 'parallel.invalid_options'],
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

it('sends every supported Task Run option with the text report schema', function (): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push(parallelResearchRun('completed'))
        ->push(parallelResearchResult())]);

    $result = app(ParallelResearchDriver::class)->run(parallelResearchRequest(options: [
        'include_domains' => ['.EDU'],
        'after_date' => '2026-01-01',
        'location' => 'gb',
        'output_schema' => 'A short briefing with a risks section.',
        'metadata' => ['tenant' => 'acme', 'attempt' => 2, 'priority' => 0.5, 'audited' => true],
        'memory_scope_key' => 'acme_research-1',
        'request_timeout_seconds' => 900,
    ]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === [
        'input' => 'Research current Parallel APIs deeply.',
        'processor' => 'pro',
        'metadata' => ['tenant' => 'acme', 'attempt' => 2, 'priority' => 0.5, 'audited' => true],
        'memory_scope_key' => 'acme_research-1',
        'task_spec' => ['output_schema' => ['type' => 'text', 'description' => 'A short briefing with a risks section.']],
        'source_policy' => ['include_domains' => ['.edu'], 'after_date' => '2026-01-01'],
        'advanced_settings' => ['location' => 'GB'],
    ]);
    expect($result->contentFormat)->toBe(ContentFormat::Markdown);
});

it('requests and returns structured JSON output for a JSON Schema', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => ['vendors' => ['type' => 'array', 'items' => ['type' => 'string']], 'summary' => ['type' => 'string']],
        'required' => ['vendors', 'summary'],
        'additionalProperties' => false,
    ];
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push(parallelResearchRun('completed'))
        ->push(parallelResearchResult(output: [
            'type' => 'json',
            'content' => ['vendors' => ['Parallel', 'Valyu'], 'summary' => 'Two vendors.', 'notes' => []],
            'basis' => [[
                'field' => 'vendors',
                'reasoning' => 'Listed on vendor sites.',
                'confidence' => 'high',
                'citations' => [['url' => 'https://example.com/vendors', 'title' => 'Vendors', 'excerpts' => ['Parallel and Valyu.']]],
            ]],
        ]))]);

    $result = app(ParallelResearchDriver::class)->run(parallelResearchRequest(options: ['output_schema' => $schema]));

    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST'
        || $request['task_spec'] === ['output_schema' => ['type' => 'json', 'json_schema' => $schema]]);
    expect($result->contentFormat)->toBe(ContentFormat::Json)
        ->and(json_encode($result->content, JSON_THROW_ON_ERROR))->toBe('{"vendors":["Parallel","Valyu"],"summary":"Two vendors.","notes":[]}')
        ->and($result->citations->sole()->source->url)->toBe('https://example.com/vendors')
        ->and(data_get($result->providerMeta, 'parallel:research.basis.0.field'))->toBe('vendors');
});

it('rejects output that does not match the requested schema type', function (array $options, array $output): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::sequence()
        ->push(parallelResearchRun('queued'), 202)
        ->push(parallelResearchRun('completed'))
        ->push(parallelResearchResult(output: $output))]);

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest(options: $options)))
        ->toThrow(fn (DriverException $exception) => expect($exception->errorCode)->toBe('parallel.invalid_response'));
})->with([
    'text for a JSON Schema' => [['output_schema' => ['type' => 'object']], ['type' => 'text', 'content' => 'Report', 'basis' => []]],
    'empty JSON object' => [['output_schema' => ['type' => 'object']], ['type' => 'json', 'content' => [], 'basis' => []]],
    'JSON for a text description' => [['output_schema' => 'A report.'], ['type' => 'json', 'content' => ['a' => 1], 'basis' => []]],
]);

it('submits each listed processor and polls it until its own default deadline unless a timeout or the shared deadline is shorter', function (string $processor, array $options, int $sharedSeconds, int $expectedSeconds): void {
    $startedAt = CarbonImmutable::now();
    $failedAt = null;
    $run = fn (string $status): array => [...parallelResearchRun($status), 'processor' => $processor];
    Http::fake(function (Request $request) use ($run) {
        if ($request->method() === 'POST') {
            return Http::response($run('queued'), 202);
        }
        // Each status poll observes five minutes passing, which divides every expected deadline.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(300));

        return Http::response($run('running'));
    });

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest(
        model: $processor,
        options: $options,
        deadline: $startedAt->addSeconds($sharedSeconds),
    )))->toThrow(function (DriverException $exception) use (&$failedAt): void {
        $failedAt = CarbonImmutable::now();
        expect($exception->errorCode)->toBe('parallel.deadline_exceeded');
    });

    expect((int) $startedAt->diffInSeconds($failedAt))->toBe($expectedSeconds);
    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST'
        || ($request['processor'] === $processor && ! array_key_exists('request_timeout_seconds', $request->data())));
})->with([
    'lite' => ['lite', [], 86400, 1800],
    'base' => ['base', [], 86400, 1800],
    'core' => ['core', [], 86400, 1800],
    'core2x' => ['core2x', [], 86400, 1800],
    'pro' => ['pro', [], 86400, 1800],
    'ultra' => ['ultra', [], 86400, 2700],
    'ultra2x' => ['ultra2x', [], 86400, 4500],
    'ultra4x' => ['ultra4x', [], 86400, 8100],
    'ultra8x' => ['ultra8x', [], 86400, 10800],
    'explicit timeout below the default' => ['ultra8x', ['request_timeout_seconds' => 600], 86400, 600],
    'explicit timeout above the default' => ['lite', ['request_timeout_seconds' => 3600], 86400, 3600],
    'shared deadline caps the default' => ['ultra', [], 1200, 1200],
]);

it('accepts every documented processor and rejects delisted ones', function (?string $processor, bool $valid): void {
    Http::fake(['https://api.parallel.ai/v1/tasks/runs*' => Http::response([...parallelResearchRun('queued'), 'processor' => $processor], 202)]);
    Sleep::whenFakingSleep(static function (): void {
        throw new RuntimeException('submitted');
    });

    expect(fn () => app(ParallelResearchDriver::class)->run(parallelResearchRequest(model: $processor)))
        ->toThrow($valid ? RuntimeException::class : DriverException::class, $valid ? 'submitted' : null);
    Http::assertSentCount($valid ? 1 : 0);
})->with([
    'lite' => ['lite', true],
    'core2x' => ['core2x', true],
    'ultra4x' => ['ultra4x', true],
    'delisted pro-fast' => ['pro-fast', false],
    'delisted ultra-fast' => ['ultra-fast', false],
    'unknown ultra16x' => ['ultra16x', false],
    'missing' => [null, false],
]);

it('validates Research options through the shared Profile validator', function (array $options, bool $valid): void {
    expect(app(ProfileOptionValidator::class)->errors(ParallelResearchDriver::class, $options) === [])->toBe($valid);
})->with([
    'all supported options' => [[
        'include_domains' => ['example.com/docs'],
        'exclude_domains' => ['.ai'],
        'after_date' => '2026-02-28',
        'location' => 'US',
        'output_schema' => ['type' => 'object'],
        'metadata' => ['sixteen_chars_ok' => str_repeat('x', 512)],
        'memory_scope_key' => str_repeat('k', 128),
        'request_timeout_seconds' => 86400,
    ], true],
    'impossible date' => [['after_date' => '2026-02-30'], false],
    'metadata key over 16 characters' => [['metadata' => ['seventeen_chars_x' => 'v']], false],
    'metadata string over 512 characters' => [['metadata' => ['note' => str_repeat('x', 513)]], false],
    'nested metadata' => [['metadata' => ['nested' => ['a' => 'b']]], false],
    'metadata list' => [['metadata' => ['a', 'b']], false],
    'empty metadata' => [['metadata' => []], false],
    'memory scope key with spaces' => [['memory_scope_key' => 'tenant one'], false],
    'memory scope key over 128 characters' => [['memory_scope_key' => str_repeat('k', 129)], false],
    'blank memory scope key' => [['memory_scope_key' => ''], false],
    'blank output description' => [['output_schema' => ' '], false],
    'list output schema' => [['output_schema' => ['object']], false],
    'string timeout' => [['request_timeout_seconds' => '600'], false],
    'zero timeout' => [['request_timeout_seconds' => 0], false],
    'processor is the Profile model' => [['processor' => 'ultra'], false],
]);

it('requires the pricing identity of the configured processor', function (string $processor, string $pricingModel, bool $eligible): void {
    config()->set('services.parallel.key', 'testing-parallel-key');
    config()->set('librarium.profiles.parallel-research.model', $processor);
    config()->set('librarium.profiles.parallel-research.pricing.model', $pricingModel);

    $availability = app(ProfileCatalog::class)->find('parallel-research');

    expect($availability?->isEligible())->toBe($eligible)
        ->and($availability?->reasons)->toBe($eligible ? [] : ["The pricing identity must be [parallel:research-{$processor}] to quote the configured research target."]);
})->with([
    'shipped pro' => ['pro', 'research-pro', true],
    'matching ultra8x' => ['ultra8x', 'research-ultra8x', true],
    'ultra quoted as pro' => ['ultra', 'research-pro', false],
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
