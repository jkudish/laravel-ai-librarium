<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\RunSummary;
use Jkudish\LaravelAiLibrarium\StoredRun;

final class MemoryRunStore implements RunStore
{
    /** @var array<string, StoredRun> */
    private array $runs = [];

    public function find(string $id): ?StoredRun
    {
        return $this->runs[$id] ?? null;
    }

    public function save(StoredRun $run, ?int $expectedVersion): int
    {
        if (($this->runs[$run->run->id()]->version ?? null) !== $expectedVersion) {
            throw new RunStoreConflict('The Run was changed by another writer.');
        }
        $version = ($expectedVersion ?? 0) + 1;
        $this->runs[$run->run->id()] = $run->withVersion($version);

        return $version;
    }

    public function list(int $limit = 50, ?string $before = null): array
    {
        return RunListing::page(array_map(fn (StoredRun $doc): RunSummary => RunSummary::fromRun($doc->run), array_values($this->runs)), $limit, $before);
    }

    public function delete(string $id): void
    {
        unset($this->runs[$id]);
    }
}
