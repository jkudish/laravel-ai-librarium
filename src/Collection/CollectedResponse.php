<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class CollectedResponse implements Arrayable, JsonSerializable
{
    /**
     * @param  Collection<int, RedactedProviderResponse>  $evidence
     * @param  Collection<int, OperationalDiagnostic>  $diagnostics
     */
    public function __construct(
        public ResearchResponse $response,
        public CaptureCoverage $coverage,
        public Collection $evidence,
        public Collection $diagnostics,
        public CarbonImmutable $startedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'response' => $this->response->toArray(),
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
            'started_at' => $this->startedAt->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
