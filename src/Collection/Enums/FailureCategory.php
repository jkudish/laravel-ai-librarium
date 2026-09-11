<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection\Enums;

enum FailureCategory: string
{
    case Temporary = 'temporary';
    case RateLimit = 'rate_limit';
    case Permanent = 'permanent';
    case Unknown = 'unknown';
}
