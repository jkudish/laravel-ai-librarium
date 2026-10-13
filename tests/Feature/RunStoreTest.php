<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Spec;
use Jkudish\LaravelAiLibrarium\StoredRun;
use Jkudish\LaravelAiLibrarium\Stores\CacheRunStore;
use Jkudish\LaravelAiLibrarium\Stores\DatabaseRunStore;
use Jkudish\LaravelAiLibrarium\Stores\FilesystemRunStore;
use Jkudish\LaravelAiLibrarium\Stores\MemoryRunStore;
use Jkudish\LaravelAiLibrarium\Tests\Support\StoreContract;

it('passes the shared store contract', function (string $driver): void {
    $store = match ($driver) {
        'memory' => new MemoryRunStore,
        'cache' => new CacheRunStore(Cache::store('array')),
        'filesystem', 'encrypted' => new FilesystemRunStore(Storage::fake('runs'), Cache::store('array'), encrypter: $driver === 'encrypted' ? app('encrypter') : null),
        'database' => (function (): RunStore {
            config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);
            (require __DIR__.'/../../database/migrations/create_librarium_runs_table.php')->up();

            return new DatabaseRunStore(DB::connection());
        })(),
    };
    (new StoreContract($store))->assertRunStoreContract();
    foreach ($store->list() as $summary) {
        $store->delete($summary->id);
    }
    $fixture = Spec::read('fixtures/run-store/stored-run.json');
    $timestamps = ['2026-10-13T12:00:00Z', '2026-10-13T12:00:00.500Z', '2026-10-13T13:00:00.5+01:00'];
    $ids = ['run_01J00000000000000000000001', 'run_01J00000000000000000000002', 'run_01J00000000000000000000003'];
    foreach ($ids as $index => $id) {
        $fixture['run']['id'] = $id;
        $fixture['run']['created_at'] = $timestamps[$index];
        $fixture['state'] = ['foreign' => ['outputs' => (object) [], 'items' => [(object) [], [], (object) ['0' => (object) []]]]];
        $doc = StoredRun::fromArray($fixture);
        $store->save($doc, null);
        $read = $store->find($id);
        expect(json_encode($read->toArray()['state'], JSON_THROW_ON_ERROR))->toBe(json_encode($doc->toArray()['state'], JSON_THROW_ON_ERROR));
    }
    expect(array_column($store->list(3), 'id'))->toBe([$ids[2], $ids[1], $ids[0]])
        ->and(array_column($store->list(3, $ids[2]), 'id'))->toBe([$ids[1], $ids[0]])
        ->and(array_column($store->list(3, $ids[1]), 'id'))->toBe([$ids[0]]);
})->with(['memory', 'cache', 'filesystem', 'encrypted', 'database']);

it('stores and finds queued Runs through registered and class-name stores', function (string $registration): void {
    Bus::fake();
    config(['librarium.runs.store' => $registration === 'class' ? MemoryRunStore::class : 'custom', 'librarium.runs.marker' => 'factory-config']);
    if ($registration === 'extension') {
        Librarium::extendStore('custom', function (Container $app, array $settings): RunStore {
            expect($settings['marker'])->toBe('factory-config');

            return $app->make(MemoryRunStore::class);
        });
    }
    $queued = Librarium::research('Stored with a custom registration')->deep()->only(['openai'])->queue();
    $id = $queued->id();
    unset($queued);
    $run = Librarium::find($id);
    expect($run)->not->toBeNull()->and($run->status())->toBe('running')
        ->and($run->toArray()['question'])->toBe('Stored with a custom registration')
        ->and($run->result('openai')['status'])->toBe('queued');
})->with(['extension', 'class']);

it('reads the shared unencrypted filesystem folder', function (): void {
    $disk = Storage::fake('interop');
    $folder = Spec::path('fixtures/run-store/filesystem');
    foreach (File::allFiles($folder) as $file) {
        $disk->put('librarium/'.$file->getRelativePathname(), $file->getContents());
    }
    $store = new FilesystemRunStore($disk, Cache::store('array'));
    $fixture = Spec::read('fixtures/run-store/stored-run.json');
    $run = $store->find($fixture['run']['id']);
    expect($run)->not->toBeNull()
        ->and($run->run->toArray())->toEqual($fixture['run'])
        ->and($run->state)->toEqual($fixture['state'])
        ->and($run->files)->toEqual($fixture['files'])
        ->and($run->version)->toBe($fixture['version']);
});

it('never exposes plaintext in encrypted filesystem storage', function (): void {
    $disk = Storage::fake('encrypted');
    $store = new FilesystemRunStore($disk, Cache::store('array'), encrypter: app('encrypter'));
    $doc = StoredRun::fromArray(Spec::read('fixtures/run-store/stored-run.json'));
    $store->save($doc, null);
    foreach ($disk->allFiles('librarium') as $file) {
        expect($disk->get($file))->not->toContain($doc->run->id(), 'librarium.run/1', 'signed-provider-handle');
    }
    expect($store->find($doc->run->id())->files)->toBe($doc->files);
});
