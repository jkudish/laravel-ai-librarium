<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use JsonSerializable;

final readonly class StoredRun implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, string>  $files
     */
    public function __construct(
        public Run $run,
        public array $state = [],
        public array $files = [],
        public int $version = 1,
    ) {
        Spec::validate($this->toArray(), 'stored-run');
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        $wire = $document;
        foreach (['state', 'files'] as $field) {
            if (is_array($wire[$field] ?? null)) {
                $wire[$field] = (object) $wire[$field];
            }
        }
        if (is_object($wire['state'] ?? null) && isset($wire['state']->handles) && is_array($wire['state']->handles)) {
            $wire['state']->handles = (object) $wire['state']->handles;
        }
        Spec::validate($wire, 'stored-run');

        /** @var array{run: array<string, mixed>, state: array<string, mixed>, files: array<string, string>, version: int} $document */
        return new self(Run::fromArray($document['run']), $document['state'], $document['files'], $document['version']);
    }

    /** @return array{run: array<string, mixed>, state: object, files: object, version: int} */
    public function toArray(): array
    {
        $state = $this->state;
        if (isset($state['handles']) && is_array($state['handles'])) {
            $state['handles'] = (object) $state['handles'];
        }

        return ['run' => $this->run->toArray(), 'state' => (object) $state, 'files' => (object) $this->files, 'version' => $this->version];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function snapshot(): Run
    {
        $diagnostics = $this->state['diagnostics'] ?? [];

        /** @var list<array<string, mixed>> $diagnostics */
        return new Run($this->run->toArray(), $this->files, $diagnostics);
    }

    public function withVersion(int $version): self
    {
        return new self($this->run, $this->state, $this->files, $version);
    }
}
