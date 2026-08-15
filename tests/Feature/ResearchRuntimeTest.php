<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Events\ResearchCompleted;
use Jkudish\LaravelAiLibrarium\Events\ResearchFailed;
use Jkudish\LaravelAiLibrarium\Events\ResearchProgressed;
use Jkudish\LaravelAiLibrarium\Events\ResearchStarted;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\ResearchCompiler;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\ResearchStatusStore;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeRuntimeDriver;

beforeEach(function (): void {
    FakeRuntimeDriver::reset();
    CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');
    config()->set('librarium.status_ttl', 10800);
    config()->set('librarium.webhook_signal_ttl', 10800);
    config()->set('librarium.profiles', []);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('runs through one runtime and emits the successful event order', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary'));
    FakeRuntimeDriver::$outcomes['primary'] = [function (DriverRequest $request): ResearchResult {
        $request->progress("  Gathering\n sources.  ");

        return runtimeResult($request, 'A researched answer.');
    }];
    $events = [];
    captureRuntimeEvents($events);

    $response = Librarium::query('Research this.')->using('primary')->run();

    expect($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($response->generator)->toBe('jkudish/laravel-ai-librarium')
        ->and($response->generatorVersion)->toMatch('/^\d+\.\d+\.\d+/')
        ->and($response->usage)->toBeNull()
        ->and($events)->toBe([
            'started',
            'progress:Running Profile [primary].:primary',
            'progress:Gathering sources.:primary',
            'completed',
        ])
        ->and(Librarium::status($response->requestId)?->state)->toBe(ResearchState::Completed);
});

it('queues one encrypted one-attempt job that uses the same runtime and callbacks', function (): void {
    Queue::fake();
    config()->set('librarium.queue.connection', 'redis');
    config()->set('librarium.queue.name', 'research');
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary'));
    FakeRuntimeDriver::$outcomes['primary'] = [
        fn (DriverRequest $request): ResearchResult => runtimeResult($request, 'Queued answer.'),
    ];
    $callbackResponse = null;

    $queued = Librarium::query('Queued research.')->using('primary')->queue()
        ->then(function (ResearchResponse $response) use (&$callbackResponse): void {
            $callbackResponse = $response;
        });
    $requestId = $queued->requestId;
    unset($queued);
    gc_collect_cycles();

    $job = Queue::pushed(RunResearch::class)->sole();
    $job->handle(app(ExecutesResearch::class));

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBe(7200)
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('research')
        ->and($callbackResponse)->toBeInstanceOf(ResearchResponse::class)
        ->and($callbackResponse?->requestId)->toBe($requestId)
        ->and(FakeRuntimeDriver::$requests)->toHaveCount(1);
});

it('completes a valid terminal failed response for an expected Driver failure', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary'));
    FakeRuntimeDriver::$outcomes['primary'] = [
        new DriverException('test.exhausted', 'The provider could not complete this research.', false),
    ];
    $events = [];
    captureRuntimeEvents($events);

    $response = Librarium::query('Research this.')->using('primary')->run();

    expect($response->status)->toBe(ResponseStatus::Failed)
        ->and($response->results)->toBeEmpty()
        ->and($response->errors->sole()->code)->toBe('test.exhausted')
        ->and($events)->toBe(['started', 'progress:Running Profile [primary].:primary', 'completed'])
        ->and(Librarium::status($response->requestId)?->state)->toBe(ResearchState::Completed);
});

it('attributes an expected fallback failure to the effective Profile', function (): void {
    config()->set('librarium.profiles', [
        'primary' => runtimeProfileConfig('primary'),
        'fallback' => runtimeProfileConfig('fallback'),
    ]);
    FakeRuntimeDriver::$outcomes = [
        'primary' => [new DriverException('test.unavailable', 'The primary provider is unavailable.')],
        'fallback' => [new DriverException('test.exhausted', 'The fallback provider was exhausted.', false)],
    ];

    $response = Librarium::query('Research this.')
        ->using('primary')
        ->fallbacks(['fallback'])
        ->run();

    expect($response->status)->toBe(ResponseStatus::Failed)
        ->and($response->errors->sole()->profile)->toBe('fallback');
});

it('reports and rethrows unexpected infrastructure failures with the effective fallback Profile', function (): void {
    config()->set('librarium.profiles', [
        'primary' => runtimeProfileConfig('primary'),
        'fallback' => runtimeProfileConfig('fallback'),
    ]);
    FakeRuntimeDriver::$outcomes = [
        'primary' => [new DriverException('test.unavailable', 'The primary provider is unavailable.')],
        'fallback' => [new RuntimeException('Infrastructure broke.')],
    ];
    Event::fake([ResearchFailed::class]);
    $research = Librarium::query('Research this.')->using('primary')->fallbacks(['fallback']);

    expect(fn () => $research->run())->toThrow(RuntimeException::class, 'Infrastructure broke.');

    Event::assertDispatchedOnce(ResearchFailed::class);
    Event::assertDispatched(
        ResearchFailed::class,
        fn (ResearchFailed $event): bool => $event->requestId === $research->requestId
            && $event->profileId === 'fallback'
            && $event->exception->getMessage() === 'Infrastructure broke.',
    );
    expect(Librarium::status($research->requestId)?->state)->toBe(ResearchState::Failed)
        ->and(Librarium::status($research->requestId)?->progress)->toBe('Research failed unexpectedly.');
});

it('uses the ordered global reserve deterministically and each Profile at most once', function (): void {
    config()->set('librarium.profiles', [
        'answer-primary' => runtimeProfileConfig('answer-primary'),
        'search-primary' => runtimeProfileConfig(
            'search-primary',
            resultKind: ResultKind::SearchResults,
            corpora: [Corpus::News],
            retrievalMethods: [RetrievalMethod::SearchEndpoint],
        ),
        'search-fallback' => runtimeProfileConfig(
            'search-fallback',
            resultKind: ResultKind::SearchResults,
            corpora: [Corpus::News],
            retrievalMethods: [RetrievalMethod::SearchEndpoint],
        ),
        'answer-fallback' => runtimeProfileConfig('answer-fallback'),
    ]);
    FakeRuntimeDriver::$outcomes = [
        'answer-primary' => [new DriverException('test.first', 'The first primary failed.')],
        'answer-fallback' => [fn (DriverRequest $request): ResearchResult => runtimeResult($request, 'Answer fallback.')],
        'search-primary' => [new DriverException('test.second', 'The second primary failed.')],
        'search-fallback' => [fn (DriverRequest $request): ResearchResult => runtimeResult($request, 'Search fallback.')],
    ];

    $response = Librarium::query('Research this.')
        ->fanOut(['answer-primary', 'search-primary'])
        ->fallbacks(['search-fallback', 'answer-fallback'])
        ->run();

    expect(FakeRuntimeDriver::$requests)->toHaveCount(4)
        ->and(collect(FakeRuntimeDriver::$requests)->pluck('profile.id')->all())
        ->toBe(['answer-primary', 'answer-fallback', 'search-primary', 'search-fallback'])
        ->and(collect(FakeRuntimeDriver::$requests)->pluck('profile.id')->unique())->toHaveCount(4)
        ->and($response->results->pluck('requestedProfile')->all())->toBe(['answer-primary', 'search-primary'])
        ->and($response->results->pluck('profile')->all())->toBe(['answer-fallback', 'search-fallback'])
        ->and($response->results->every(fn (ResearchResult $result): bool => $result->fallbackReason !== null))->toBeTrue();
});

it('rejects semantically incompatible reserves and transient TTLs before Driver construction', function (): void {
    config()->set('librarium.profiles', [
        'primary' => runtimeProfileConfig('primary'),
        'wrong-kind' => runtimeProfileConfig('wrong-kind', resultKind: ResultKind::ResearchReport),
        'surface-primary' => runtimeProfileConfig('surface-primary', observation: ObservationMode::SurfaceSnapshot),
        'surface-fallback' => runtimeProfileConfig('surface-fallback', observation: ObservationMode::SurfaceSnapshot),
    ]);

    expect(fn () => Librarium::query('Research this.')
        ->using('primary')
        ->fallbacks(['wrong-kind'])
        ->preview())
        ->toThrow(ValidationException::class, 'compatible with at least one primary');

    expect(fn () => Librarium::query('Observe this.')
        ->using('surface-primary')
        ->fallbacks(['surface-fallback'])
        ->preview())
        ->toThrow(ValidationException::class, 'compatible with at least one primary');

    expect(fn () => Librarium::query('Research this.')->using('primary')->timeout(7200)->preview())
        ->toThrow(ValidationException::class, 'must be less than the queue timeout');

    config()->set('librarium.status_ttl', 60);

    expect(fn () => Librarium::query('Research this.')->using('primary')->timeout(60)->preview())
        ->toThrow(ValidationException::class, 'greater than the selected timeout');

    config()->set('librarium.status_ttl', 10800);
    config()->set('librarium.webhook_signal_ttl', 60);

    expect(fn () => Librarium::query('Research this.')->using('primary')->timeout(60)->preview())
        ->toThrow(ValidationException::class, 'greater than the selected timeout')
        ->and(FakeRuntimeDriver::$constructorCalls)->toBe(0);
});

it('rejects Driver results outside the effective Profile semantics', function (string $mismatch): void {
    $observation = $mismatch === 'missing surface facts'
        ? ObservationMode::SurfaceSnapshot
        : ObservationMode::ApiOutput;
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary', observation: $observation));
    FakeRuntimeDriver::$outcomes['primary'] = [function (DriverRequest $request) use ($mismatch): ResearchResult {
        $provenance = match ($mismatch) {
            'result kind' => new ResultProvenance(
                resultKind: ResultKind::ResearchReport,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: CarbonImmutable::now(),
            ),
            'retrieval method' => new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: collect([RetrievalMethod::SearchEndpoint]),
                corpora: $request->profile->corpora,
                observedAt: CarbonImmutable::now(),
            ),
            'corpus' => new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: collect([Corpus::News]),
                observedAt: CarbonImmutable::now(),
            ),
            'missing surface facts' => new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: CarbonImmutable::now(),
            ),
        };

        return runtimeResult($request, 'Mismatched answer.', $provenance);
    }];

    expect(fn () => Librarium::query('Research this.')->using('primary')->run())
        ->toThrow(LogicException::class, 'outside Profile [primary] semantics');
})->with(['result kind', 'retrieval method', 'corpus', 'missing surface facts']);

it('accepts a surface result with explicit collector and surface provenance', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig(
        'primary',
        observation: ObservationMode::SurfaceSnapshot,
    ));
    FakeRuntimeDriver::$outcomes['primary'] = [function (DriverRequest $request): ResearchResult {
        return runtimeResult(
            $request,
            'Observed answer.',
            new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: CarbonImmutable::now(),
                collector: 'searchapi',
                surface: 'chatgpt',
            ),
        );
    }];

    $response = Librarium::query('Observe this.')->using('primary')->run();

    expect($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($response->results->sole()->provenance->surface)->toBe('chatgpt');
});

it('bounds human-readable progress before events and status updates', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary'));
    FakeRuntimeDriver::$outcomes['primary'] = [function (DriverRequest $request): ResearchResult {
        $request->progress("\0  ".str_repeat('long progress ', 40));

        return runtimeResult($request, 'Answer.');
    }];
    $progress = [];
    Event::listen(ResearchProgressed::class, function (ResearchProgressed $event) use (&$progress): void {
        $progress[] = $event->message;
    });

    Librarium::query('Research this.')->using('primary')->run();

    expect($progress)->toHaveCount(2)
        ->and($progress[1])->not->toContain("\0")
        ->and(Str::length($progress[1]))->toBeLessThanOrEqual(255)
        ->and($progress[1])->not->toStartWith(' ')
        ->and($progress[1])->not->toEndWith(' ');
});

it('shares one deadline and converts an overrun into a terminal timeout', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary'));
    FakeRuntimeDriver::$outcomes['primary'] = [function (DriverRequest $request): ResearchResult {
        expect($request->deadline->toIso8601ZuluString())->toBe('2026-08-15T12:00:05Z');
        CarbonImmutable::setTestNow('2026-08-15T12:00:06Z');

        return runtimeResult($request, 'Late answer.');
    }];

    $response = Librarium::query('Research this.')->using('primary')->timeout(5)->run();

    expect($response->status)->toBe(ResponseStatus::Failed)
        ->and($response->errors->sole()->code)->toBe('librarium.timeout')
        ->and($response->results)->toBeEmpty();
});

it('reports job failures outside the runtime once and preserves catch callbacks', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary'));
    $plan = app(ResearchCompiler::class)
        ->compile(Librarium::query('Research this.')->using('primary'));
    $job = new RunResearch($plan);
    $caught = null;
    $job->catch(function (Throwable $exception) use (&$caught): void {
        $caught = $exception;
    });
    Event::fake([ResearchFailed::class]);
    $exception = new RuntimeException('The worker failed before execution.');

    $job->failed($exception);
    $job->failed($exception);

    Event::assertDispatchedOnce(ResearchFailed::class);
    expect($caught)->toBe($exception)
        ->and(Librarium::status($plan->requestId)?->state)->toBe(ResearchState::Failed);
});

it('keeps the first terminal status and emits only its terminal event', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary'));
    FakeRuntimeDriver::$outcomes['primary'] = [
        fn (DriverRequest $request): ResearchResult => runtimeResult($request, 'Late successful answer.'),
    ];
    $plan = app(ResearchCompiler::class)
        ->compile(Librarium::query('Research this.')->using('primary'));
    $job = new RunResearch($plan);
    Event::fake([ResearchCompleted::class, ResearchFailed::class]);

    $job->failed(new RuntimeException('The stale reservation failed first.'));

    expect(fn () => app(ExecutesResearch::class)->run($plan))
        ->toThrow(LogicException::class, 'already reached a terminal state');

    Event::assertDispatchedOnce(ResearchFailed::class);
    Event::assertNotDispatched(ResearchCompleted::class);
    expect(Librarium::status($plan->requestId)?->state)->toBe(ResearchState::Failed);
});

it('keeps terminal events and callbacks working when transient status is unavailable', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary'));
    FakeRuntimeDriver::$outcomes['primary'] = [
        fn (DriverRequest $request): ResearchResult => runtimeResult($request, 'Successful answer.'),
    ];
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('put')->andThrow(new RuntimeException('Cache unavailable.'));
    $cache->shouldReceive('add')->andThrow(new RuntimeException('Cache unavailable.'));
    app()->forgetInstance(ResearchStatusStore::class);
    app()->instance('cache.store', $cache);
    Event::fake([ResearchCompleted::class, ResearchFailed::class]);

    $response = Librarium::query('Research this.')->using('primary')->run();

    Event::assertDispatchedOnce(ResearchCompleted::class);
    expect($response->status)->toBe(ResponseStatus::Succeeded);

    $plan = app(ResearchCompiler::class)
        ->compile(Librarium::query('Research this again.')->using('primary'));
    $job = new RunResearch($plan);
    $caught = null;
    $job->catch(function (Throwable $exception) use (&$caught): void {
        $caught = $exception;
    });
    $exception = new RuntimeException('The worker failed.');

    $job->failed($exception);

    Event::assertDispatchedOnce(ResearchFailed::class);
    expect($caught)->toBe($exception);
});

it('encrypts queued prompt and credential payloads', function (): void {
    config()->set('librarium.profiles.primary', runtimeProfileConfig('primary', credential: 'secret-credential-value'));
    $plan = app(ResearchCompiler::class)
        ->compile(Librarium::query('sensitive prompt text')->using('primary'));
    $queue = new class extends SyncQueue
    {
        public function payload(object $job): string
        {
            return $this->createPayload($job, 'research');
        }
    };
    $queue->setContainer(app());

    $payload = $queue->payload(new RunResearch($plan));

    /** @var array{displayName: string} $decoded */
    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->not->toContain('sensitive prompt text')
        ->and($payload)->not->toContain('secret-credential-value')
        ->and($decoded['displayName'])->toBe(RunResearch::class);
});

it('requires Driver exception messages to be safe terminal text', function (string $message): void {
    expect(fn () => new DriverException('test.invalid', $message))
        ->toThrow(ValidationException::class);
})->with([
    'leading whitespace' => ' unsafe',
    'control character' => "unsafe\0message",
    'unbounded response' => str_repeat('x', 2049),
]);

/** @param list<string> $events */
function captureRuntimeEvents(array &$events): void
{
    Event::listen(ResearchStarted::class, function () use (&$events): void {
        $events[] = 'started';
    });
    Event::listen(ResearchProgressed::class, function (ResearchProgressed $event) use (&$events): void {
        $events[] = "progress:{$event->message}:{$event->profileId}";
    });
    Event::listen(ResearchCompleted::class, function () use (&$events): void {
        $events[] = 'completed';
    });
    Event::listen(ResearchFailed::class, function () use (&$events): void {
        $events[] = 'failed';
    });

}

/**
 * @param  list<Corpus>  $corpora
 * @param  list<RetrievalMethod>  $retrievalMethods
 * @return array<string, mixed>
 */
function runtimeProfileConfig(
    string $provider,
    ResultKind $resultKind = ResultKind::GroundedAnswer,
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ObservationMode $observation = ObservationMode::ApiOutput,
    array $corpora = [Corpus::Web],
    array $retrievalMethods = [RetrievalMethod::ModelSearchTool],
    ?string $credential = null,
): array {
    $configuration = [
        'driver' => FakeRuntimeDriver::class,
        'provider' => $provider,
        'model' => 'test-model',
        'result_kind' => $resultKind->value,
        'grounding' => $grounding->value,
        'observation' => $observation->value,
        'corpora' => array_map(fn (Corpus $corpus): string => $corpus->value, $corpora),
        'retrieval_methods' => array_map(fn (RetrievalMethod $method): string => $method->value, $retrievalMethods),
        'prompt' => 'Research carefully: {{ query }}',
        'enabled' => true,
        'options' => [],
        'estimated_cost' => ['amount' => '0.01', 'currency' => 'USD'],
    ];

    if ($credential !== null) {
        $configuration['credential'] = $credential;
    }

    return $configuration;
}

function runtimeResult(
    DriverRequest $request,
    string $content,
    ?ResultProvenance $provenance = null,
): ResearchResult {
    return new ResearchResult(
        id: Str::ulid()->toString(),
        contentFormat: ContentFormat::Markdown,
        content: $content,
        requestedProfile: 'driver-placeholder',
        provider: 'driver-placeholder',
        profile: 'driver-placeholder',
        provenance: $provenance ?? new ResultProvenance(
            resultKind: $request->profile->resultKind,
            retrievalMethods: $request->profile->retrievalMethods,
            corpora: $request->profile->corpora,
            observedAt: CarbonImmutable::now(),
        ),
        citations: collect(),
        completedAt: CarbonImmutable::now(),
    );
}
