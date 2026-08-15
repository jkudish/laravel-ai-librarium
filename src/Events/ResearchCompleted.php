<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;

final readonly class ResearchCompleted
{
    use Dispatchable;

    public function __construct(public ResearchResponse $response) {}
}
