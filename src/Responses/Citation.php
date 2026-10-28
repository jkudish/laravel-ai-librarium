<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use JsonSerializable;

final readonly class Citation implements JsonSerializable
{
    public function __construct(
        public string $id,
        public CitationDerivation $derivation,
        public Source $source,
        public ?string $excerpt = null,
        public ?string $locator = null,
    ) {}

    public static function make(CitationDerivation $derivation, Source $source, ?string $excerpt = null, ?string $locator = null): self
    {
        return new self(Str::ulid()->toString(), $derivation, $source, $excerpt, $locator);
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);

        $source = self::array($value, 'source');

        return new self(
            id: self::string($value, 'id'),
            derivation: CitationDerivation::from(self::string($value, 'derivation')),
            source: Source::fromArray($source),
            excerpt: self::nullableString($value, 'excerpt'),
            locator: self::nullableString($value, 'locator'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = array_filter([
            'id' => $this->id,
            'derivation' => $this->derivation->value,
            'source' => $this->source->toArray(),
            'excerpt' => $this->excerpt,
            'locator' => $this->locator,
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
        // The usual citation needs no Validator allocation. Its Source still
        // validates independently; noncanonical values retain Laravel's errors.
        if (array_diff(array_keys($value), ['id', 'derivation', 'source', 'excerpt', 'locator']) === []
            && is_string($value['id'] ?? null) && mb_strlen($value['id']) <= 255
            && preg_match(OpaqueIdentifier::PATTERN, $value['id']) === 1
            && is_string($value['derivation'] ?? null) && CitationDerivation::tryFrom($value['derivation']) !== null
            && is_array($value['source'] ?? null) && $value['source'] !== []) {
            $strings = true;
            foreach (['excerpt', 'locator'] as $field) {
                if (array_key_exists($field, $value) && (! is_string($value[$field]) || trim($value[$field]) === '')) {
                    $strings = false;
                }
            }
            if ($strings) {
                return;
            }
        }

        $validator = Validator::make($value, [
            'id' => ['bail', 'required', 'string', 'min:1', 'max:255', 'regex:'.OpaqueIdentifier::PATTERN],
            'derivation' => ['bail', 'required', 'string', Rule::enum(CitationDerivation::class)],
            'source' => ['required', 'array'],
            'excerpt' => ['string', 'min:1'],
            'locator' => ['string', 'min:1'],
        ]);

        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            if (array_diff(array_keys($value), ['id', 'derivation', 'source', 'excerpt', 'locator']) !== []) {
                $validator->errors()->add('citation', 'contains an unknown field.');
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

    /** @param array<array-key, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        return self::string($value, $key);
    }
}
