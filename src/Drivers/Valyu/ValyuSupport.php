<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Valyu;

use Carbon\CarbonImmutable;
use Jkudish\LaravelAiLibrarium\Responses\Usage;

final class ValyuSupport
{
    public static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function providerReference(mixed $value): ?string
    {
        $reference = self::string($value);

        return $reference !== null
            && mb_strlen($reference) <= 255
            && preg_match('/^[^\x00-\x1f\x7f]+$/u', $reference) === 1
                ? $reference
                : null;
    }

    public static function evidenceUrl(mixed $value): ?string
    {
        $url = self::string($value);
        if ($url === null || $url !== $value || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }

        if (self::hasCredentialQueryKey($parts['query'] ?? '')) {
            return null;
        }

        return $url;
    }

    private static function hasCredentialQueryKey(string $query): bool
    {
        foreach (preg_split('/[&;]/', $query) ?: [] as $parameter) {
            [$rawKey] = explode('=', $parameter, 2);
            $key = rawurldecode(str_replace('+', ' ', $rawKey));
            $segments = preg_split('/[^a-z0-9]+/i', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $candidates = [...$segments, implode('', $segments)];

            foreach ($candidates as $candidate) {
                $normalized = strtolower($candidate);
                if (in_array($normalized, ['key', 'sig'], true)
                    || preg_match('/(?:signature|credential|token|secret|api(?:access)?key|accesskeyid)$/', $normalized) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        try {
            $timestamp = is_string($value)
                ? CarbonImmutable::parse($value)
                : CarbonImmutable::createFromTimestampMs($value);

            return $timestamp->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function usage(mixed $cost): ?Usage
    {
        if ((! is_int($cost) && ! is_float($cost)) || $cost < 0 || (is_float($cost) && ! is_finite($cost))) {
            return null;
        }

        $actualCost = is_int($cost) ? (string) $cost : self::decimal($cost);
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,18})?$/', $actualCost) !== 1) {
            return null;
        }

        return new Usage(actualCost: $actualCost, currency: 'USD');
    }

    private static function decimal(float $value): string
    {
        $encoded = strtolower((string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        if (! str_contains($encoded, 'e')) {
            return rtrim(rtrim($encoded, '0'), '.');
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?e([+-]?\d+)$/', $encoded, $matches) !== 1) {
            return '';
        }

        $integer = $matches[1];
        $fraction = rtrim($matches[2], '0');
        $exponent = (int) $matches[3];
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + $exponent;

        if ($decimalPosition <= 0) {
            return '0.'.str_repeat('0', -$decimalPosition).$digits;
        }

        if ($decimalPosition >= strlen($digits)) {
            return $digits.str_repeat('0', $decimalPosition - strlen($digits));
        }

        return substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
    }
}
