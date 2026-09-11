<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers;

/** Validates untrusted provider URLs before they become terminal evidence. */
final class EvidenceUrl
{
    private const int MAX_LENGTH = 8192;

    private const int MAX_DECODE_DEPTH = 3;

    public static function sanitize(mixed $value): ?string
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

        foreach (['path', 'query', 'fragment'] as $component) {
            if (self::hasCredentialMaterial(
                $parts[$component] ?? '',
                allowBareKey: $component !== 'path',
            )) {
                return null;
            }
        }

        return $value;
    }

    private static function hasCredentialMaterial(string $component, bool $allowBareKey): bool
    {
        $decoded = self::decode($component);
        if ($decoded === null) {
            return true;
        }

        if ($allowBareKey) {
            foreach (preg_split('/[&;]/', $decoded) ?: [] as $parameter) {
                [$key] = explode('=', $parameter, 2);
                if (self::isCredentialKey($key)) {
                    return true;
                }
            }
        }

        preg_match_all('/(?:^|[?&;\/=])\K([^?&;\/=]+)(?==)/', $decoded, $matches);
        foreach ($matches[1] as $key) {
            if (self::isCredentialKey($key)) {
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
                || preg_match('/(?:signature|credential|token|secret|password|passwd|authorization|authentication|auth|session(?:id)?|api(?:access)?key|accesskeyid)$/D', $normalized) === 1) {
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
