<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\RunSummary;
use Jkudish\LaravelAiLibrarium\StoredRun;
use LogicException;

final readonly class CacheRunStore implements RunStore
{
    private LockProvider $locks;

    public function __construct(private Repository $cache, private int $ttl = 604800, private string $prefix = 'librarium:runs:')
    {
        $backend = $cache->getStore();
        if (! $backend instanceof LockProvider || $ttl < 1) {
            throw new LogicException('Run cache storage requires atomic locks and a positive TTL.');
        }
        $this->locks = $backend;
    }

    public function find(string $id): ?StoredRun
    {
        $value = $this->cache->get($this->prefix.$id);
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new LogicException('Invalid cached Run.');
        }

        return StoredRun::fromJson($value);
    }

    public function save(StoredRun $run, ?int $expectedVersion): int
    {
        /** @var int $version */
        $version = $this->locks->lock($this->prefix.'lock', 30)->block(10, function () use ($run, $expectedVersion): int {
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
        $this->locks->lock($this->prefix.'lock', 30)->block(10, function () use ($id): void {
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
        /** @var array<string, true> $ids */
        $ids = $this->cache->get($this->prefix.'index', []);

        return $ids;
    }
}
