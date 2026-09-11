<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\DurableOutcome;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\ResearchStatus;

interface Librarium
{
    public function query(string $input): PendingResearch;

    public function retrieve(ResearchHandle $handle, bool $capture = true): DurableOutcome;

    public function status(string $requestId): ?ResearchStatus;
}
