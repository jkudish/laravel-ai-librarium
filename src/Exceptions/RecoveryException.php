<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Exceptions;

use Jkudish\LaravelAiLibrarium\Collection\OperationalDiagnostic;
use RuntimeException;

final class RecoveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly OperationalDiagnostic $diagnostic,
    ) {
        parent::__construct($message);
    }
}
