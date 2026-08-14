<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;

final readonly class ResearchResult
{
    /**
     * @param  Collection<int, Citation>  $citations
     * @param  array<array-key, mixed>  $content
     * @param  array<array-key, mixed>|null  $providerMeta
     */
    public function __construct(
        public string $id,
        public ContentFormat $contentFormat,
        public string|array $content,
        public string $requestedProfile,
        public string $provider,
        public string $profile,
        public ResultProvenance $provenance,
        public Collection $citations,
        public CarbonImmutable $completedAt,
        public ?string $model = null,
        public ?string $fallbackReason = null,
        public ?Usage $usage = null,
        public ?array $providerMeta = null,
        private ?string $completedAtWire = null,
    ) {}

    /**
     * @param  Collection<int, Citation>  $citations
     * @param  array<array-key, mixed>  $content
     * @param  array<array-key, mixed>|null  $providerMeta
     */
    public static function make(
        ContentFormat $contentFormat,
        string|array $content,
        string $requestedProfile,
        string $provider,
        string $profile,
        ResultProvenance $provenance,
        Collection $citations,
        CarbonImmutable $completedAt,
        ?string $model = null,
        ?string $fallbackReason = null,
        ?Usage $usage = null,
        ?array $providerMeta = null,
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
        $providerMeta = self::nullableArray($value, 'provider_meta');

        return new self(
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
            completedAtWire: $completedAt,
        );
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
            'completed_at' => $this->completedAtWire ?? $this->completedAt->format('Y-m-d\\TH:i:s\\Z'),
            'model' => $this->model,
            'fallback_reason' => $this->fallbackReason,
            'usage' => $this->usage?->toArray(),
            'provider_meta' => $this->providerMeta,
        ], static fn (mixed $item): bool => $item !== null);

        self::validate($value);

        return $value;
    }

    /** @param array<array-key, mixed> $value */
    private static function validate(array $value): void
    {
        $validator = Validator::make($value, [
            'id' => ['bail', 'required', 'string', 'min:1', 'max:255', 'regex:/^(?!\\s)(?!.*\\s$)[^\\x00-\\x1f\\x7f]+$/'],
            'content_format' => ['bail', 'required', 'string', 'in:markdown,text,json'],
            'content' => ['present'],
            'requested_profile' => ['required', 'string', 'min:1'],
            'provider' => ['required', 'string', 'min:1'],
            'profile' => ['required', 'string', 'min:1'],
            'provenance' => ['required', 'array'],
            'citations' => ['present', 'array', 'list'],
            'citations.*' => ['array'],
            'completed_at' => ['required', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/'],
            'model' => ['nullable', 'string', 'min:1'],
            'fallback_reason' => ['nullable', 'string', 'min:1'],
            'usage' => ['nullable', 'array'],
            'provider_meta' => ['nullable', 'array'],
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
            if ($format === ContentFormat::Json->value && ! is_array($content)) {
                $validator->errors()->add('content', 'must be an object or array for JSON content.');
            }
            if ($format === ContentFormat::Json->value && is_array($content)) {
                self::validateJsonValue($content, $validator, 'content');
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

            if (isset($value['provider_meta'])) {
                self::validateProviderMeta($value['provider_meta'], $validator);
            }
        });

        $validator->validate();
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

        foreach ($item as $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages([$key => 'must contain objects.']);
            }
        }

        return $item;
    }

    /** @param array<array-key, mixed> $value
     * @return string|array<array-key, mixed>
     */
    private static function content(array $value): string|array
    {
        $item = $value['content'] ?? null;
        if (! is_string($item) && ! is_array($item)) {
            throw ValidationException::withMessages(['content' => 'must be a string or JSON value.']);
        }

        return $item;
    }

    /** @param array<array-key, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        return self::string($value, $key);
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>|null
     */
    private static function nullableArray(array $value, string $key): ?array
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        return self::array($value, $key);
    }

    private static function validateProviderMeta(mixed $metadata, \Illuminate\Validation\Validator $validator): void
    {
        if (! is_array($metadata) || array_is_list($metadata)) {
            $validator->errors()->add('provider_meta', 'must be an object.');

            return;
        }

        self::validateJsonValue($metadata, $validator, 'provider_meta', true);
    }

    private static function validateJsonValue(mixed $value, \Illuminate\Validation\Validator $validator, string $path, bool $inspectMetadataKeys = false): void
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $validator->errors()->add($path, 'must be JSON-safe.');

            return;
        }

        $walk = static function (mixed $value, string $path) use (&$walk, $inspectMetadataKeys, $validator): void {
            if (is_resource($value) || is_object($value) || (is_float($value) && ! is_finite($value))) {
                $validator->errors()->add($path, 'must be JSON-safe.');

                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $key => $nested) {
                $keyString = (string) $key;
                $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $keyString));
                if ($inspectMetadataKeys) {
                    $forbidden = ['apikey', 'accesstoken', 'refreshtoken', 'authtoken', 'authorization', 'secret', 'password', 'credential', 'rawresponse', 'rawrequest', 'responsebody', 'requestbody', 'headers', 'header', 'stacktrace', 'privatekey', 'signingkey'];
                    if (array_any($forbidden, static fn (string $term): bool => str_contains($normalized, $term)) || $normalized === 'token' || str_ends_with($normalized, 'token')) {
                        $validator->errors()->add($path.'.'.$keyString, 'must not include credentials or raw provider data.');

                        return;
                    }
                }

                $walk($nested, $path.'.'.$keyString);
            }
        };

        $walk($value, $path);
    }
}
