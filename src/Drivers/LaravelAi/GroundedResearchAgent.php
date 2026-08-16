<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\LaravelAi;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

/** @internal */
final class GroundedResearchAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Use web search to answer the user with current, grounded information. Cite the sources that support the answer.';
    }

    /** @return list<WebSearch> */
    public function tools(): iterable
    {
        return [new WebSearch];
    }
}
