<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Personalization;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use stdClass;

final class ResultProvenance
{
    private ?string $observedAtWire = null;

    /**
     * @param  Collection<int, RetrievalMethod>  $retrievalMethods
     * @param  Collection<int, Corpus>  $corpora
     * @param  array<string, string|Authentication|Personalization>|stdClass|null  $context
     */
    public function __construct(
        public readonly ResultKind $resultKind,
        public readonly Collection $retrievalMethods,
        public readonly Collection $corpora,
        public readonly CarbonImmutable $observedAt,
        public readonly ?string $collector = null,
        public readonly ?string $surface = null,
        public readonly array|stdClass|null $context = null,
        public readonly ?ObservationMode $observationMode = null,
    ) {}

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);
        $context = self::context($value);
        $retrievalMethods = self::listOfStrings($value, 'retrieval_methods');
        $corpora = self::listOfStrings($value, 'corpora');

        $provenance = new self(
            resultKind: ResultKind::from(self::string($value, 'result_kind')),
            retrievalMethods: collect($retrievalMethods)->map(static fn (string $method): RetrievalMethod => RetrievalMethod::from($method)),
            corpora: collect($corpora)->map(static fn (string $corpus): Corpus => Corpus::from($corpus)),
            observedAt: CarbonImmutable::parse($observedAt = self::string($value, 'observed_at')),
            collector: self::nullableString($value, 'collector'),
            surface: self::nullableString($value, 'surface'),
            context: $context,
            observationMode: ($observationMode = self::nullableString($value, 'observation_mode')) === null
                ? null
                : ObservationMode::from($observationMode),
        );
        $provenance->observedAtWire = $observedAt;

        return $provenance;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $context = $this->context;
        if (is_array($context)) {
            foreach (['authentication', 'personalization'] as $key) {
                if (($context[$key] ?? null) instanceof Authentication || ($context[$key] ?? null) instanceof Personalization) {
                    $context[$key] = $context[$key]->value;
                }
            }
        }

        $value = array_filter([
            'result_kind' => $this->resultKind->value,
            'retrieval_methods' => $this->retrievalMethods->map(static fn (RetrievalMethod $method): string => $method->value)->all(),
            'corpora' => $this->corpora->map(static fn (Corpus $corpus): string => $corpus->value)->all(),
            'observed_at' => $this->observedAtWire ?? self::formatTimestamp($this->observedAt),
            'observation_mode' => $this->observationMode?->value,
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
        $validationValue = $value;
        if (($validationValue['context'] ?? null) instanceof stdClass) {
            $validationValue['context'] = get_object_vars($validationValue['context']);
        }

        $validator = Validator::make($validationValue, [
            'result_kind' => ['bail', 'required', 'string', Rule::enum(ResultKind::class)],
            'retrieval_methods' => ['present', 'array', 'list'],
            'retrieval_methods.*' => ['bail', 'string', Rule::enum(RetrievalMethod::class)],
            'corpora' => ['present', 'array', 'list'],
            'corpora.*' => ['bail', 'string', Rule::enum(Corpus::class)],
            'observation_mode' => ['string', Rule::enum(ObservationMode::class)],
            'observed_at' => ['required', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/'],
            'collector' => ['string', 'min:1'],
            'surface' => ['string', 'min:1'],
            'context' => ['array:locale,country,device,authentication,personalization'],
            'context.locale' => ['string', 'min:1'],
            'context.country' => ['string', 'min:1'],
            'context.device' => ['string', 'min:1'],
            'context.authentication' => ['string', Rule::enum(Authentication::class)],
            'context.personalization' => ['string', Rule::enum(Personalization::class)],
        ]);

        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            if (array_diff(array_keys($value), ['result_kind', 'retrieval_methods', 'corpora', 'observation_mode', 'observed_at', 'collector', 'surface', 'context']) !== []) {
                $validator->errors()->add('provenance', 'contains an unknown field.');
            }

            if (array_key_exists('context', $value)
                && ! $value['context'] instanceof stdClass
                && (! is_array($value['context']) || array_is_list($value['context']))) {
                $validator->errors()->add('context', 'must be an object.');
            }

            if (($value['surface'] ?? null) !== null && ($value['collector'] ?? null) === null) {
                $validator->errors()->add('collector', 'is required when a surface is present.');
            }

            if (($value['result_kind'] ?? null) === ResultKind::SurfaceObservation->value
                && ($value['surface'] ?? null) === null) {
                $validator->errors()->add('surface', 'is required for a surface observation.');
            }

            if (($value['observation_mode'] ?? null) === ObservationMode::SurfaceSnapshot->value) {
                if (($value['result_kind'] ?? null) !== ResultKind::SurfaceObservation->value) {
                    $validator->errors()->add('result_kind', 'must be surface_observation for a surface snapshot.');
                }
                if (! is_array($value['retrieval_methods'] ?? null)
                    || ! in_array(RetrievalMethod::SurfaceCollector->value, $value['retrieval_methods'], true)) {
                    $validator->errors()->add('retrieval_methods', 'must include surface_collector for a surface snapshot.');
                }
                if (($value['collector'] ?? null) === null) {
                    $validator->errors()->add('collector', 'is required for a surface snapshot.');
                }
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
     * @return array<string, string|Authentication|Personalization>|stdClass|null
     */
    private static function context(array $value): array|stdClass|null
    {
        if (! array_key_exists('context', $value)) {
            return null;
        }
        $input = $value['context'];
        if ($input instanceof stdClass) {
            $input = get_object_vars($input);
            if ($input === []) {
                return new stdClass;
            }
        } elseif (! is_array($input) || array_is_list($input)) {
            throw ValidationException::withMessages(['context' => 'must be an object.']);
        }

        $context = array_filter([
            'locale' => self::nullableString($input, 'locale'),
            'country' => self::nullableString($input, 'country'),
            'device' => self::nullableString($input, 'device'),
            'authentication' => ($authentication = self::nullableString($input, 'authentication')) === null ? null : Authentication::from($authentication),
            'personalization' => ($personalization = self::nullableString($input, 'personalization')) === null ? null : Personalization::from($personalization),
        ], static fn (mixed $item): bool => $item !== null);

        return $context;
    }

    private static function formatTimestamp(CarbonImmutable $timestamp): string
    {
        $timestamp = $timestamp->utc();
        $fraction = rtrim($timestamp->format('u'), '0');

        return $timestamp->format('Y-m-d\\TH:i:s').($fraction === '' ? '' : '.'.$fraction).'Z';
    }
}
