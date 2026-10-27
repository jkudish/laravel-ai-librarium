<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Recovery\ResearchHandleSigner;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\SourceCatalog;
use Jkudish\LaravelAiLibrarium\StoredRun;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeRuntimeDriver;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11T12:00:00Z');
    config(['librarium.runs.store' => 'memory', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('rejects encoded credential reflections without retaining them in the Run or evidence', function (string $reflection): void {
    $credential = 'private:key /+value!';
    config(['services.exa.key' => $credential]);
    Bus::fake();
    $response = durableExaRun('completed');
    $response['output']['text'] = 'Safe provider context. Reflected '.$reflection;
    Http::fake([
        'https://api.exa.ai/agent/runs' => Http::response(durableExaRun('running')),
        'https://api.exa.ai/agent/runs/*' => Http::response($response, 200, ['X-Request-Id' => $reflection]),
    ]);
    $queued = Librarium::research('Research')->deep()->only(['exa'])->queue();
    app(RunRuntime::class)->start($queued->id());
    $run = Librarium::find($queued->id())->refresh();
    expect($run->status())->toBe('failed')
        ->and($run->result('exa')['error']['code'])->toBe('exa_research.invalid_response')
        ->and($run->result('exa')['answer'])->toBeNull();
    foreach ([$run->toArray(), $run->evidence('exa'), app(LibrariumManager::class)->store()->find($run->id())->toArray()] as $retained) {
        expect(json_encode($retained, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->not->toContain($credential, $reflection);
    }
    expect($run->evidence('exa')[1]['body'])->toContain('Safe provider context.', '[REDACTED]')
        ->and($run->evidence('exa')[1]['headers']['x-request-id'])->toBe(['[REDACTED]']);
    Http::assertSentCount(2);
})->with([
    'URI' => ['private%3Akey%20%2F%2Bvalue%21'],
    'lowercase JS URI component' => ['private%3akey%20%2f%2bvalue!'],
    'mixed percent case' => ['private%3akey%20%2F%2bvalue%21'],
    'unpadded Base64url' => ['cHJpdmF0ZTprZXkgLyt2YWx1ZSE'],
]);

it('submits once, persists a private signed handle and observes through a reconstructed Run', function (): void {
    Http::fake(['https://api.exa.ai/agent/runs' => function (Request $request) {
        $id = app(LibrariumManager::class)->store()->list(1)[0]->id;
        // A second worker cannot submit while the first paid call is in flight.
        app(RunRuntime::class)->start($id);
        Librarium::find($id)->refresh();

        return Http::response(durableExaRun('running'), 200, ['Authorization' => 'testing-exa-key']);
    }]);
    $run = app(LibrariumManager::class)->execute(['question' => 'Research', 'depth' => 'deep', 'skip' => [], 'only' => ['exa'], 'options' => []], true);
    $doc = app(LibrariumManager::class)->store()->find($run->id());
    $handle = ResearchHandle::fromArray(array_reverse($doc->state['handles']['exa'], true));
    expect($handle->reference)->toBe('agent_run_durable_123')
        ->and($handle->fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($handle->fingerprint)->not->toBe(str_repeat('0', 64))
        ->and(json_encode($doc))->not->toContain('testing-exa-key')
        ->and(json_encode($run))->not->toContain('agent_run_durable_123');
    Http::assertSentCount(1);
    Http::fake(['https://api.exa.ai/agent/runs/*' => Http::sequence()->push(durableExaRun('running'))->push(durableExaRun('completed'))]);
    expect(Run::fromArray($run->toArray())->refresh()->status())->toBe('running');
    $done = Librarium::find($run->id())->refresh();
    expect($done->status())->toBe('done')->and($done->result('exa')['answer'])->toBe('Durable report.')
        ->and($done->evidence('exa'))->toHaveCount(3);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.exa.ai/agent/runs/agent_run_durable_123');
    Sleep::assertNeverSlept();
});

it('rejects tampered signed handle fields before HTTP', function (string $field, mixed $replacement): void {
    $id = durableStoredRun('exa', 'agent_run_durable_123');
    $store = app(LibrariumManager::class)->store();
    $doc = $store->find($id);
    $state = $doc->state;
    $state['handles']['exa'][$field] = $replacement;
    $store->save(new StoredRun($doc->run, $state, $doc->files, $doc->version), $doc->version);
    Http::fake();
    expect(fn () => Librarium::find($id)->refresh())->toThrow(ValidationException::class, 'does not match');
    Http::assertNothingSent();
})->with([
    ['provider', 'other'], ['reference', 'agent_run_changed'], ['fingerprint', str_repeat('a', 64)],
    ['submitted_usage', ['actual_cost' => '9.99', 'currency' => 'USD']],
]);

it('rejects rotated credentials and changed semantic bindings before retrieval', function (string $path, mixed $replacement): void {
    $id = durableStoredRun('exa', 'agent_run_durable_123');
    config([$path => $replacement]);
    Http::fake();
    expect(fn () => Librarium::find($id)->refresh())->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([
    ['services.exa.key', 'rotated-key'],
    ['librarium.sources.exa.deep', ['driver' => FakeRuntimeDriver::class]],
]);

it('requires a valid application key before a paid submission', function (?string $key): void {
    config(['app.key' => $key]);
    Http::fake();
    expect(fn () => app(LibrariumManager::class)->execute(['question' => 'Research', 'depth' => 'deep', 'skip' => [], 'only' => ['exa'], 'options' => []], true))->toThrow(LogicException::class);
    Http::assertNothingSent();
})->with([null, 'base64:not-valid-base64']);

it('retrieves each durable variant with one observation and no POST or sleep', function (string $source, array $options, string $reference, array $pending, string $url): void {
    $id = durableStoredRun($source, $reference, $options);
    Http::fakeSequence()->push($pending);
    expect(Librarium::find($id)->refresh()->status())->toBe('running');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === $url);
    Sleep::assertNeverSlept();
})->with([
    ['exa', [], 'agent_run_durable_123', durableExaRun('running'), 'https://api.exa.ai/agent/runs/agent_run_durable_123'],
    ['gemini', [], 'interaction-durable-123', ['id' => 'interaction-durable-123', 'object' => 'interaction', 'status' => 'in_progress', 'agent' => 'deep-research-preview-04-2026', 'created' => '2026-09-11T12:00:00Z', 'updated' => '2026-09-11T12:00:00Z'], 'https://generativelanguage.googleapis.com/v1beta/interactions/interaction-durable-123'],
    ['perplexity', ['deep_effort' => 'medium'], 'resp_durable_123', ['id' => 'resp_durable_123', 'status' => 'in_progress'], 'https://api.perplexity.ai/v1/agent/resp_durable_123'],
    ['perplexity', ['deep_effort' => 'high'], 'resp_durable_123', ['id' => 'resp_durable_123', 'status' => 'in_progress'], 'https://api.perplexity.ai/v1/agent/resp_durable_123'],
    ['parallel', [], 'trun_durable123', ['run_id' => 'trun_durable123', 'status' => 'running', 'processor' => 'pro'], 'https://api.parallel.ai/v1/tasks/runs/trun_durable123'],
    ['valyu', [], 'dr-durable-123', ['deepresearch_id' => 'dr-durable-123', 'status' => 'queued', 'mode' => 'standard'], 'https://api.valyu.ai/v1/deepresearch/tasks/dr-durable-123/status'],
    ['openai', [], 'resp_durable_123', ['id' => 'resp_durable_123', 'status' => 'in_progress'], 'https://api.openai.com/v1/responses/resp_durable_123'],
    ['you', [], '123e4567-e89b-12d3-a456-426614174000', ['id' => '123e4567-e89b-12d3-a456-426614174000', 'task_type' => 'research', 'status' => 'running'], 'https://api.you.com/v1/research/123e4567-e89b-12d3-a456-426614174000'],
]);

it('retains transient tasks but makes permanent retrieval failures terminal with safe diagnostics', function (array $body, int $status, string $expected): void {
    $id = durableStoredRun('exa', 'agent_run_durable_123');
    Http::fakeSequence()->push($body, $status, ['Retry-After' => '30']);
    $run = Librarium::find($id)->refresh();
    expect($run->status())->toBe($expected)->and($run->diagnostics()[0]['operation'])->toBe('retrieve')
        ->and(json_encode($run->diagnostics()))->not->toContain('testing-exa-key')->not->toContain('provider-secret');
    Http::assertSentCount(1);
})->with([
    [durableExaRun('failed'), 200, 'failed'],
    [['error' => ['code' => 'overloaded', 'message' => 'provider-secret testing-exa-key']], 503, 'running'],
]);

it('does not cancel or resubmit a failed Valyu task', function (): void {
    $id = durableStoredRun('valyu', 'dr-durable-123');
    Http::fakeSequence()->push(['deepresearch_id' => 'dr-durable-123', 'status' => 'failed', 'mode' => 'standard']);
    expect(Librarium::find($id)->refresh()->status())->toBe('failed');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET');
});

it('merges evidence from concurrent pending observations without duplicating a submission', function (): void {
    $id = durableStoredRun('exa', 'agent_run_durable_123');
    $nested = false;
    Http::fake(function () use ($id, &$nested) {
        if (! $nested) {
            $nested = true;
            Librarium::find($id)->refresh();
        }

        return Http::response(durableExaRun('running'));
    });
    $run = Librarium::find($id)->refresh();
    expect($run->evidence('exa'))->toHaveCount(2)->and($run->status())->toBe('running');
    Http::assertSentCount(2);
});

it('keeps submitted tasks recoverable after worker interruption', function (): void {
    $id = durableStoredRun('exa', 'agent_run_durable_123');
    app(RunRuntime::class)->failInterrupted($id);
    Http::fakeSequence()->push(durableExaRun('completed'));
    expect(Librarium::find($id)->refresh()->status())->toBe('done');
});

it('retains a late accepted handle after a concurrent local interruption without resubmitting', function (string $interrupt): void {
    config(['librarium.timeout' => 60]);
    $id = null;
    Http::fake(['https://api.exa.ai/agent/runs' => function () use (&$id, $interrupt) {
        $id = app(LibrariumManager::class)->store()->list(1)[0]->id;
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        if ($interrupt === 'refresh') {
            Librarium::find($id)->refresh();
        } else {
            app(RunRuntime::class)->failInterrupted($id);
        }
        expect(Librarium::find($id)->result('exa')['error']['code'])->toBe('interrupted');

        return Http::response(durableExaRun('running'));
    }]);
    $run = app(LibrariumManager::class)->execute(['question' => 'Research', 'depth' => 'deep', 'skip' => [], 'only' => ['exa'], 'options' => []], true);
    expect($run->status())->toBe('running')
        ->and($run->result('exa')['error'])->toBeNull()
        ->and(app(LibrariumManager::class)->store()->find($id)->state['handles']['exa']['reference'])->toBe('agent_run_durable_123');
    Http::assertSentCount(1);
    Http::fake(['https://api.exa.ai/agent/runs/*' => Http::response(durableExaRun('completed'))]);
    expect(Librarium::find($id)->refresh()->status())->toBe('done');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET');
})->with(['refresh', 'worker-failure']);

it('logs only a safe operational diagnostic when a provider throws an unsafe exception', function (): void {
    $handler = new TestHandler;
    Log::swap(new Illuminate\Log\Logger(new Logger('test', [$handler])));
    Http::fake(function () {
        throw new RuntimeException('sentinel-auth-secret provider-body', previous: new RuntimeException('sentinel-cause-secret'));
    });
    $run = app(LibrariumManager::class)->execute(['question' => 'Research', 'depth' => 'deep', 'skip' => [], 'only' => ['exa'], 'options' => []], true);
    expect($run->status())->toBe('failed');
    $records = $handler->getRecords();
    expect($records)->not->toBeEmpty();
    $logged = json_encode($records, JSON_THROW_ON_ERROR);
    expect($logged)->not->toContain('sentinel-auth-secret', 'sentinel-cause-secret', 'provider-body')
        ->and($records[0]->context)->not->toHaveKey('exception');
});

it('queues You research and finishes its report through a later Run observation', function (): void {
    config(['services.you.key' => 'placeholder-you-key']);
    Bus::fake();
    $task = '123e4567-e89b-12d3-a456-426614174000';
    Http::fake([
        'https://api.you.com/v1/research' => Http::response(['task_id' => $task, 'type' => 'research', 'status' => 'queued']),
        'https://api.you.com/v1/research/'.$task => Http::response([
            'id' => $task, 'task_type' => 'research', 'status' => 'completed',
            'result' => ['output' => ['content' => 'Recovered You report.', 'content_type' => 'text', 'sources' => [
                ['url' => 'https://example.org/you', 'title' => 'You evidence', 'snippets' => ['Supporting excerpt.']],
            ]]],
        ]),
    ]);
    $queued = Librarium::research('Research')->deep()->only(['you'])->queue();
    $id = $queued->id();
    unset($queued);
    app(RunRuntime::class)->start($id);
    $run = Librarium::find($id)->refresh();
    expect($run->status())->toBe('done')
        ->and($run->result('you')['answer'])->toBe('Recovered You report.')
        ->and($run->citations()[0]['url'])->toBe('https://example.org/you')
        ->and($run->evidence('you'))->toHaveCount(2)
        ->and($run->toArray()['cost']['reported'])->toBeNull();
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

/** @param array<string, mixed> $options */
function durableStoredRun(string $source, string $reference, array $options = []): string
{
    $manager = app(LibrariumManager::class);
    $doc = $manager->plan(['question' => 'Research', 'depth' => 'deep', 'skip' => [], 'only' => [$source], 'options' => [$source => $options]]);
    $document = $doc->run->toArray();
    $document['status'] = 'running';
    $document['results'][0]['status'] = 'running';
    $document['results'][0]['started_at'] = CarbonImmutable::now()->toIso8601ZuluString();
    $handle = app(ResearchHandleSigner::class)->issue(app(SourceCatalog::class)->profile($source, 'deep', $options), $doc->run->id(), $reference, CarbonImmutable::now());
    $state = $doc->state;
    $state['handles'][$source] = $handle->toArray();
    $manager->store()->save(new StoredRun(Run::fromArray($document), $state), null);

    return $doc->run->id();
}

/** @return array<string, mixed> */
function durableExaRun(string $status): array
{
    return ['id' => 'agent_run_durable_123', 'object' => 'agent_run', 'status' => $status,
        'stopReason' => $status === 'completed' ? 'schema_satisfied' : null,
        'createdAt' => '2026-09-11T12:00:00Z', 'completedAt' => $status === 'completed' ? '2026-09-11T12:01:00Z' : null,
        'output' => ['text' => $status === 'completed' ? 'Durable report.' : '', 'structured' => null,
            'grounding' => $status === 'completed' ? [['field' => 'output.text', 'citations' => [['url' => 'https://example.com/evidence']]]] : []],
        'usage' => ['agentComputeUnits' => 1, 'searches' => 1], 'costDollars' => ['total' => 0.1, 'agentCompute' => 0.1, 'search' => 0]];
}
