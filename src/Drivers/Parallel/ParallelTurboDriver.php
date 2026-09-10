<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Parallel;

use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

/** Fixed Turbo-mode entry point for the shared Parallel Search contract. */
final readonly class ParallelTurboDriver implements Driver
{
    public function __construct(private ParallelSearchDriver $search) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return ParallelOptions::searchRules(true);
    }

    public function run(DriverRequest $request): ResearchResult
    {
        return $this->search->run($request);
    }
}
