<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\AttemptStatus;

final readonly class Attempt
{
    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $extensions
     */
    public function __construct(
        public string $attemptId,
        public string $slotId,
        public int $attemptNumber,
        public array $profile,
        public string $startedAt,
        public AttemptStatus $attemptStatus,
        public ?string $replacesAttemptId = null,
        public ?string $candidateId = null,
        public ?string $finishedAt = null,
        public ?string $resultId = null,
        public ?StructuredError $error = null,
        public ?DurableHandle $durableHandle = null,
        public ?Usage $usage = null,
        public ?array $extensions = null,
    ) {}
}
