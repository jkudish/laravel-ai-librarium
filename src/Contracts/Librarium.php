<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\PendingResearch;

interface Librarium
{
    public function query(string $input): PendingResearch;
}
