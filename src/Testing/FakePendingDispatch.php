<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Testing;

use Illuminate\Foundation\Bus\PendingDispatch;

final class FakePendingDispatch extends PendingDispatch
{
    public function __construct()
    {
        parent::__construct(new class
        {
            /** @param list<mixed> $arguments */
            public function __call(string $method, array $arguments): self
            {
                return $this;
            }
        });
    }

    #[\Override]
    public function __destruct()
    {
        // Fake research queries are recorded without dispatching a job.
    }
}
