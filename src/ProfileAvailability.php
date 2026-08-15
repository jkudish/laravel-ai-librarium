<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

final class ProfileAvailability
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly Profile $profile,
        public readonly array $reasons = [],
    ) {}

    public function isEligible(): bool
    {
        return $this->reasons === [];
    }
}
