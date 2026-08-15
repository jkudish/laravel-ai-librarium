<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

final readonly class ResearchFailed
{
    use Dispatchable;

    public function __construct(
        public string $requestId,
        public Throwable $exception,
        public CarbonImmutable $occurredAt,
        public ?string $profileId = null,
    ) {}
}
