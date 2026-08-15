<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Execution;

use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use LogicException;

final class UnconfiguredResearchExecutor implements ExecutesResearch
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
