<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\RunSummary;
use Jkudish\LaravelAiLibrarium\StoredRun;
use LogicException;

final readonly class FilesystemRunStore implements RunStore
{
    private LockProvider $backend;

    public function __construct(
        private Filesystem $disk,
        private Repository $locks,
        private string $path = 'librarium',
        private ?Encrypter $encrypter = null,
        private string $lockNamespace = 'default',
    ) {
        $backend = $locks->getStore();
        if (! $backend instanceof LockProvider) {
            throw new LogicException('Filesystem Run storage requires a cache store with atomic locks.');
        }
        $this->backend = $backend;
    }

    public function find(string $id): ?StoredRun
    {
        $folder = $this->folder($id);

        /** @var StoredRun|null $run */
        $run = $this->backend->lock($this->lockKey($id), 30)->block(10, fn (): ?StoredRun => $this->read($folder));

        return $run;
    }

    public function save(StoredRun $run, ?int $expectedVersion): int
    {
        $id = $run->run->id();
        $folder = $this->folder($id);

        /** @var int $version */
        $version = $this->backend->lock($this->lockKey($id), 30)->block(10, function () use ($run, $folder, $expectedVersion): int {
            $existing = $this->read($folder);
            if ($existing?->version !== $expectedVersion) {
                throw new RunStoreConflict('The Run was changed by another writer.');
            }
            $version = ($expectedVersion ?? 0) + 1;
            foreach ($run->files as $name => $contents) {
                $this->write($folder.'/'.$name, $contents);
            }
            foreach (array_diff(array_keys($existing === null ? [] : $existing->files), array_keys($run->files)) as $name) {
                $this->disk->delete($folder.'/'.$name);
            }
            $this->write($folder.'/run.json', json_encode($run->run, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->write($folder.'/state.json', json_encode(['version' => $version, 'state' => $run->toArray()['state']], JSON_THROW_ON_ERROR));

            return $version;
        });

        return $version;
    }

    public function list(int $limit = 50, ?string $before = null): array
    {
        $runs = [];
        foreach ($this->disk->directories($this->path) as $folder) {
            $id = basename($folder);
            if (preg_match('/^run_[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
                continue;
            }
            $run = $this->find($id);
            if ($run !== null) {
                $runs[] = RunSummary::fromRun($run->run);
            }
        }

        return RunListing::page($runs, $limit, $before);
    }

    public function delete(string $id): void
    {
        $folder = $this->folder($id);
        $this->backend->lock($this->lockKey($id), 30)->block(10, fn (): bool => $this->disk->deleteDirectory($folder));
    }

    private function folder(string $id): string
    {
        if (preg_match('/^run_[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid Run id.');
        }

        return rtrim($this->path, '/').'/'.$id;
    }

    private function lockKey(string $id): string
    {
        return 'librarium:filesystem:'.hash('sha256', $this->lockNamespace.':'.$this->path.':'.$id);
    }

    private function read(string $folder): ?StoredRun
    {
        if (! $this->disk->exists($folder.'/run.json')) {
            return null;
        }
        /** @var array<string, mixed> $run */
        $run = json_decode($this->contents($folder.'/run.json'), true, 512, JSON_THROW_ON_ERROR);
        /** @var object{state: object, version: int} $state */
        $state = json_decode($this->contents($folder.'/state.json'), false, 512, JSON_THROW_ON_ERROR);
        $files = [];
        foreach (['answers', 'evidence'] as $directory) {
            foreach ($this->disk->files($folder.'/'.$directory) as $file) {
                $name = substr($file, strlen($folder) + 1);
                $files[$name] = $this->contents($file);
            }
        }

        return StoredRun::fromJson(json_encode(['run' => $run, 'state' => $state->state, 'files' => (object) $files, 'version' => $state->version], JSON_THROW_ON_ERROR));
    }

    private function contents(string $file): string
    {
        $value = $this->disk->get($file);
        if ($value === null) {
            throw new LogicException('Incomplete Run folder.');
        }

        $contents = $this->encrypter === null ? $value : $this->encrypter->decrypt($value, false);
        if (! is_string($contents)) {
            throw new LogicException('Invalid Run file contents.');
        }

        return $contents;
    }

    private function write(string $file, string $contents): void
    {
        if (! $this->disk->put($file, $this->encrypter === null ? $contents : $this->encrypter->encrypt($contents, false))) {
            throw new LogicException('Could not persist the Run.');
        }
    }
}
