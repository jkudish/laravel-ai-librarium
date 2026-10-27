<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @phpstan-type RunResult array{source: string, status: string, observation: string, answer: ?string, citations: list<array{url: string, title?: ?string}>, cost: array{estimated: ?string, reported: ?string}, started_at: ?string, duration_ms: ?int, provenance: array{provider: string, variant: string, model: ?string, collector: ?string}, error: ?array{code: string, message: string, retryable: bool}}
 * @phpstan-type RunDocument array{spec: string, id: string, question: string, depth: string, status: string, created_at: string, finished_at: ?string, cost: array{estimated: ?string, reported: ?string, complete: bool, max: ?string}, results: list<RunResult>, skipped: list<array{source: string, reason: string}>, notices: list<array{code: string, message: string}>, summary_of?: string}
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Run implements Arrayable, JsonSerializable
{
    /**
     * @param  RunDocument  $document
     * @param  array<string, string>  $files
     * @param  list<array<string, mixed>>  $diagnosticItems
     */
    public function __construct(
        private array $document,
        private array $files = [],
        private array $diagnosticItems = [],
    ) {
        Spec::validate($document, 'run');
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        Spec::validate($document, 'run');

        /** @var RunDocument $document */
        return new self($document);
    }

    public function id(): string
    {
        return $this->document['id'];
    }

    public function status(): string
    {
        return $this->document['status'];
    }

    /** Observe pending tasks once; the returned value is a new snapshot. */
    public function refresh(): self
    {
        return app(LibrariumManager::class)->refresh($this->id());
    }

    /** @return RunResult|null */
    public function result(string $source): ?array
    {
        foreach ($this->document['results'] as $result) {
            if ($result['source'] === $source) {
                return $result;
            }
        }

        return null;
    }

    /** @return list<array{url: string, title?: ?string}> */
    public function citations(): array
    {
        $citations = [];
        foreach ($this->document['results'] as $result) {
            foreach ($result['citations'] as $citation) {
                $citations[$citation['url']] ??= $citation;
            }
        }

        return array_values($citations);
    }

    public function evidence(string $source): mixed
    {
        $contents = $this->files['evidence/'.$source.'.json'] ?? null;

        return $contents === null ? null : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->diagnosticItems;
    }

    /** @return RunDocument */
    public function toArray(): array
    {
        return $this->document;
    }

    /** @return RunDocument */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
