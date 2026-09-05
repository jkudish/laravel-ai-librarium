<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses\Enums;

enum Personalization: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Unknown = 'unknown';
}
