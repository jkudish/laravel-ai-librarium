<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Carbon\CarbonImmutable;
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
        $seconds = [];
        if ($before !== null) {
            $cursor = $this->find($before);
            if ($cursor === null) {
                return [];
            }
            $created = explode('.', RunListing::createdAt($cursor->run->toArray()['created_at']), 2)[0];
            $seconds[] = $created;
            $query->where('created_at', '<', $created);
        }

        // Earlier draft indexes include fractions; current indexes contain only seconds.
        // Read complete second ranges for bounded candidates and the cursor, then sort
        // documents exactly. Neither index representation determines within-second order.
        foreach ($query->select('created_at')->orderByDesc('created_at')->limit($limit)->get() as $row) {
            /** @var object{created_at: string} $row */
            $seconds[] = substr($row->created_at, 0, 19);
        }
        if ($seconds === []) {
            return [];
        }
        $runs = $this->connection->table($this->table)->where(function (Builder $query) use ($seconds): void {
            foreach (array_unique($seconds) as $second) {
                $next = CarbonImmutable::parse($second, 'UTC')->addSecond()->format('Y-m-d\TH:i:s');
                $query->orWhere(fn (Builder $range): Builder => $range->where('created_at', '>=', $second)->where('created_at', '<', $next));
            }
        })->get()
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
