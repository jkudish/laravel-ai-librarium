<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Enums;

enum DurableHandleStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
