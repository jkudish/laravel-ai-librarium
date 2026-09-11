<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\DurableSubmission;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

interface DurableDriver extends Driver
{
    public function submitDurable(DriverRequest $request): DurableSubmission;

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult;
}
