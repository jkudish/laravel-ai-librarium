<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Events\ResearchFailed;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\ResearchStatus;
use Jkudish\LaravelAiLibrarium\ResearchStatusStore;
use Laravel\Ai\Jobs\Concerns\InvokesQueuedResponseCallbacks;
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

    public function __construct(public readonly ResearchPlan $plan)
    {
        $this->timeout = $plan->jobTimeout;

        $connection = config('librarium.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        $queue = config('librarium.queue.name', 'research');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(ExecutesResearch $executor): void
    {
        $this->withCallbacks(fn () => $executor->run($this->plan));
    }

    public function failed(Throwable $exception): void
    {
        $statuses = app(ResearchStatusStore::class);
        $occurredAt = now()->toImmutable();

        try {
            $shouldDispatch = $statuses->transitionToTerminal(
                $this->plan->requestId,
                new ResearchStatus(ResearchState::Failed, 'Research failed unexpectedly.', $occurredAt),
            );
        } catch (Throwable $statusException) {
            report($statusException);
            $shouldDispatch = true;
        }

        try {
            if ($shouldDispatch) {
                ResearchFailed::dispatch($this->plan->requestId, $exception, $occurredAt);
            }
        } finally {
            $this->invokeFailureCallbacks($exception);
        }
    }
}
