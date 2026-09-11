<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection\Enums;

enum Operation: string
{
    case Submit = 'submit';
    case Poll = 'poll';
    case Retrieve = 'retrieve';
    case Cancel = 'cancel';
    case Inline = 'inline';
}
