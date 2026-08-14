<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses\Enums;

enum Authentication: string
{
    case Anonymous = 'anonymous';
    case Authenticated = 'authenticated';
    case Managed = 'managed';
    case Unknown = 'unknown';
}
