<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Testing;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
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

final class LibrariumFake implements ExecutesResearch
{
    private int $responseIndex = 0;

    private bool $preventStrayQueries = false;

    /** @var list<ResearchPlan> */
    private array $queries = [];

    /** @var list<ResearchPlan> */
    private array $queuedQueries = [];

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

    private function queryCallback(Closure|string $callback): Closure
    {
        return is_string($callback)
            ? static fn (ResearchPlan $plan): bool => $plan->preview->input === $callback
            : $callback;
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
