<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use InvalidArgumentException;

final readonly class PendingResearch
{
    public function __construct(public string $input)
    {
        if (trim($input) === '') {
            throw new InvalidArgumentException('Research input must not be empty.');
        }
    }
}
