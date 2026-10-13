<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\RunSummary;
use Jkudish\LaravelAiLibrarium\StoredRun;
use League\Flysystem\Local\LocalFilesystemAdapter;
use LogicException;

final readonly class FilesystemRunStore implements RunStore
{
    public function __construct(
        private Filesystem $disk,
        private string $path = 'librarium',
        private ?Encrypter $encrypter = null,
    ) {
        if (! $disk instanceof FilesystemAdapter || $disk->getAdapter()::class !== LocalFilesystemAdapter::class) {
            throw new LogicException('Filesystem Run storage requires a local Laravel disk; use a custom RunStore for other disks.');
        }
    }

    public function find(string $id): ?StoredRun
    {
        $folder = $this->folder($id);

        /** @var StoredRun|null $run */
        $run = $this->locked($folder, fn (): ?StoredRun => $this->read($folder));

        return $run;
    }

    public function save(StoredRun $run, ?int $expectedVersion): int
    {
        $id = $run->run->id();
        $folder = $this->folder($id);

        /** @var int $version */
        $version = $this->locked($folder, function () use ($run, $folder, $expectedVersion): int {
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
        $this->locked($folder, function () use ($folder): void {
            // Keep the marker in place until commit/release. Removing its
            // parent directory would let another writer acquire a new marker.
            foreach ($this->disk->allFiles($folder) as $file) {
                if ($file !== $folder.'/run.json.lock' && ! $this->disk->delete($file)) {
                    throw new LogicException('Could not delete the Run.');
                }
            }
        });
    }

    private function folder(string $id): string
    {
        if (preg_match('/^run_[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid Run id.');
        }

        return rtrim($this->path, '/').'/'.$id;
    }

    /** @param callable(): mixed $action */
    private function locked(string $folder, callable $action): mixed
    {
        if (! $this->disk->makeDirectory($folder)) {
            throw new LogicException('Could not create the Run folder.');
        }
        /** @var FilesystemAdapter $disk */
        $disk = $this->disk;
        $marker = $disk->path($folder.'/run.json.lock');
        $token = (string) Str::uuid();
        $deadline = microtime(true) + 10;
        do {
            $mask = umask(0077);
            try {
                $stream = @fopen($marker, 'x+b');
            } finally {
                umask($mask);
            }
            if ($stream !== false) {
                break;
            }
            clearstatcache(true, $marker);
            if (! is_file($marker) || microtime(true) >= $deadline) {
                throw new LogicException('Cannot acquire run.json.lock; confirm its owner stopped before manually removing a crashed-owner marker.');
            }
            usleep(20000);
        } while (true);
        try {
            $owner = json_encode(['token' => $token, 'pid' => getmypid(), 'createdAt' => (int) (microtime(true) * 1000)], JSON_THROW_ON_ERROR);
            if (fwrite($stream, $owner) !== strlen($owner) || ! fflush($stream)) {
                throw new LogicException('Could not persist the Run lock owner.');
            }

            return $action();
        } finally {
            fclose($stream);
            $owner = @file_get_contents($marker);
            $owner = is_string($owner) ? json_decode($owner, true) : null;
            if (is_array($owner) && ($owner['token'] ?? null) === $token) {
                if (! unlink($marker)) {
                    throw new LogicException('Could not release the Run lock.');
                }
            }
        }
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
