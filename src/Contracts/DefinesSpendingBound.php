<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\SpendingBound;

interface DefinesSpendingBound
{
    public function spendingBound(DriverRequest $request): SpendingBound;
}
