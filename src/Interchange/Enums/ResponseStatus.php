<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Enums;

enum ResponseStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unsuccessful = 'unsuccessful';
}
