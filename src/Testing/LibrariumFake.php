<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Testing;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Collection\OperationalDiagnostic;
use Jkudish\LaravelAiLibrarium\Collection\RedactedProviderResponse;
use Jkudish\LaravelAiLibrarium\Collection\SensitiveCollection;
use Jkudish\LaravelAiLibrarium\Contracts\CollectsResearch;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\RecoversResearch;
use Jkudish\LaravelAiLibrarium\DurableOutcome;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\ResearchError;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

final class LibrariumFake implements CollectsResearch, ExecutesResearch, RecoversResearch
{
    private int $responseIndex = 0;

    private bool $preventStrayQueries = false;

    /** @var list<ResearchPlan> */
    private array $queries = [];

    /** @var list<ResearchPlan> */
    private array $queuedQueries = [];

    /** @var list<ResearchPlan> */
    private array $submittedQueries = [];

    /** @var list<ResearchHandle> */
    private array $retrievedHandles = [];

    /** @param list<ResearchResponse> $responses */
    public function __construct(private readonly array $responses = []) {}

    public function run(ResearchPlan $plan): ResearchResponse
    {
        $this->queries[] = $plan;
        $response = $this->responses[$this->responseIndex] ?? null;
        $this->responseIndex++;

        if ($response !== null) {
            return $response;
        }

        if ($this->preventStrayQueries) {
            throw new RuntimeException('Attempted research query ['.Str::words($plan->preview->input, 10).'] without a fake response.');
        }

        return $this->defaultResponse($plan);
    }

    public function queue(ResearchPlan $plan): QueuedResearchResponse
    {
        $this->queuedQueries[] = $plan;

        return new QueuedResearchResponse($plan->requestId, new FakePendingDispatch);
    }

    public function collect(ResearchPlan $plan): SensitiveCollection
    {
        $startedAt = CarbonImmutable::now();

        return new SensitiveCollection(
            response: $this->run($plan),
            coverage: CaptureCoverage::Unavailable,
            responses: [],
            diagnostics: $this->emptyDiagnostics(),
            startedAt: $startedAt,
        );
    }

    public function submit(ResearchPlan $plan, bool $capture = true): DurableOutcome
    {
        $this->submittedQueries[] = $plan;
        /** @var Profile $profile */
        $profile = $plan->preview->profiles->first();
        $startedAt = CarbonImmutable::now();
        $handle = new ResearchHandle(
            requestId: $plan->requestId,
            profile: $profile->id,
            provider: $profile->provider,
            model: $profile->model,
            reference: 'fake_'.Str::lower(Str::random(24)),
            submittedAt: $startedAt,
            submittedUsage: null,
            fingerprint: str_repeat('0', 64),
        );

        return new DurableOutcome(
            handle: $handle,
            response: null,
            coverage: CaptureCoverage::Unavailable,
            evidence: $this->emptyEvidence(),
            diagnostics: $this->emptyDiagnostics(),
            startedAt: $startedAt,
        );
    }

    public function retrieve(ResearchHandle $handle, bool $capture = true): DurableOutcome
    {
        $this->retrievedHandles[] = $handle;
        $startedAt = CarbonImmutable::now();
        $response = $this->responses[$this->responseIndex] ?? null;
        $this->responseIndex++;

        return new DurableOutcome(
            handle: $handle,
            response: $response,
            coverage: CaptureCoverage::Unavailable,
            evidence: $this->emptyEvidence(),
            diagnostics: $this->emptyDiagnostics(),
            startedAt: $startedAt,
        );
    }

    public function preventStrayQueries(bool $prevent = true): self
    {
        $this->preventStrayQueries = $prevent;

        return $this;
    }

    /** @return Collection<int, ResearchPlan> */
    public function queries(?Closure $callback = null): Collection
    {
        return collect($this->queries)->when($callback !== null, fn (Collection $queries): Collection => $queries->filter($callback));
    }

    /** @return Collection<int, ResearchPlan> */
    public function queuedQueries(?Closure $callback = null): Collection
    {
        return collect($this->queuedQueries)->when($callback !== null, fn (Collection $queries): Collection => $queries->filter($callback));
    }

    /** @return Collection<int, ResearchPlan> */
    public function submittedQueries(?Closure $callback = null): Collection
    {
        return collect($this->submittedQueries)->when($callback !== null, fn (Collection $queries): Collection => $queries->filter($callback));
    }

    /** @return Collection<int, ResearchHandle> */
    public function retrievedHandles(?Closure $callback = null): Collection
    {
        return collect($this->retrievedHandles)->when($callback !== null, fn (Collection $handles): Collection => $handles->filter($callback));
    }

    public function assertQueried(Closure|string $callback): self
    {
        PHPUnit::assertTrue(
            $this->queries($this->queryCallback($callback))->isNotEmpty(),
            'An expected research query was not executed.',
        );

        return $this;
    }

    public function assertNotQueried(Closure|string $callback): self
    {
        PHPUnit::assertTrue(
            $this->queries($this->queryCallback($callback))->isEmpty(),
            'An unexpected research query was executed.',
        );

        return $this;
    }

    public function assertNeverQueried(): self
    {
        PHPUnit::assertEmpty($this->queries, 'An unexpected research query was executed.');

        return $this;
    }

    public function assertQueued(Closure|string $callback): self
    {
        PHPUnit::assertTrue(
            $this->queuedQueries($this->queryCallback($callback))->isNotEmpty(),
            'An expected research query was not queued.',
        );

        return $this;
    }

    public function assertNotQueued(Closure|string $callback): self
    {
        PHPUnit::assertTrue(
            $this->queuedQueries($this->queryCallback($callback))->isEmpty(),
            'An unexpected research query was queued.',
        );

        return $this;
    }

    public function assertNeverQueued(): self
    {
        PHPUnit::assertEmpty($this->queuedQueries, 'An unexpected research query was queued.');

        return $this;
    }

    public function assertSubmitted(Closure|string $callback): self
    {
        PHPUnit::assertTrue(
            $this->submittedQueries($this->queryCallback($callback))->isNotEmpty(),
            'An expected durable research query was not submitted.',
        );

        return $this;
    }

    public function assertRetrieved(Closure|string $callback): self
    {
        $filter = is_string($callback)
            ? static fn (ResearchHandle $handle): bool => $handle->requestId === $callback
            : $callback;
        PHPUnit::assertTrue(
            $this->retrievedHandles($filter)->isNotEmpty(),
            'An expected durable research handle was not retrieved.',
        );

        return $this;
    }

    private function queryCallback(Closure|string $callback): Closure
    {
        return is_string($callback)
            ? static fn (ResearchPlan $plan): bool => $plan->preview->input === $callback
            : $callback;
    }

    /** @return Collection<int, RedactedProviderResponse> */
    private function emptyEvidence(): Collection
    {
        return collect()
            ->filter(static fn (mixed $value): bool => $value instanceof RedactedProviderResponse)
            ->values();
    }

    /** @return Collection<int, OperationalDiagnostic> */
    private function emptyDiagnostics(): Collection
    {
        return collect()
            ->filter(static fn (mixed $value): bool => $value instanceof OperationalDiagnostic)
            ->values();
    }

    private function defaultResponse(ResearchPlan $plan): ResearchResponse
    {
        /** @var Profile $profile */
        $profile = $plan->preview->profiles->first();
        $completedAt = CarbonImmutable::now();
        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        /** @var Collection<int, ResearchError> $errors */
        $errors = collect();

        return new ResearchResponse(
            generator: 'jkudish/laravel-ai-librarium',
            generatorVersion: '1.0.0',
            requestId: $plan->requestId,
            status: ResponseStatus::Succeeded,
            completedAt: $completedAt,
            results: collect([
                ResearchResult::make(
                    contentFormat: ContentFormat::Markdown,
                    content: 'Fake response for query: '.Str::words($plan->preview->input, 10),
                    requestedProfile: $profile->id,
                    provider: $profile->provider,
                    profile: $profile->id,
                    provenance: new ResultProvenance(
                        resultKind: $profile->resultKind,
                        retrievalMethods: $profile->retrievalMethods,
                        corpora: $profile->corpora,
                        observedAt: $completedAt,
                        collector: $profile->observation === ObservationMode::SurfaceSnapshot ? 'librarium-fake' : null,
                        surface: $profile->observation === ObservationMode::SurfaceSnapshot ? 'synthetic-surface' : null,
                        observationMode: $profile->observation === ObservationMode::SurfaceSnapshot ? ObservationMode::SurfaceSnapshot : null,
                    ),
                    citations: $citations,
                    completedAt: $completedAt,
                    model: $profile->model,
                ),
            ]),
            errors: $errors,
        );
    }
}
