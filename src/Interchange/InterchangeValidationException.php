<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange;

use InvalidArgumentException;

final class InterchangeValidationException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $fieldPath,
        string $message,
    ) {
        parent::__construct("Invalid Librarium interchange value at {$fieldPath}: {$message}");
    }
}
