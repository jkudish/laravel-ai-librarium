<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\RunSummary;
use Jkudish\LaravelAiLibrarium\StoredRun;

interface RunStore
{
    public function find(string $id): ?StoredRun;

    /** @throws RunStoreConflict */
    public function save(StoredRun $run, ?int $expectedVersion): int;

    /** @return list<RunSummary> Newest first, with an exclusive Run-id cursor. */
    public function list(int $limit = 50, ?string $before = null): array;

    public function delete(string $id): void;
}
