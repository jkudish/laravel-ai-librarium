<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Run;
use Laravel\Ai\Jobs\Concerns\InvokesQueuedResponseCallbacks;
use LogicException;
use Throwable;

final class RunResearch implements ShouldBeEncrypted, ShouldQueue
{
    use InvokesQueuedResponseCallbacks {
        failed as private invokeFailureCallbacks;
    }
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public readonly string $runId)
    {
        $timeout = config('librarium.queue.timeout', 7200);
        $researchTimeout = app(LibrariumManager::class)->timeout();
        if (! is_int($timeout) || $timeout <= $researchTimeout || $timeout > 86400) {
            throw new LogicException('The queue timeout must exceed librarium.timeout and be at most 86400 seconds.');
        }
        $this->timeout = $timeout;
        $connection = config('librarium.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
        $queue = config('librarium.queue.name', 'research');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(RunRuntime $runtime): void
    {
        $this->withCallbacks(function () use ($runtime): Run {
            $deadline = CarbonImmutable::now()->addSeconds(app(LibrariumManager::class)->timeout());
            $run = $runtime->start($this->runId);
            while ($run->status() === 'running' && CarbonImmutable::now()->lessThan($deadline)) {
                Sleep::for(1)->seconds();
                $run = $runtime->refresh($this->runId);
            }

            return $run;
        });
    }

    public function failed(Throwable $exception): void
    {
        try {
            app(RunRuntime::class)->failInterrupted($this->runId);
        } finally {
            $this->invokeFailureCallbacks($exception);
        }
    }
}
