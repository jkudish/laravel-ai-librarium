<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use BadMethodCallException;
use Illuminate\Support\Facades\Validator;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;

final class PendingResearch
{
    /** @var array{question: string, depth: string, only?: list<string>, skip: list<string>, max_cost?: string, options: array<string, array<string, mixed>>} */
    private array $request;

    public function __construct(string $question, private readonly Librarium $manager)
    {
        Validator::make(['question' => $question], ['question' => ['required', 'string']])->validate();
        $this->request = ['question' => $question, 'depth' => 'quick', 'skip' => [], 'options' => []];
    }

    public function deep(): self
    {
        $this->request['depth'] = 'deep';

        return $this;
    }

    /** @param list<string> $names */
    public function only(array $names): self
    {
        $this->names($names);
        $this->request['only'] = $names;

        return $this;
    }

    /** @param list<string> $names */
    public function skip(array $names): self
    {
        $this->names($names);
        $this->request['skip'] = $names;

        return $this;
    }

    public function maxCost(int|float|string $amount): self
    {
        Validator::make(['amount' => (string) $amount], ['amount' => ['required', 'regex:/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D']])->validate();
        $this->request['max_cost'] = (string) $amount;

        return $this;
    }

    /** @param array<string, array<string, mixed>> $options */
    public function options(array $options): self
    {
        $this->request['options'] = $options;

        return $this;
    }

    public function run(): Run
    {
        return $this->manager->execute($this->request, false);
    }

    public function dryRun(): Run
    {
        return $this->manager->plan($this->request)->run;
    }

    public function queue(): QueuedResearchResponse
    {
        return $this->manager->enqueue($this->request);
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): never
    {
        $replacement = match ($method) {
            'using', 'fanOut', 'group', 'matching', 'fallbacks' => 'only(), skip() and deep()',
            'preview', 'spendingBound' => 'dryRun()',
            'collect', 'collectSensitive' => 'run() and Run::evidence()/diagnostics()',
            'submit' => 'queue() and Librarium::find()->refresh()',
            default => null,
        };
        throw new BadMethodCallException($replacement === null ? "Unknown research method [{$method}]." : "[{$method}()] was removed; use {$replacement}.");
    }

    /** @param list<string> $names */
    private function names(array $names): void
    {
        Validator::make(['names' => $names], ['names' => ['array', 'list'], 'names.*' => ['required', 'string', 'max:64']])->validate();
    }
}
