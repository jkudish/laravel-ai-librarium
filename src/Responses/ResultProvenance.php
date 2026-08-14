<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

final readonly class ResultProvenance
{
    /**
     * @param  Collection<int, RetrievalMethod>  $retrievalMethods
     * @param  Collection<int, Corpus>  $corpora
     * @param  array<string, string|Authentication>|null  $context
     */
    public function __construct(
        public ResultKind $resultKind,
        public Collection $retrievalMethods,
        public Collection $corpora,
        public CarbonImmutable $observedAt,
        public ?string $collector = null,
        public ?string $surface = null,
        public ?array $context = null,
        private ?string $observedAtWire = null,
    ) {}

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);
        $context = self::context($value);
        $retrievalMethods = self::listOfStrings($value, 'retrieval_methods');
        $corpora = self::listOfStrings($value, 'corpora');

        return new self(
            resultKind: ResultKind::from(self::string($value, 'result_kind')),
            retrievalMethods: collect($retrievalMethods)->map(static fn (string $method): RetrievalMethod => RetrievalMethod::from($method)),
            corpora: collect($corpora)->map(static fn (string $corpus): Corpus => Corpus::from($corpus)),
            observedAt: CarbonImmutable::parse($observedAt = self::string($value, 'observed_at')),
            collector: self::nullableString($value, 'collector'),
            surface: self::nullableString($value, 'surface'),
            context: $context,
            observedAtWire: $observedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $context = $this->context;
        if ($context !== null && ($context['authentication'] ?? null) instanceof Authentication) {
            $context['authentication'] = $context['authentication']->value;
        }

        $value = array_filter([
            'result_kind' => $this->resultKind->value,
            'retrieval_methods' => $this->retrievalMethods->map(static fn (RetrievalMethod $method): string => $method->value)->all(),
            'corpora' => $this->corpora->map(static fn (Corpus $corpus): string => $corpus->value)->all(),
            'observed_at' => $this->observedAtWire ?? $this->observedAt->format('Y-m-d\\TH:i:s\\Z'),
            'collector' => $this->collector,
            'surface' => $this->surface,
            'context' => $context,
        ], static fn (mixed $item): bool => $item !== null);

        self::validate($value);

        return $value;
    }

    /** @param array<array-key, mixed> $value */
    private static function validate(array $value): void
    {
        $validator = Validator::make($value, [
            'result_kind' => ['bail', 'required', 'string', Rule::enum(ResultKind::class)],
            'retrieval_methods' => ['present', 'array', 'list'],
            'retrieval_methods.*' => ['bail', 'string', Rule::enum(RetrievalMethod::class)],
            'corpora' => ['present', 'array', 'list'],
            'corpora.*' => ['bail', 'string', Rule::enum(Corpus::class)],
            'observed_at' => ['required', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/'],
            'collector' => ['nullable', 'string', 'min:1'],
            'surface' => ['nullable', 'string', 'min:1'],
            'context' => ['nullable', 'array:locale,country,device,authentication'],
            'context.locale' => ['nullable', 'string', 'min:1'],
            'context.country' => ['nullable', 'string', 'min:1'],
            'context.device' => ['nullable', 'string', 'min:1'],
            'context.authentication' => ['nullable', 'string', Rule::enum(Authentication::class)],
        ]);

        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            if (array_diff(array_keys($value), ['result_kind', 'retrieval_methods', 'corpora', 'observed_at', 'collector', 'surface', 'context']) !== []) {
                $validator->errors()->add('provenance', 'contains an unknown field.');
            }

            if (($value['surface'] ?? null) !== null && ($value['collector'] ?? null) === null) {
                $validator->errors()->add('collector', 'is required when a surface is present.');
            }

            if (is_string($value['observed_at'] ?? null)) {
                try {
                    $date = CarbonImmutable::parse($value['observed_at']);
                    if ($date->format('Y-m-d\\TH:i:s') !== substr($value['observed_at'], 0, 19)) {
                        $validator->errors()->add('observed_at', 'must be a valid UTC timestamp.');
                    }
                } catch (\Throwable) {
                    $validator->errors()->add('observed_at', 'must be a valid UTC timestamp.');
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

    /** @param array<array-key, mixed> $value
     * @return list<string>
     */
    private static function listOfStrings(array $value, string $key): array
    {
        $item = $value[$key] ?? null;
        if (! is_array($item) || ! array_is_list($item)) {
            throw ValidationException::withMessages([$key => 'must be a list.']);
        }

        foreach ($item as $entry) {
            if (! is_string($entry)) {
                throw ValidationException::withMessages([$key => 'must contain strings.']);
            }
        }

        return $item;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, string|Authentication>|null
     */
    private static function context(array $value): ?array
    {
        $input = $value['context'] ?? null;
        if ($input === null) {
            return null;
        }
        if (! is_array($input)) {
            throw ValidationException::withMessages(['context' => 'must be an object.']);
        }

        $context = array_filter([
            'locale' => self::nullableString($input, 'locale'),
            'country' => self::nullableString($input, 'country'),
            'device' => self::nullableString($input, 'device'),
            'authentication' => ($authentication = self::nullableString($input, 'authentication')) === null ? null : Authentication::from($authentication),
        ], static fn (mixed $item): bool => $item !== null);

        return $context;
    }
}
