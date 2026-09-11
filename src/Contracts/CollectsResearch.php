<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\Collection\SensitiveCollection;
use Jkudish\LaravelAiLibrarium\ResearchPlan;

interface CollectsResearch
{
    public function collect(ResearchPlan $plan): SensitiveCollection;
}
