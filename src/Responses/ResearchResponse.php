<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Responses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use JsonSerializable;
use stdClass;

final class ResearchResponse implements JsonSerializable
{
    private const string GENERATOR_PATTERN = '/^(?:[a-z0-9][a-z0-9._-]*|@?[a-z0-9][a-z0-9._-]*\\/[a-z0-9][a-z0-9._-]*)$/';

    private const string VERSION_PATTERN = '/^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)(?:-(?:0|[1-9]\\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\\.(?:0|[1-9]\\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\\+[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?$/';

    private const string TIMESTAMP_PATTERN = '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/';

    private ?string $completedAtWire = null;

    /**
     * @param  Collection<int, ResearchResult>  $results
     * @param  Collection<int, ResearchError>  $errors
     */
    public function __construct(
        public readonly string $generator,
        public readonly string $generatorVersion,
        public readonly string $requestId,
        public readonly ResponseStatus $status,
        public readonly CarbonImmutable $completedAt,
        public readonly Collection $results,
        public readonly Collection $errors,
        public readonly ?Usage $usage = null,
    ) {}

    /**
     * @param  Collection<int, ResearchResult>  $results
     * @param  Collection<int, ResearchError>  $errors
     */
    public static function make(
        string $generator,
        string $generatorVersion,
        ResponseStatus $status,
        CarbonImmutable $completedAt,
        Collection $results,
        Collection $errors,
        ?Usage $usage = null,
    ): self {
        return new self($generator, $generatorVersion, Str::ulid()->toString(), $status, $completedAt, $results, $errors, $usage);
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);
        $resultValues = self::listOfArrays($value, 'results');
        $errorValues = self::listOfArrays($value, 'errors');
        $usage = self::nullableArray($value, 'usage');

        $response = new self(
            generator: self::string($value, 'generator'),
            generatorVersion: self::string($value, 'generator_version'),
            requestId: self::string($value, 'request_id'),
            status: ResponseStatus::from(self::string($value, 'status')),
            completedAt: CarbonImmutable::parse($completedAt = self::string($value, 'completed_at')),
            results: collect($resultValues)->map(static fn (array $result): ResearchResult => ResearchResult::fromArray($result)),
            errors: collect($errorValues)->map(static fn (array $error): ResearchError => ResearchError::fromArray($error)),
            usage: $usage === null ? null : Usage::fromArray($usage),
        );
        $response->completedAtWire = $completedAt;

        return $response;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = array_filter([
            'generator' => $this->generator,
            'generator_version' => $this->generatorVersion,
            'request_id' => $this->requestId,
            'status' => $this->status->value,
            'completed_at' => $this->completedAtWire ?? self::formatTimestamp($this->completedAt),
            'results' => $this->results->map(static fn (ResearchResult $result): array => $result->toArray())->values()->all(),
            'errors' => $this->errors->map(static fn (ResearchError $error): array => $error->toArray())->values()->all(),
            'usage' => $this->usage?->jsonSerialize(),
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
        // Driver-produced responses need no Validator allocation. Every other
        // shape, including every failure, keeps Laravel's rules and error bags.
        if (self::passesNatively($value)) {
            return;
        }

        $validationValue = $value;
        if (($validationValue['usage'] ?? null) instanceof stdClass) {
            $validationValue['usage'] = get_object_vars($validationValue['usage']);
        }

        $validator = Validator::make($validationValue, [
            'generator' => ['bail', 'required', 'string', 'min:1', 'max:255', 'regex:'.self::GENERATOR_PATTERN],
            'generator_version' => ['bail', 'required', 'string', 'max:255', 'regex:'.self::VERSION_PATTERN],
            'request_id' => ['bail', 'required', 'string', 'min:1', 'max:255', 'regex:'.OpaqueIdentifier::PATTERN],
            'status' => ['bail', 'required', 'string', Rule::enum(ResponseStatus::class)],
            'completed_at' => ['required', 'string', 'regex:'.self::TIMESTAMP_PATTERN],
            'results' => ['present', 'array', 'list'],
            'results.*' => ['array'],
            'errors' => ['present', 'array', 'list'],
            'errors.*' => ['array'],
            'usage' => ['array'],
        ]);

        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($value): void {
            $allowed = ['generator', 'generator_version', 'request_id', 'status', 'completed_at', 'results', 'errors', 'usage'];
            if (array_diff(array_keys($value), $allowed) !== []) {
                $validator->errors()->add('response', 'contains an unknown field.');
            }

            $status = $value['status'] ?? null;
            $results = is_array($value['results'] ?? null) ? $value['results'] : [];
            $errors = is_array($value['errors'] ?? null) ? $value['errors'] : [];
            if ($status === ResponseStatus::Succeeded->value && (count($results) === 0 || count($errors) !== 0)) {
                $validator->errors()->add('status', 'requires results and no errors when succeeded.');
            }
            if ($status === ResponseStatus::Partial->value && (count($results) === 0 || count($errors) === 0)) {
                $validator->errors()->add('status', 'requires results and errors when partial.');
            }
            if ($status === ResponseStatus::Failed->value && (count($results) !== 0 || count($errors) === 0)) {
                $validator->errors()->add('status', 'requires no results and errors when failed.');
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
        $results = $value['results'] ?? null;
        $errors = $value['errors'] ?? null;
        // Laravel's regex rule also fails when preg_match() errors.
        if (array_diff(array_keys($value), ['generator', 'generator_version', 'request_id', 'status', 'completed_at', 'results', 'errors', 'usage']) !== []
            || ! is_string($value['generator'] ?? null) || mb_strlen($value['generator']) > 255
            || preg_match(self::GENERATOR_PATTERN, $value['generator']) !== 1
            || ! is_string($value['generator_version'] ?? null) || mb_strlen($value['generator_version']) > 255
            || preg_match(self::VERSION_PATTERN, $value['generator_version']) !== 1
            || ! is_string($value['request_id'] ?? null) || mb_strlen($value['request_id']) > 255
            || preg_match(OpaqueIdentifier::PATTERN, $value['request_id']) !== 1
            || ! is_string($value['status'] ?? null) || ($status = ResponseStatus::tryFrom($value['status'])) === null
            || ! self::isUtcTimestamp($value['completed_at'] ?? null)
            || ! self::isListOfArrays($results)
            || ! self::isListOfArrays($errors)
            || (array_key_exists('usage', $value) && ! is_array($value['usage']) && ! $value['usage'] instanceof stdClass)) {
            return false;
        }

        return match ($status) {
            ResponseStatus::Succeeded => $results !== [] && $errors === [],
            ResponseStatus::Partial => $results !== [] && $errors !== [],
            ResponseStatus::Failed => $results === [] && $errors !== [],
        };
    }

    /** @phpstan-assert-if-true list<array<array-key, mixed>> $value */
    private static function isListOfArrays(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_array($item)) {
                return false;
            }
        }

        return true;
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

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|null
     */
    private static function nullableArray(array $value, string $key): ?array
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        if ($value[$key] instanceof stdClass) {
            return get_object_vars($value[$key]);
        }

        if (! is_array($value[$key])) {
            throw ValidationException::withMessages([$key => 'must be an object.']);
        }

        return $value[$key];
    }

    private static function formatTimestamp(CarbonImmutable $timestamp): string
    {
        $timestamp = $timestamp->utc();
        $fraction = rtrim($timestamp->format('u'), '0');

        return $timestamp->format('Y-m-d\\TH:i:s').($fraction === '' ? '' : '.'.$fraction).'Z';
    }
}
