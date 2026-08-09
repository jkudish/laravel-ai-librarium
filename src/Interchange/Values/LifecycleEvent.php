<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\LifecycleEventKind;

final readonly class LifecycleEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $eventId,
        public string $requestId,
        public int $sequence,
        public string $occurredAt,
        public LifecycleEventKind $eventKind,
        public array $data,
        public ?string $slotId = null,
        public ?string $attemptId = null,
    ) {}
}
