<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Enums;

enum ErrorCategory: string
{
    case Validation = 'validation';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case RateLimit = 'rate_limit';
    case Timeout = 'timeout';
    case Network = 'network';
    case Provider = 'provider';
    case Budget = 'budget';
    case Cancelled = 'cancelled';
    case Unsupported = 'unsupported';
    case Internal = 'internal';
}
