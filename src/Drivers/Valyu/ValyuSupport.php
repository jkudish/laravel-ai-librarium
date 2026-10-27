<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Valyu;

use Carbon\CarbonImmutable;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use stdClass;

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
     * Keeps Valyu's PDF bearer link in the result artifact and records when
     * it expires if the URL states it. The link is withheld when it is not a
     * credential-free HTTPS URL of at most 8,192 bytes, or when it reflects
     * private task material outside isTaskAssetLink()'s exact shape.
     * Valyu's asset link states no expiry, so expires_at stays absent.
     *
     * @param  list<string>  $privateValues
     * @return array{kind: 'pdf', url?: string, expires_at?: string}
     */
    public static function pdfArtifact(mixed $value, array $privateValues, string $taskId): array
    {
        $url = is_string($value) ? $value : '';
        $parts = self::safeHttpsUrl($url);
        if ($parts === null
            || (! self::isTaskAssetLink($url, $taskId, $privateValues)
                && array_any($privateValues, static fn (string $private): bool => $private !== '' && str_contains($url, $private)))) {
            return ['kind' => 'pdf'];
        }

        $expiresAt = self::signedUrlExpiry($parts['query'] ?? '');

        return array_filter([
            'kind' => 'pdf',
            'url' => $url,
            'expires_at' => $expiresAt?->toIso8601ZuluString(),
        ], static fn (?string $item): bool => $item !== null);
    }

    /**
     * Whether a whole value is Valyu's token-authenticated asset link for
     * this task: https://api.valyu.ai/v1/deepresearch/tasks/{task}/assets/{asset}
     * with a 36-character asset ID, the path the official SDKs'
     * getAssets(taskId, assetId) calls. The link necessarily names the task,
     * so only the task handle may appear in it; it must still be a safe
     * HTTPS URL reflecting no other private value. It fails closed when
     * another private value equals the handle.
     *
     * @param  list<string>  $privateValues
     */
    public static function isTaskAssetLink(string $url, string $taskId, array $privateValues): bool
    {
        $parts = self::safeHttpsUrl($url);
        if ($parts === null || $taskId === '' || count(array_keys($privateValues, $taskId, true)) > 1) {
            return false;
        }

        return strtolower($parts['host'] ?? '') === 'api.valyu.ai'
            && ! isset($parts['port'])
            && ! isset($parts['fragment'])
            && preg_match('#^/v1/deepresearch/tasks/'.preg_quote(rawurlencode($taskId), '#').'/assets/[A-Za-z0-9_-]{36}$#D', $parts['path'] ?? '') === 1
            && ! array_any($privateValues, static fn (string $private): bool => $private !== '' && $private !== $taskId && str_contains($url, $private));
    }

    /**
     * Replaces the PDF bearer link and the private task handle in a captured
     * Valyu body, so evidence and diagnostics retain neither. The handle is
     * the body's own deepresearch_id and the task the request addressed.
     * A JSON body is captured as its redacted re-encoding, so no escape
     * spelling, duplicate member or object key keeps either value; any other
     * body has each value replaced in place.
     */
    public static function capturedBody(string $body, ?string $taskId = null): string
    {
        try {
            $json = json_decode($body, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $isJson = true;
        } catch (\JsonException) {
            $json = null;
            $isJson = false;
        }

        $values = [$taskId];
        if ($json instanceof stdClass) {
            $values[] = $json->pdf_url ?? null;
            $values[] = $json->deepresearch_id ?? null;
        }
        $values = array_values(array_unique(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '')));
        if ($values === []) {
            return $body;
        }
        // The link contains the task handle, so the longest value is replaced first.
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $isJson
            ? (string) json_encode(self::redactDecoded($json, $values), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
            : str_replace($values, '[REDACTED]', $body);
    }

    /** @param list<string> $values */
    private static function redactDecoded(mixed $decoded, array $values): mixed
    {
        if (is_string($decoded)) {
            return str_replace($values, '[REDACTED]', $decoded);
        }

        if ($decoded instanceof stdClass) {
            $redacted = new stdClass;
            foreach (get_object_vars($decoded) as $key => $item) {
                $redacted->{str_replace($values, '[REDACTED]', (string) $key)} = self::redactDecoded($item, $values);
            }

            return $redacted;
        }

        return is_array($decoded)
            ? array_map(static fn (mixed $item): mixed => self::redactDecoded($item, $values), $decoded)
            : $decoded;
    }

    /** @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}|null */
    private static function safeHttpsUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if ($url === ''
            || strlen($url) > 8192
            || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $parts;
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
