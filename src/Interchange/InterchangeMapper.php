<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange;

use Jkudish\LaravelAiLibrarium\Interchange\Internal\LeafMapper;
use Jkudish\LaravelAiLibrarium\Interchange\Internal\LifecycleMapper;
use Jkudish\LaravelAiLibrarium\Interchange\Internal\ResponseMapper;
use Jkudish\LaravelAiLibrarium\Interchange\Internal\WireValueReader;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Citation;
use Jkudish\LaravelAiLibrarium\Interchange\Values\CollectionProvenance;
use Jkudish\LaravelAiLibrarium\Interchange\Values\DurableHandle;
use Jkudish\LaravelAiLibrarium\Interchange\Values\LifecycleEvent;
use Jkudish\LaravelAiLibrarium\Interchange\Values\NormalizedSource;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Response;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Result;
use Jkudish\LaravelAiLibrarium\Interchange\Values\StructuredError;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Usage;

final readonly class InterchangeMapper
{
    public const string VERSION = WireValueReader::VERSION;

    /** @param array<string, mixed> $value */
    public function citationFromArray(array $value): Citation
    {
        return $this->leaf()->citationFromArray($value);
    }

    /** @return array<string, mixed> */
    public function citationToArray(Citation $citation): array
    {
        return $this->leaf()->citationToArray($citation);
    }

    /** @param array<string, mixed> $value */
    public function sourceFromArray(array $value): NormalizedSource
    {
        return $this->leaf()->sourceFromArray($value);
    }

    /** @return array<string, mixed> */
    public function sourceToArray(NormalizedSource $source): array
    {
        return $this->leaf()->sourceToArray($source);
    }

    /** @param array<string, mixed> $value */
    public function provenanceFromArray(array $value): CollectionProvenance
    {
        return $this->leaf()->provenanceFromArray($value);
    }

    /** @return array<string, mixed> */
    public function provenanceToArray(CollectionProvenance $provenance): array
    {
        return $this->leaf()->provenanceToArray($provenance);
    }

    /** @param array<string, mixed> $value */
    public function usageFromArray(array $value): Usage
    {
        return $this->leaf()->usageFromArray($value);
    }

    /** @return array<string, mixed> */
    public function usageToArray(Usage $usage): array
    {
        return $this->leaf()->usageToArray($usage);
    }

    /** @param array<string, mixed> $value */
    public function errorFromArray(array $value): StructuredError
    {
        return $this->leaf()->errorFromArray($value);
    }

    /** @return array<string, mixed> */
    public function errorToArray(StructuredError $error): array
    {
        return $this->leaf()->errorToArray($error);
    }

    /** @param array<string, mixed> $value */
    public function durableHandleFromArray(array $value): DurableHandle
    {
        return $this->leaf()->durableHandleFromArray($value);
    }

    /** @return array<string, mixed> */
    public function durableHandleToArray(DurableHandle $handle): array
    {
        return $this->leaf()->durableHandleToArray($handle);
    }

    /** @param array<string, mixed> $value */
    public function lifecycleEventFromArray(array $value): LifecycleEvent
    {
        return $this->lifecycle()->lifecycleEventFromArray($value);
    }

    /** @return array<string, mixed> */
    public function lifecycleEventToArray(LifecycleEvent $event): array
    {
        return $this->lifecycle()->lifecycleEventToArray($event);
    }

    /**
     * @param  list<array<string, mixed>>  $value
     * @return list<LifecycleEvent>
     */
    public function lifecycleTraceFromArray(array $value): array
    {
        return $this->lifecycle()->lifecycleTraceFromArray($value);
    }

    /**
     * @param  list<LifecycleEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function lifecycleTraceToArray(array $events): array
    {
        return $this->lifecycle()->lifecycleTraceToArray($events);
    }

    /** @param array<string, mixed> $value */
    public function resultFromArray(array $value): Result
    {
        return $this->response()->resultFromArray($value);
    }

    /** @return array<string, mixed> */
    public function resultToArray(Result $result): array
    {
        return $this->response()->resultToArray($result);
    }

    /** @param array<string, mixed> $value */
    public function responseFromArray(array $value): Response
    {
        return $this->response()->responseFromArray($value);
    }

    /** @return array<string, mixed> */
    public function responseToArray(Response $response): array
    {
        return $this->response()->responseToArray($response);
    }

    private function leaf(): LeafMapper
    {
        return new LeafMapper(new WireValueReader);
    }

    private function lifecycle(): LifecycleMapper
    {
        $wire = new WireValueReader;

        return new LifecycleMapper($wire, new LeafMapper($wire));
    }

    private function response(): ResponseMapper
    {
        $wire = new WireValueReader;

        return new ResponseMapper($wire, new LeafMapper($wire));
    }
}
