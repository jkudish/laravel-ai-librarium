<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ResearchStarted
{
    use Dispatchable;

    public function __construct(
        public string $requestId,
        public CarbonImmutable $occurredAt,
    ) {}
}
