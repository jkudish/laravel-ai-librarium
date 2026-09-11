<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use JsonSerializable;
use Throwable;

/** @implements Arrayable<string, mixed> */
final readonly class ResearchHandle implements Arrayable, JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $requestId,
        public string $profile,
        public string $provider,
        public ?string $model,
        public string $reference,
        public CarbonImmutable $submittedAt,
        public ?Usage $submittedUsage,
        public string $fingerprint,
    ) {
        self::validate($this->toArray());
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::validate($value);

        $requestId = self::string($value, 'request_id');
        $profile = self::string($value, 'profile');
        $provider = self::string($value, 'provider');
        $model = self::nullableString($value, 'model');
        $reference = self::string($value, 'reference');
        $submittedAtValue = self::string($value, 'submitted_at');
        $submittedUsage = self::nullableUsage($value, 'submitted_usage');
        $fingerprint = self::string($value, 'fingerprint');

        try {
            $submittedAt = CarbonImmutable::parse($submittedAtValue);
        } catch (Throwable) {
            throw ValidationException::withMessages(['submitted_at' => 'The submitted_at timestamp is invalid.']);
        }

        return new self(
            requestId: $requestId,
            profile: $profile,
            provider: $provider,
            model: $model,
            reference: $reference,
            submittedAt: $submittedAt,
            submittedUsage: $submittedUsage,
            fingerprint: $fingerprint,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'request_id' => $this->requestId,
            'profile' => $this->profile,
            'provider' => $this->provider,
            'model' => $this->model,
            'reference' => $this->reference,
            'submitted_at' => $this->submittedAt->utc()->toIso8601ZuluString(),
            'submitted_usage' => $this->submittedUsage?->toArray(),
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<array-key, mixed> $value */
    private static function validate(array $value): void
    {
        $allowed = ['schema_version', 'request_id', 'profile', 'provider', 'model', 'reference', 'submitted_at', 'submitted_usage', 'fingerprint'];
        $keys = array_keys($value);
        sort($keys);
        sort($allowed);
        if ($keys !== $allowed) {
            throw ValidationException::withMessages(['handle' => 'The research handle has missing or unknown fields.']);
        }

        if (($value['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw ValidationException::withMessages(['schema_version' => 'The research handle version is not supported.']);
        }

        foreach (['request_id', 'profile', 'provider', 'reference', 'submitted_at', 'fingerprint'] as $field) {
            if (! is_string($value[$field] ?? null)) {
                throw ValidationException::withMessages([$field => "The {$field} field must be a string."]);
            }
        }

        if (($value['model'] ?? null) !== null && ! is_string($value['model'])) {
            throw ValidationException::withMessages(['model' => 'The model field must be null or a string.']);
        }
        if (($value['submitted_usage'] ?? null) !== null && ! is_array($value['submitted_usage'])) {
            throw ValidationException::withMessages(['submitted_usage' => 'The submitted_usage field must be null or an object.']);
        }

        if (strlen($value['request_id']) > 255 || preg_match('/^(?!\s)(?!.*\s$)[^\x00-\x1f\x7f]+$/u', $value['request_id']) !== 1) {
            throw ValidationException::withMessages(['request_id' => 'The request_id field is invalid.']);
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,254}$/D', $value['profile']) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,254}$/D', $value['provider']) !== 1) {
            throw ValidationException::withMessages(['handle' => 'The research handle identity is invalid.']);
        }
        if (strlen($value['reference']) > 1024
            || trim($value['reference']) !== $value['reference']
            || preg_match('/^[^\x00-\x1f\x7f]+$/uD', $value['reference']) !== 1) {
            throw ValidationException::withMessages(['reference' => 'The provider reference is invalid.']);
        }
        $model = $value['model'] ?? null;
        if (is_string($model)
            && (strlen($model) > 255 || preg_match('/^(?!\s)(?!.*\s$)[^\x00-\x1f\x7f]+$/u', $model) !== 1)) {
            throw ValidationException::withMessages(['model' => 'The model field is invalid.']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', $value['submitted_at']) !== 1) {
            throw ValidationException::withMessages(['submitted_at' => 'The submitted_at timestamp must be UTC.']);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $value['fingerprint']) !== 1) {
            throw ValidationException::withMessages(['fingerprint' => 'The research handle fingerprint is invalid.']);
        }
    }

    /** @param array<array-key, mixed> $value */
    private static function string(array $value, string $key): string
    {
        $item = $value[$key] ?? null;
        if (! is_string($item)) {
            throw ValidationException::withMessages([$key => "The {$key} field must be a string."]);
        }

        return $item;
    }

    /** @param array<array-key, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        $item = $value[$key] ?? null;
        if ($item !== null && ! is_string($item)) {
            throw ValidationException::withMessages([$key => "The {$key} field must be null or a string."]);
        }

        return $item;
    }

    /** @param array<array-key, mixed> $value */
    private static function nullableUsage(array $value, string $key): ?Usage
    {
        $item = $value[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if (! is_array($item) || array_is_list($item)) {
            throw ValidationException::withMessages([$key => "The {$key} field must be null or an object."]);
        }

        return Usage::fromArray($item);
    }
}
