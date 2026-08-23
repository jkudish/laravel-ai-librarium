<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\You;

final readonly class YouCredential
{
    public static function resolve(mixed $canonical, mixed $docsAlias): ?string
    {
        if (is_string($canonical) && trim($canonical) !== '') {
            return $canonical;
        }

        return is_string($docsAlias) && trim($docsAlias) !== '' ? $docsAlias : null;
    }
}
