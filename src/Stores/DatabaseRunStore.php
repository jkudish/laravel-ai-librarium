<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\RunSummary;
use Jkudish\LaravelAiLibrarium\StoredRun;

final readonly class DatabaseRunStore implements RunStore
{
    public function __construct(private ConnectionInterface $connection, private string $table = 'librarium_runs') {}

    public function find(string $id): ?StoredRun
    {
        $row = $this->connection->table($this->table)->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        /** @var object{document: string} $row */
        return StoredRun::fromJson($row->document);
    }

    public function save(StoredRun $run, ?int $expectedVersion): int
    {
        $version = ($expectedVersion ?? 0) + 1;
        $data = [
            'id' => $run->run->id(),
            'created_at' => explode('.', RunListing::createdAt($run->run->toArray()['created_at']), 2)[0],
            'version' => $version,
            'document' => json_encode($run->withVersion($version), JSON_THROW_ON_ERROR),
        ];
        if ($expectedVersion === null) {
            try {
                $this->connection->table($this->table)->insert($data);
            } catch (UniqueConstraintViolationException $exception) {
                throw new RunStoreConflict('The Run already exists.', previous: $exception);
            }
        } elseif ($this->connection->table($this->table)->where('id', $run->run->id())->where('version', $expectedVersion)->update($data) !== 1) {
            throw new RunStoreConflict('The Run was changed by another writer.');
        }

        return $version;
    }

    public function list(int $limit = 50, ?string $before = null): array
    {
        if ($limit < 1) {
            return [];
        }
        $query = $this->connection->table($this->table);
        if ($before !== null) {
            $cursor = $this->find($before);
            if ($cursor === null) {
                return [];
            }
            $created = explode('.', RunListing::createdAt($cursor->run->toArray()['created_at']), 2)[0];
            $query->where('created_at', '<=', $created);
        }

        // Index whole-second buckets without capping fraction length. Include complete
        // buckets, then order their documents exactly; a cursor may exhaust its own bucket.
        $seconds = (clone $query)->select('created_at')->distinct()->orderByDesc('created_at')->limit($limit + 1)->pluck('created_at');
        $runs = $query->whereIn('created_at', $seconds)->get()
            ->map(function (object $row): RunSummary {
                /** @var object{document: string} $row */
                return RunSummary::fromRun(StoredRun::fromJson($row->document)->run);
            })->all();

        return RunListing::page(array_values($runs), $limit, $before);
    }

    public function delete(string $id): void
    {
        $this->connection->table($this->table)->where('id', $id)->delete();
    }
}
