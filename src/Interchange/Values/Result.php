<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

final readonly class Result
{
    /**
     * @param  array<string, mixed>  $semanticFacts
     * @param  list<Citation>  $citations
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>|null  $extensions
     */
    public function __construct(
        public string $resultId,
        public string $slotId,
        public string $attemptId,
        public string $contentFormat,
        public string $content,
        public array $semanticFacts,
        public array $citations,
        public array $provenance,
        public string $completedAt,
        public ?Usage $usage = null,
        public ?array $extensions = null,
    ) {}
}
