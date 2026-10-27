<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Closure;
use Illuminate\Contracts\Container\Container;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\StoredRun;

interface Librarium
{
    public function research(string $question): PendingResearch;

    public function find(string $id): ?Run;

    /** @return array<string, mixed> */
    public function sources(): array;

    /** @param Closure(Container, array<string, mixed>): RunStore $factory */
    public function extendStore(string $name, Closure $factory): void;

    /**
     * @internal
     *
     * @param  array<string, mixed>  $request
     */
    public function plan(array $request): StoredRun;

    /**
     * @internal
     *
     * @param  array<string, mixed>  $request
     */
    public function execute(array $request, bool $detach): Run;

    /**
     * @internal
     *
     * @param  array<string, mixed>  $request
     */
    public function enqueue(array $request): QueuedResearchResponse;
}
