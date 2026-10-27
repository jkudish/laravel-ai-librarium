<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Spec;
use Jkudish\LaravelAiLibrarium\StoredRun;
use Jkudish\LaravelAiLibrarium\Stores\CacheRunStore;
use Jkudish\LaravelAiLibrarium\Tests\Support\StoreContract;
use Predis\Client;
use Predis\Retry\Retry;
use Predis\Retry\Strategy\NoBackoff;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $port = getenv('LIBRARIUM_REDIS_TEST_PORT');
    if ($port === false || $port === '') {
        $this->markTestSkipped('Provide LIBRARIUM_REDIS_TEST_PORT for the required disposable loopback Redis proof.');
    }
    expect(ctype_digit($port) && (int) $port > 0 && (int) $port < 65536)->toBeTrue();
    $this->redisPrefix = 'librarium-proof:'.Str::uuid().':';
});

afterEach(function (): void {
    if (isset($this->redisPrefix)) {
        $client = runStoreRedis('phpredis', $this->redisPrefix)->getStore()->connection()->client();
        $keys = $client->keys($this->redisPrefix.'*');
        if ($keys !== []) {
            $client->del($keys);
        }
    }
});

/** The endpoint is always loopback; this never reads app Redis credentials. */
function runStoreRedis(string $client, string $prefix): Repository
{
    $manager = new RedisManager(new Container, $client, [
        'default' => ['host' => '127.0.0.1', 'port' => (int) getenv('LIBRARIUM_REDIS_TEST_PORT'), 'database' => 0, 'max_retries' => 0, 'timeout' => 3, 'read_timeout' => 3],
    ]);

    return new Repository(new RedisStore($manager, $prefix));
}

it('passes the normative contract with real Redis JSON TTL and index semantics', function (string $client): void {
    $repository = runStoreRedis($client, $this->redisPrefix);
    $store = new CacheRunStore($repository, 1);
    (new StoreContract($store))->assertRunStoreContract();
    foreach ($store->list() as $summary) {
        $store->delete($summary->id);
    }
    $fixture = Spec::read('fixtures/run-store/stored-run.json');
    $fixture['state']['opaque'] = ['outputs' => (object) [], 'items' => [[], (object) []]];
    $doc = StoredRun::fromArray($fixture);
    expect($store->save($doc, null))->toBe(1);
    $connection = $repository->getStore()->connection();
    $key = $this->redisPrefix.'librarium:runs:'.$doc->run->id();
    $json = $connection->get($key);
    expect(json_decode($json, false, 512, JSON_THROW_ON_ERROR)->state->opaque->outputs)->toBeInstanceOf(stdClass::class)
        ->and($connection->ttl($key))->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1)
        ->and(json_encode($store->find($doc->run->id())->toArray()['state']))->toBe(json_encode($doc->toArray()['state']))
        ->and(array_column($store->list(), 'id'))->toContain($doc->run->id());
    usleep(1100000);
    expect($store->find($doc->run->id()))->toBeNull()->and($store->list())->toBeEmpty();
    $store->delete($doc->run->id());
    expect($connection->smembers($this->redisPrefix.'librarium:runs:index'))->toBeEmpty();
})->with(['phpredis', 'predis']);

it('rejects a real stalled process after another writer commits without overwriting it', function (string $client): void {
    $repository = runStoreRedis($client, $this->redisPrefix);
    $store = new CacheRunStore($repository);
    $doc = StoredRun::fromArray(Spec::read('fixtures/run-store/stored-run.json'));
    $store->save($doc, null);
    $input = new InputStream;
    $code = <<<'PHP'
require $argv[1];
$manager = new Illuminate\Redis\RedisManager(new Illuminate\Container\Container, $argv[2], ['default' => ['host' => '127.0.0.1', 'port' => (int) $argv[3], 'max_retries' => 0, 'timeout' => 3, 'read_timeout' => 3]]);
$connection = $manager->connection();
$connection->setEventDispatcher(new Illuminate\Events\Dispatcher);
$connection->listen(function ($event) {
    if ($event->command === 'get') { echo "read\n"; fflush(STDOUT); fgets(STDIN); }
});
$store = new Jkudish\LaravelAiLibrarium\Stores\CacheRunStore(new Illuminate\Cache\Repository(new Illuminate\Cache\RedisStore($manager, $argv[4])));
$doc = Jkudish\LaravelAiLibrarium\StoredRun::fromJson($argv[5]);
try { $store->save($doc, 1); echo "overwritten\n"; exit(2); }
catch (Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict) { echo "conflict\n"; }
PHP;
    $process = new Process([PHP_BINARY, '-r', $code, dirname(__DIR__, 2).'/vendor/autoload.php', $client, (string) getenv('LIBRARIUM_REDIS_TEST_PORT'), $this->redisPrefix, json_encode($doc, JSON_THROW_ON_ERROR)]);
    $process->setInput($input);
    $process->setTimeout(15);
    $process->start();
    try {
        expect($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'read')))->toBeTrue();
        $newer = new StoredRun($doc->run, ['writer' => 'newer'], $doc->files, 1);
        expect($store->save($newer, 1))->toBe(2);
        $input->write("release\n");
        $input->close();
        expect($process->wait())->toBe(0)->and($process->getOutput())->toContain('conflict')->not->toContain('overwritten')
            ->and($store->find($doc->run->id())->version)->toBe(2)
            ->and($store->find($doc->run->id())->state)->toBe(['writer' => 'newer'])
            ->and(array_column($store->list(), 'id'))->toContain($doc->run->id());
    } finally {
        $process->stop();
        $store->delete($doc->run->id());
    }
})->with(['phpredis', 'predis']);

it('fails closed on corrupt index and ACL-denied claim or handle commits', function (string $stage): void {
    $repository = runStoreRedis('phpredis', $this->redisPrefix);
    $connection = $repository->getStore()->connection();
    $admin = $connection->client();
    $user = 'proof-'.Str::uuid();
    $admin->rawCommand('ACL', 'SETUSER', $user, 'on', 'nopass', '~'.$this->redisPrefix.'*', '+@all');
    try {
        $manager = new RedisManager(new Container, 'phpredis', ['default' => [
            'host' => '127.0.0.1', 'port' => (int) getenv('LIBRARIUM_REDIS_TEST_PORT'), 'username' => $user, 'password' => 'placeholder', 'max_retries' => 0,
        ]]);
        $store = new CacheRunStore(new Repository(new RedisStore($manager, $this->redisPrefix)));
        Librarium::extendStore('redis-proof', fn () => $store);
        config(['librarium.runs.store' => 'redis-proof', 'librarium.timeout' => 10]);
        Bus::fake();
        $pending = Librarium::research('Rejected persistence')->deep()->only(['openai'])->queue();
        $id = $pending->id();
        unset($pending);
        $key = $this->redisPrefix.'librarium:runs:'.$id;
        if ($stage === 'index') {
            $connection->del($this->redisPrefix.'librarium:runs:index');
            $connection->set($this->redisPrefix.'librarium:runs:index', 'wrong-type');
        } elseif ($stage === 'claim') {
            $admin->rawCommand('ACL', 'SETUSER', $user, '-set');
        } elseif ($stage === 'capability') {
            $admin->rawCommand('ACL', 'SETUSER', $user, '-info');
        }
        Http::fake(function () use ($admin, $user) {
            $admin->rawCommand('ACL', 'SETUSER', $user, '-sadd');

            return Http::response(['id' => 'resp_accepted_123', 'status' => 'queued']);
        });
        $error = match ($stage) {
            'index' => 'Invalid Run index type',
            'capability' => 'Run storage capabilities denied',
            default => 'Run commit permissions denied',
        };
        expect(fn () => app(RunRuntime::class)->start($id))->toThrow(RedisException::class, $error);
        if ($stage === 'handle') {
            Http::assertSentCount(1);
            expect($store->find($id)->version)->toBe(2)->and($store->find($id)->state)->not->toHaveKey('handles');
        } else {
            Http::assertNothingSent();
            expect($store->find($id)->version)->toBe(1);
        }
        $admin->rawCommand('ACL', 'SETUSER', $user, '+@all');
        $connection->del($key, $this->redisPrefix.'librarium:runs:index');
    } finally {
        $admin->rawCommand('ACL', 'DELUSER', $user);
    }
})->with(['claim', 'handle', 'index', 'capability']);

it('rejects serializer compression and native retry configuration before mutation', function (string $option): void {
    $repository = runStoreRedis('phpredis', $this->redisPrefix);
    $client = $repository->getStore()->connection()->client();
    [$key, $value] = match ($option) {
        'serializer' => [Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP],
        'retries' => [Redis::OPT_MAX_RETRIES, 1],
        default => [Redis::OPT_COMPRESSION, constant(Redis::class.'::'.$option)],
    };
    expect($client->setOption($key, $value))->toBeTrue();
    expect(fn () => new CacheRunStore($repository))->toThrow(LogicException::class);
    $client->setOption($key, 0);
    expect($client->keys($this->redisPrefix.'*'))->toBeEmpty();
})->with(fn (): array => [
    'serializer',
    ...array_filter(['COMPRESSION_LZF', 'COMPRESSION_LZ4', 'COMPRESSION_ZSTD'], fn (string $codec): bool => defined(Redis::class.'::'.$codec)),
    'retries',
]);

it('rejects native Predis retries and aggregate connections before mutation', function (string $option): void {
    $parameters = ['host' => '127.0.0.1', 'port' => (int) getenv('LIBRARIUM_REDIS_TEST_PORT')];
    $client = match ($option) {
        'retries' => new Client($parameters + ['retry' => new Retry(new NoBackoff, 1)]),
        'replication' => new Client([$parameters, $parameters], ['replication' => 'sentinel', 'service' => 'proof']),
        'cluster' => new Client([$parameters, $parameters], ['cluster' => 'redis']),
    };
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->once()->andReturn(new PredisConnection($client));
    expect(fn () => new CacheRunStore(new Repository(new RedisStore($factory, $this->redisPrefix))))->toThrow(LogicException::class);
    expect(runStoreRedis('phpredis', $this->redisPrefix)->getStore()->connection()->client()->keys($this->redisPrefix.'*'))->toBeEmpty();
})->with(['retries', 'replication', 'cluster']);
