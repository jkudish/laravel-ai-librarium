# Writing a RunStore

Implement `Jkudish\LaravelAiLibrarium\Contracts\RunStore`:

```php
public function find(string $id): ?StoredRun;
public function save(StoredRun $run, ?int $expectedVersion): int;
/** @return list<RunSummary> */
public function list(int $limit = 50, ?string $before = null): array;
public function delete(string $id): void;
```

Persist the whole StoredRun (`run`, private `state`, UTF-8 `files`, `version`).
`StoredRun::fromArray()` validates the shared schema. Return a new version from
save; do not mutate the immutable document. Null expectedVersion means create
only, not unconditional overwrite. Updates must atomically compare the stored
version, increment it, or throw `Exceptions\RunStoreConflict`. A read/check/write
sequence without a lock or conditional write is unsafe.

Return null for a missing Run; delete is idempotent. List newest first by
created_at then id descending, with an exclusive `before` id cursor and the
requested limit. Retain all files and optional Run fields. Do not place
credentials in state or log handles/evidence. Expiry and encryption are store
policy; document them for the consuming app.

Register in an application's service provider:

```php
Librarium::extendStore('redis-runs', fn ($app, array $config) =>
    $app->make(App\Research\RedisRunStore::class));
// config/librarium.php
'runs' => ['store' => 'redis-runs'],
```

Alternatively set `runs.store` to the class name; Laravel resolves it through
the container. Factories receive the container and the `runs` configuration.

Run the same shared contract fixture as the four built-in stores:

```php
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Testing\RunStoreContractTests;

class StoreTestCase extends Tests\TestCase {
    use RunStoreContractTests;

    protected function store(): RunStore {
        return app(App\Research\RedisRunStore::class); // Fresh isolated namespace.
    }
}

uses(StoreTestCase::class);

it('passes the shared store contract', function () {
    $this->assertRunStoreContract();
});
```

The contract tests create/update/stale conflicts, round-trip files/state,
not-found, order/paging and delete. Also test backend-specific concurrency,
expiry, write failure and encryption where those are your responsibility.
