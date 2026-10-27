<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\StoredRun;
use PHPUnit\Framework\Assert;
use RuntimeException;

final class LibrariumFake implements Librarium
{
    /** @var list<array<string, mixed>> */
    private array $queries = [];

    /** @var list<array<string, mixed>> */
    private array $queued = [];

    /** @var array<string, Run> */
    private array $runs = [];

    private bool $preventStray = false;

    /** @param list<Run> $responses */
    public function __construct(private readonly LibrariumManager $manager, private array $responses = []) {}

    public function research(string $question): PendingResearch
    {
        return new PendingResearch($question, $this);
    }

    public function find(string $id): ?Run
    {
        return $this->runs[$id] ?? null;
    }

    /** @return array<string, mixed> */
    public function sources(): array
    {
        return $this->manager->sources();
    }

    /** @param Closure(Container, array<string, mixed>): RunStore $factory */
    public function extendStore(string $name, Closure $factory): void
    {
        $this->manager->extendStore($name, $factory);
    }

    /** @param array<string, mixed> $request */
    public function plan(array $request): StoredRun
    {
        return $this->manager->plan($request);
    }

    /** @param array<string, mixed> $request */
    public function execute(array $request, bool $detach): Run
    {
        $plan = $this->plan($request);
        $this->queries[] = $request;
        $run = array_shift($this->responses);
        if ($run === null) {
            if ($this->preventStray) {
                throw new RuntimeException('Research attempted without a fake Run.');
            }
            $document = $plan->run->toArray();
            $document['status'] = 'done';
            $document['finished_at'] = $document['created_at'];
            foreach ($document['results'] as &$result) {
                $result['status'] = 'done';
                $result['answer'] = 'Fake research answer.';
            }
            unset($result);
            $run = new Run($document);
        }
        $this->runs[$run->id()] = $run;

        return $run;
    }

    /** @param array<string, mixed> $request */
    public function enqueue(array $request): QueuedResearchResponse
    {
        $plan = $this->plan($request);
        $this->queued[] = $request;
        $document = $plan->run->toArray();
        $document['status'] = 'running';
        $run = new Run($document);
        $this->runs[$run->id()] = $run;

        return new QueuedResearchResponse($run->id(), new FakePendingDispatch);
    }

    public function preventStrayQueries(bool $prevent = true): self
    {
        $this->preventStray = $prevent;

        return $this;
    }

    public function assertQueried(string|Closure $predicate): self
    {
        Assert::assertTrue(array_any($this->queries, is_string($predicate) ? fn (array $request): bool => $request['question'] === $predicate : $predicate));

        return $this;
    }

    public function assertQueued(string|Closure $predicate): self
    {
        Assert::assertTrue(array_any($this->queued, is_string($predicate) ? fn (array $request): bool => $request['question'] === $predicate : $predicate));

        return $this;
    }

    public function assertNeverQueried(): self
    {
        Assert::assertEmpty($this->queries);

        return $this;
    }

    public function assertNeverQueued(): self
    {
        Assert::assertEmpty($this->queued);

        return $this;
    }
}
