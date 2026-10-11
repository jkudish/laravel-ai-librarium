<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Valyu;

use Carbon\CarbonImmutable;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
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
        return EvidenceUrl::sanitize($value);
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

    /**
     * Keeps Valyu's signed PDF bearer link in the result artifact and records
     * when it expires if the signed URL states it. The link is withheld when
     * it is not a credential-free HTTPS URL or reflects private task material.
     *
     * @param  list<string>  $privateValues
     * @return array{kind: 'pdf', url?: string, expires_at?: string}
     */
    public static function pdfArtifact(mixed $value, array $privateValues): array
    {
        $url = is_string($value) ? $value : '';
        $parts = parse_url($url);
        if ($url === ''
            || strlen($url) > 8192
            || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_any($privateValues, static fn (string $private): bool => $private !== '' && str_contains($url, $private))) {
            return ['kind' => 'pdf'];
        }

        $expiresAt = self::signedUrlExpiry($parts['query'] ?? '');

        return array_filter([
            'kind' => 'pdf',
            'url' => $url,
            'expires_at' => $expiresAt?->toIso8601ZuluString(),
        ], static fn (?string $item): bool => $item !== null);
    }

    /** Replaces the signed PDF URL in a captured Valyu body so diagnostics never retain the bearer link. */
    public static function withoutPdfUrl(string $body): string
    {
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $body;
        }
        $url = is_array($json) ? ($json['pdf_url'] ?? null) : null;
        if (! is_string($url) || $url === '') {
            return $body;
        }

        $forms = array_filter([
            $url,
            substr((string) json_encode($url), 1, -1),
            substr((string) json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1),
        ], static fn (string $form): bool => $form !== '');

        return str_replace(array_values(array_unique($forms)), '[REDACTED]', $body);
    }

    /**
     * Reads the expiry a signed URL states: AWS SigV4 or Google V4 date plus
     * lifetime, or an epoch Expires (S3 SigV2, CloudFront). Valyu documents no
     * separate expiry field, so none is read from the response body.
     */
    private static function signedUrlExpiry(string $query): ?CarbonImmutable
    {
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parameters[strtolower(rawurldecode($key))] = rawurldecode($value);
        }

        foreach (['x-amz', 'x-goog'] as $scheme) {
            $date = $parameters[$scheme.'-date'] ?? null;
            $lifetime = $parameters[$scheme.'-expires'] ?? null;
            if ($date !== null && $lifetime !== null && preg_match('/^[1-9]\d{0,7}$/D', $lifetime) === 1) {
                $signedAt = CarbonImmutable::createFromFormat('Ymd\THis\Z', $date, 'UTC');
                if ($signedAt instanceof CarbonImmutable && $signedAt->format('Ymd\THis\Z') === $date) {
                    return $signedAt->addSeconds((int) $lifetime);
                }
            }
        }

        $epoch = $parameters['expires'] ?? null;
        if ($epoch !== null && preg_match('/^[1-9]\d{8,10}$/D', $epoch) === 1) {
            return CarbonImmutable::createFromTimestamp((int) $epoch, 'UTC');
        }

        return null;
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
