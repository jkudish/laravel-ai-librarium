<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Exceptions;

use InvalidArgumentException;

final class SelectionException extends InvalidArgumentException
{
    public function __construct(public readonly string $errorCode, public readonly ?string $name = null)
    {
        parent::__construct($errorCode.($name === null ? '' : ": [{$name}]"));
    }
}
