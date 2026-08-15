<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

interface ValidatesProfileOptions
{
    /** @return array<string, mixed> */
    public function profileOptionRules(): array;
}
