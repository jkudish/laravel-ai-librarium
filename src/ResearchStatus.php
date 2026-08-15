<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Carbon\CarbonImmutable;

final class ResearchStatus
{
    public function __construct(
        public readonly ResearchState $state,
        public readonly ?string $progress,
        public readonly CarbonImmutable $updatedAt,
    ) {}

    /** @return array{state: string, progress: string|null, updated_at: string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'progress' => $this->progress,
            'updated_at' => $this->updatedAt->utc()->toIso8601ZuluString(),
        ];
    }

    /** @param array{state: string, progress?: string|null, updated_at: string} $value */
    public static function fromArray(array $value): self
    {
        return new self(
            state: ResearchState::from($value['state']),
            progress: $value['progress'] ?? null,
            updatedAt: CarbonImmutable::parse($value['updated_at']),
        );
    }
}
