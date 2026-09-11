<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Jkudish\LaravelAiLibrarium\Collection\Enums\FailureCategory;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\Enums\SubmissionState;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class OperationalDiagnostic implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $provider,
        public string $profile,
        public string $code,
        public FailureCategory $category,
        public Operation $operation,
        public SubmissionState $submissionState,
        public ?int $httpStatus = null,
        public ?string $providerCode = null,
        public ?CarbonImmutable $retryAt = null,
        public ?CarbonImmutable $resetAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'provider' => $this->provider,
            'profile' => $this->profile,
            'code' => $this->code,
            'category' => $this->category->value,
            'operation' => $this->operation->value,
            'submission_state' => $this->submissionState->value,
            'http_status' => $this->httpStatus,
            'provider_code' => $this->providerCode,
            'retry_at' => $this->retryAt?->toIso8601ZuluString(),
            'reset_at' => $this->resetAt?->toIso8601ZuluString(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
