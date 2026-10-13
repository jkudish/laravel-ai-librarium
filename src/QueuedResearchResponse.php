<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;
use Jkudish\LaravelAiLibrarium\Testing\FakePendingDispatch;
use LogicException;

/** @mixin PendingDispatch */
final class QueuedResearchResponse
{
    public function __construct(
        public readonly string $requestId,
        private readonly PendingDispatch $dispatchable,
    ) {}

    public function id(): string
    {
        return $this->requestId;
    }

    public function then(Closure $callback): self
    {
        if (! $this->dispatchable instanceof FakePendingDispatch) {
            $job = $this->dispatchable->getJob();
            if (! is_object($job) || ! method_exists($job, 'then')) {
                throw new LogicException('The queued research job does not support completion callbacks.');
            }

            $job->then($callback);
        }

        return $this;
    }

    public function catch(Closure $callback): self
    {
        if (! $this->dispatchable instanceof FakePendingDispatch) {
            $job = $this->dispatchable->getJob();
            if (! is_object($job) || ! method_exists($job, 'catch')) {
                throw new LogicException('The queued research job does not support failure callbacks.');
            }

            $job->catch($callback);
        }

        return $this;
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->dispatchable->{$method}(...$arguments);

        return $result === $this->dispatchable ? $this : $result;
    }
}
