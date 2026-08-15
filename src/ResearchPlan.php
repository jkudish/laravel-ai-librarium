<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

/** @internal */
final class ResearchPlan
{
    public function __construct(
        public readonly string $requestId,
        public readonly ResearchPreview $preview,
        public readonly int $timeout,
    ) {}
}
