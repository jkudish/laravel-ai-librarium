<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\DurableOutcome;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\ResearchPlan;

interface RecoversResearch
{
    public function submit(ResearchPlan $plan, bool $capture = true): DurableOutcome;

    public function retrieve(ResearchHandle $handle, bool $capture = true): DurableOutcome;
}
