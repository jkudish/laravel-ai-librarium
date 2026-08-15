<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

interface Driver extends ValidatesProfileOptions
{
    public function run(DriverRequest $request): ResearchResult;
}
