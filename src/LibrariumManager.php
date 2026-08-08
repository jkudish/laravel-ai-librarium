<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Jkudish\LaravelAiLibrarium\Contracts\Librarium;

final class LibrariumManager implements Librarium
{
    public function query(string $input): PendingResearch
    {
        return new PendingResearch($input);
    }
}
