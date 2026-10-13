<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Stores\CacheRunStore;

beforeEach(function (): void {
    config(['librarium.runs.store' => 'memory', 'services.firecrawl.key' => 'placeholder-firecrawl-key', 'services.you.key' => 'placeholder-you-key']);
});

it('runs offline quick and durable queued research through every built-in store', function (string $store): void {
    config(['librarium.runs.store' => $store]);
    if ($store === 'filesystem') {
        Storage::fake('runs');
        config(['librarium.runs.disk' => 'runs']);
    }
    if ($store === 'database') {
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        (require __DIR__.'/../../database/migrations/create_librarium_runs_table.php')->up();
    }
    Bus::fake();
    $task = 'resp_offline_4957';
    Http::fake([
        'https://api.firecrawl.dev/v2/search' => Http::response(['success' => true, 'creditsUsed' => 2, 'data' => ['web' => [
            ['url' => 'https://example.com/first', 'title' => 'First', 'description' => 'First finding'],
            ['url' => 'https://example.com/first/#duplicate', 'title' => 'Duplicate'],
            ['url' => 'http://example.com/unsafe'],
            ['url' => 'https://example.org/second', 'title' => 'Second'],
        ]]]),
        'https://api.openai.com/v1/responses' => Http::response(['id' => $task, 'status' => 'queued']),
        'https://api.openai.com/v1/responses/'.$task => Http::sequence()
            ->push(['id' => $task, 'status' => 'in_progress'])
            ->push(['id' => $task, 'status' => 'completed', 'model' => 'gpt-6.1-sol', 'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search']],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Deep offline report.', 'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://example.org/report', 'title' => 'Report'],
                ]]]],
            ]]),
    ]);
    $plan = Librarium::research('Offline research')->only(['firecrawl'])->dryRun();
    expect($plan->status())->toBe('planned')->and(Librarium::find($plan->id()))->toBeNull();
    Http::assertNothingSent();
    expect(Librarium::sources()['sources'])->toHaveCount(16);
    Http::assertNothingSent();
    $quick = Librarium::research('Offline research')->only(['firecrawl'])->run();
    expect($quick->status())->toBe('done')
        ->and($quick->result('firecrawl')['answer'])->toBeNull()
        ->and(array_column($quick->citations(), 'url'))->toBe(['https://example.com/first', 'https://example.org/second'])
        ->and($quick->toArray()['cost']['reported'])->toBeNull()
        ->and($quick->evidence('firecrawl'))->not->toBeEmpty()
        ->and(Librarium::find($quick->id())->toArray())->toBe($quick->toArray());

    $queued = Librarium::research('Deep offline research')->deep()->only(['openai'])->queue();
    $id = $queued->id();
    unset($queued); // Laravel dispatches after the fluent PendingDispatch is released.
    Bus::assertDispatched(RunResearch::class, fn (RunResearch $job): bool => $job->runId === $id);
    $pending = app(RunRuntime::class)->start($id);
    expect($pending->status())->toBe('running');
    $stored = app(LibrariumManager::class)->store()->find($id);
    expect($stored->state['handles']['openai']['reference'])->toBe($task)
        ->and(json_encode($stored))->not->toContain('testing-openai-key');
    $observed = Librarium::find($id)->refresh();
    expect($observed->status())->toBe('running');
    $done = Librarium::find($id)->refresh();
    expect($done->result('openai')['error'])->toBeNull();
    expect($done->status())->toBe('done')
        ->and($done->result('openai')['answer'])->toBe('Deep offline report.')
        ->and($done->citations()[0]['url'])->toBe('https://example.org/report')
        ->and($done->evidence('openai'))->toHaveCount(3);
    Http::assertSentCount(4);
})->with(['memory', 'cache', 'filesystem', 'database']);

it('rejects an unsupported cache backend before storing or submitting work', function (): void {
    $backend = new class extends ArrayStore
    {
        public function put($key, $value, $seconds): bool
        {
            throw new LogicException('Unsupported backend must never be written.');
        }
    };
    Librarium::extendStore('rejecting', fn () => new CacheRunStore(new Repository($backend)));
    config(['librarium.runs.store' => 'rejecting']);
    expect(fn () => Librarium::research('Rejected persistence')->deep()->only(['openai'])->run())->toThrow(LogicException::class, 'undecorated');
    Http::assertNothingSent();
    expect($backend->all())->toBeEmpty();
});
