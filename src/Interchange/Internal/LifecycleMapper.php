<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Internal;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\LifecycleEventKind;
use Jkudish\LaravelAiLibrarium\Interchange\Values\LifecycleEvent;

/** @internal */
final readonly class LifecycleMapper
{
    public function __construct(
        private WireValueReader $wire,
        private LeafMapper $leaf,
    ) {}

    /** @param array<string, mixed> $value */
    public function lifecycleEventFromArray(array $value): LifecycleEvent
    {
        return $this->mapLifecycleEvent($value, '');
    }

    /** @return array<string, mixed> */
    public function lifecycleEventToArray(LifecycleEvent $event): array
    {
        $value = $this->wire->withoutNulls([
            'interchange_version' => WireValueReader::VERSION,
            'message_type' => 'lifecycle_event',
            'event_id' => $event->eventId,
            'request_id' => $event->requestId,
            'sequence' => $event->sequence,
            'occurred_at' => $event->occurredAt,
            'slot_id' => $event->slotId,
            'attempt_id' => $event->attemptId,
            'event_kind' => $event->eventKind->value,
            'data' => $event->data,
        ]);
        $this->mapLifecycleEvent($value, '');

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $value
     * @return list<LifecycleEvent>
     */
    public function lifecycleTraceFromArray(array $value): array
    {
        $this->wire->assertList($value, '', 1, 10_000);

        $events = [];
        foreach ($value as $index => $event) {
            $events[] = $this->mapLifecycleEvent($this->wire->assertObject($event, "/{$index}"), "/{$index}");
        }

        $this->validateLifecycleTrace($events);

        return $events;
    }

    /**
     * @param  list<LifecycleEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function lifecycleTraceToArray(array $events): array
    {
        $this->validateLifecycleTrace($events);

        return array_map($this->lifecycleEventToArray(...), $events);
    }

    /** @param array<string, mixed> $value */
    private function mapLifecycleEvent(array $value, string $path): LifecycleEvent
    {
        $this->wire->assertKeys(
            $value,
            ['interchange_version', 'message_type', 'event_id', 'request_id', 'sequence', 'occurred_at', 'event_kind', 'data'],
            ['interchange_version', 'message_type', 'event_id', 'request_id', 'sequence', 'occurred_at', 'slot_id', 'attempt_id', 'event_kind', 'data'],
            $path,
        );
        $this->wire->assertLiteral($value['interchange_version'], WireValueReader::VERSION, $this->wire->child($path, 'interchange_version'));
        $this->wire->assertLiteral($value['message_type'], 'lifecycle_event', $this->wire->child($path, 'message_type'));

        $eventKind = $this->wire->enum(LifecycleEventKind::class, $value['event_kind'], $this->wire->child($path, 'event_kind'));
        $requiresAttempt = in_array($eventKind, [
            LifecycleEventKind::AttemptStarted,
            LifecycleEventKind::DurableTaskSubmitted,
            LifecycleEventKind::AttemptProgress,
            LifecycleEventKind::AttemptFinished,
            LifecycleEventKind::FallbackSelected,
        ], true);
        if ($requiresAttempt) {
            $this->wire->assertKeysPresent($value, ['slot_id', 'attempt_id'], $path);
        } elseif (array_key_exists('slot_id', $value) || array_key_exists('attempt_id', $value)) {
            $this->wire->fail($path, "{$eventKind->value} must omit slot_id and attempt_id");
        }

        $dataPath = $this->wire->child($path, 'data');
        $data = $this->mapLifecycleData($eventKind, $this->wire->assertObject($value['data'], $dataPath), $dataPath);

        return new LifecycleEvent(
            eventId: $this->wire->opaqueId($value['event_id'], $this->wire->child($path, 'event_id')),
            requestId: $this->wire->opaqueId($value['request_id'], $this->wire->child($path, 'request_id')),
            sequence: $this->wire->nonNegativeInteger($value['sequence'], $this->wire->child($path, 'sequence')),
            occurredAt: $this->wire->timestamp($value['occurred_at'], $this->wire->child($path, 'occurred_at')),
            eventKind: $eventKind,
            data: $data,
            slotId: $this->wire->optionalOpaqueId($value, 'slot_id', $path),
            attemptId: $this->wire->optionalOpaqueId($value, 'attempt_id', $path),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapLifecycleData(LifecycleEventKind $kind, array $data, string $path): array
    {
        return match ($kind) {
            LifecycleEventKind::RequestStarted => $this->mapRequestStartedData($data, $path),
            LifecycleEventKind::AttemptStarted => $this->mapAttemptStartedData($data, $path),
            LifecycleEventKind::DurableTaskSubmitted => $this->mapDurableSubmittedData($data, $path),
            LifecycleEventKind::AttemptProgress => $this->mapProgressData($data, $path),
            LifecycleEventKind::AttemptFinished => $this->mapAttemptFinishedData($data, $path),
            LifecycleEventKind::FallbackSelected => $this->mapFallbackData($data, $path),
            LifecycleEventKind::RequestCompleted => $this->mapRequestCompletedData($data, $path),
            LifecycleEventKind::RequestFailed => $this->mapRequestFailedData($data, $path),
            LifecycleEventKind::RequestCancelled => $this->mapRequestCancelledData($data, $path),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapRequestStartedData(array $data, string $path): array
    {
        $this->wire->assertKeys($data, ['mode'], ['mode'], $path);

        return ['mode' => $this->wire->closedString($data['mode'], ['sync', 'async'], $this->wire->child($path, 'mode'))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapAttemptStartedData(array $data, string $path): array
    {
        $this->wire->assertKeys($data, ['provider', 'attempt_number'], ['provider', 'attempt_number'], $path);

        return [
            'provider' => $this->leaf->providerToArray($this->leaf->mapProvider($this->wire->assertObject($data['provider'], $this->wire->child($path, 'provider')), $this->wire->child($path, 'provider'))),
            'attempt_number' => $this->wire->positiveInteger($data['attempt_number'], $this->wire->child($path, 'attempt_number')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapDurableSubmittedData(array $data, string $path): array
    {
        $this->wire->assertKeys($data, ['handle'], ['handle'], $path);

        return ['handle' => $this->leaf->durableHandleToArray($this->leaf->mapDurableHandle($this->wire->assertObject($data['handle'], $this->wire->child($path, 'handle')), $this->wire->child($path, 'handle')))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapProgressData(array $data, string $path): array
    {
        $this->wire->assertKeys($data, ['progress_percent'], ['progress_percent', 'message'], $path);
        $progress = $this->wire->nonNegativeInteger($data['progress_percent'], $this->wire->child($path, 'progress_percent'));
        if ($progress > 100) {
            $this->wire->fail($this->wire->child($path, 'progress_percent'), 'must be at most 100');
        }

        return $this->wire->withoutNulls([
            'progress_percent' => $progress,
            'message' => $this->wire->optionalString($data, 'message', $path, 1, 512),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapAttemptFinishedData(array $data, string $path): array
    {
        $this->wire->assertKeysPresent($data, ['outcome'], $path);
        $outcome = $this->wire->closedString($data['outcome'], ['succeeded', 'failed', 'timed_out', 'cancelled'], $this->wire->child($path, 'outcome'));
        $errorRequired = $outcome === 'failed' || $outcome === 'timed_out';
        $allowed = $outcome === 'succeeded' ? ['outcome'] : ['outcome', 'error'];
        $this->wire->assertKeys($data, $errorRequired ? ['error'] : [], $allowed, $path);

        return $this->wire->withoutNulls([
            'outcome' => $outcome,
            'error' => array_key_exists('error', $data)
                ? $this->leaf->errorToArray($this->leaf->mapError($this->wire->assertObject($data['error'], $this->wire->child($path, 'error')), $this->wire->child($path, 'error')))
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapFallbackData(array $data, string $path): array
    {
        $this->wire->assertKeys($data, ['failed_attempt_id', 'replacement_attempt_id', 'candidate_id'], ['failed_attempt_id', 'replacement_attempt_id', 'candidate_id'], $path);

        return [
            'failed_attempt_id' => $this->wire->opaqueId($data['failed_attempt_id'], $this->wire->child($path, 'failed_attempt_id')),
            'replacement_attempt_id' => $this->wire->opaqueId($data['replacement_attempt_id'], $this->wire->child($path, 'replacement_attempt_id')),
            'candidate_id' => $this->wire->opaqueId($data['candidate_id'], $this->wire->child($path, 'candidate_id')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapRequestCompletedData(array $data, string $path): array
    {
        $this->wire->assertKeys($data, ['outcome'], ['outcome'], $path);

        return ['outcome' => $this->wire->closedString($data['outcome'], ['succeeded', 'partial', 'unsuccessful'], $this->wire->child($path, 'outcome'))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapRequestFailedData(array $data, string $path): array
    {
        $this->wire->assertKeys($data, ['error'], ['error'], $path);

        return ['error' => $this->leaf->errorToArray($this->leaf->mapError($this->wire->assertObject($data['error'], $this->wire->child($path, 'error')), $this->wire->child($path, 'error')))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapRequestCancelledData(array $data, string $path): array
    {
        $this->wire->assertKeys($data, [], ['error'], $path);

        return $this->wire->withoutNulls([
            'error' => array_key_exists('error', $data)
                ? $this->leaf->errorToArray($this->leaf->mapError($this->wire->assertObject($data['error'], $this->wire->child($path, 'error')), $this->wire->child($path, 'error')))
                : null,
        ]);
    }

    /** @param list<LifecycleEvent> $events */
    private function validateLifecycleTrace(array $events): void
    {
        if ($events === []) {
            $this->wire->fail('/', 'must contain at least one lifecycle event');
        }
        $requestId = $events[0]->requestId;
        $eventIds = [];
        $priorSequence = -1;
        $priorTimestamp = null;
        $terminalSeen = false;
        foreach ($events as $index => $event) {
            if ($event->requestId !== $requestId) {
                $this->wire->fail("/{$index}/request_id", 'lifecycle traces may contain only one request_id');
            }
            if (isset($eventIds[$event->eventId])) {
                $this->wire->fail("/{$index}/event_id", 'must be unique');
            }
            $eventIds[$event->eventId] = true;
            if ($event->sequence <= $priorSequence) {
                $this->wire->fail("/{$index}/sequence", 'must increase monotonically');
            }
            $priorSequence = $event->sequence;
            $this->wire->dateTime($event->occurredAt);
            if ($priorTimestamp !== null && $this->wire->compareTimestamps($event->occurredAt, $priorTimestamp) < 0) {
                $this->wire->fail("/{$index}/occurred_at", 'must not move backwards');
            }
            $priorTimestamp = $event->occurredAt;
            if ($terminalSeen) {
                $this->wire->fail("/{$index}/event_kind", 'no event may follow a terminal request event');
            }
            $terminalSeen = in_array($event->eventKind, [LifecycleEventKind::RequestCompleted, LifecycleEventKind::RequestFailed, LifecycleEventKind::RequestCancelled], true);
        }
        if ($events[0]->eventKind !== LifecycleEventKind::RequestStarted) {
            $this->wire->fail('/0/event_kind', 'lifecycle traces must begin with request_started');
        }
    }
}
