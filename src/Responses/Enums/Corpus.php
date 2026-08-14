<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses\Enums;

enum Corpus: string
{
    case Web = 'web';
    case News = 'news';
    case X = 'x';
    case Files = 'files';
    case Places = 'places';
}
