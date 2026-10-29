<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use JsonSerializable;
use stdClass;

final class ResearchResult implements JsonSerializable
{
    /** @var list<string> */
    private const array UNSAFE_WRAPPER_SUFFIXES = [
        'blob',
        'body',
        'data',
        'jar',
        'map',
        'material',
        'metadata',
        'payload',
        'pem',
        'response',
        'value',
    ];

    private const string TIMESTAMP_PATTERN = '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/';

    private ?string $completedAtWire = null;

    /**
     * @param  Collection<int, Citation>  $citations
     * @param  array<array-key, mixed>|stdClass  $content
     */
    public function __construct(
        public readonly string $id,
        public readonly ContentFormat $contentFormat,
        public readonly string|array|stdClass $content,
        public readonly string $requestedProfile,
        public readonly string $provider,
        public readonly string $profile,
        public readonly ResultProvenance $provenance,
        public readonly Collection $citations,
        public readonly CarbonImmutable $completedAt,
        public readonly ?string $model = null,
        public readonly ?string $fallbackReason = null,
        public readonly ?Usage $usage = null,
        public readonly ?stdClass $providerMeta = null,
    ) {}

    /**
     * @param  Collection<int, Citation>  $citations
     * @param  array<array-key, mixed>|stdClass  $content
     */
    public static function make(
        ContentFormat $contentFormat,
        string|array|stdClass $content,
        string $requestedProfile,
        string $provider,
        string $profile,
        ResultProvenance $provenance,
        Collection $citations,
        CarbonImmutable $completedAt,
        ?string $model = null,
        ?string $fallbackReason = null,
        ?Usage $usage = null,
        ?stdClass $providerMeta = null,
    ): self {
        return new self(Str::ulid()->toString(), $contentFormat, $content, $requestedProfile, $provider, $profile, $provenance, $citations, $completedAt, $model, $fallbackReason, $usage, $providerMeta);
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);
        $provenance = self::array($value, 'provenance');
        $citationValues = self::listOfArrays($value, 'citations');
        $usage = self::nullableArray($value, 'usage');
        $providerMeta = self::providerMeta($value);

        $result = new self(
            id: self::string($value, 'id'),
            contentFormat: ContentFormat::from(self::string($value, 'content_format')),
            content: self::content($value),
            requestedProfile: self::string($value, 'requested_profile'),
            provider: self::string($value, 'provider'),
            profile: self::string($value, 'profile'),
            provenance: ResultProvenance::fromArray($provenance),
            citations: collect($citationValues)->map(static fn (array $citation): Citation => Citation::fromArray($citation)),
            completedAt: CarbonImmutable::parse($completedAt = self::string($value, 'completed_at')),
            model: self::nullableString($value, 'model'),
            fallbackReason: self::nullableString($value, 'fallback_reason'),
            usage: $usage === null ? null : Usage::fromArray($usage),
            providerMeta: $providerMeta,
        );
        $result->completedAtWire = $completedAt;

        return $result;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = array_filter([
            'id' => $this->id,
            'content_format' => $this->contentFormat->value,
            'content' => $this->content,
            'requested_profile' => $this->requestedProfile,
            'provider' => $this->provider,
            'profile' => $this->profile,
            'provenance' => $this->provenance->toArray(),
            'citations' => $this->citations->map(static fn (Citation $citation): array => $citation->toArray())->values()->all(),
            'completed_at' => $this->completedAtWire ?? self::formatTimestamp($this->completedAt),
            'model' => $this->model,
            'fallback_reason' => $this->fallbackReason,
            'usage' => $this->usage?->jsonSerialize(),
            'provider_meta' => $this->providerMeta,
        ], static fn (mixed $item): bool => $item !== null);

        self::validate($value);

        return $value;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<array-key, mixed> $value */
    private static function validate(array $value): void
    {
        // Driver-produced results need no Validator allocation. Every other
        // shape, including every failure, keeps Laravel's rules and error bags.
        if (self::passesNatively($value)) {
            return;
        }

        $validationValue = $value;
        if (($validationValue['usage'] ?? null) instanceof stdClass) {
            $validationValue['usage'] = get_object_vars($validationValue['usage']);
        }

        $validator = Validator::make($validationValue, [
            'id' => ['bail', 'required', 'string', 'min:1', 'max:255', 'regex:'.OpaqueIdentifier::PATTERN],
            'content_format' => ['bail', 'required', 'string', 'in:markdown,text,json'],
            'content' => ['present'],
            'requested_profile' => ['required', 'string', 'min:1'],
            'provider' => ['required', 'string', 'min:1'],
            'profile' => ['required', 'string', 'min:1'],
            'provenance' => ['required', 'array'],
            'citations' => ['present', 'array', 'list'],
            'citations.*' => ['array'],
            'completed_at' => ['required', 'string', 'regex:'.self::TIMESTAMP_PATTERN],
            'model' => ['string', 'min:1'],
            'fallback_reason' => ['string', 'min:1'],
            'usage' => ['array'],
        ]);

        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            $allowed = ['id', 'content_format', 'content', 'requested_profile', 'provider', 'profile', 'provenance', 'citations', 'completed_at', 'model', 'fallback_reason', 'usage', 'provider_meta'];
            if (array_diff(array_keys($value), $allowed) !== []) {
                $validator->errors()->add('result', 'contains an unknown field.');
            }

            $format = $value['content_format'] ?? null;
            $content = $value['content'] ?? null;
            if (in_array($format, [ContentFormat::Markdown->value, ContentFormat::Text->value], true) && ! is_string($content)) {
                $validator->errors()->add('content', 'must be a string for text content.');
            }
            if ($format === ContentFormat::Json->value && ! is_array($content) && ! $content instanceof stdClass) {
                $validator->errors()->add('content', 'must be an object or array for JSON content.');
            }
            if ($format === ContentFormat::Json->value && (is_array($content) || $content instanceof stdClass)) {
                self::validateJsonValue($content, $validator->errors(), 'content');
            }

            if (is_string($value['completed_at'] ?? null)) {
                try {
                    $date = CarbonImmutable::parse($value['completed_at']);
                    if ($date->format('Y-m-d\\TH:i:s') !== substr($value['completed_at'], 0, 19)) {
                        $validator->errors()->add('completed_at', 'must be a valid UTC timestamp.');
                    }
                } catch (\Throwable) {
                    $validator->errors()->add('completed_at', 'must be a valid UTC timestamp.');
                }
            }

            if (array_key_exists('provider_meta', $value)) {
                self::validateProviderMeta($value['provider_meta'], $validator->errors());
            }
        });

        $validator->validate();
    }

    /**
     * Accept only values that every Laravel rule and after callback below accepts.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function passesNatively(array $value): bool
    {
        if (array_diff(array_keys($value), ['id', 'content_format', 'content', 'requested_profile', 'provider', 'profile', 'provenance', 'citations', 'completed_at', 'model', 'fallback_reason', 'usage', 'provider_meta']) !== []
            || ! is_string($value['id'] ?? null) || mb_strlen($value['id']) > 255
            || preg_match(OpaqueIdentifier::PATTERN, $value['id']) !== 1
            || ! is_string($value['content_format'] ?? null) || ($format = ContentFormat::tryFrom($value['content_format'])) === null
            || ! array_key_exists('content', $value)
            || ! is_array($value['provenance'] ?? null) || $value['provenance'] === []
            || ! is_array($value['citations'] ?? null) || ! array_is_list($value['citations'])
            || ! self::isUtcTimestamp($value['completed_at'] ?? null)) {
            return false;
        }

        foreach ($value['citations'] as $citation) {
            if (! is_array($citation)) {
                return false;
            }
        }

        foreach (['requested_profile', 'provider', 'profile'] as $field) {
            if (! self::isNonBlankString($value[$field] ?? null)) {
                return false;
            }
        }

        foreach (['model', 'fallback_reason'] as $field) {
            if (array_key_exists($field, $value) && ! self::isNonBlankString($value[$field])) {
                return false;
            }
        }

        if (array_key_exists('usage', $value) && ! is_array($value['usage']) && ! $value['usage'] instanceof stdClass) {
            return false;
        }

        $errors = new MessageBag;
        if ($format === ContentFormat::Json) {
            if (! is_array($value['content']) && ! $value['content'] instanceof stdClass) {
                return false;
            }
            self::validateJsonValue($value['content'], $errors, 'content');
        } elseif (! is_string($value['content'])) {
            return false;
        }

        if (array_key_exists('provider_meta', $value)) {
            self::validateProviderMeta($value['provider_meta'], $errors);
        }

        return $errors->isEmpty();
    }

    /** Laravel skips non-implicit rules for blank strings, so blank values fall back. */
    private static function isNonBlankString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function isUtcTimestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match(self::TIMESTAMP_PATTERN, $value) !== 1) {
            return false;
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d\\TH:i:s') === substr($value, 0, 19);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<array-key, mixed> $value */
    private static function string(array $value, string $key): string
    {
        $item = $value[$key] ?? null;
        if (! is_string($item)) {
            throw ValidationException::withMessages([$key => 'must be a string.']);
        }

        return $item;
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function array(array $value, string $key): array
    {
        $item = $value[$key] ?? null;
        if (! is_array($item)) {
            throw ValidationException::withMessages([$key => 'must be an object.']);
        }

        return $item;
    }

    /** @param array<array-key, mixed> $value
     * @return list<array<array-key, mixed>>
     */
    private static function listOfArrays(array $value, string $key): array
    {
        $item = $value[$key] ?? null;
        if (! is_array($item) || ! array_is_list($item)) {
            throw ValidationException::withMessages([$key => 'must be a list.']);
        }

        $list = [];
        foreach ($item as $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages([$key => 'must contain objects.']);
            }

            $list[] = $entry;
        }

        return $list;
    }

    /** @param array<array-key, mixed> $value
     * @return string|array<array-key, mixed>|stdClass
     */
    private static function content(array $value): string|array|stdClass
    {
        $item = $value['content'] ?? null;
        if (! is_string($item) && ! is_array($item) && ! $item instanceof stdClass) {
            throw ValidationException::withMessages(['content' => 'must be a string or JSON value.']);
        }

        return is_string($item) ? $item : self::normalizeJsonValue($item);
    }

    /** @param array<array-key, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        return self::string($value, $key);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|null
     */
    private static function nullableArray(array $value, string $key): ?array
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }

        if ($value[$key] instanceof stdClass) {
            return get_object_vars($value[$key]);
        }

        return self::array($value, $key);
    }

    /** @param array<array-key, mixed> $value */
    private static function providerMeta(array $value): ?stdClass
    {
        if (! array_key_exists('provider_meta', $value)) {
            return null;
        }

        $metadata = $value['provider_meta'];
        if ($metadata instanceof stdClass) {
            return self::normalizeJsonObject($metadata);
        }
        if (! is_array($metadata) || array_is_list($metadata)) {
            throw ValidationException::withMessages(['provider_meta' => 'must be an object.']);
        }

        return self::normalizeJsonObject((object) $metadata);
    }

    private static function validateProviderMeta(mixed $metadata, MessageBag $errors): void
    {
        if (! $metadata instanceof stdClass && (! is_array($metadata) || array_is_list($metadata))) {
            $errors->add('provider_meta', 'must be an object.');

            return;
        }

        foreach ($metadata instanceof stdClass ? get_object_vars($metadata) : $metadata as $key => $_value) {
            if ((string) $key === '') {
                $errors->add('provider_meta', 'property names must not be empty.');

                return;
            }
        }

        self::validateJsonValue($metadata, $errors, 'provider_meta', true);
    }

    private static function validateJsonValue(mixed $value, MessageBag $errors, string $path, bool $inspectMetadataKeys = false): void
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors->add($path, 'must be JSON-safe.');

            return;
        }

        $walk = static function (mixed $value, string $path) use (&$walk, $inspectMetadataKeys, $errors): void {
            if (is_resource($value) || (is_object($value) && ! $value instanceof stdClass) || (is_float($value) && ! is_finite($value))) {
                $errors->add($path, 'must be JSON-safe.');

                return;
            }

            $isObject = $value instanceof stdClass || (is_array($value) && ! array_is_list($value));
            if ($value instanceof stdClass) {
                $entries = get_object_vars($value);
            } elseif (is_array($value)) {
                $entries = $value;
            } else {
                return;
            }

            foreach ($entries as $key => $nested) {
                $keyString = (string) $key;
                if ($inspectMetadataKeys && $isObject && (self::isForbiddenMetadataKey($keyString) || self::isRawMetadataKey($keyString) || self::isCompleteRawContainer($keyString, $nested))) {
                    $errors->add($path.'.'.$keyString, 'must not include credentials or raw provider data.');

                    return;
                }

                $walk($nested, $path.'.'.$keyString);
            }
        };

        $walk($value, $path);
    }

    private static function isForbiddenMetadataKey(string $key): bool
    {
        $normalized = self::normalizeMetadataKey($key);
        $candidates = [$normalized, (string) preg_replace('/_?\\d+$/', '', $normalized)];
        $namespaceSeparator = strrpos($key, ':');
        if ($namespaceSeparator !== false) {
            $localName = self::normalizeMetadataKey(substr($key, $namespaceSeparator + 1));
            $candidates[] = $localName;
            $candidates[] = (string) preg_replace('/_?\\d+$/', '', $localName);
        }

        return array_any($candidates, self::isForbiddenNormalizedMetadataKey(...));
    }

    private static function isForbiddenNormalizedMetadataKey(string $key): bool
    {
        $forbidden = [
            'api_key', 'api_keys', 'access_token', 'access_tokens', 'auth_token', 'auth_tokens', 'client_token', 'client_tokens',
            'client_secret', 'client_secrets', 'secret_key', 'secret_keys', 'secret_access_key', 'secret_access_keys',
            'refresh_token', 'refresh_tokens', 'resume_token', 'resume_tokens', 'resume_secret', 'resume_secrets',
            'session_token', 'session_tokens', 'session_secret', 'session_secrets', 'task_token', 'task_tokens', 'task_secret',
            'task_secrets', 'id_token', 'id_tokens', 'private_key', 'private_keys', 'encryption_key', 'encryption_keys',
            'signing_key', 'signing_keys', 'presigned_url', 'presigned_urls', 'signed_url', 'signed_urls', 'signed_polling_url',
            'signed_polling_urls', 'connection_string', 'connection_strings', 'database_url', 'database_urls', 'dsn', 'dsns',
            'authorization', 'auth', 'bearer', 'cookie', 'cookies', 'credential', 'credentials', 'password', 'passwords',
            'passwd', 'passwds', 'secret', 'secrets', 'token', 'tokens', 'request_header', 'request_headers', 'response_header',
            'response_headers', 'authorization_header', 'authorization_headers', 'header', 'headers', 'raw_body', 'raw_bodies',
            'raw_provider', 'raw_providers', 'raw_response', 'raw_responses', 'binary', 'binary_data', 'binary_material',
            'binary_materials', 'binary_payload', 'binary_payloads', 'stack', 'stacks', 'stack_trace', 'stack_traces',
        ];
        $benignTokenQualifiers = ['accepted', 'cached', 'cache_creation', 'cache_read', 'completion', 'input', 'max', 'num', 'output', 'prompt', 'reasoning', 'rejected', 'total', 'thinking'];
        foreach ($forbidden as $name) {
            if (in_array($name, ['token', 'tokens'], true)) {
                continue;
            }

            if ($key === $name || str_ends_with($key, '_'.$name)) {
                return true;
            }

            $prefix = $name.'_';
            $interior = '_'.$name.'_';
            $start = str_starts_with($key, $prefix) ? 0 : strpos($key, $interior);
            if ($start === false) {
                continue;
            }

            $suffix = substr($key, $start + strlen($name) + ($start === 0 ? 1 : 2));
            if (array_any(explode('_', $suffix), static fn (string $part): bool => in_array($part, self::UNSAFE_WRAPPER_SUFFIXES, true))) {
                return true;
            }
        }

        if (self::hasSensitiveTokenSegment($key, $benignTokenQualifiers)) {
            return true;
        }

        $fused = str_replace('_', '', $key);
        $fusedNames = array_map(static fn (string $name): string => str_replace('_', '', $name), $forbidden);
        $benignFusedToken = array_any($benignTokenQualifiers, static fn (string $qualifier): bool => str_contains($fused, str_replace('_', '', $qualifier).'token'));
        $fusedSuffixes = array_filter($fusedNames, static fn (string $name): bool => strlen($name) >= 6 && $name !== 'tokens');
        $hasFusedUnsafeTokenWrapper = array_any(self::UNSAFE_WRAPPER_SUFFIXES, static fn (string $suffix): bool => str_ends_with($fused, 'token'.$suffix)
            || str_ends_with($fused, 'tokens'.$suffix));

        return in_array($fused, $fusedNames, true)
            || $hasFusedUnsafeTokenWrapper
            || (! $benignFusedToken && (str_ends_with($fused, 'token') || str_ends_with($fused, 'tokens')))
            || array_any($fusedSuffixes, static fn (string $name): bool => str_ends_with($fused, $name)
                || array_any(self::UNSAFE_WRAPPER_SUFFIXES, static fn (string $suffix): bool => str_ends_with($fused, $name.$suffix)));
    }

    /** @param list<string> $benignTokenQualifiers */
    private static function hasSensitiveTokenSegment(string $key, array $benignTokenQualifiers): bool
    {
        $words = explode('_', $key);

        foreach ($words as $index => $word) {
            if (! in_array($word, ['token', 'tokens'], true)) {
                continue;
            }

            $suffix = array_slice($words, $index + 1);
            if (array_any($suffix, static fn (string $part): bool => in_array($part, self::UNSAFE_WRAPPER_SUFFIXES, true))) {
                return true;
            }
            if ($suffix !== []) {
                continue;
            }

            $qualifier = implode('_', array_slice($words, 0, $index));
            $isBenign = array_any($benignTokenQualifiers, static fn (string $benign): bool => $qualifier === $benign || str_ends_with($qualifier, '_'.$benign));

            if (! $isBenign) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeMetadataKey(string $key): string
    {
        return (string) preg_replace('/^_+|_+$/', '', strtolower((string) preg_replace(
            ['/URLs/', '/([A-Z]+)([A-Z][a-z])/', '/([a-z\\d])([A-Z])/', '/[^a-z\\d]+/i'],
            ['_urls', '$1_$2', '$1_$2', '_'],
            $key,
        )));
    }

    private static function isRawMetadataKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace(
            ['/([A-Z]+)([A-Z][a-z])/', '/([a-z\\d])([A-Z])/', '/[^a-z\\d]+/i'],
            ['$1_$2', '$1_$2', '_'],
            $key,
        ));

        return preg_match('/(?:^|_)raw(?:_|$)/', $normalized) === 1
            || preg_match('/(?:^|_)provider_(?:response|payload|body)(?:_|$)/', $normalized) === 1;
    }

    private static function isCompleteRawContainer(string $key, mixed $value): bool
    {
        $normalized = strtolower((string) preg_replace(
            ['/([A-Z]+)([A-Z][a-z])/', '/([a-z\\d])([A-Z])/'],
            ['$1_$2', '$1_$2'],
            $key,
        ));

        if (! in_array($normalized, ['response', 'payload', 'body'], true)) {
            return false;
        }

        $fields = $value instanceof stdClass
            ? array_keys(get_object_vars($value))
            : (is_array($value) && ! array_is_list($value) ? array_keys($value) : []);

        return in_array('headers', $fields, true)
            && (in_array('status', $fields, true) || in_array('status_code', $fields, true));
    }

    /**
     * @param  array<array-key, mixed>|stdClass  $value
     * @return array<array-key, mixed>|stdClass
     */
    private static function normalizeJsonValue(array|stdClass $value): array|stdClass
    {
        if ($value instanceof stdClass) {
            $normalized = new stdClass;
            foreach (get_object_vars($value) as $key => $nested) {
                $normalized->{$key} = is_array($nested) || $nested instanceof stdClass ? self::normalizeJsonValue($nested) : $nested;
            }

            return $normalized;
        }

        if (! array_is_list($value)) {
            return self::normalizeJsonValue((object) $value);
        }

        return array_map(static fn (mixed $nested): mixed => is_array($nested) || $nested instanceof stdClass ? self::normalizeJsonValue($nested) : $nested, $value);
    }

    private static function normalizeJsonObject(stdClass $value): stdClass
    {
        $normalized = new stdClass;
        foreach (get_object_vars($value) as $key => $nested) {
            $normalized->{$key} = is_array($nested) || $nested instanceof stdClass ? self::normalizeJsonValue($nested) : $nested;
        }

        return $normalized;
    }

    private static function formatTimestamp(CarbonImmutable $timestamp): string
    {
        $timestamp = $timestamp->utc();
        $fraction = rtrim($timestamp->format('u'), '0');

        return $timestamp->format('Y-m-d\\TH:i:s').($fraction === '' ? '' : '.'.$fraction).'Z';
    }
}
