<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

final readonly class RunSummary
{
    public function __construct(public string $id, public string $question, public string $status, public string $createdAt) {}

    public static function fromRun(Run $run): self
    {
        $document = $run->toArray();

        return new self($run->id(), $document['question'], $document['status'], $document['created_at']);
    }
}
