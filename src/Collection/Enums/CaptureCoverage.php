<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection\Enums;

enum CaptureCoverage: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
    case NoResponse = 'no_response';
}
