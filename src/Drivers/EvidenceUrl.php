<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers;

/** Validates untrusted provider URLs before they become terminal evidence. */
final class EvidenceUrl
{
    private const int MAX_LENGTH = 8192;

    private const int MAX_DECODE_DEPTH = 3;

    private const int MAX_NESTED_URL_DEPTH = 3;

    public static function sanitize(mixed $value): ?string
    {
        return self::sanitizeAtDepth($value, 0);
    }

    private static function sanitizeAtDepth(mixed $value, int $depth): ?string
    {
        if (! is_string($value)
            || trim($value) !== $value
            || $value === ''
            || strlen($value) > self::MAX_LENGTH
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '') {
            return null;
        }

        foreach (['query', 'fragment'] as $component) {
            if (self::hasCredentialParameter($parts[$component] ?? '', $depth)) {
                return null;
            }
        }

        return $value;
    }

    private static function hasCredentialParameter(string $parameters, int $depth): bool
    {
        foreach (preg_split('/[&;]/', $parameters) ?: [] as $parameter) {
            [$rawKey, $rawValue] = array_pad(explode('=', $parameter, 2), 2, '');
            if (self::isCredentialKey($rawKey)) {
                return true;
            }

            if ($rawValue === '') {
                continue;
            }

            $nested = self::decode($rawValue);
            if ($nested === null) {
                return true;
            }
            if (preg_match('#^https?://#i', $nested) === 1
                && ($depth >= self::MAX_NESTED_URL_DEPTH
                    || self::sanitizeAtDepth($nested, $depth + 1) === null)) {
                return true;
            }
        }

        return false;
    }

    private static function isCredentialKey(string $rawKey): bool
    {
        $key = self::decode($rawKey);
        if ($key === null) {
            return true;
        }
        $segments = preg_split('/[^a-z0-9]+/i', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ([...$segments, implode('', $segments)] as $candidate) {
            $normalized = strtolower($candidate);
            if (in_array($normalized, ['key', 'sig'], true)
                || preg_match('/(?:signature|credential|token|secret|api(?:access)?key|accesskeyid)$/D', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function decode(string $value): ?string
    {
        $decoded = str_replace('+', ' ', $value);

        for ($attempt = 0; $attempt < self::MAX_DECODE_DEPTH; $attempt++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return preg_match('/%[0-9a-f]{2}/i', $decoded) === 1 ? null : $decoded;
    }
}
