<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Internal;

use DateTimeImmutable;
use Jkudish\LaravelAiLibrarium\Interchange\InterchangeValidationException;
use JsonException;

/** @internal */
final readonly class WireValueReader
{
    public const string VERSION = '1.0.0';

    public const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    public const string NAMESPACED_KEY_PATTERN = '/\A[a-z0-9]+(?:[.-][a-z0-9]+)+:[A-Za-z0-9][A-Za-z0-9._-]*\z/D';

    public const string SNAKE_CASE_PATTERN = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/D';

    public const string DECIMAL_PATTERN = '/\A(?:0|[1-9]\d*)(?:\.\d{1,18})?\z/D';

    public const string RFC3339_UTC_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/D';

    public const string JSON_POINTER_PATTERN = '/\A(?:\/(?:[^~\/]|~0|~1)*)*\z/D';

    public const string HTTP_URL_PATTERN = '/\Ahttps?:\/\/(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*(?::(?:[1-9]\d{0,3}|[1-5]\d{4}|6[0-4]\d{3}|65[0-4]\d{2}|655[0-2]\d|6553[0-5]))?(?:[\/?#][\x21-\x5B\x5D-\x7E]*)?\z/D';

    /** @var list<string> */
    public const FORBIDDEN_EXTENSION_KEYS = [
        'api_key', 'api_keys', 'access_token', 'access_tokens', 'auth_token', 'auth_tokens',
        'client_token', 'client_tokens', 'client_secret', 'client_secrets', 'secret_key', 'secret_keys',
        'secret_access_key', 'secret_access_keys', 'refresh_token', 'refresh_tokens', 'resume_token',
        'resume_tokens', 'resume_secret', 'resume_secrets', 'session_token', 'session_tokens',
        'session_secret', 'session_secrets', 'task_token', 'task_tokens', 'task_secret', 'task_secrets',
        'id_token', 'id_tokens', 'private_key', 'private_keys', 'encryption_key', 'encryption_keys',
        'signing_key', 'signing_keys', 'presigned_url', 'presigned_urls', 'signed_url', 'signed_urls',
        'signed_polling_url', 'signed_polling_urls', 'connection_string', 'connection_strings',
        'database_url', 'database_urls', 'dsn', 'dsns', 'authorization', 'auth', 'bearer', 'cookie',
        'cookies', 'credential', 'credentials', 'password', 'passwords', 'passwd', 'passwds', 'secret',
        'secrets', 'token', 'tokens', 'request_header', 'request_headers', 'response_header',
        'response_headers', 'authorization_header', 'authorization_headers', 'header', 'headers',
        'raw_body', 'raw_bodies', 'raw_provider', 'raw_providers', 'raw_response', 'raw_responses',
        'binary', 'binary_data', 'binary_material', 'binary_materials', 'binary_payload',
        'binary_payloads', 'stack', 'stacks', 'stack_trace', 'stack_traces',
    ];

    /** @var list<string> */
    public const BENIGN_TOKEN_QUALIFIERS = [
        'accepted', 'cached', 'cache_creation', 'cache_read', 'completion', 'input', 'max', 'num',
        'output', 'prompt', 'reasoning', 'rejected', 'total', 'thinking',
    ];

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, string>|null
     */
    public function optionalCorrelationKeys(array $value, string $key, string $path): ?array
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }
        $object = $this->assertObject($value[$key], $this->child($path, $key));
        if (count($object) > 32) {
            $this->fail($this->child($path, $key), 'must contain at most 32 entries');
        }
        $mapped = [];
        foreach ($object as $name => $correlationValue) {
            $namePath = $this->child($path, "{$key}/{$name}");
            $this->assertNamespacedKey($name, $namePath);
            $mapped[$name] = $this->boundedString($correlationValue, $namePath, 1, 512);
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|null
     */
    public function optionalExtensions(array $value, string $key, string $path): ?array
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }
        $extensionsPath = $this->child($path, $key);
        $extensions = $this->assertObject($value[$key], $extensionsPath);
        if (count($extensions) > 32) {
            $this->fail($extensionsPath, 'must contain at most 32 namespaced keys');
        }
        foreach ($extensions as $name => $extensionValue) {
            $namePath = $this->child($extensionsPath, $name);
            $this->assertNamespacedKey($name, $namePath);
            $this->assertExtensionKeySafe($name, $namePath);
            $this->assertJsonValue($extensionValue, $namePath, 1);
        }
        try {
            $encoded = json_encode($extensions, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            $this->fail($extensionsPath, 'must contain only JSON-safe values');
        }
        if (strlen($encoded) > 16_384) {
            $this->fail($extensionsPath, 'must serialize to at most 16384 UTF-8 bytes');
        }

        return $extensions;
    }

    public function assertJsonValue(mixed $value, string $path, int $depth): void
    {
        if ($depth > 6) {
            $this->fail($path, 'nesting exceeds 6 levels');
        }
        if (is_string($value)) {
            if (mb_strlen($value) > 8192) {
                $this->fail($path, 'strings must be at most 8192 characters');
            }

            return;
        }
        if (is_int($value)) {
            if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
                $this->fail($path, 'integers must be within the cross-language safe range');
            }

            return;
        }
        if (is_bool($value) || $value === null) {
            return;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                $this->fail($path, 'numbers must be finite');
            }

            return;
        }
        if (! is_array($value)) {
            $this->fail($path, 'must contain only JSON-safe values');
        }
        if (array_is_list($value)) {
            if (count($value) > 100) {
                $this->fail($path, 'arrays must contain at most 100 items');
            }
            foreach ($value as $index => $child) {
                $this->assertJsonValue($child, $this->child($path, (string) $index), $depth + 1);
            }

            return;
        }
        if (count($value) > 32) {
            $this->fail($path, 'objects must contain at most 32 keys');
        }
        foreach ($value as $key => $child) {
            if (! is_string($key)) {
                $this->fail($path, 'object keys must be strings');
            }
            $childPath = $this->literalChild($path, $key);
            $this->assertExtensionKeySafe($key, $childPath);
            $this->assertJsonValue($child, $childPath, $depth + 1);
        }
    }

    public function assertExtensionKeySafe(string $key, string $path): void
    {
        $normalized = $this->normalizeExtensionKey($key);
        $candidates = [$normalized, preg_replace('/_?\d+\z/', '', $normalized) ?? $normalized];
        $separator = strrpos($key, ':');
        if ($separator !== false) {
            $localName = $this->normalizeExtensionKey(substr($key, $separator + 1));
            $candidates[] = $localName;
            $candidates[] = preg_replace('/_?\d+\z/', '', $localName) ?? $localName;
        }
        foreach ($candidates as $candidate) {
            if ($this->isForbiddenExtensionName($candidate)) {
                $this->fail($path, 'must not contain secrets, credentials, raw responses, headers, stacks, or binary payloads');
            }
        }
    }

    public function normalizeExtensionKey(string $key): string
    {
        $normalized = preg_replace('/URLs/', '_urls', $key) ?? $key;
        $normalized = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $normalized) ?? $normalized;
        $normalized = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $normalized) ?? $normalized;
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z\d]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    public function isForbiddenExtensionName(string $key): bool
    {
        foreach (self::FORBIDDEN_EXTENSION_KEYS as $forbidden) {
            if ($key === $forbidden || str_ends_with($key, "_{$forbidden}")) {
                if (($forbidden === 'token' || $forbidden === 'tokens') && ! $this->hasSensitiveTokenSuffix($key)) {
                    continue;
                }

                return true;
            }
        }
        $fused = str_replace('_', '', $key);
        $benignToken = false;
        foreach (self::BENIGN_TOKEN_QUALIFIERS as $qualifier) {
            $fusedQualifier = str_replace('_', '', $qualifier);
            if (str_ends_with($fused, $fusedQualifier.'token') || str_ends_with($fused, $fusedQualifier.'tokens')) {
                $benignToken = true;
                break;
            }
        }
        if (! $benignToken && (str_ends_with($fused, 'token') || str_ends_with($fused, 'tokens'))) {
            return true;
        }
        foreach (self::FORBIDDEN_EXTENSION_KEYS as $forbidden) {
            $forbiddenFused = str_replace('_', '', $forbidden);
            if ($fused === $forbiddenFused || (strlen($forbiddenFused) >= 6 && $forbiddenFused !== 'tokens' && str_ends_with($fused, $forbiddenFused))) {
                return true;
            }
        }

        return false;
    }

    public function hasSensitiveTokenSuffix(string $key): bool
    {
        $words = explode('_', $key);
        $suffix = array_pop($words);
        if ($suffix !== 'token' && $suffix !== 'tokens') {
            return false;
        }
        $qualifier = implode('_', $words);
        foreach (self::BENIGN_TOKEN_QUALIFIERS as $benign) {
            if ($qualifier === $benign || str_ends_with($qualifier, "_{$benign}")) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $value */
    public function optionalOpaqueId(array $value, string $key, string $path): ?string
    {
        return array_key_exists($key, $value) ? $this->opaqueId($value[$key], $this->child($path, $key)) : null;
    }

    /** @param array<string, mixed> $value */
    public function optionalTimestamp(array $value, string $key, string $path): ?string
    {
        return array_key_exists($key, $value) ? $this->timestamp($value[$key], $this->child($path, $key)) : null;
    }

    /** @param array<string, mixed> $value */
    public function optionalString(array $value, string $key, string $path, int $minimum, int $maximum): ?string
    {
        return array_key_exists($key, $value)
            ? $this->boundedString($value[$key], $this->child($path, $key), $minimum, $maximum)
            : null;
    }

    /** @param array<string, mixed> $value */
    public function optionalNonNegativeInteger(array $value, string $key, string $path): ?int
    {
        return array_key_exists($key, $value)
            ? $this->nonNegativeInteger($value[$key], $this->child($path, $key))
            : null;
    }

    public function opaqueId(mixed $value, string $path): string
    {
        $identifier = $this->boundedString($value, $path, 1, 255);
        if (trim($identifier) !== $identifier || preg_match('/[\x00-\x1F\x7F]/u', $identifier) === 1) {
            $this->fail($path, 'must not contain surrounding whitespace or control characters');
        }

        return $identifier;
    }

    public function timestamp(mixed $value, string $path): string
    {
        $timestamp = $this->boundedString($value, $path, 20, 255);
        $this->assertPattern($timestamp, self::RFC3339_UTC_PATTERN, $path, 'must include seconds and use the RFC 3339 UTC Z form');
        $this->dateTime($timestamp, $path);

        return $timestamp;
    }

    public function dateTime(string $value, string $path = '/'): DateTimeImmutable
    {
        $base = substr($value, 0, 19).'Z';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $base);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $this->fail($path, 'must be a real RFC 3339 UTC date and time');
        }

        return $date;
    }

    public function compareTimestamps(string $left, string $right): int
    {
        $seconds = strcmp(substr($left, 0, 19), substr($right, 0, 19));
        if ($seconds !== 0) {
            return $seconds;
        }
        $leftFraction = $this->timestampFraction($left);
        $rightFraction = $this->timestampFraction($right);
        $length = max(strlen($leftFraction), strlen($rightFraction));

        return strcmp(str_pad($leftFraction, $length, '0'), str_pad($rightFraction, $length, '0'));
    }

    public function timestampFraction(string $timestamp): string
    {
        $dot = strpos($timestamp, '.');

        return $dot === false ? '' : substr($timestamp, $dot + 1, -1);
    }

    public function decimal(mixed $value, string $path): string
    {
        $decimal = $this->boundedString($value, $path, 1, 128);
        $this->assertPattern($decimal, self::DECIMAL_PATTERN, $path, 'must be a non-negative base-10 decimal string with at most 18 fractional digits');

        return $decimal;
    }

    public function jsonPointer(mixed $value, string $path): string
    {
        $pointer = $this->boundedString($value, $path, 0, 512);
        $this->assertPattern($pointer, self::JSON_POINTER_PATTERN, $path, 'must be an RFC 6901 JSON Pointer');

        return $pointer;
    }

    public function boundedString(mixed $value, string $path, int $minimum, int $maximum): string
    {
        if (! is_string($value)) {
            $this->fail($path, 'must be a string');
        }
        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum) {
            $this->fail($path, "must contain between {$minimum} and {$maximum} characters");
        }

        return $value;
    }

    public function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            $this->fail($path, 'must be a boolean');
        }

        return $value;
    }

    public function nonNegativeInteger(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 0 || $value > self::MAX_SAFE_INTEGER) {
            $this->fail($path, 'must be a non-negative safe integer');
        }

        return $value;
    }

    public function positiveInteger(mixed $value, string $path): int
    {
        $integer = $this->nonNegativeInteger($value, $path);
        if ($integer === 0) {
            $this->fail($path, 'must be a positive safe integer');
        }

        return $integer;
    }

    /** @param list<string> $allowed */
    public function closedString(mixed $value, array $allowed, string $path): string
    {
        $string = $this->boundedString($value, $path, 1, 255);
        if (! in_array($string, $allowed, true)) {
            $this->fail($path, 'must be one of: '.implode(', ', $allowed));
        }

        return $string;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function closedStringList(mixed $value, array $allowed, string $path, int $minimum, int $maximum): array
    {
        $list = $this->assertList($value, $path, $minimum, $maximum);
        $mapped = [];
        foreach ($list as $index => $item) {
            $mapped[] = $this->closedString($item, $allowed, $this->child($path, (string) $index));
        }

        return $mapped;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    public function enum(string $enum, mixed $value, string $path): \BackedEnum
    {
        $string = $this->boundedString($value, $path, 1, 255);
        $case = $enum::tryFrom($string);
        if ($case === null) {
            $allowed = array_map(static fn (\BackedEnum $item): string => (string) $item->value, $enum::cases());
            $this->fail($path, 'must be one of: '.implode(', ', $allowed));
        }

        return $case;
    }

    public function assertLiteral(mixed $value, string $expected, string $path): void
    {
        if ($value !== $expected) {
            $this->fail($path, "must equal {$expected}");
        }
    }

    public function assertPattern(string $value, string $pattern, string $path, string $message): void
    {
        if (preg_match($pattern, $value) !== 1) {
            $this->fail($path, $message);
        }
    }

    public function assertNamespacedKey(string $value, string $path): void
    {
        if (mb_strlen($value) < 3 || mb_strlen($value) > 128 || preg_match(self::NAMESPACED_KEY_PATTERN, $value) !== 1) {
            $this->fail($path, 'must be a namespaced key such as com.example:fieldName');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $required
     * @param  list<string>  $allowed
     */
    public function assertKeys(array $value, array $required, array $allowed, string $path): void
    {
        $this->assertKeysPresent($value, $required, $path);
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->fail($this->literalChild($path, $key), 'is an unknown semantic field');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $required
     */
    public function assertKeysPresent(array $value, array $required, string $path): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                $this->fail($this->child($path, $key), 'is required');
            }
        }
    }

    /** @return array<string, mixed> */
    public function assertObject(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($path, 'must be a JSON object');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<mixed> */
    public function assertList(mixed $value, string $path, int $minimum, int $maximum): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'must be a JSON array');
        }
        $count = count($value);
        if ($count < $minimum || $count > $maximum) {
            $this->fail($path, "must contain between {$minimum} and {$maximum} items");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function withoutNulls(array $value): array
    {
        return array_filter($value, static fn (mixed $item): bool => $item !== null);
    }

    public function jsonValuesEqual(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }
        if (array_is_list($left) || array_is_list($right)) {
            if (! array_is_list($left) || ! array_is_list($right) || count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $index => $value) {
                if (! $this->jsonValuesEqual($value, $right[$index])) {
                    return false;
                }
            }

            return true;
        }
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! $this->jsonValuesEqual($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    public function child(string $path, string $key): string
    {
        foreach (explode('/', $key) as $segment) {
            $path = $this->literalChild($path, $segment);
        }

        return $path;
    }

    public function literalChild(string $path, string $key): string
    {
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $key);

        return ($path === '' ? '' : $path).'/'.$escaped;
    }

    public function fail(string $path, string $message): never
    {
        throw new InterchangeValidationException($path === '' ? '/' : $path, $message);
    }
}
