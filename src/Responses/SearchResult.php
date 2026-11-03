<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use JsonSerializable;
use Opis\JsonSchema\Formats\UriFormats;

/** A page retrieved by the provider, not necessarily cited or read. */
final readonly class SearchResult implements JsonSerializable
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?string $snippet = null,
        public ?CarbonImmutable $publishedAt = null,
    ) {}

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);

        /** @var array{url: string, title?: ?string, snippet?: ?string, published_at?: ?string} $value */
        return new self(
            url: $value['url'],
            title: $value['title'] ?? null,
            snippet: $value['snippet'] ?? null,
            publishedAt: isset($value['published_at']) ? CarbonImmutable::parse($value['published_at']) : null,
        );
    }

    /** @return array{url: string, title: ?string, snippet: ?string, published_at: ?string} */
    public function toArray(): array
    {
        $date = $this->publishedAt?->utc();
        $fraction = $date === null ? '' : rtrim($date->format('u'), '0');
        $value = [
            'url' => $this->url,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'published_at' => $date === null ? null : $date->format('Y-m-d\TH:i:s').($fraction === '' ? '' : '.'.$fraction).'Z',
        ];
        self::validate($value);

        return $value;
    }

    /** @return array{url: string, title: ?string, snippet: ?string, published_at: ?string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Validates natively, and only builds a Laravel validator for its error
     * messages when the native check fails: a stored answer can carry dozens
     * of retrieved pages, and a validator per page dominates decoding.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function validate(array $value): void
    {
        if (self::passesNatively($value)) {
            return;
        }

        $validator = Validator::make($value, [
            'url' => ['required', 'string'],
            'title' => ['nullable', 'string'],
            'snippet' => ['nullable', 'string'],
            'published_at' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D'],
        ]);
        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            if (is_string($value['url'] ?? null) && ! UriFormats::uri($value['url'])) {
                $validator->errors()->add('url', 'must be a valid absolute URI.');
            }
            if (array_diff(array_keys($value), ['url', 'title', 'snippet', 'published_at']) !== []) {
                $validator->errors()->add('search_result', 'contains an unknown field.');
            }
            if (is_string($value['published_at'] ?? null)) {
                try {
                    if (CarbonImmutable::parse($value['published_at'])->format('Y-m-d\TH:i:s') !== substr($value['published_at'], 0, 19)) {
                        $validator->errors()->add('published_at', 'must be a valid UTC timestamp.');
                    }
                } catch (\Throwable) {
                    $validator->errors()->add('published_at', 'must be a valid UTC timestamp.');
                }
            }
        });
        $validator->validate();
    }

    /**
     * Accept only values that every Laravel rule and after callback above accepts.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function passesNatively(array $value): bool
    {
        if (array_diff(array_keys($value), ['url', 'title', 'snippet', 'published_at']) !== []
            || ! is_string($value['url'] ?? null) || $value['url'] === '' || ! UriFormats::uri($value['url'])) {
            return false;
        }
        foreach (['title', 'snippet'] as $field) {
            if (($value[$field] ?? null) !== null && ! is_string($value[$field])) {
                return false;
            }
        }
        $date = $value['published_at'] ?? null;
        if ($date === null) {
            return true;
        }
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', $date) !== 1) {
            return false;
        }
        try {
            return CarbonImmutable::parse($date)->format('Y-m-d\TH:i:s') === substr($date, 0, 19);
        } catch (\Throwable) {
            return false;
        }
    }
}
