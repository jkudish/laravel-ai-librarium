<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\RunSummary;
use Jkudish\LaravelAiLibrarium\StoredRun;
use LogicException;
use Predis\Client;
use Predis\Connection\StreamConnection;
use Predis\Retry\Retry;

final readonly class CacheRunStore implements RunStore
{
    private ArrayStore|RedisStore $backend;

    private PhpRedisConnection|PredisConnection|null $redis;

    // Compare the complete JSON read before this commit, not a floating-point
    // Lua version number. No writer can interleave the comparison and writes.
    private const string SAVE = <<<'LUA'
if not redis.acl_check_cmd('INFO', 'cluster') then return redis.error_reply('Run storage capabilities denied') end
if not string.find(redis.call('INFO', 'cluster'), 'cluster_enabled:0', 1, true) then
    return redis.error_reply('Run storage requires standalone Redis')
end
local current = redis.call('GET', KEYS[1])
if (current or '') ~= ARGV[1] then return 0 end
local kind = redis.call('TYPE', KEYS[2]).ok
if kind ~= 'none' and kind ~= 'set' then return redis.error_reply('Invalid Run index type') end
if not redis.acl_check_cmd('SET', KEYS[1], ARGV[2], 'EX', ARGV[3])
    or not redis.acl_check_cmd('SADD', KEYS[2], ARGV[4]) then
    return redis.error_reply('Run commit permissions denied')
end
redis.call('SET', KEYS[1], ARGV[2], 'EX', ARGV[3])
redis.call('SADD', KEYS[2], ARGV[4])
return 1
LUA;

    private const string DELETE = <<<'LUA'
if not redis.acl_check_cmd('INFO', 'cluster') then return redis.error_reply('Run storage capabilities denied') end
if not string.find(redis.call('INFO', 'cluster'), 'cluster_enabled:0', 1, true) then
    return redis.error_reply('Run storage requires standalone Redis')
end
local kind = redis.call('TYPE', KEYS[2]).ok
if kind ~= 'none' and kind ~= 'set' then return redis.error_reply('Invalid Run index type') end
if not redis.acl_check_cmd('DEL', KEYS[1]) or not redis.acl_check_cmd('SREM', KEYS[2], ARGV[1]) then
    return redis.error_reply('Run delete permissions denied')
end
redis.call('DEL', KEYS[1])
redis.call('SREM', KEYS[2], ARGV[1])
return 1
LUA;

    public function __construct(private Repository $cache, private int $ttl = 604800, private string $prefix = 'librarium:runs:')
    {
        $backend = $cache->getStore();
        if ($cache::class !== Repository::class || ! in_array($backend::class, [ArrayStore::class, RedisStore::class], true) || $ttl < 1) {
            throw new LogicException('Run cache storage requires an undecorated ArrayStore or standalone RedisStore and a positive TTL; use a custom RunStore for other caches.');
        }
        /** @var ArrayStore|RedisStore $backend */
        $this->backend = $backend;
        $connection = $backend instanceof RedisStore ? $backend->connection() : null;
        if ($connection !== null && ! in_array($connection::class, [PhpRedisConnection::class, PredisConnection::class], true)) {
            throw new LogicException('Run cache storage does not support Redis clusters or decorated connections.');
        }
        /** @var PhpRedisConnection|PredisConnection|null $connection */
        $this->redis = $connection;
        $this->assertRedisOptions();
    }

    public function find(string $id): ?StoredRun
    {
        $value = $this->document($id);
        if ($value === null) {
            return null;
        }

        return StoredRun::fromJson($value);
    }

    public function save(StoredRun $run, ?int $expectedVersion): int
    {
        if ($this->redis !== null) {
            $current = $this->document($run->run->id());
            if (($current === null ? null : StoredRun::fromJson($current)->version) !== $expectedVersion) {
                throw new RunStoreConflict('The Run was changed by another writer.');
            }
            $version = ($expectedVersion ?? 0) + 1;
            $committed = $this->script(self::SAVE, $run->run->id(), [$current ?? '', json_encode($run->withVersion($version), JSON_THROW_ON_ERROR), (string) $this->ttl, $run->run->id()]);
            if ($committed === 0) {
                throw new RunStoreConflict('The Run was changed by another writer.');
            }

            return $version;
        }
        /** @var int $version */
        $version = $this->backend->lock($this->prefix.'lock', 0)->block(10, function () use ($run, $expectedVersion): int {
            if ($this->find($run->run->id())?->version !== $expectedVersion) {
                throw new RunStoreConflict('The Run was changed by another writer.');
            }
            $version = ($expectedVersion ?? 0) + 1;
            if (! $this->cache->put($this->prefix.$run->run->id(), json_encode($run->withVersion($version), JSON_THROW_ON_ERROR), $this->ttl)) {
                throw new LogicException('Could not persist the Run.');
            }
            $ids = $this->ids();
            $ids[$run->run->id()] = true;
            if (! $this->cache->forever($this->prefix.'index', $ids)) {
                throw new LogicException('Could not persist the Run index.');
            }

            return $version;
        });

        return $version;
    }

    public function list(int $limit = 50, ?string $before = null): array
    {
        $runs = [];
        foreach (array_keys($this->ids()) as $id) {
            $doc = $this->find($id);
            if ($doc !== null) {
                $runs[] = RunSummary::fromRun($doc->run);
            }
        }

        return RunListing::page($runs, $limit, $before);
    }

    public function delete(string $id): void
    {
        if ($this->redis !== null) {
            $this->script(self::DELETE, $id, [$id]);

            return;
        }
        $this->backend->lock($this->prefix.'lock', 0)->block(10, function () use ($id): void {
            $this->cache->forget($this->prefix.$id);
            $ids = $this->ids();
            unset($ids[$id]);
            if (! $this->cache->forever($this->prefix.'index', $ids)) {
                throw new LogicException('Could not persist the Run index.');
            }
        });
    }

    /** @return array<string, true> */
    private function ids(): array
    {
        if ($this->redis !== null) {
            $this->assertRedisOptions();
            /** @var list<string> $ids */
            $ids = $this->redis->smembers($this->redisKey('index'));

            return array_fill_keys($ids, true);
        }
        /** @var array<string, true> $ids */
        $ids = $this->cache->get($this->prefix.'index', []);

        return $ids;
    }

    private function document(string $id): ?string
    {
        $this->assertRedisOptions();
        $value = $this->redis === null ? $this->cache->get($this->prefix.$id) : $this->redis->get($this->redisKey($id));
        if ($value !== null && ! is_string($value)) {
            throw new LogicException('Invalid cached Run.');
        }

        return $value;
    }

    private function redisKey(string $suffix): string
    {
        return ($this->backend instanceof RedisStore ? $this->backend->getPrefix() : '').$this->prefix.$suffix;
    }

    private function assertRedisOptions(): void
    {
        if ($this->redis instanceof PhpRedisConnection && ($this->redis->serialized() || $this->redis->compressed())) {
            throw new LogicException('Run Redis storage requires serialization and compression disabled.');
        }
        if ($this->redis instanceof PhpRedisConnection) {
            /** @var \Redis $client */
            $client = $this->redis->client();
            if ($client::class !== \Redis::class) {
                throw new LogicException('Run Redis storage requires an undecorated standalone client.');
            }
            if ($client->getOption(\Redis::OPT_MAX_RETRIES) !== 0) {
                throw new LogicException('Run Redis storage requires native command retries disabled.');
            }
        } elseif ($this->redis instanceof PredisConnection) {
            /** @var Client $client */
            $client = $this->redis->client();
            if ($client::class !== Client::class || $client->getConnection()::class !== StreamConnection::class) {
                throw new LogicException('Run Redis storage requires an undecorated standalone client.');
            }
            $retry = $client->getConnection()->getParameters()->__get('retry');
            if (! $retry instanceof Retry || $retry->getRetries() !== 0) {
                throw new LogicException('Run Redis storage requires native command retries disabled.');
            }
        }
    }

    /** @param list<string> $arguments */
    private function script(string $script, string $id, array $arguments): int
    {
        $this->assertRedisOptions();
        if ($this->redis instanceof PhpRedisConnection) {
            // Bypass Laravel's configurable command replay: a lost commit
            // response is indeterminate, never permission to submit again.
            /** @var \Redis $client */
            $client = $this->redis->client();
            $result = $client->eval($script, [$this->redisKey($id), $this->redisKey('index'), ...$arguments], 2);
        } elseif ($this->redis instanceof PredisConnection) {
            $result = $this->redis->eval($script, 2, $this->redisKey($id), $this->redisKey('index'), ...$arguments);
        } else {
            throw new LogicException('Missing Redis connection.');
        }
        if (! in_array($result, [0, 1], true)) {
            throw new LogicException('Could not persist the Run.');
        }

        return $result;
    }
}
