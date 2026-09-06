<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\DataForSeo;

final readonly class DataForSeoCredential
{
    public static function encode(mixed $login, mixed $password): ?string
    {
        if (! is_string($login) || trim($login) === '' || ! is_string($password) || trim($password) === '') {
            return null;
        }

        return base64_encode($login."\0".$password);
    }

    /** @return array{string, string}|null */
    public static function decode(?string $credential): ?array
    {
        if ($credential === null || $credential === '') {
            return null;
        }

        $decoded = base64_decode($credential, true);
        if (! is_string($decoded) || substr_count($decoded, "\0") !== 1) {
            return null;
        }

        [$login, $password] = explode("\0", $decoded, 2);

        return $login === '' || $password === '' ? null : [$login, $password];
    }
}
