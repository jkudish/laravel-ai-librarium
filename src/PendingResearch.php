<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class PendingResearch
{
    public function __construct(public string $input)
    {
        if (Str::of($input)->trim()->isEmpty()) {
            throw new InvalidArgumentException('Research input must not be empty.');
        }
    }
}
