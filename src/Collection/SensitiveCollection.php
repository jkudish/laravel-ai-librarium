<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use LogicException;

final class SensitiveCollection
{
    /**
     * @param  list<SensitiveProviderResponse>  $responses
     * @param  Collection<int, OperationalDiagnostic>  $diagnostics
     */
    public function __construct(
        private readonly ResearchResponse $response,
        private readonly CaptureCoverage $coverage,
        private readonly array $responses,
        private readonly Collection $diagnostics,
        private readonly CarbonImmutable $startedAt,
    ) {}

    public function response(): ResearchResponse
    {
        return $this->response;
    }

    public function coverage(): CaptureCoverage
    {
        return $this->coverage;
    }

    /** @return list<SensitiveProviderResponse> */
    public function responses(): array
    {
        return $this->responses;
    }

    /** @return Collection<int, OperationalDiagnostic> */
    public function diagnostics(): Collection
    {
        return $this->diagnostics;
    }

    public function startedAt(): CarbonImmutable
    {
        return $this->startedAt;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Sensitive collection results cannot be serialized.');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'request_id' => $this->response->requestId,
            'coverage' => $this->coverage->value,
            'responses' => count($this->responses).' sensitive response(s)',
            'diagnostics' => $this->diagnostics->count(),
            'started_at' => $this->startedAt->toIso8601ZuluString(),
        ];
    }
}
