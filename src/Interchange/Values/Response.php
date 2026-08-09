<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\ResponseStatus;

final readonly class Response
{
    /**
     * @param  list<array<string, mixed>>  $slots
     * @param  list<Attempt>  $attempts
     * @param  list<Result>  $results
     * @param  list<StructuredError>  $errors
     * @param  array<string, mixed>|null  $extensions
     */
    public function __construct(
        public string $requestId,
        public ResponseStatus $responseStatus,
        public string $emittedAt,
        public array $slots,
        public array $attempts,
        public array $results,
        public array $errors,
        public ?array $extensions = null,
    ) {}
}
