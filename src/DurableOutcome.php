<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Collection\OperationalDiagnostic;
use Jkudish\LaravelAiLibrarium\Collection\RedactedProviderResponse;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class DurableOutcome implements Arrayable, JsonSerializable
{
    /**
     * @param  Collection<int, RedactedProviderResponse>  $evidence
     * @param  Collection<int, OperationalDiagnostic>  $diagnostics
     */
    public function __construct(
        public ResearchHandle $handle,
        public ?ResearchResponse $response,
        public CaptureCoverage $coverage,
        public Collection $evidence,
        public Collection $diagnostics,
        public CarbonImmutable $startedAt,
    ) {}

    public function pending(): bool
    {
        return $this->response === null;
    }

    public function usage(): ?Usage
    {
        if ($this->response === null) {
            return $this->handle->submittedUsage;
        }

        return $this->response->results->first()->usage ?? $this->handle->submittedUsage;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'handle' => $this->handle->toArray(),
            'response' => $this->response?->toArray(),
            'capture' => [
                'coverage' => $this->coverage->value,
                'responses' => $this->evidence
                    ->map(static fn (RedactedProviderResponse $response): array => $response->toArray())
                    ->values()
                    ->all(),
            ],
            'diagnostics' => $this->diagnostics
                ->map(static fn (OperationalDiagnostic $diagnostic): array => $diagnostic->toArray())
                ->values()
                ->all(),
            'usage' => $this->usage()?->toArray(),
            'started_at' => $this->startedAt->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
