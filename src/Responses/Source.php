<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;

final readonly class Source
{
    public function __construct(
        public SourceKind $kind,
        public ?string $url = null,
        public ?string $providerReference = null,
        public ?string $title = null,
        public ?string $publisher = null,
        public ?CarbonImmutable $publishedAt = null,
        private ?string $publishedAtWire = null,
    ) {}

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);

        return new self(
            kind: SourceKind::from(self::string($value, 'kind')),
            url: self::nullableString($value, 'url'),
            providerReference: self::nullableString($value, 'provider_reference'),
            title: self::nullableString($value, 'title'),
            publisher: self::nullableString($value, 'publisher'),
            publishedAt: ($publishedAt = self::nullableString($value, 'published_at')) === null ? null : CarbonImmutable::parse($publishedAt),
            publishedAtWire: $publishedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = array_filter([
            'kind' => $this->kind->value,
            'url' => $this->url,
            'provider_reference' => $this->providerReference,
            'title' => $this->title,
            'publisher' => $this->publisher,
            'published_at' => $this->publishedAt === null ? null : ($this->publishedAtWire ?? $this->publishedAt->format('Y-m-d\\TH:i:s\\Z')),
        ], static fn (mixed $item): bool => $item !== null);

        self::validate($value);

        return $value;
    }

    /** @param array<array-key, mixed> $value */
    private static function validate(array $value): void
    {
        $validator = Validator::make($value, [
            'kind' => ['bail', 'required', 'string', Rule::enum(SourceKind::class)],
            'url' => ['nullable', 'string', 'min:1'],
            'provider_reference' => ['nullable', 'string', 'min:1', 'max:255', 'regex:/^(?!\\s)(?!.*\\s$)[^\\x00-\\x1f\\x7f]+$/'],
            'title' => ['nullable', 'string', 'min:1'],
            'publisher' => ['nullable', 'string', 'min:1'],
            'published_at' => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/'],
        ]);

        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            if (array_diff(array_keys($value), ['kind', 'url', 'provider_reference', 'title', 'publisher', 'published_at']) !== []) {
                $validator->errors()->add('source', 'contains an unknown field.');
            }

            if (($value['url'] ?? null) === null && ($value['provider_reference'] ?? null) === null) {
                $validator->errors()->add('url', 'requires a URL or provider reference.');
            }

            if (is_string($value['published_at'] ?? null)) {
                try {
                    $date = CarbonImmutable::parse($value['published_at']);
                    if ($date->format('Y-m-d\\TH:i:s') !== substr($value['published_at'], 0, 19)) {
                        $validator->errors()->add('published_at', 'must be a valid UTC timestamp.');
                    }
                } catch (\Throwable) {
                    $validator->errors()->add('published_at', 'must be a valid UTC timestamp.');
                }
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

    /** @param array<array-key, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        return self::string($value, $key);
    }
}
