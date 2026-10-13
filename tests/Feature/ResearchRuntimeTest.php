<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Events\ResearchCompleted;
use Jkudish\LaravelAiLibrarium\Events\ResearchProgressed;
use Jkudish\LaravelAiLibrarium\Events\ResearchStarted;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeRuntimeDriver;

beforeEach(function (): void {
    FakeRuntimeDriver::reset();
    CarbonImmutable::setTestNow('2026-09-11T12:00:00Z');
    config(['librarium.runs.store' => 'memory', 'librarium.sources.primary' => ['key' => 'placeholder', 'quick' => runtimeProfileConfig('primary')]]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores before calling a driver and emits bounded progress and one completion', function (): void {
    $events = [];
    Event::listen(ResearchStarted::class, function () use (&$events): void {
        $events[] = 'started';
    });
    Event::listen(ResearchProgressed::class, function (ResearchProgressed $event) use (&$events): void {
        $events[] = $event->message;
    });
    Event::listen(ResearchCompleted::class, function () use (&$events): void {
        $events[] = 'completed';
    });
    FakeRuntimeDriver::$outcomes['primary-quick'] = [function (DriverRequest $request) {
        expect(Librarium::find($request->requestId)->status())->toBe('running');
        $request->progress("  Gathering\n sources.  ");
        $request->progress("\0 ".str_repeat('long progress ', 40));

        return runtimeResult($request, 'A researched answer.');
    }];
    $run = Librarium::research('Research')->only(['primary'])->run();
    $run->refresh();
    expect($run->status())->toBe('done')->and($events[0])->toBe('started')
        ->and($events[2])->toBe('Gathering sources.')
        ->and(strlen($events[3]))->toBeLessThanOrEqual(255)
        ->and($events[3])->not->toContain("\0")
        ->and(array_filter($events, fn ($event) => $event === 'completed'))->toHaveCount(1);
});

it('queues one encrypted one-attempt job with Run callbacks and no prompt or key payload', function (): void {
    Queue::fake();
    config(['librarium.queue.connection' => 'redis']);
    FakeRuntimeDriver::$outcomes['primary-quick'] = [fn (DriverRequest $request) => runtimeResult($request, 'Queued answer.')];
    $callback = null;
    $queued = Librarium::research('sensitive prompt text')->only(['primary'])->queue()->then(function (Run $run) use (&$callback): void {
        $callback = $run;
    });
    $id = $queued->id();
    unset($queued);
    $job = Queue::pushed(RunResearch::class)->sole();
    $job->handle(app(RunRuntime::class));
    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->tries)->toBe(1)->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBe(7200)->and($job->connection)->toBe('redis')
        ->and($callback->id())->toBe($id)->and($callback->status())->toBe('done');
    $queue = new class extends SyncQueue
    {
        public function payload(object $job): string
        {
            return $this->createPayload($job, 'research');
        }
    };
    $queue->setContainer(app());
    $payload = $queue->payload(new RunResearch($id));
    expect($payload)->not->toContain($id)->not->toContain('sensitive prompt text')->not->toContain('placeholder');
});

it('keeps expected failures terminal and unexpected interruptions safe without resubmitting', function (Closure $error, string $code): void {
    FakeRuntimeDriver::$outcomes['primary-quick'] = [$error()];
    $run = Librarium::research('Research')->only(['primary'])->run();
    expect($run->status())->toBe('failed')->and($run->result('primary')['error']['code'])->toBe($code);
    app(RunRuntime::class)->start($run->id());
    $run->refresh();
    expect(FakeRuntimeDriver::$requests)->toHaveCount(1);
})->with([
    [fn () => new DriverException('test.exhausted', 'Safe failure.', false), 'test.exhausted'],
    [fn () => new RuntimeException('Infrastructure broke.'), 'interrupted'],
]);

it('rejects results outside source semantics and reflected private material', function (string $mismatch): void {
    FakeRuntimeDriver::$outcomes['primary-quick'] = [function (DriverRequest $request) use ($mismatch) {
        $provenance = new ResultProvenance(
            resultKind: $mismatch === 'kind' ? ResultKind::ResearchReport : $request->profile->resultKind,
            retrievalMethods: $mismatch === 'method' ? collect([RetrievalMethod::SearchEndpoint]) : $request->profile->retrievalMethods,
            corpora: $mismatch === 'corpus' ? collect([Corpus::News]) : $request->profile->corpora,
            observedAt: CarbonImmutable::now(),
            observationMode: $mismatch === 'surface' ? ObservationMode::SurfaceSnapshot : null,
        );

        return runtimeResult($request, $mismatch === 'private' ? 'Reflected placeholder' : 'Wrong answer.', $provenance, $mismatch === 'provider' ? 'other' : null);
    }];
    $run = Librarium::research('Research')->only(['primary'])->run();
    expect($run->status())->toBe('failed')->and($run->result('primary')['answer'])->toBeNull();
})->with(['kind', 'method', 'corpus', 'surface', 'provider', 'private']);

it('preserves permitted routing identity', function (): void {
    config(['librarium.sources.primary.quick.allow_provider_routing' => true]);
    FakeRuntimeDriver::$outcomes['primary-quick'] = [fn (DriverRequest $request) => runtimeResult($request, 'Routed answer.', provider: 'anthropic', model: 'routed-model')];
    $run = Librarium::research('Research')->only(['primary'])->run();
    expect($run->status())->toBe('done')->and($run->result('primary')['provenance']['provider'])->toBe('anthropic')
        ->and($run->result('primary')['provenance']['model'])->toBe('routed-model');
});

it('shares one deadline and fails work that completes after it', function (): void {
    config(['librarium.timeout' => 5, 'librarium.sources.second' => ['key' => 'placeholder', 'quick' => runtimeProfileConfig('second')]]);
    FakeRuntimeDriver::$outcomes['primary-quick'] = [function (DriverRequest $request) {
        expect($request->deadline->toIso8601ZuluString())->toBe('2026-09-11T12:00:05Z');
        CarbonImmutable::setTestNow('2026-09-11T12:00:06Z');

        return runtimeResult($request, 'Late answer.');
    }];
    $run = Librarium::research('Research')->only(['primary', 'second'])->run();
    expect($run->result('primary')['error']['code'])->toBe('librarium.timeout')
        ->and($run->result('second')['error']['code'])->toBe('librarium.timeout')
        ->and(FakeRuntimeDriver::$requests)->toHaveCount(1);
});

it('stops unstarted sources after reported cost exceeds reservations', function (): void {
    foreach (['primary', 'second'] as $source) {
        config(['librarium.sources.'.$source => ['key' => 'placeholder', 'quick' => runtimeProfileConfig($source)],
            'ai-pricing.prices.'.$source.':test-model' => ['requests' => ['amount' => '0.02', 'currency' => 'USD']]]);
    }
    FakeRuntimeDriver::$outcomes['primary-quick'] = [fn (DriverRequest $request) => runtimeResult($request, 'Answer.', usage: new Usage(actualCost: '0.06', currency: 'USD'))];
    $run = Librarium::research('Research')->only(['primary', 'second'])->maxCost('0.05')->run();
    expect($run->toArray()['cost']['reported'])->toBe('0.06')
        ->and($run->toArray()['skipped'])->toBe([['source' => 'second', 'reason' => 'over_budget']])
        ->and(FakeRuntimeDriver::$requests)->toHaveCount(1);
});

it('fails interrupted unsubmitted work and preserves catch callbacks', function (): void {
    Queue::fake();
    $queued = Librarium::research('Research')->only(['primary'])->queue();
    $id = $queued->id();
    unset($queued);
    $job = Queue::pushed(RunResearch::class)->sole();
    $caught = null;
    $job->catch(function (Throwable $exception) use (&$caught): void {
        $caught = $exception;
    });
    $exception = new RuntimeException('Worker terminated.');
    $job->failed($exception);
    app(RunRuntime::class)->start($id);
    expect($caught)->toBe($exception)->and(Librarium::find($id)->result('primary')['error']['code'])->toBe('interrupted')
        ->and(FakeRuntimeDriver::$requests)->toBeEmpty();
});

it('requires safe bounded Driver exception messages', function (string $message): void {
    expect(fn () => new DriverException('test.invalid', $message))->toThrow(ValidationException::class);
})->with([' unsafe', "unsafe\0message", str_repeat('x', 2049)]);

/** @return array<string, mixed> */
function runtimeProfileConfig(string $provider): array
{
    return ['driver' => FakeRuntimeDriver::class, 'provider' => $provider, 'model' => 'test-model',
        'result_kind' => 'grounded_answer', 'grounding' => 'required', 'observation' => 'api_output',
        'corpora' => ['web'], 'retrieval_methods' => ['model_search_tool'], 'prompt' => '{{ query }}',
        'pricing' => ['provider' => $provider, 'model' => 'test-model', 'usage' => ['requests' => 1], 'conservative' => true]];
}

function runtimeResult(DriverRequest $request, string $content, ?ResultProvenance $provenance = null, ?string $provider = null, ?string $model = null, ?Usage $usage = null): ResearchResult
{
    return ResearchResult::make(contentFormat: ContentFormat::Markdown, content: $content,
        requestedProfile: $request->profile->id, profile: $request->profile->id, provider: $provider ?? $request->profile->provider,
        provenance: $provenance ?? new ResultProvenance(resultKind: $request->profile->resultKind,
            retrievalMethods: $request->profile->retrievalMethods, corpora: $request->profile->corpora, observedAt: CarbonImmutable::now()),
        citations: collect(), completedAt: CarbonImmutable::now(), model: $model, usage: $usage);
}
