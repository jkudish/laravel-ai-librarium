<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Collection\Enums\FailureCategory;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\Enums\SubmissionState;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Contracts\RecoversResearch;
use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoCredential;
use Jkudish\LaravelAiLibrarium\Exceptions\RecoveryException;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\Recovery\ResearchHandleSigner;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\ResearchStatusStore;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11T12:00:00Z');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('round trips a signed handle through JSON and a reconstructed application regardless of associative key order', function (): void {
    Http::fake(['https://api.exa.ai/agent/runs' => Http::response(durableExaRun('running', secret: true))]);
    $submitted = Librarium::query('Persist this research.')
        ->using('exa-research')
        ->submit();
    $wire = json_encode($submitted->handle, JSON_THROW_ON_ERROR);
    $value = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('The encoded handle must decode to an array.');
    }
    $value = array_reverse($value, true);

    durableReconstructRuntime();
    Http::preventStrayRequests();
    Http::fake(['https://api.exa.ai/agent/runs/*' => Http::response(durableExaRun('running'))]);

    $restored = ResearchHandle::fromArray($value);
    $retrieved = Librarium::retrieve($restored);

    expect($restored->toArray())->toBe($submitted->handle->toArray())
        ->and($retrieved->pending())->toBeTrue()
        ->and($retrieved->handle)->toBe($restored);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.exa.ai/agent/runs/'.durableExaReference());
});

it('preserves DataForSEO submission cost across restart and never treats retrieval cost as another charge', function (): void {
    durableConfigureDataForSeo();
    Http::fake(function (Request $request) {
        $operation = $request->method() === 'POST'
            ? 'task_post'
            : 'task_get/advanced/'.durableDataForSeoReference();

        return Http::response(durableDataForSeoEnvelope(
            operation: $operation,
            status: $request->method() === 'POST' ? 20100 : 20000,
            completed: $request->method() === 'GET',
            cost: $request->method() === 'POST' ? '0.001200' : '0',
        ));
    });

    $submitted = Librarium::query('DataForSEO durable query')
        ->using('dataforseo-gemini')
        ->submit();
    $wire = json_encode($submitted->handle, JSON_THROW_ON_ERROR);
    $stored = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($stored)) {
        throw new RuntimeException('The encoded handle must decode to an array.');
    }

    durableReconstructRuntime();
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => Http::response(durableDataForSeoEnvelope(
        operation: 'task_get/advanced/'.durableDataForSeoReference(),
        status: 20000,
        completed: true,
        cost: '0',
    )));

    $restored = ResearchHandle::fromArray($stored);
    $completed = Librarium::retrieve($restored);

    expect($submitted->usage()?->actualCost)->toBe('0.001200')
        ->and($restored->submittedUsage?->actualCost)->toBe('0.001200')
        ->and($completed->usage()?->actualCost)->toBe('0.001200')
        ->and($completed->response?->results->sole()->usage?->actualCost)->toBe('0.001200')
        ->and(data_get($completed->response?->results->sole()->providerMeta, 'task_id'))->toBeNull();
    expect(Http::recorded())->toHaveCount(1)
        ->and(Http::recorded()->sole()[0]->method())->toBe('GET');
});

it('distinguishes a reported zero DataForSEO submission cost from unknown cost', function (?string $cost, ?string $expected): void {
    durableConfigureDataForSeo();
    Http::fake(fn (Request $request) => Http::response(durableDataForSeoEnvelope(
        operation: 'task_post',
        status: 20100,
        completed: false,
        cost: $cost,
    )));

    $submitted = Librarium::query('DataForSEO durable query')
        ->using('dataforseo-gemini')
        ->submit();

    expect($submitted->usage()?->actualCost)->toBe($expected)
        ->and($submitted->handle->submittedUsage?->actualCost)->toBe($expected);
    Http::assertSentCount(1);
})->with([
    'reported zero' => ['0', '0'],
    'unknown' => [null, null],
]);

it('rejects DataForSEO Live durable submission before HTTP', function (): void {
    durableConfigureDataForSeo('dataforseo-gemini-live');
    Http::fake();

    try {
        Librarium::query('Must remain inline')->using('dataforseo-gemini-live')->submit();
        throw new RuntimeException('Live durable submission unexpectedly succeeded.');
    } catch (RecoveryException $exception) {
        expect($exception->getMessage())->toContain('DataForSEO Live Profiles run inline and cannot be submitted')
            ->and($exception->diagnostic->operation)->toBe(Operation::Submit)
            ->and($exception->diagnostic->submissionState)->toBe(SubmissionState::NotSent);
    }
    Http::assertNothingSent();
});

it('requires using with one exact profile and forbids fallbacks before any request', function (string $selector): void {
    Http::fake();
    $query = Librarium::query('Invalid durable selector');
    match ($selector) {
        'none' => null,
        'group' => $query->group('quick'),
        'fanOut' => $query->fanOut(['exa-research']),
        'fallback' => $query->using('exa-research')->fallbacks(['gemini-deep-research']),
        default => throw new RuntimeException("Unknown selector [{$selector}]."),
    };

    expect(fn () => $query->submit())
        ->toThrow(ValidationException::class, 'Durable submission requires using() with one exact Profile and no fallbacks.');
    Http::assertNothingSent();
})->with([
    'no selector' => ['none'],
    'group' => ['group'],
    'fan out' => ['fanOut'],
    'fallback' => ['fallback'],
]);

it('requires a valid application key before durable submission', function (?string $key): void {
    config()->set('app.key', $key);
    Http::fake();

    expect(fn () => Librarium::query('Must not submit')->using('exa-research')->submit())
        ->toThrow(LogicException::class, $key === null
            ? 'A configured application key is required'
            : 'The configured application key is invalid');
    Http::assertNothingSent();
})->with([
    'missing key' => [null],
    'invalid base64 key' => ['base64:not-valid-base64'],
]);

it('rejects unsupported and inline profiles before HTTP', function (string $profile, string $exception, string $message): void {
    Http::fake();

    expect(fn () => Librarium::query('Must be durable')->using($profile)->submit())
        ->toThrow($exception, $message);
    Http::assertNothingSent();
})->with([
    'non-durable SDK profile' => ['gemini-grounded', ValidationException::class, 'does not support durable submission'],
    'inline Perplexity profile' => ['perplexity-sonar-pro', RecoveryException::class, 'runs inline and cannot be submitted'],
]);

it('submits exactly once and returns redacted evidence and a nontrivial signed handle', function (): void {
    Http::fake(['https://api.exa.ai/agent/runs' => Http::response(
        durableExaRun('running', secret: true),
        200,
        ['X-Request-Id' => 'safe-request', 'Authorization' => 'testing-exa-key'],
    )]);

    $outcome = Librarium::query('Submit once')->using('exa-research')->submit();

    $wire = json_encode($outcome, JSON_THROW_ON_ERROR);
    expect($outcome->pending())->toBeTrue()
        ->and($outcome->coverage)->toBe(CaptureCoverage::Complete)
        ->and($outcome->evidence)->toHaveCount(1)
        ->and($outcome->evidence->sole()->operation)->toBe(Operation::Submit)
        ->and($outcome->evidence->sole()->headers)->toBe([
            'content-type' => ['application/json'],
            'x-request-id' => ['safe-request'],
        ])
        ->and($outcome->handle->reference)->toBe(durableExaReference())
        ->and($outcome->handle->fingerprint)->toMatch('/^[a-f0-9]{64}$/');
    PHPUnit::assertNotSame(str_repeat('0', 64), $outcome->handle->fingerprint);
    PHPUnit::assertStringContainsString('[REDACTED]', $wire);
    PHPUnit::assertStringNotContainsString('testing-exa-key', $wire);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('does not resubmit when caller persistence fails after successful submission', function (): void {
    Http::fake(['https://api.exa.ai/agent/runs' => Http::response(durableExaRun('running'))]);

    try {
        Librarium::query('Caller persistence')->using('exa-research')->submit();
        throw new RuntimeException('database unavailable');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('database unavailable');
    }

    Http::assertSentCount(1);
    expect(Http::recorded()->filter(fn (array $pair): bool => $pair[0]->method() === 'POST'))->toHaveCount(1);
});

it('retrieves pending and completed Exa outcomes using only the known handle GET without sleeping', function (): void {
    $handle = durableSubmittedHandle();

    Http::fake(['https://api.exa.ai/agent/runs/*' => Http::sequence()
        ->push(durableExaRun('running'))
        ->push(durableExaRun('completed'))]);

    $pending = Librarium::retrieve($handle);
    $completed = Librarium::retrieve($handle);

    expect($pending->pending())->toBeTrue()
        ->and($completed->pending())->toBeFalse()
        ->and($completed->response?->results->sole()->content)->toBe('Durable report.')
        ->and($completed->response?->requestId)->toBe($handle->requestId);
    durableExpectOnlyKnownGets(2, 'https://api.exa.ai/agent/runs/'.durableExaReference());
});

it('exposes safe operational diagnostics for terminal and transient retrieval failures', function (array $response, FailureCategory $category, int $status): void {
    $handle = durableSubmittedHandle();
    Http::fake(['https://api.exa.ai/agent/runs/*' => Http::response($response, $status, [
        'Retry-After' => '30',
        'Authorization' => 'testing-exa-key',
    ])]);

    try {
        Librarium::retrieve($handle);
        throw new RuntimeException('Retrieval unexpectedly succeeded.');
    } catch (RecoveryException $exception) {
        expect($exception->diagnostic->category)->toBe($category)
            ->and($exception->diagnostic->operation)->toBe(Operation::Retrieve)
            ->and($exception->diagnostic->submissionState)->toBe(SubmissionState::Accepted)
            ->and($exception->diagnostic->httpStatus)->toBe($status);
        PHPUnit::assertStringNotContainsString('testing-exa-key', $exception->getMessage());
        PHPUnit::assertStringNotContainsString('provider-secret', $exception->getMessage());
        $diagnostic = json_encode($exception->diagnostic, JSON_THROW_ON_ERROR);
        PHPUnit::assertStringNotContainsString('testing-exa-key', $diagnostic);
        PHPUnit::assertStringNotContainsString('provider-secret', $diagnostic);
    }

    durableExpectOnlyKnownGets(1, 'https://api.exa.ai/agent/runs/'.durableExaReference());
})->with([
    'terminal provider state' => [durableExaRun('failed', secret: true), FailureCategory::Permanent, 200],
    'transient HTTP failure' => [['error' => ['code' => 'overloaded', 'message' => 'provider-secret testing-exa-key']], FailureCategory::Temporary, 503],
]);

it('rejects altered handle identity reference and signature before HTTP', function (string $field, mixed $replacement): void {
    $handle = durableSubmittedHandle();
    $value = $handle->toArray();
    $value[$field] = $replacement;
    Http::fake();

    expect(fn () => Librarium::retrieve(ResearchHandle::fromArray($value)))
        ->toThrow(ValidationException::class, 'does not match the current Profile binding');
    Http::assertNothingSent();
})->with([
    'provider' => ['provider', 'other'],
    'provider reference' => ['reference', 'agent_run_changed'],
    'signature' => ['fingerprint', str_repeat('a', 64)],
]);

it('rejects changed profile binding or credential after process reconstruction before HTTP', function (string $path, mixed $replacement): void {
    $handle = durableSubmittedHandle();

    durableReconstructRuntime();
    config()->set($path, $replacement);
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => Librarium::retrieve(ResearchHandle::fromArray($handle->toArray())))
        ->toThrow(ValidationException::class, 'does not match the current Profile binding');
    Http::assertNothingSent();
})->with([
    'credential' => ['librarium.profiles.exa-research.credential', 'rotated-exa-key'],
    'profile semantics' => ['librarium.profiles.exa-research.options', ['effort' => 'max']],
]);

it('omits evidence when capture is disabled for submit and retrieve', function (): void {
    config()->set('librarium.redaction', ['invalid' => true]);
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(durableExaRun('running'))
        ->push(durableExaRun('running'))]);

    $submitted = Librarium::query('No evidence')->using('exa-research')->submit(false);
    $retrieved = Librarium::retrieve($submitted->handle, false);

    expect($submitted->coverage)->toBe(CaptureCoverage::Unavailable)
        ->and($submitted->evidence)->toBeEmpty()
        ->and($retrieved->coverage)->toBe(CaptureCoverage::Unavailable)
        ->and($retrieved->evidence)->toBeEmpty();
});

it('labels both completed Parallel public requests as retrieval observations', function (): void {
    $handle = durableHandle('parallel-research', durableParallelReference());
    Http::fakeSequence()
        ->push(durableParallelRun('completed'))
        ->push(durableParallelResult());

    $outcome = Librarium::retrieve($handle);
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

    expect($outcome->pending())->toBeFalse()
        ->and($outcome->response?->results->sole()->content)->toBe('Durable Parallel report.')
        ->and($outcome->evidence)->toHaveCount(2)
        ->and($outcome->evidence->pluck('operation')->all())->toBe([
            Operation::Retrieve,
            Operation::Retrieve,
        ])
        ->and($requests->map(fn (Request $request): string => $request->method())->all())->toBe(['GET', 'GET'])
        ->and($requests->map(fn (Request $request): string => $request->url())->all())->toBe([
            'https://api.parallel.ai/v1/tasks/runs/'.durableParallelReference(),
            'https://api.parallel.ai/v1/tasks/runs/'.durableParallelReference().'/result?timeout=25',
        ]);
    Sleep::assertNeverSlept();
});

it('rejects altered submitted usage before HTTP', function (): void {
    $handle = durableSubmittedHandle();
    $value = $handle->toArray();
    $value['submitted_usage'] = ['actual_cost' => '9.99', 'currency' => 'USD'];
    Http::fake();

    expect(fn () => Librarium::retrieve(ResearchHandle::fromArray($value)))
        ->toThrow(ValidationException::class, 'does not match the current Profile binding');
    Http::assertNothingSent();
});

it('does not emit package events or write transient status during durable submission and retrieval', function (): void {
    Event::fake();
    Http::fake(['https://api.exa.ai/agent/runs*' => Http::sequence()
        ->push(durableExaRun('running'))
        ->push(durableExaRun('running'))]);

    $submitted = Librarium::query('Application-owned lifecycle')->using('exa-research')->submit();
    Librarium::retrieve($submitted->handle);

    Event::assertNothingDispatched();
    expect(app(ResearchStatusStore::class)->get($submitted->handle->requestId))->toBeNull();
});

it('uses provider-specific durable retrieval without POST polling fallback cancellation run or sleep', function (string $profile, string $reference, array $pending, string $url): void {
    $handle = durableHandle($profile, $reference);
    Http::fakeSequence()->push($pending);
    $outcome = Librarium::retrieve($handle, false);
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

    expect($outcome->pending())->toBeTrue()
        ->and($requests)->toHaveCount(1)
        ->and($requests->sole()->method())->toBe('GET')
        ->and($requests->sole()->url())->toBe($url);
    Sleep::assertNeverSlept();
})->with([
    'Exa Research' => ['exa-research', durableExaReference(), durableExaRun('running'), 'https://api.exa.ai/agent/runs/'.durableExaReference()],
    'Gemini Deep' => ['gemini-deep-research', durableGeminiReference(), durableGeminiInteraction('in_progress'), 'https://generativelanguage.googleapis.com/v1beta/interactions/'.durableGeminiReference()],
    'Perplexity medium' => ['perplexity-deep-research', durablePerplexityReference(), ['id' => durablePerplexityReference(), 'status' => 'in_progress'], 'https://api.perplexity.ai/v1/agent/'.durablePerplexityReference()],
    'Perplexity high' => ['perplexity-sonar-deep', durablePerplexityReference(), ['id' => durablePerplexityReference(), 'status' => 'in_progress'], 'https://api.perplexity.ai/v1/agent/'.durablePerplexityReference()],
    'Parallel Research' => ['parallel-research', durableParallelReference(), durableParallelRun('running'), 'https://api.parallel.ai/v1/tasks/runs/'.durableParallelReference()],
    'Valyu Research' => ['valyu-research', durableValyuReference(), durableValyuTask('queued'), 'https://api.valyu.ai/v1/deepresearch/tasks/'.durableValyuReference().'/status'],
]);

it('never cancels or resubmits a known Valyu task when public retrieval fails', function (): void {
    $handle = durableHandle('valyu-research', durableValyuReference());
    Http::fakeSequence()->push(durableValyuTask('failed'));

    expect(fn () => Librarium::retrieve($handle))
        ->toThrow(RecoveryException::class, 'could not complete');
    expect(Http::recorded())->toHaveCount(1)
        ->and(Http::recorded()->sole()[0]->method())->toBe('GET');
    Sleep::assertNeverSlept();
});

it('fake submit and retrieve return durable outcomes and record both calls', function (): void {
    $fake = Librarium::fake();

    $submitted = Librarium::query('Fake durable')->using('exa-research')->submit();
    $retrieved = Librarium::retrieve($submitted->handle);

    expect($submitted->pending())->toBeTrue()
        ->and($retrieved->pending())->toBeTrue()
        ->and($fake->submittedQueries())->toHaveCount(1)
        ->and($fake->retrievedHandles())->toHaveCount(1)
        ->and($fake->retrievedHandles()->sole())->toBe($submitted->handle);
    $fake->assertSubmitted('Fake durable')->assertRetrieved($submitted->handle->requestId);
});

function durableSubmittedHandle(): ResearchHandle
{
    return durableHandle('exa-research', durableExaReference());
}

function durableHandle(string $profileId, string $reference): ResearchHandle
{
    $available = app(ProfileCatalog::class)->find($profileId);
    if ($available === null) {
        throw new RuntimeException("Missing test profile [{$profileId}].");
    }

    return app(ResearchHandleSigner::class)->issue(
        $available->profile,
        'durable-request-123',
        $reference,
        CarbonImmutable::now(),
    );
}

function durableReconstructRuntime(): void
{
    app()->forgetInstance(ProfileCatalog::class);
    app()->forgetInstance(RecoversResearch::class);
    app()->forgetInstance(LibrariumManager::class);
    app()->forgetInstance(LibrariumContract::class);
    Librarium::clearResolvedInstance();
}

function durableExpectOnlyKnownGets(int $count, string $url): void
{
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($requests)->toHaveCount($count)
        ->and($requests->every(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $url))->toBeTrue();
    Sleep::assertNeverSlept();
}

function durableExaReference(): string
{
    return 'agent_run_durable_123';
}

/** @return array<string, mixed> */
function durableExaRun(string $status, bool $secret = false): array
{
    return [
        'id' => durableExaReference(),
        'object' => 'agent_run',
        'status' => $status,
        'stopReason' => $status === 'completed' ? 'schema_satisfied' : null,
        'createdAt' => '2026-09-11T12:00:00Z',
        'completedAt' => $status === 'completed' ? '2026-09-11T12:01:00Z' : null,
        'output' => [
            'text' => $status === 'completed' ? 'Durable report.' : ($secret ? 'testing-exa-key provider-secret' : ''),
            'structured' => null,
            'grounding' => $status === 'completed' ? [[
                'field' => 'output.text',
                'citations' => [['url' => 'https://example.com/evidence']],
            ]] : [],
        ],
        'usage' => ['agentComputeUnits' => 1, 'searches' => 1],
        'costDollars' => ['total' => 0.1, 'agentCompute' => 0.1, 'search' => 0],
        ...($secret ? ['error' => ['message' => 'provider-secret testing-exa-key']] : []),
    ];
}

function durableGeminiReference(): string
{
    return 'interaction-durable-123';
}

/** @return array<string, mixed> */
function durableGeminiInteraction(string $status): array
{
    return [
        'id' => durableGeminiReference(),
        'object' => 'interaction',
        'status' => $status,
        'agent' => 'deep-research-preview-04-2026',
        'created' => '2026-09-11T12:00:00Z',
        'updated' => '2026-09-11T12:00:00Z',
    ];
}

function durablePerplexityReference(): string
{
    return 'resp_durable_123';
}

function durableParallelReference(): string
{
    return 'trun_durable123';
}

function durableValyuReference(): string
{
    return 'dr-durable-123';
}

/** @return array<string, mixed> */
function durableValyuTask(string $status): array
{
    return [
        'deepresearch_id' => durableValyuReference(),
        'status' => $status,
        'mode' => 'standard',
    ];
}

function durableDataForSeoReference(): string
{
    return '123e4567-e89b-12d3-a456-426614174000';
}

function durableConfigureDataForSeo(string $profile = 'dataforseo-gemini'): void
{
    config()->set("librarium.profiles.{$profile}.enabled", true);
    config()->set("librarium.profiles.{$profile}.options", [
        'location_code' => 2840,
        'language_code' => 'en',
    ]);
    config()->set(
        "librarium.profiles.{$profile}.credential",
        DataForSeoCredential::encode('dataforseo-login', 'dataforseo-password'),
    );
}

/** @return array<string, mixed> */
function durableDataForSeoEnvelope(string $operation, int $status, bool $completed, ?string $cost): array
{
    $query = 'DataForSEO durable query';

    return [
        'version' => '0.1.20260911',
        'status_code' => 20000,
        'tasks_count' => 1,
        'tasks_error' => 0,
        'tasks' => [[
            'id' => durableDataForSeoReference(),
            'status_code' => $status,
            'time' => '0.1 sec.',
            'cost' => $cost,
            'result_count' => $completed ? 1 : 0,
            'path' => ['v3', 'ai_optimization', 'gemini', 'llm_scraper', ...explode('/', $operation)],
            'data' => [
                'api' => 'ai_optimization',
                'function' => 'llm_scraper',
                'se' => 'gemini',
                'keyword' => $query,
                'location_code' => 2840,
                'language_code' => 'en',
            ],
            'result' => $completed ? [[
                'keyword' => $query,
                'location_code' => 2840,
                'language_code' => 'en',
                'datetime' => '2026-09-11 11:59:58.1234 +00:00',
                'model' => 'gemini-observed',
                'markdown' => 'Collected durable answer.',
                'sources' => [],
                'items' => [],
            ]] : null,
        ]],
    ];
}

/** @return array<string, mixed> */
function durableParallelRun(string $status): array
{
    return [
        'run_id' => durableParallelReference(),
        'status' => $status,
        'processor' => 'pro',
    ];
}

/** @return array<string, mixed> */
function durableParallelResult(): array
{
    return [
        'run' => durableParallelRun('completed'),
        'output' => [
            'type' => 'text',
            'content' => 'Durable Parallel report.',
            'basis' => [[
                'field' => 'output',
                'reasoning' => 'Provider-reported basis.',
                'confidence' => 'high',
                'citations' => [[
                    'url' => 'https://example.com/parallel-evidence',
                    'title' => 'Parallel evidence',
                    'excerpts' => ['Supporting evidence.'],
                ]],
            ]],
        ],
    ];
}
