<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

final readonly class Usage
{
    /**
     * @param  list<array{unit: string, quantity_decimal: string, source: string}>|null  $billableUnits
     * @param  array{amount: array{amount_decimal: string, currency: string}, source: string, pricing_version?: string}|null  $actualCost
     * @param  array{amount: array{amount_decimal: string, currency: string}, source: string, pricing_version?: string}|null  $estimatedCost
     * @param  array<string, mixed>|null  $extensions
     */
    public function __construct(
        public string $completeness,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public ?array $billableUnits = null,
        public ?array $actualCost = null,
        public ?array $estimatedCost = null,
        public ?array $extensions = null,
    ) {}
}
