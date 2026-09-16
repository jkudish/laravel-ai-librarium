<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonSerializable;

final readonly class ResearchError implements JsonSerializable
{
    public function __construct(public string $code, public string $message, public ?string $profile = null) {}

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);

        return new self(self::string($value, 'code'), self::string($value, 'message'), self::nullableString($value, 'profile'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = array_filter([
            'code' => $this->code,
            'message' => $this->message,
            'profile' => $this->profile,
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
        $validator = Validator::make($value, [
            'code' => ['bail', 'required', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*(?:[.:\\/][A-Za-z0-9_.-]+)+$/'],
            'message' => ['required', 'string', 'min:1', 'max:2048'],
            'profile' => ['string', 'min:1'],
        ]);

        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            if (array_diff(array_keys($value), ['code', 'message', 'profile']) !== []) {
                $validator->errors()->add('error', 'contains an unknown field.');
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
