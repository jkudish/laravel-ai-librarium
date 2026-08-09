<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Enums;

enum AttemptStatus: string
{
    case Started = 'started';
    case Submitted = 'submitted';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';
}
