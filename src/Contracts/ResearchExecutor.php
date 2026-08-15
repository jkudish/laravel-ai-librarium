<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;

interface ResearchExecutor
{
    public function run(ResearchPlan $plan): ResearchResponse;

    public function queue(ResearchPlan $plan): QueuedResearchResponse;
}
