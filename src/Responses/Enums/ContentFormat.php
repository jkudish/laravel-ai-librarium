<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses\Enums;

enum ContentFormat: string
{
    case Markdown = 'markdown';
    case Text = 'text';
    case Json = 'json';
}
