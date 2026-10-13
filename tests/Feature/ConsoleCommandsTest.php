<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\Spec;
use Jkudish\LaravelAiLibrarium\StoredRun;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config(['librarium.runs.store' => 'memory', 'services.firecrawl.key' => 'placeholder']);
});

it('prints schema-valid dry-run JSON without calls, storage or terminal formatting', function (): void {
    $exit = Artisan::call('librarium:research', ['question' => 'q', '--only' => 'firecrawl', '--dry-run' => true, '--json' => true]);
    $output = Artisan::output();
    $run = Run::fromArray(json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    expect($exit)->toBe(0)->and($run->status())->toBe('planned')->and(Librarium::find($run->id()))->toBeNull()->and($output)->not->toContain("\e[");
    Http::assertNothingSent();
});

it('waits with per-source progress and prints the recovered report', function (): void {
    Sleep::fake();
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(['id' => 'resp_cli_4957', 'status' => 'queued']),
        'https://api.openai.com/v1/responses/resp_cli_4957' => Http::response([
            'id' => 'resp_cli_4957', 'status' => 'completed', 'model' => 'gpt-6.1-sol',
            'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search']],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'CLI recovered report.', 'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://example.org/report', 'title' => 'Report'],
                ]]]],
            ],
        ]),
    ]);
    expect(Artisan::call('librarium:research', ['question' => 'q', '--deep' => true, '--only' => 'openai']))->toBe(0)
        ->and(Artisan::output())->toContain('Ctrl-C detaches', 'openai: running', 'openai: done', 'CLI recovered report.')
        ->not->toContain('testing-openai-key', 'resp_cli_4957');
    Http::assertSentCount(2);
});

it('queues one job, shows the stored Run and lists sources without secrets', function (): void {
    Bus::fake();
    expect(Artisan::call('librarium:research', ['question' => 'q', '--deep' => true, '--only' => 'openai', '--no-wait' => true, '--json' => true]))->toBe(0);
    $queued = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($queued['status'])->toBe('running');
    Bus::assertDispatchedTimes(RunResearch::class, 1);
    expect(Artisan::call('librarium:show', ['id' => $queued['id'], '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['id'])->toBe($queued['id']);
    expect(Artisan::call('librarium:sources', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['sources'])->toHaveCount(16)->and($output)->not->toContain('testing-openai-key', 'placeholder');
    Http::assertNothingSent();
});

it('returns stable failure exits and keeps real JSON stderr secret-safe', function (): void {
    expect(Artisan::call('librarium:show', ['id' => 'run_01JAB2C3D4E5F6G7H8J9K0M1N2', '--json' => true]))->toBe(3)->and(Artisan::output())->toBe('');
    $process = new Process([PHP_BINARY, 'vendor/bin/testbench', 'librarium:research', 'secret-question', '--only=unknown', '--json', '--dry-run'], dirname(__DIR__, 2));
    $process->setTimeout(15);
    $process->run();
    expect($process->getExitCode())->toBe(2)->and($process->getOutput())->toBe('')->and($process->getErrorOutput())->toContain('unknown_name')->not->toContain('secret-question', "\e[");
});

it('prunes old Runs over several pages without deleting newer Runs', function (): void {
    config(['librarium.runs.keep_days' => 7]);
    $fixture = Spec::read('fixtures/runs/valid/summary-run.json');
    $store = app(LibrariumManager::class)->store();
    $now = CarbonImmutable::now();
    for ($i = 0; $i < 52; $i++) {
        $fixture['id'] = 'run_'.Str::ulid();
        $fixture['created_at'] = $now->subDays($i < 51 ? 30 : 1)->toIso8601ZuluString();
        $fixture['finished_at'] = $fixture['created_at'];
        $store->save(new StoredRun(Run::fromArray($fixture)), null);
    }
    expect(Artisan::call('librarium:prune', ['--json' => true]))->toBe(0)->and(json_decode(Artisan::output(), true)['deleted'])->toBe(51)->and($store->list())->toHaveCount(1);
});
