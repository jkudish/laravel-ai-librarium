<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
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
        /** @var array<string, mixed> $document */
        $document = json_decode((string) $row->document, true, 512, JSON_THROW_ON_ERROR);

        return StoredRun::fromArray($document);
    }

    public function save(StoredRun $run, ?int $expectedVersion): int
    {
        $version = ($expectedVersion ?? 0) + 1;
        $data = [
            'id' => $run->run->id(),
            'created_at' => $run->run->toArray()['created_at'],
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
            $created = $cursor->run->toArray()['created_at'];
            $query->where(fn (Builder $q) => $q->where('created_at', '<', $created)
                ->orWhere(fn (Builder $q) => $q->where('created_at', $created)->where('id', '<', $before)));
        }

        return array_values($query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(function (object $row): RunSummary {
                /** @var object{document: string} $row */
                /** @var array<string, mixed> $document */
                $document = json_decode((string) $row->document, true, 512, JSON_THROW_ON_ERROR);

                return RunSummary::fromRun(StoredRun::fromArray($document)->run);
            })->all());
    }

    public function delete(string $id): void
    {
        $this->connection->table($this->table)->where('id', $id)->delete();
    }
}
