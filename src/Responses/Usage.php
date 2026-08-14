<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class Usage
{
    public function __construct(
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $cacheWriteInputTokens = null,
        public ?int $cacheReadInputTokens = null,
        public ?int $reasoningTokens = null,
        public ?string $actualCost = null,
        public ?string $estimatedCost = null,
        public ?string $currency = null,
    ) {}

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);

        return new self(
            promptTokens: self::nullableInt($value, 'prompt_tokens'),
            completionTokens: self::nullableInt($value, 'completion_tokens'),
            cacheWriteInputTokens: self::nullableInt($value, 'cache_write_input_tokens'),
            cacheReadInputTokens: self::nullableInt($value, 'cache_read_input_tokens'),
            reasoningTokens: self::nullableInt($value, 'reasoning_tokens'),
            actualCost: self::nullableString($value, 'actual_cost'),
            estimatedCost: self::nullableString($value, 'estimated_cost'),
            currency: self::nullableString($value, 'currency'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = array_filter([
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'actual_cost' => $this->actualCost,
            'estimated_cost' => $this->estimatedCost,
            'currency' => $this->currency,
        ], static fn (mixed $item): bool => $item !== null);

        self::validate($value);

        return $value;
    }

    /** @param array<array-key, mixed> $value */
    private static function validate(array $value): void
    {
        $validator = Validator::make($value, [
            'prompt_tokens' => ['integer', 'min:0', 'max:9007199254740991'],
            'completion_tokens' => ['integer', 'min:0', 'max:9007199254740991'],
            'cache_write_input_tokens' => ['integer', 'min:0', 'max:9007199254740991'],
            'cache_read_input_tokens' => ['integer', 'min:0', 'max:9007199254740991'],
            'reasoning_tokens' => ['integer', 'min:0', 'max:9007199254740991'],
            'actual_cost' => ['string', 'regex:/^(?:0|[1-9]\\d*)(?:\\.\\d{1,18})?$/'],
            'estimated_cost' => ['string', 'regex:/^(?:0|[1-9]\\d*)(?:\\.\\d{1,18})?$/'],
            'currency' => ['string', 'min:1'],
        ]);

        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            if (array_diff(array_keys($value), ['prompt_tokens', 'completion_tokens', 'cache_write_input_tokens', 'cache_read_input_tokens', 'reasoning_tokens', 'actual_cost', 'estimated_cost', 'currency']) !== []) {
                $validator->errors()->add('usage', 'contains an unknown field.');
            }

            if (($value['actual_cost'] ?? null) !== null || ($value['estimated_cost'] ?? null) !== null) {
                if (($value['currency'] ?? null) === null) {
                    $validator->errors()->add('currency', 'is required when a cost is present.');
                }
            }
        });

        $validator->validate();
    }

    /** @param array<array-key, mixed> $value */
    private static function nullableInt(array $value, string $key): ?int
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        if (! is_int($value[$key])) {
            throw ValidationException::withMessages([$key => 'must be an integer.']);
        }

        return $value[$key];
    }

    /** @param array<array-key, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        if (! is_string($value[$key])) {
            throw ValidationException::withMessages([$key => 'must be a string.']);
        }

        return $value[$key];
    }
}
