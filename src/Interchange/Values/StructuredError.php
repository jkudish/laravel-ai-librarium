<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\ErrorCategory;

final readonly class StructuredError
{
    /** @param list<array{detail_code: string, message: string, field_path?: string, related_id?: string}>|null $details */
    public function __construct(
        public string $code,
        public string $message,
        public ErrorCategory $category,
        public bool $retryable,
        public bool $fallbackAllowed,
        public ?string $providerCode = null,
        public ?string $fieldPath = null,
        public ?array $details = null,
    ) {}
}
