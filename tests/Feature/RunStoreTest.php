<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Spec;
use Jkudish\LaravelAiLibrarium\StoredRun;
use Jkudish\LaravelAiLibrarium\Stores\CacheRunStore;
use Jkudish\LaravelAiLibrarium\Stores\DatabaseRunStore;
use Jkudish\LaravelAiLibrarium\Stores\FilesystemRunStore;
use Jkudish\LaravelAiLibrarium\Stores\MemoryRunStore;
use Jkudish\LaravelAiLibrarium\Tests\Support\StoreContract;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

it('passes the shared store contract', function (string $driver): void {
    $store = match ($driver) {
        'memory' => new MemoryRunStore,
        'cache' => new CacheRunStore(Cache::store('array')),
        'filesystem', 'encrypted' => new FilesystemRunStore(Storage::fake('runs'), encrypter: $driver === 'encrypted' ? app('encrypter') : null),
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
    $timestamps = [
        '2026-10-13T12:00:00Z', '2026-10-13T12:00:00.500Z', '2026-10-13T13:00:00.5+01:00',
        '2026-10-13T12:00:00.1234569Z', '2026-10-13T12:00:00.1234561Z', '2026-10-13T13:00:00.1234569000+01:00',
        '2026-10-13T12:00:00.'.str_repeat('1', 300).'9Z', '2026-10-13T12:00:00.'.str_repeat('1', 300).'2Z',
        '2026-10-14T01:00:00.000+13:00', '2026-10-13T11:59:59.99999999999999Z', '2026-10-13T12:00:01Z',
    ];
    $ids = array_map(fn (int $index): string => 'run_01J000000000000000000000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), range(1, count($timestamps)));
    foreach ($ids as $index => $id) {
        $fixture['run']['id'] = $id;
        $fixture['run']['created_at'] = $timestamps[$index];
        $fixture['state'] = ['foreign' => ['outputs' => (object) [], 'items' => [(object) [], [], (object) ['0' => (object) []]]]];
        $doc = StoredRun::fromArray($fixture);
        $store->save($doc, null);
        $read = $store->find($id);
        expect(json_encode($read->toArray()['state'], JSON_THROW_ON_ERROR))->toBe(json_encode($doc->toArray()['state'], JSON_THROW_ON_ERROR))
            ->and($read->run->toArray()['created_at'])->toBe($timestamps[$index]);
    }
    $expected = [$ids[10], $ids[2], $ids[1], $ids[5], $ids[3], $ids[4], $ids[6], $ids[7], $ids[8], $ids[0], $ids[9]];
    expect(array_column($store->list(20), 'id'))->toBe($expected)
        ->and(array_column($store->list(1), 'id'))->toBe([$ids[10]])
        ->and(array_column($store->list(3, $ids[2]), 'id'))->toBe([$ids[1], $ids[5], $ids[3]])
        ->and(array_column($store->list(3, $ids[4]), 'id'))->toBe([$ids[6], $ids[7], $ids[8]]);
    $paged = [];
    $cursor = null;
    for ($page = 0; $page < 6; $page++) {
        $items = array_column($store->list(2, $cursor), 'id');
        array_push($paged, ...$items);
        $cursor = $items === [] ? $cursor : end($items);
    }
    expect($paged)->toBe($expected)->and($store->list(2, $cursor))->toBeEmpty();
})->with(['memory', 'cache', 'filesystem', 'encrypted', 'database']);

it('pages earlier draft database indexes alongside current indexes without rewriting stored rows', function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    (require __DIR__.'/../../database/migrations/create_librarium_runs_table.php')->up();
    $store = new DatabaseRunStore(DB::connection());
    $fixture = Spec::read('fixtures/run-store/stored-run.json');
    $timestamps = [
        ['2026-10-13T12:00:00.9Z', '2026-10-13T12:00:00.900000Z'],
        ['2026-10-13T12:00:00.1Z', '2026-10-13T12:00:00.100000Z'],
        ['2026-10-13T12:00:00.95Z', '2026-10-13T12:00:00'],
        ['2026-10-13T11:59:59.8Z', '2026-10-13T11:59:59.800000Z'],
        ['2026-10-13T11:59:59.9Z', '2026-10-13T11:59:59'],
        ['2026-10-13T11:59:58Z', '2026-10-13T11:59:58.000000Z'],
        ['2026-10-13T12:00:01Z', '2026-10-13T12:00:01'],
    ];
    $ids = [];
    foreach ($timestamps as $index => [$timestamp, $storedIndex]) {
        $id = 'run_01J000000000000000000000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
        $ids[] = $id;
        $fixture['run']['id'] = $id;
        $fixture['run']['created_at'] = $timestamp;
        DB::table('librarium_runs')->insert([
            'id' => $id, 'created_at' => $storedIndex, 'version' => $fixture['version'],
            'document' => json_encode(StoredRun::fromArray($fixture), JSON_THROW_ON_ERROR),
        ]);
    }
    $original = DB::table('librarium_runs')->orderBy('id')->get()->all();
    expect(array_column($store->list(1, $ids[0]), 'id'))->toBe([$ids[1]])
        ->and(array_column($store->list(3, $ids[0]), 'id'))->toBe([$ids[1], $ids[4], $ids[3]])
        ->and($store->list(1, 'run_01J00000000000000000000099'))->toBeEmpty();
    $paged = [];
    $cursor = null;
    for ($page = 0; $page < count($timestamps); $page++) {
        $items = array_column($store->list(1, $cursor), 'id');
        array_push($paged, ...$items);
        $cursor = $items === [] ? $cursor : end($items);
    }
    expect($paged)->toBe([$ids[6], $ids[2], $ids[0], $ids[1], $ids[4], $ids[3], $ids[5]])
        ->and($store->list(1, $cursor))->toBeEmpty()
        ->and($store->find($ids[0])->run->toArray()['created_at'])->toBe('2026-10-13T12:00:00.9Z')
        ->and(DB::table('librarium_runs')->orderBy('id')->get()->all())->toEqual($original);
});

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
    $store = new FilesystemRunStore($disk);
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
    $store = new FilesystemRunStore($disk, encrypter: app('encrypter'));
    $doc = StoredRun::fromArray(Spec::read('fixtures/run-store/stored-run.json'));
    $store->save($doc, null);
    foreach ($disk->allFiles('librarium') as $file) {
        expect($disk->get($file))->not->toContain($doc->run->id(), 'librarium.run/1', 'signed-provider-handle');
    }
    expect($store->find($doc->run->id())->files)->toBe($doc->files);
});

it('holds the Array cache lock past the old lease through the complete commit', function (): void {
    $cache = Cache::store('array');
    $cache->setEventDispatcher(app('events'));
    $store = new CacheRunStore($cache);
    $doc = StoredRun::fromArray(Spec::read('fixtures/run-store/stored-run.json'));
    $observed = 0;
    app('events')->listen(WritingKey::class, function (WritingKey $event) use ($cache, &$observed): void {
        if (str_starts_with($event->key, 'librarium:runs:')) {
            Carbon::setTestNow(Carbon::now()->addSeconds(31));
            expect($cache->getStore()->lock('librarium:runs:lock', 0)->get())->toBeFalse();
            $observed++;
        }
    });
    try {
        expect($store->save($doc, null))->toBe(1)->and($observed)->toBe(2);
        expect($cache->getStore()->lock('librarium:runs:lock', 0)->get())->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('honors the shared marker across processes without stale-age takeover', function (): void {
    $disk = Storage::fake('contention');
    $store = new FilesystemRunStore($disk);
    $doc = StoredRun::fromArray(Spec::read('fixtures/run-store/stored-run.json'));
    $store->save($doc, null);
    $marker = $disk->path('librarium/'.$doc->run->id().'/run.json.lock');
    $input = new InputStream;
    $code = <<<'PHP'
$stream = fopen($argv[1], 'x');
chmod($argv[1], 0600);
fwrite($stream, json_encode(['token' => 'foreign-owner', 'pid' => getmypid(), 'createdAt' => 1]));
fflush($stream);
echo "locked\n"; fflush(STDOUT);
fgets(STDIN); fclose($stream);
$owner = json_decode(file_get_contents($argv[1]), true);
if ($owner['token'] === 'foreign-owner') unlink($argv[1]);
PHP;
    $process = new Process([PHP_BINARY, '-r', $code, $marker]);
    $process->setInput($input);
    $process->setTimeout(20);
    $process->start();
    try {
        expect($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'locked')))->toBeTrue();
        expect(fn () => $store->save($doc, 1))->toThrow(LogicException::class, 'run.json.lock');
        expect(json_decode(file_get_contents($marker), true)['token'])->toBe('foreign-owner')
            ->and(json_decode($disk->get('librarium/'.$doc->run->id().'/state.json'), true)['version'])->toBe(1);
        $input->write("release\n");
        $input->close();
        expect($process->wait())->toBe(0)->and($store->save($doc, 1))->toBe(2);
        expect(fn () => $store->save($doc, 1))->toThrow(RunStoreConflict::class);
        expect(file_exists($marker))->toBeFalse();
        $store->delete($doc->run->id());
        expect($store->find($doc->run->id()))->toBeNull()->and(file_exists($marker))->toBeFalse();
    } finally {
        $process->stop();
    }
});

it('rejects unsupported filesystem cache decorators and Redis clusters before mutation', function (): void {
    expect(fn () => new FilesystemRunStore(Mockery::mock(Filesystem::class)))->toThrow(LogicException::class, 'local Laravel disk');
    expect(fn () => new CacheRunStore(Cache::store('file')))->toThrow(LogicException::class, 'undecorated');
    expect(fn () => new CacheRunStore(Cache::memo('array')))->toThrow(LogicException::class, 'undecorated');
    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->once()->andReturn(Mockery::mock(PhpRedisClusterConnection::class));
    expect(fn () => new CacheRunStore(new Repository(new RedisStore($factory))))->toThrow(LogicException::class, 'clusters');
});
