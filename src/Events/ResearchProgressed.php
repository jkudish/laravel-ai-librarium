<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ResearchProgressed
{
    use Dispatchable;

    public function __construct(
        public string $requestId,
        public string $message,
        public CarbonImmutable $occurredAt,
        public ?string $profileId = null,
    ) {}
}
