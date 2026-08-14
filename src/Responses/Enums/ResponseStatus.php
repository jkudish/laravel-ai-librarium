<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses\Enums;

enum ResponseStatus: string
{
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
