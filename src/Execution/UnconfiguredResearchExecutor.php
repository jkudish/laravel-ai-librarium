<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Execution;

use Jkudish\LaravelAiLibrarium\Contracts\ResearchExecutor;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use LogicException;

final class UnconfiguredResearchExecutor implements ResearchExecutor
{
    public function run(ResearchPlan $plan): ResearchResponse
    {
        throw new LogicException('No Librarium research executor has been registered.');
    }

    public function queue(ResearchPlan $plan): QueuedResearchResponse
    {
        throw new LogicException('No Librarium research executor has been registered.');
    }
}
