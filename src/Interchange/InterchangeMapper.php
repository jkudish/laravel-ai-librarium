<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange;

use DateTimeImmutable;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\AttemptStatus;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\DurableHandleStatus;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\ErrorCategory;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\LifecycleEventKind;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Attempt;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Citation;
use Jkudish\LaravelAiLibrarium\Interchange\Values\CollectionProvenance;
use Jkudish\LaravelAiLibrarium\Interchange\Values\DurableHandle;
use Jkudish\LaravelAiLibrarium\Interchange\Values\LifecycleEvent;
use Jkudish\LaravelAiLibrarium\Interchange\Values\NormalizedSource;
use Jkudish\LaravelAiLibrarium\Interchange\Values\ProviderIdentity;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Response;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Result;
use Jkudish\LaravelAiLibrarium\Interchange\Values\StructuredError;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Usage;
use JsonException;

final readonly class InterchangeMapper
{
    public const string VERSION = '1.0.0';

    private const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    private const string NAMESPACED_KEY_PATTERN = '/\A[a-z0-9]+(?:[.-][a-z0-9]+)+:[A-Za-z0-9][A-Za-z0-9._-]*\z/D';

    private const string SNAKE_CASE_PATTERN = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/D';

    private const string DECIMAL_PATTERN = '/\A(?:0|[1-9]\d*)(?:\.\d{1,18})?\z/D';

    private const string RFC3339_UTC_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/D';

    private const string JSON_POINTER_PATTERN = '/\A(?:\/(?:[^~\/]|~0|~1)*)*\z/D';

    private const string HTTP_URL_PATTERN = '/\Ahttps?:\/\/(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*(?::(?:[1-9]\d{0,3}|[1-5]\d{4}|6[0-4]\d{3}|65[0-4]\d{2}|655[0-2]\d|6553[0-5]))?(?:[\/?#][\x21-\x5B\x5D-\x7E]*)?\z/D';

    /** @var list<string> */
    private const FORBIDDEN_EXTENSION_KEYS = [
        'api_key', 'api_keys', 'access_token', 'access_tokens', 'auth_token', 'auth_tokens',
        'client_token', 'client_tokens', 'client_secret', 'client_secrets', 'secret_key', 'secret_keys',
        'secret_access_key', 'secret_access_keys', 'refresh_token', 'refresh_tokens', 'resume_token',
        'resume_tokens', 'resume_secret', 'resume_secrets', 'session_token', 'session_tokens',
        'session_secret', 'session_secrets', 'task_token', 'task_tokens', 'task_secret', 'task_secrets',
        'id_token', 'id_tokens', 'private_key', 'private_keys', 'encryption_key', 'encryption_keys',
        'signing_key', 'signing_keys', 'presigned_url', 'presigned_urls', 'signed_url', 'signed_urls',
        'signed_polling_url', 'signed_polling_urls', 'connection_string', 'connection_strings',
        'database_url', 'database_urls', 'dsn', 'dsns', 'authorization', 'auth', 'bearer', 'cookie',
        'cookies', 'credential', 'credentials', 'password', 'passwords', 'passwd', 'passwds', 'secret',
        'secrets', 'token', 'tokens', 'request_header', 'request_headers', 'response_header',
        'response_headers', 'authorization_header', 'authorization_headers', 'header', 'headers',
        'raw_body', 'raw_bodies', 'raw_provider', 'raw_providers', 'raw_response', 'raw_responses',
        'binary', 'binary_data', 'binary_material', 'binary_materials', 'binary_payload',
        'binary_payloads', 'stack', 'stacks', 'stack_trace', 'stack_traces',
    ];

    /** @var list<string> */
    private const BENIGN_TOKEN_QUALIFIERS = [
        'accepted', 'cached', 'cache_creation', 'cache_read', 'completion', 'input', 'max', 'num',
        'output', 'prompt', 'reasoning', 'rejected', 'total', 'thinking',
    ];

    /** @param array<string, mixed> $value */
    public function citationFromArray(array $value): Citation
    {
        return $this->mapCitation($value, '');
    }

    /** @return array<string, mixed> */
    public function citationToArray(Citation $citation): array
    {
        $value = $this->withoutNulls([
            'citation_id' => $citation->citationId,
            'source_kind' => $citation->sourceKind->value,
            'source_category' => $citation->sourceCategory,
            'dataset_id' => $citation->datasetId,
            'derivation' => $citation->derivation->value,
            'url' => $citation->url,
            'title' => $citation->title,
            'snippet' => $citation->snippet,
            'provider_reference' => $citation->providerReference,
            'location' => $citation->location,
            'retrieved_at' => $citation->retrievedAt,
            'provenance' => $this->provenanceToArray($citation->provenance),
            'extensions' => $citation->extensions,
        ]);
        $this->mapCitation($value, '');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public function sourceFromArray(array $value): NormalizedSource
    {
        return $this->mapSource($value, '');
    }

    /** @return array<string, mixed> */
    public function sourceToArray(NormalizedSource $source): array
    {
        $value = $this->withoutNulls([
            'source_id' => $source->sourceId,
            'canonical_url' => $source->canonicalUrl,
            'provider_reference' => $source->providerReference,
            'source_kind' => $source->sourceKind->value,
            'source_category' => $source->sourceCategory,
            'dataset_id' => $source->datasetId,
            'publisher_id' => $source->publisherId,
            'publisher_name' => $source->publisherName,
            'citation_ids' => $source->citationIds,
            'correlation_keys' => $source->correlationKeys,
            'extensions' => $source->extensions,
        ]);
        $this->mapSource($value, '');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public function provenanceFromArray(array $value): CollectionProvenance
    {
        return $this->mapProvenance($value, '');
    }

    /** @return array<string, mixed> */
    public function provenanceToArray(CollectionProvenance $provenance): array
    {
        $value = $this->withoutNulls([
            'provider' => $this->providerToArray($provenance->provider),
            'access_mode' => $provenance->accessMode,
            'operator_id' => $provenance->operatorId,
            'collector_id' => $provenance->collectorId,
            'surface_id' => $provenance->surfaceId,
            'surface_context' => $provenance->surfaceContext,
            'origin_key' => $provenance->originKey,
            'correlation_keys' => $provenance->correlationKeys,
            'extensions' => $provenance->extensions,
        ]);
        $this->mapProvenance($value, '');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public function usageFromArray(array $value): Usage
    {
        return $this->mapUsage($value, '');
    }

    /** @return array<string, mixed> */
    public function usageToArray(Usage $usage): array
    {
        $value = $this->withoutNulls([
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'total_tokens' => $usage->totalTokens,
            'billable_units' => $usage->billableUnits,
            'actual_cost' => $usage->actualCost,
            'estimated_cost' => $usage->estimatedCost,
            'completeness' => $usage->completeness,
            'extensions' => $usage->extensions,
        ]);
        $this->mapUsage($value, '');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public function errorFromArray(array $value): StructuredError
    {
        return $this->mapError($value, '');
    }

    /** @return array<string, mixed> */
    public function errorToArray(StructuredError $error): array
    {
        $value = $this->withoutNulls([
            'code' => $error->code,
            'message' => $error->message,
            'category' => $error->category->value,
            'retryable' => $error->retryable,
            'fallback_allowed' => $error->fallbackAllowed,
            'provider_code' => $error->providerCode,
            'field_path' => $error->fieldPath,
            'details' => $error->details,
        ]);
        $this->mapError($value, '');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public function durableHandleFromArray(array $value): DurableHandle
    {
        return $this->mapDurableHandle($value, '');
    }

    /** @return array<string, mixed> */
    public function durableHandleToArray(DurableHandle $handle): array
    {
        $value = $this->withoutNulls([
            'handle_id' => $handle->handleId,
            'provider_task_id' => $handle->providerTaskId,
            'provider' => $this->providerToArray($handle->provider),
            'submitted_at' => $handle->submittedAt,
            'last_observed_at' => $handle->lastObservedAt,
            'status' => $handle->status->value,
            'extensions' => $handle->extensions,
        ]);
        $this->mapDurableHandle($value, '');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public function lifecycleEventFromArray(array $value): LifecycleEvent
    {
        return $this->mapLifecycleEvent($value, '');
    }

    /** @return array<string, mixed> */
    public function lifecycleEventToArray(LifecycleEvent $event): array
    {
        $value = $this->withoutNulls([
            'interchange_version' => self::VERSION,
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
        $this->assertList($value, '', 1, 10_000);

        $events = [];
        foreach ($value as $index => $event) {
            $events[] = $this->mapLifecycleEvent($this->assertObject($event, "/{$index}"), "/{$index}");
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
    public function resultFromArray(array $value): Result
    {
        return $this->mapResult($value, '');
    }

    /** @return array<string, mixed> */
    public function resultToArray(Result $result): array
    {
        $citations = [];
        foreach ($result->citations as $citation) {
            $citations[] = $this->citationToArray($citation);
        }
        $value = $this->withoutNulls([
            'result_id' => $result->resultId,
            'slot_id' => $result->slotId,
            'attempt_id' => $result->attemptId,
            'content_format' => $result->contentFormat,
            'content' => $result->content,
            'semantic_facts' => $result->semanticFacts,
            'citations' => $citations,
            'provenance' => $result->provenance,
            'usage' => $result->usage === null ? null : $this->usageToArray($result->usage),
            'completed_at' => $result->completedAt,
            'extensions' => $result->extensions,
        ]);
        $this->mapResult($value, '');

        return $value;
    }

    /** @param array<string, mixed> $value */
    public function responseFromArray(array $value): Response
    {
        return $this->mapResponse($value, '');
    }

    /** @return array<string, mixed> */
    public function responseToArray(Response $response): array
    {
        $attempts = [];
        foreach ($response->attempts as $attempt) {
            $attempts[] = $this->attemptToArray($attempt);
        }
        $results = [];
        foreach ($response->results as $result) {
            $results[] = $this->resultToArray($result);
        }
        $errors = [];
        foreach ($response->errors as $error) {
            $errors[] = $this->errorToArray($error);
        }
        $value = $this->withoutNulls([
            'interchange_version' => self::VERSION,
            'message_type' => 'response',
            'request_id' => $response->requestId,
            'response_status' => $response->responseStatus->value,
            'emitted_at' => $response->emittedAt,
            'slots' => $response->slots,
            'attempts' => $attempts,
            'results' => $results,
            'errors' => $errors,
            'extensions' => $response->extensions,
        ]);
        $this->mapResponse($value, '');

        return $value;
    }

    /** @return array<string, mixed> */
    private function attemptToArray(Attempt $attempt): array
    {
        return $this->withoutNulls([
            'attempt_id' => $attempt->attemptId,
            'slot_id' => $attempt->slotId,
            'attempt_number' => $attempt->attemptNumber,
            'profile' => $attempt->profile,
            'started_at' => $attempt->startedAt,
            'replaces_attempt_id' => $attempt->replacesAttemptId,
            'candidate_id' => $attempt->candidateId,
            'usage' => $attempt->usage === null ? null : $this->usageToArray($attempt->usage),
            'extensions' => $attempt->extensions,
            'attempt_status' => $attempt->attemptStatus->value,
            'finished_at' => $attempt->finishedAt,
            'result_id' => $attempt->resultId,
            'error' => $attempt->error === null ? null : $this->errorToArray($attempt->error),
            'durable_handle' => $attempt->durableHandle === null ? null : $this->durableHandleToArray($attempt->durableHandle),
        ]);
    }

    /** @param array<string, mixed> $value */
    private function mapCitation(array $value, string $path): Citation
    {
        $this->assertKeys(
            $value,
            ['citation_id', 'source_kind', 'derivation', 'provenance'],
            ['citation_id', 'source_kind', 'source_category', 'dataset_id', 'derivation', 'url', 'title', 'snippet', 'provider_reference', 'location', 'retrieved_at', 'provenance', 'extensions'],
            $path,
        );

        $url = $this->optionalString($value, 'url', $path, 1, 4096);
        if ($url !== null) {
            $this->assertPattern($url, self::HTTP_URL_PATTERN, $this->child($path, 'url'), 'must use the strict HTTP(S) wire format');
        }

        $providerReference = $this->optionalOpaqueId($value, 'provider_reference', $path);
        if ($url === null && $providerReference === null) {
            $this->fail($this->child($path, 'url'), 'requires an HTTP(S) url or provider_reference');
        }

        $sourceCategory = $this->optionalString($value, 'source_category', $path, 1, 128);
        if ($sourceCategory !== null) {
            $this->assertPattern($sourceCategory, self::SNAKE_CASE_PATTERN, $this->child($path, 'source_category'), 'must be snake_case');
        }

        $retrievedAt = $this->optionalTimestamp($value, 'retrieved_at', $path);
        $extensions = $this->optionalExtensions($value, 'extensions', $path);

        return new Citation(
            citationId: $this->opaqueId($value['citation_id'], $this->child($path, 'citation_id')),
            sourceKind: $this->enum(SourceKind::class, $value['source_kind'], $this->child($path, 'source_kind')),
            derivation: $this->enum(CitationDerivation::class, $value['derivation'], $this->child($path, 'derivation')),
            provenance: $this->mapProvenance($this->assertObject($value['provenance'], $this->child($path, 'provenance')), $this->child($path, 'provenance')),
            sourceCategory: $sourceCategory,
            datasetId: $this->optionalOpaqueId($value, 'dataset_id', $path),
            url: $url,
            title: $this->optionalString($value, 'title', $path, 1, 1024),
            snippet: $this->optionalString($value, 'snippet', $path, 1, 8192),
            providerReference: $providerReference,
            location: $this->optionalString($value, 'location', $path, 1, 512),
            retrievedAt: $retrievedAt,
            extensions: $extensions,
        );
    }

    /** @param array<string, mixed> $value */
    private function mapSource(array $value, string $path): NormalizedSource
    {
        $this->assertKeys(
            $value,
            ['source_id', 'source_kind', 'citation_ids'],
            ['source_id', 'canonical_url', 'provider_reference', 'source_kind', 'source_category', 'dataset_id', 'publisher_id', 'publisher_name', 'citation_ids', 'correlation_keys', 'extensions'],
            $path,
        );

        $canonicalUrl = $this->optionalString($value, 'canonical_url', $path, 1, 4096);
        if ($canonicalUrl !== null) {
            $this->assertPattern($canonicalUrl, self::HTTP_URL_PATTERN, $this->child($path, 'canonical_url'), 'must use the strict HTTP(S) wire format');
        }
        $providerReference = $this->optionalOpaqueId($value, 'provider_reference', $path);
        if ($canonicalUrl === null && $providerReference === null) {
            $this->fail($this->child($path, 'canonical_url'), 'requires an HTTP(S) canonical_url or provider_reference');
        }

        $citationValues = $this->assertList($value['citation_ids'], $this->child($path, 'citation_ids'), 1, 1000);
        $citationIds = [];
        foreach ($citationValues as $index => $citationId) {
            $citationIds[] = $this->opaqueId($citationId, $this->child($path, "citation_ids/{$index}"));
        }

        $sourceCategory = $this->optionalString($value, 'source_category', $path, 1, 128);
        if ($sourceCategory !== null) {
            $this->assertPattern($sourceCategory, self::SNAKE_CASE_PATTERN, $this->child($path, 'source_category'), 'must be snake_case');
        }

        return new NormalizedSource(
            sourceId: $this->opaqueId($value['source_id'], $this->child($path, 'source_id')),
            sourceKind: $this->enum(SourceKind::class, $value['source_kind'], $this->child($path, 'source_kind')),
            citationIds: $citationIds,
            canonicalUrl: $canonicalUrl,
            providerReference: $providerReference,
            sourceCategory: $sourceCategory,
            datasetId: $this->optionalOpaqueId($value, 'dataset_id', $path),
            publisherId: $this->optionalOpaqueId($value, 'publisher_id', $path),
            publisherName: $this->optionalString($value, 'publisher_name', $path, 1, 512),
            correlationKeys: $this->optionalCorrelationKeys($value, 'correlation_keys', $path),
            extensions: $this->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    private function mapProvenance(array $value, string $path): CollectionProvenance
    {
        $this->assertKeys(
            $value,
            ['provider', 'access_mode', 'operator_id'],
            ['provider', 'access_mode', 'operator_id', 'collector_id', 'surface_id', 'surface_context', 'origin_key', 'correlation_keys', 'extensions'],
            $path,
        );

        $accessMode = $this->closedString($value['access_mode'], ['direct', 'brokered', 'collected'], $this->child($path, 'access_mode'));
        $surfaceContext = null;
        if (array_key_exists('surface_context', $value)) {
            $surfaceContext = $this->mapSurfaceContext(
                $this->assertObject($value['surface_context'], $this->child($path, 'surface_context')),
                $this->child($path, 'surface_context'),
            );
        }

        return new CollectionProvenance(
            provider: $this->mapProvider($this->assertObject($value['provider'], $this->child($path, 'provider')), $this->child($path, 'provider')),
            accessMode: $accessMode,
            operatorId: $this->opaqueId($value['operator_id'], $this->child($path, 'operator_id')),
            collectorId: $this->optionalOpaqueId($value, 'collector_id', $path),
            surfaceId: $this->optionalOpaqueId($value, 'surface_id', $path),
            surfaceContext: $surfaceContext,
            originKey: $this->optionalOpaqueId($value, 'origin_key', $path),
            correlationKeys: $this->optionalCorrelationKeys($value, 'correlation_keys', $path),
            extensions: $this->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    private function mapUsage(array $value, string $path): Usage
    {
        $this->assertKeys(
            $value,
            ['completeness'],
            ['input_tokens', 'output_tokens', 'total_tokens', 'billable_units', 'actual_cost', 'estimated_cost', 'completeness', 'extensions'],
            $path,
        );

        $billableUnits = null;
        if (array_key_exists('billable_units', $value)) {
            $billableUnits = [];
            foreach ($this->assertList($value['billable_units'], $this->child($path, 'billable_units'), 0, 32) as $index => $unit) {
                $unitPath = $this->child($path, "billable_units/{$index}");
                $unit = $this->assertObject($unit, $unitPath);
                $this->assertKeys($unit, ['unit', 'quantity_decimal', 'source'], ['unit', 'quantity_decimal', 'source'], $unitPath);
                $unitName = $this->boundedString($unit['unit'], $this->child($unitPath, 'unit'), 1, 128);
                $this->assertPattern($unitName, self::SNAKE_CASE_PATTERN, $this->child($unitPath, 'unit'), 'must be snake_case');
                $billableUnits[] = [
                    'unit' => $unitName,
                    'quantity_decimal' => $this->decimal($unit['quantity_decimal'], $this->child($unitPath, 'quantity_decimal')),
                    'source' => $this->closedString($unit['source'], ['provider_reported', 'computed', 'configured', 'estimated'], $this->child($unitPath, 'source')),
                ];
            }
        }

        $actualCost = array_key_exists('actual_cost', $value)
            ? $this->mapCost($this->assertObject($value['actual_cost'], $this->child($path, 'actual_cost')), $this->child($path, 'actual_cost'), true)
            : null;
        $estimatedCost = array_key_exists('estimated_cost', $value)
            ? $this->mapCost($this->assertObject($value['estimated_cost'], $this->child($path, 'estimated_cost')), $this->child($path, 'estimated_cost'), false)
            : null;

        return new Usage(
            completeness: $this->closedString($value['completeness'], ['complete', 'partial', 'unknown'], $this->child($path, 'completeness')),
            inputTokens: $this->optionalNonNegativeInteger($value, 'input_tokens', $path),
            outputTokens: $this->optionalNonNegativeInteger($value, 'output_tokens', $path),
            totalTokens: $this->optionalNonNegativeInteger($value, 'total_tokens', $path),
            billableUnits: $billableUnits,
            actualCost: $actualCost,
            estimatedCost: $estimatedCost,
            extensions: $this->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    private function mapError(array $value, string $path): StructuredError
    {
        $this->assertKeys(
            $value,
            ['code', 'message', 'category', 'retryable', 'fallback_allowed'],
            ['code', 'message', 'category', 'retryable', 'fallback_allowed', 'provider_code', 'field_path', 'details'],
            $path,
        );

        $code = $this->boundedString($value['code'], $this->child($path, 'code'), 1, 128);
        $this->assertPattern($code, self::SNAKE_CASE_PATTERN, $this->child($path, 'code'), 'must be snake_case');
        $details = null;
        if (array_key_exists('details', $value)) {
            $details = [];
            foreach ($this->assertList($value['details'], $this->child($path, 'details'), 0, 16) as $index => $detail) {
                $detailPath = $this->child($path, "details/{$index}");
                $detail = $this->assertObject($detail, $detailPath);
                $this->assertKeys($detail, ['detail_code', 'message'], ['detail_code', 'message', 'field_path', 'related_id'], $detailPath);
                $detailCode = $this->boundedString($detail['detail_code'], $this->child($detailPath, 'detail_code'), 1, 128);
                $this->assertPattern($detailCode, self::SNAKE_CASE_PATTERN, $this->child($detailPath, 'detail_code'), 'must be snake_case');
                $mappedDetail = [
                    'detail_code' => $detailCode,
                    'message' => $this->boundedString($detail['message'], $this->child($detailPath, 'message'), 1, 512),
                ];
                if (array_key_exists('field_path', $detail)) {
                    $mappedDetail['field_path'] = $this->jsonPointer($detail['field_path'], $this->child($detailPath, 'field_path'));
                }
                if (array_key_exists('related_id', $detail)) {
                    $mappedDetail['related_id'] = $this->opaqueId($detail['related_id'], $this->child($detailPath, 'related_id'));
                }
                $details[] = $mappedDetail;
            }
        }

        return new StructuredError(
            code: $code,
            message: $this->boundedString($value['message'], $this->child($path, 'message'), 1, 2048),
            category: $this->enum(ErrorCategory::class, $value['category'], $this->child($path, 'category')),
            retryable: $this->boolean($value['retryable'], $this->child($path, 'retryable')),
            fallbackAllowed: $this->boolean($value['fallback_allowed'], $this->child($path, 'fallback_allowed')),
            providerCode: $this->optionalString($value, 'provider_code', $path, 1, 128),
            fieldPath: array_key_exists('field_path', $value) ? $this->jsonPointer($value['field_path'], $this->child($path, 'field_path')) : null,
            details: $details,
        );
    }

    /** @param array<string, mixed> $value */
    private function mapDurableHandle(array $value, string $path): DurableHandle
    {
        $this->assertKeys(
            $value,
            ['handle_id', 'provider_task_id', 'provider', 'submitted_at', 'status'],
            ['handle_id', 'provider_task_id', 'provider', 'submitted_at', 'last_observed_at', 'status', 'extensions'],
            $path,
        );

        return new DurableHandle(
            handleId: $this->opaqueId($value['handle_id'], $this->child($path, 'handle_id')),
            providerTaskId: $this->opaqueId($value['provider_task_id'], $this->child($path, 'provider_task_id')),
            provider: $this->mapProvider($this->assertObject($value['provider'], $this->child($path, 'provider')), $this->child($path, 'provider')),
            submittedAt: $this->timestamp($value['submitted_at'], $this->child($path, 'submitted_at')),
            status: $this->enum(DurableHandleStatus::class, $value['status'], $this->child($path, 'status')),
            lastObservedAt: $this->optionalTimestamp($value, 'last_observed_at', $path),
            extensions: $this->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    private function mapLifecycleEvent(array $value, string $path): LifecycleEvent
    {
        $this->assertKeys(
            $value,
            ['interchange_version', 'message_type', 'event_id', 'request_id', 'sequence', 'occurred_at', 'event_kind', 'data'],
            ['interchange_version', 'message_type', 'event_id', 'request_id', 'sequence', 'occurred_at', 'slot_id', 'attempt_id', 'event_kind', 'data'],
            $path,
        );
        $this->assertLiteral($value['interchange_version'], self::VERSION, $this->child($path, 'interchange_version'));
        $this->assertLiteral($value['message_type'], 'lifecycle_event', $this->child($path, 'message_type'));

        $eventKind = $this->enum(LifecycleEventKind::class, $value['event_kind'], $this->child($path, 'event_kind'));
        $requiresAttempt = in_array($eventKind, [
            LifecycleEventKind::AttemptStarted,
            LifecycleEventKind::DurableTaskSubmitted,
            LifecycleEventKind::AttemptProgress,
            LifecycleEventKind::AttemptFinished,
            LifecycleEventKind::FallbackSelected,
        ], true);
        if ($requiresAttempt) {
            $this->assertKeysPresent($value, ['slot_id', 'attempt_id'], $path);
        } elseif (array_key_exists('slot_id', $value) || array_key_exists('attempt_id', $value)) {
            $this->fail($path, "{$eventKind->value} must omit slot_id and attempt_id");
        }

        $dataPath = $this->child($path, 'data');
        $data = $this->mapLifecycleData($eventKind, $this->assertObject($value['data'], $dataPath), $dataPath);

        return new LifecycleEvent(
            eventId: $this->opaqueId($value['event_id'], $this->child($path, 'event_id')),
            requestId: $this->opaqueId($value['request_id'], $this->child($path, 'request_id')),
            sequence: $this->nonNegativeInteger($value['sequence'], $this->child($path, 'sequence')),
            occurredAt: $this->timestamp($value['occurred_at'], $this->child($path, 'occurred_at')),
            eventKind: $eventKind,
            data: $data,
            slotId: $this->optionalOpaqueId($value, 'slot_id', $path),
            attemptId: $this->optionalOpaqueId($value, 'attempt_id', $path),
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

    /** @param array<string, mixed> $value */
    private function mapAttempt(array $value, string $path): Attempt
    {
        $this->assertKeysPresent($value, ['attempt_id', 'slot_id', 'attempt_number', 'profile', 'started_at', 'attempt_status'], $path);
        $status = $this->enum(AttemptStatus::class, $value['attempt_status'], $this->child($path, 'attempt_status'));
        $allowed = ['attempt_id', 'slot_id', 'attempt_number', 'profile', 'started_at', 'replaces_attempt_id', 'candidate_id', 'usage', 'extensions', 'attempt_status'];
        $required = [];
        $statusFields = match ($status) {
            AttemptStatus::Started => [],
            AttemptStatus::Submitted => ['durable_handle'],
            AttemptStatus::Succeeded => ['finished_at', 'result_id'],
            AttemptStatus::Failed, AttemptStatus::TimedOut => ['finished_at', 'error'],
            AttemptStatus::Cancelled => ['finished_at'],
        };
        $allowed = [...$allowed, ...$statusFields];
        if ($status === AttemptStatus::Succeeded || $status === AttemptStatus::Failed || $status === AttemptStatus::TimedOut || $status === AttemptStatus::Cancelled) {
            $allowed[] = 'durable_handle';
        }
        if ($status === AttemptStatus::Cancelled) {
            $allowed[] = 'error';
        }
        $this->assertKeys($value, $required, $allowed, $path);
        $this->assertKeysPresent($value, $statusFields, $path);

        $profilePath = $this->child($path, 'profile');
        $profile = $this->mapExecutionProfile($this->assertObject($value['profile'], $profilePath), $profilePath);
        $handle = array_key_exists('durable_handle', $value)
            ? $this->mapDurableHandle($this->assertObject($value['durable_handle'], $this->child($path, 'durable_handle')), $this->child($path, 'durable_handle'))
            : null;
        if ($handle !== null && ! $this->jsonValuesEqual($this->providerToArray($handle->provider), $profile['identity'])) {
            $this->fail($this->child($path, 'durable_handle/provider'), 'must match the attempt profile identity');
        }

        return new Attempt(
            attemptId: $this->opaqueId($value['attempt_id'], $this->child($path, 'attempt_id')),
            slotId: $this->opaqueId($value['slot_id'], $this->child($path, 'slot_id')),
            attemptNumber: $this->positiveInteger($value['attempt_number'], $this->child($path, 'attempt_number')),
            profile: $profile,
            startedAt: $this->timestamp($value['started_at'], $this->child($path, 'started_at')),
            attemptStatus: $status,
            replacesAttemptId: $this->optionalOpaqueId($value, 'replaces_attempt_id', $path),
            candidateId: $this->optionalOpaqueId($value, 'candidate_id', $path),
            finishedAt: $this->optionalTimestamp($value, 'finished_at', $path),
            resultId: $this->optionalOpaqueId($value, 'result_id', $path),
            error: array_key_exists('error', $value) ? $this->mapError($this->assertObject($value['error'], $this->child($path, 'error')), $this->child($path, 'error')) : null,
            durableHandle: $handle,
            usage: array_key_exists('usage', $value) ? $this->mapUsage($this->assertObject($value['usage'], $this->child($path, 'usage')), $this->child($path, 'usage')) : null,
            extensions: $this->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    private function mapResult(array $value, string $path): Result
    {
        $this->assertKeys(
            $value,
            ['result_id', 'slot_id', 'attempt_id', 'content_format', 'content', 'semantic_facts', 'citations', 'provenance', 'completed_at'],
            ['result_id', 'slot_id', 'attempt_id', 'content_format', 'content', 'semantic_facts', 'citations', 'provenance', 'usage', 'completed_at', 'extensions'],
            $path,
        );
        $citations = [];
        foreach ($this->assertList($value['citations'], $this->child($path, 'citations'), 0, 10_000) as $index => $citation) {
            $citationPath = $this->child($path, "citations/{$index}");
            $citations[] = $this->mapCitation($this->assertObject($citation, $citationPath), $citationPath);
        }
        $semanticFactsPath = $this->child($path, 'semantic_facts');
        $semanticFacts = $this->mapSemanticFacts($this->assertObject($value['semantic_facts'], $semanticFactsPath), $semanticFactsPath);
        $provenancePath = $this->child($path, 'provenance');
        $provenance = $this->mapResultProvenance($this->assertObject($value['provenance'], $provenancePath), $provenancePath);
        $this->validateResultSemantics($semanticFacts, $provenance, $path);

        return new Result(
            resultId: $this->opaqueId($value['result_id'], $this->child($path, 'result_id')),
            slotId: $this->opaqueId($value['slot_id'], $this->child($path, 'slot_id')),
            attemptId: $this->opaqueId($value['attempt_id'], $this->child($path, 'attempt_id')),
            contentFormat: $this->closedString($value['content_format'], ['plain_text', 'markdown'], $this->child($path, 'content_format')),
            content: $this->boundedString($value['content'], $this->child($path, 'content'), 1, 2_000_000),
            semanticFacts: $semanticFacts,
            citations: $citations,
            provenance: $provenance,
            completedAt: $this->timestamp($value['completed_at'], $this->child($path, 'completed_at')),
            usage: array_key_exists('usage', $value) ? $this->mapUsage($this->assertObject($value['usage'], $this->child($path, 'usage')), $this->child($path, 'usage')) : null,
            extensions: $this->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    private function mapResponse(array $value, string $path): Response
    {
        $this->assertKeys(
            $value,
            ['interchange_version', 'message_type', 'request_id', 'response_status', 'emitted_at', 'slots', 'attempts', 'results', 'errors'],
            ['interchange_version', 'message_type', 'request_id', 'response_status', 'emitted_at', 'slots', 'attempts', 'results', 'errors', 'extensions'],
            $path,
        );
        $this->assertLiteral($value['interchange_version'], self::VERSION, $this->child($path, 'interchange_version'));
        $this->assertLiteral($value['message_type'], 'response', $this->child($path, 'message_type'));

        $slots = [];
        foreach ($this->assertList($value['slots'], $this->child($path, 'slots'), 1, 64) as $index => $slot) {
            $slotPath = $this->child($path, "slots/{$index}");
            $slots[] = $this->mapSlot($this->assertObject($slot, $slotPath), $slotPath);
        }
        $attempts = [];
        foreach ($this->assertList($value['attempts'], $this->child($path, 'attempts'), 0, 256) as $index => $attempt) {
            $attemptPath = $this->child($path, "attempts/{$index}");
            $attempts[] = $this->mapAttempt($this->assertObject($attempt, $attemptPath), $attemptPath);
        }
        $results = [];
        foreach ($this->assertList($value['results'], $this->child($path, 'results'), 0, 64) as $index => $result) {
            $resultPath = $this->child($path, "results/{$index}");
            $results[] = $this->mapResult($this->assertObject($result, $resultPath), $resultPath);
        }
        $errors = [];
        foreach ($this->assertList($value['errors'], $this->child($path, 'errors'), 0, 64) as $index => $error) {
            $errorPath = $this->child($path, "errors/{$index}");
            $errors[] = $this->mapError($this->assertObject($error, $errorPath), $errorPath);
        }

        $response = new Response(
            requestId: $this->opaqueId($value['request_id'], $this->child($path, 'request_id')),
            responseStatus: $this->enum(ResponseStatus::class, $value['response_status'], $this->child($path, 'response_status')),
            emittedAt: $this->timestamp($value['emitted_at'], $this->child($path, 'emitted_at')),
            slots: $slots,
            attempts: $attempts,
            results: $results,
            errors: $errors,
            extensions: $this->optionalExtensions($value, 'extensions', $path),
        );
        $this->validateResponseReferences($response, $path);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapRequestStartedData(array $data, string $path): array
    {
        $this->assertKeys($data, ['mode'], ['mode'], $path);

        return ['mode' => $this->closedString($data['mode'], ['sync', 'async'], $this->child($path, 'mode'))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapAttemptStartedData(array $data, string $path): array
    {
        $this->assertKeys($data, ['provider', 'attempt_number'], ['provider', 'attempt_number'], $path);

        return [
            'provider' => $this->providerToArray($this->mapProvider($this->assertObject($data['provider'], $this->child($path, 'provider')), $this->child($path, 'provider'))),
            'attempt_number' => $this->positiveInteger($data['attempt_number'], $this->child($path, 'attempt_number')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapDurableSubmittedData(array $data, string $path): array
    {
        $this->assertKeys($data, ['handle'], ['handle'], $path);

        return ['handle' => $this->durableHandleToArray($this->mapDurableHandle($this->assertObject($data['handle'], $this->child($path, 'handle')), $this->child($path, 'handle')))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapProgressData(array $data, string $path): array
    {
        $this->assertKeys($data, ['progress_percent'], ['progress_percent', 'message'], $path);
        $progress = $this->nonNegativeInteger($data['progress_percent'], $this->child($path, 'progress_percent'));
        if ($progress > 100) {
            $this->fail($this->child($path, 'progress_percent'), 'must be at most 100');
        }

        return $this->withoutNulls([
            'progress_percent' => $progress,
            'message' => $this->optionalString($data, 'message', $path, 1, 512),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapAttemptFinishedData(array $data, string $path): array
    {
        $this->assertKeysPresent($data, ['outcome'], $path);
        $outcome = $this->closedString($data['outcome'], ['succeeded', 'failed', 'timed_out', 'cancelled'], $this->child($path, 'outcome'));
        $errorRequired = $outcome === 'failed' || $outcome === 'timed_out';
        $allowed = $outcome === 'succeeded' ? ['outcome'] : ['outcome', 'error'];
        $this->assertKeys($data, $errorRequired ? ['error'] : [], $allowed, $path);

        return $this->withoutNulls([
            'outcome' => $outcome,
            'error' => array_key_exists('error', $data)
                ? $this->errorToArray($this->mapError($this->assertObject($data['error'], $this->child($path, 'error')), $this->child($path, 'error')))
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapFallbackData(array $data, string $path): array
    {
        $this->assertKeys($data, ['failed_attempt_id', 'replacement_attempt_id', 'candidate_id'], ['failed_attempt_id', 'replacement_attempt_id', 'candidate_id'], $path);

        return [
            'failed_attempt_id' => $this->opaqueId($data['failed_attempt_id'], $this->child($path, 'failed_attempt_id')),
            'replacement_attempt_id' => $this->opaqueId($data['replacement_attempt_id'], $this->child($path, 'replacement_attempt_id')),
            'candidate_id' => $this->opaqueId($data['candidate_id'], $this->child($path, 'candidate_id')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapRequestCompletedData(array $data, string $path): array
    {
        $this->assertKeys($data, ['outcome'], ['outcome'], $path);

        return ['outcome' => $this->closedString($data['outcome'], ['succeeded', 'partial', 'unsuccessful'], $this->child($path, 'outcome'))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapRequestFailedData(array $data, string $path): array
    {
        $this->assertKeys($data, ['error'], ['error'], $path);

        return ['error' => $this->errorToArray($this->mapError($this->assertObject($data['error'], $this->child($path, 'error')), $this->child($path, 'error')))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapRequestCancelledData(array $data, string $path): array
    {
        $this->assertKeys($data, [], ['error'], $path);

        return $this->withoutNulls([
            'error' => array_key_exists('error', $data)
                ? $this->errorToArray($this->mapError($this->assertObject($data['error'], $this->child($path, 'error')), $this->child($path, 'error')))
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{amount: array{amount_decimal: string, currency: string}, source: string, pricing_version?: string}
     */
    private function mapCost(array $value, string $path, bool $actual): array
    {
        $this->assertKeys($value, ['amount', 'source'], ['amount', 'source', 'pricing_version'], $path);
        $amountPath = $this->child($path, 'amount');
        $amount = $this->assertObject($value['amount'], $amountPath);
        $this->assertKeys($amount, ['amount_decimal', 'currency'], ['amount_decimal', 'currency'], $amountPath);
        $source = $this->closedString(
            $value['source'],
            ['provider_reported', 'computed_from_tokens', 'computed_from_request', 'computed_from_credits', 'account_usage_delta', 'configured', 'pricing_snapshot'],
            $this->child($path, 'source'),
        );
        if ($actual && in_array($source, ['configured', 'pricing_snapshot'], true)) {
            $this->fail($this->child($path, 'source'), 'must identify reported or computed actual-cost provenance');
        }
        if (! $actual && ! in_array($source, ['configured', 'pricing_snapshot'], true)) {
            $this->fail($this->child($path, 'source'), 'must identify configured or pricing_snapshot provenance');
        }
        if (! $actual && $source === 'pricing_snapshot' && ! array_key_exists('pricing_version', $value)) {
            $this->fail($this->child($path, 'pricing_version'), 'is required for pricing_snapshot estimates');
        }

        $currency = $this->boundedString($amount['currency'], $this->child($amountPath, 'currency'), 3, 3);
        $this->assertPattern($currency, '/\A[A-Z]{3}\z/D', $this->child($amountPath, 'currency'), 'must be a three-letter uppercase currency');
        $mapped = [
            'amount' => [
                'amount_decimal' => $this->decimal($amount['amount_decimal'], $this->child($amountPath, 'amount_decimal')),
                'currency' => $currency,
            ],
            'source' => $source,
        ];
        if (array_key_exists('pricing_version', $value)) {
            $version = $this->boundedString($value['pricing_version'], $this->child($path, 'pricing_version'), 5, 128);
            $this->assertPattern($version, '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $this->child($path, 'pricing_version'), 'must be major.minor.patch');
            $mapped['pricing_version'] = $version;
        }

        return $mapped;
    }

    /** @param array<string, mixed> $value */
    private function mapProvider(array $value, string $path): ProviderIdentity
    {
        $this->assertKeys($value, ['provider_id', 'profile_id'], ['provider_id', 'profile_id', 'model_id'], $path);

        return new ProviderIdentity(
            providerId: $this->opaqueId($value['provider_id'], $this->child($path, 'provider_id')),
            profileId: $this->opaqueId($value['profile_id'], $this->child($path, 'profile_id')),
            modelId: $this->optionalOpaqueId($value, 'model_id', $path),
        );
    }

    /** @return array{provider_id: string, profile_id: string, model_id?: string} */
    private function providerToArray(ProviderIdentity $provider): array
    {
        /** @var array{provider_id: string, profile_id: string, model_id?: string} */
        return $this->withoutNulls([
            'provider_id' => $provider->providerId,
            'profile_id' => $provider->profileId,
            'model_id' => $provider->modelId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapSurfaceContext(array $value, string $path): array
    {
        $this->assertKeys($value, ['account_context', 'personalization'], ['account_context', 'locale', 'country', 'device', 'personalization', 'extensions'], $path);
        $country = $this->optionalString($value, 'country', $path, 2, 2);
        if ($country !== null) {
            $this->assertPattern($country, '/\A[A-Z]{2}\z/D', $this->child($path, 'country'), 'must be a two-letter uppercase country');
        }

        return $this->withoutNulls([
            'account_context' => $this->closedString($value['account_context'], ['anonymous', 'authenticated', 'managed', 'unknown'], $this->child($path, 'account_context')),
            'locale' => $this->optionalString($value, 'locale', $path, 1, 64),
            'country' => $country,
            'device' => $this->optionalString($value, 'device', $path, 1, 128),
            'personalization' => $this->closedString($value['personalization'], ['present', 'absent', 'unknown'], $this->child($path, 'personalization')),
            'extensions' => $this->optionalExtensions($value, 'extensions', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapExecutionProfile(array $value, string $path): array
    {
        $this->assertKeys(
            $value,
            ['identity', 'result_kind', 'observation_mode', 'corpora', 'retrieval_method', 'access_mode', 'operator_id', 'invocation', 'resumability'],
            ['identity', 'result_kind', 'grounding_policy', 'observation_mode', 'corpora', 'retrieval_method', 'access_mode', 'operator_id', 'collector_id', 'surface_id', 'surface_context', 'invocation', 'resumability', 'extensions'],
            $path,
        );
        $resultKind = $this->closedString($value['result_kind'], ['search_results', 'grounded_answer', 'research_report', 'model_answer', 'surface_observation'], $this->child($path, 'result_kind'));
        $groundingPolicy = array_key_exists('grounding_policy', $value)
            ? $this->closedString($value['grounding_policy'], ['required', 'optional', 'none'], $this->child($path, 'grounding_policy'))
            : null;
        if ($resultKind === 'search_results' && $groundingPolicy !== null) {
            $this->fail($this->child($path, 'grounding_policy'), 'must be omitted for search_results');
        }
        if ($resultKind !== 'search_results' && $groundingPolicy === null) {
            $this->fail($this->child($path, 'grounding_policy'), 'is required for non-search profiles');
        }
        $corpora = $this->closedStringList($value['corpora'], ['web', 'news', 'x', 'files', 'places', 'specialized'], $this->child($path, 'corpora'), 0, 8);
        $retrievalMethod = $this->closedString($value['retrieval_method'], ['search_endpoint', 'model_search_tool', 'research_agent', 'surface_collector', 'model_only'], $this->child($path, 'retrieval_method'));
        $accessMode = $this->closedString($value['access_mode'], ['direct', 'brokered', 'collected'], $this->child($path, 'access_mode'));
        $observationMode = $this->closedString($value['observation_mode'], ['api_output', 'surface_snapshot'], $this->child($path, 'observation_mode'));
        $invocation = $this->closedString($value['invocation'], ['inline', 'background'], $this->child($path, 'invocation'));
        $resumability = $this->closedString($value['resumability'], ['none', 'process_local', 'durable'], $this->child($path, 'resumability'));
        if ($invocation === 'inline' && $resumability !== 'none') {
            $this->fail($this->child($path, 'resumability'), 'must be none for inline profiles');
        }
        if ($invocation === 'background' && $resumability === 'none') {
            $this->fail($this->child($path, 'resumability'), 'must be process_local or durable for background profiles');
        }
        $surfaceId = $this->optionalOpaqueId($value, 'surface_id', $path);
        $collectorId = $this->optionalOpaqueId($value, 'collector_id', $path);
        if ($resultKind === 'surface_observation' && $surfaceId === null) {
            $this->fail($this->child($path, 'surface_id'), 'is required for surface observations');
        }
        if ($observationMode === 'surface_snapshot') {
            if ($resultKind !== 'surface_observation') {
                $this->fail($this->child($path, 'result_kind'), 'must be surface_observation for surface snapshots');
            }
            if ($retrievalMethod !== 'surface_collector') {
                $this->fail($this->child($path, 'retrieval_method'), 'must be surface_collector for surface snapshots');
            }
            if ($collectorId === null) {
                $this->fail($this->child($path, 'collector_id'), 'is required for surface snapshots');
            }
            if ($accessMode !== 'collected') {
                $this->fail($this->child($path, 'access_mode'), 'must be collected for surface snapshots');
            }
        }

        return $this->withoutNulls([
            'identity' => $this->providerToArray($this->mapProvider($this->assertObject($value['identity'], $this->child($path, 'identity')), $this->child($path, 'identity'))),
            'result_kind' => $resultKind,
            'grounding_policy' => $groundingPolicy,
            'observation_mode' => $observationMode,
            'corpora' => $corpora,
            'retrieval_method' => $retrievalMethod,
            'access_mode' => $accessMode,
            'operator_id' => $this->opaqueId($value['operator_id'], $this->child($path, 'operator_id')),
            'collector_id' => $collectorId,
            'surface_id' => $surfaceId,
            'surface_context' => array_key_exists('surface_context', $value)
                ? $this->mapSurfaceContext($this->assertObject($value['surface_context'], $this->child($path, 'surface_context')), $this->child($path, 'surface_context'))
                : null,
            'invocation' => $invocation,
            'resumability' => $resumability,
            'extensions' => $this->optionalExtensions($value, 'extensions', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapSemanticFacts(array $value, string $path): array
    {
        $this->assertKeys(
            $value,
            ['result_kinds', 'observation_mode', 'corpora', 'retrieval_methods', 'observed_at'],
            ['result_kinds', 'grounding_outcome', 'observation_mode', 'corpora', 'retrieval_methods', 'observed_at', 'measured_surface_id', 'surface_context', 'extensions'],
            $path,
        );
        $resultKinds = $this->closedStringList($value['result_kinds'], ['search_results', 'grounded_answer', 'research_report', 'model_answer', 'surface_observation'], $this->child($path, 'result_kinds'), 1, 8);
        $groundingOutcome = array_key_exists('grounding_outcome', $value)
            ? $this->closedString($value['grounding_outcome'], ['used', 'not_used', 'unknown'], $this->child($path, 'grounding_outcome'))
            : null;
        $searchOnly = count(array_filter($resultKinds, static fn (string $kind): bool => $kind !== 'search_results')) === 0;
        if ($searchOnly && $groundingOutcome !== null) {
            $this->fail($this->child($path, 'grounding_outcome'), 'must be omitted for search-results-only facts');
        }
        if (! $searchOnly && $groundingOutcome === null) {
            $this->fail($this->child($path, 'grounding_outcome'), 'is required for answer and observation facts');
        }

        return $this->withoutNulls([
            'result_kinds' => $resultKinds,
            'grounding_outcome' => $groundingOutcome,
            'observation_mode' => $this->closedString($value['observation_mode'], ['api_output', 'surface_snapshot'], $this->child($path, 'observation_mode')),
            'corpora' => $this->closedStringList($value['corpora'], ['web', 'news', 'x', 'files', 'places', 'specialized'], $this->child($path, 'corpora'), 0, 8),
            'retrieval_methods' => $this->closedStringList($value['retrieval_methods'], ['search_endpoint', 'model_search_tool', 'research_agent', 'surface_collector', 'model_only'], $this->child($path, 'retrieval_methods'), 1, 8),
            'observed_at' => $this->timestamp($value['observed_at'], $this->child($path, 'observed_at')),
            'measured_surface_id' => $this->optionalOpaqueId($value, 'measured_surface_id', $path),
            'surface_context' => array_key_exists('surface_context', $value)
                ? $this->mapSurfaceContext($this->assertObject($value['surface_context'], $this->child($path, 'surface_context')), $this->child($path, 'surface_context'))
                : null,
            'extensions' => $this->optionalExtensions($value, 'extensions', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapResultProvenance(array $value, string $path): array
    {
        $this->assertKeys(
            $value,
            ['request_id', 'slot_id', 'attempt_id', 'requested_profile', 'effective_profile', 'collection'],
            ['request_id', 'slot_id', 'attempt_id', 'requested_profile', 'effective_profile', 'collection', 'replaced_attempt_id', 'extensions'],
            $path,
        );

        return $this->withoutNulls([
            'request_id' => $this->opaqueId($value['request_id'], $this->child($path, 'request_id')),
            'slot_id' => $this->opaqueId($value['slot_id'], $this->child($path, 'slot_id')),
            'attempt_id' => $this->opaqueId($value['attempt_id'], $this->child($path, 'attempt_id')),
            'requested_profile' => $this->mapExecutionProfile($this->assertObject($value['requested_profile'], $this->child($path, 'requested_profile')), $this->child($path, 'requested_profile')),
            'effective_profile' => $this->mapExecutionProfile($this->assertObject($value['effective_profile'], $this->child($path, 'effective_profile')), $this->child($path, 'effective_profile')),
            'collection' => $this->provenanceToArray($this->mapProvenance($this->assertObject($value['collection'], $this->child($path, 'collection')), $this->child($path, 'collection'))),
            'replaced_attempt_id' => $this->optionalOpaqueId($value, 'replaced_attempt_id', $path),
            'extensions' => $this->optionalExtensions($value, 'extensions', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapSlot(array $value, string $path): array
    {
        $this->assertKeysPresent($value, ['slot_id', 'slot_status'], $path);
        $status = $this->closedString($value['slot_status'], ['pending', 'succeeded', 'failed', 'cancelled'], $this->child($path, 'slot_status'));
        $required = match ($status) {
            'succeeded' => ['selected_attempt_id', 'result_id'],
            'failed' => ['error'],
            default => [],
        };
        $allowed = match ($status) {
            'pending' => ['slot_id', 'slot_status', 'selected_attempt_id', 'extensions'],
            'succeeded' => ['slot_id', 'slot_status', 'selected_attempt_id', 'result_id', 'extensions'],
            'failed', 'cancelled' => ['slot_id', 'slot_status', 'selected_attempt_id', 'error', 'extensions'],
            default => [],
        };
        $this->assertKeys($value, $required, $allowed, $path);

        return $this->withoutNulls([
            'slot_id' => $this->opaqueId($value['slot_id'], $this->child($path, 'slot_id')),
            'slot_status' => $status,
            'selected_attempt_id' => $this->optionalOpaqueId($value, 'selected_attempt_id', $path),
            'result_id' => $this->optionalOpaqueId($value, 'result_id', $path),
            'error' => array_key_exists('error', $value)
                ? $this->errorToArray($this->mapError($this->assertObject($value['error'], $this->child($path, 'error')), $this->child($path, 'error')))
                : null,
            'extensions' => $this->optionalExtensions($value, 'extensions', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $provenance
     */
    private function validateResultSemantics(array $facts, array $provenance, string $path): void
    {
        /** @var array<string, mixed> $profile */
        $profile = $provenance['effective_profile'];
        /** @var list<string> $resultKinds */
        $resultKinds = $facts['result_kinds'];
        /** @var list<string> $retrievalMethods */
        $retrievalMethods = $facts['retrieval_methods'];
        /** @var list<string> $corpora */
        $corpora = $facts['corpora'];
        /** @var list<string> $profileCorpora */
        $profileCorpora = $profile['corpora'];
        if (! in_array($profile['result_kind'], $resultKinds, true)) {
            $this->fail($this->child($path, 'semantic_facts/result_kinds'), 'must include the effective profile result_kind');
        }
        if (! in_array($profile['retrieval_method'], $retrievalMethods, true)) {
            $this->fail($this->child($path, 'semantic_facts/retrieval_methods'), 'must include the effective profile retrieval_method');
        }
        foreach ($corpora as $index => $corpus) {
            if (! in_array($corpus, $profileCorpora, true)) {
                $this->fail($this->child($path, "semantic_facts/corpora/{$index}"), 'cannot claim a corpus outside the effective profile');
            }
        }
        if ($facts['observation_mode'] !== $profile['observation_mode']) {
            $this->fail($this->child($path, 'semantic_facts/observation_mode'), 'must match the effective profile observation_mode');
        }
        $profileSurface = $profile['surface_id'] ?? null;
        $measuredSurface = $facts['measured_surface_id'] ?? null;
        if ($profileSurface !== $measuredSurface) {
            $this->fail($this->child($path, 'semantic_facts/measured_surface_id'), 'must match the effective profile surface_id');
        }
        $groundingPolicy = $profile['grounding_policy'] ?? null;
        $groundingOutcome = $facts['grounding_outcome'] ?? null;
        if ($groundingPolicy === 'required' && $groundingOutcome !== 'used') {
            $this->fail($this->child($path, 'semantic_facts/grounding_outcome'), 'must be used when grounding is required');
        }
        if ($groundingPolicy === 'none' && $groundingOutcome === 'used') {
            $this->fail($this->child($path, 'semantic_facts/grounding_outcome'), 'cannot be used when grounding policy is none');
        }
    }

    private function validateResponseReferences(Response $response, string $path): void
    {
        /** @var array<string, array<string, mixed>> $slots */
        $slots = [];
        foreach ($response->slots as $index => $slot) {
            /** @var string $slotId */
            $slotId = $slot['slot_id'];
            if (array_key_exists($slotId, $slots)) {
                $this->fail($this->child($path, "slots/{$index}/slot_id"), 'must be unique');
            }
            $slots[$slotId] = $slot;
        }

        /** @var array<string, Attempt> $attempts */
        $attempts = [];
        /** @var array<string, true> $profileKeys */
        $profileKeys = [];
        /** @var array<string, true> $candidateIds */
        $candidateIds = [];
        foreach ($response->attempts as $index => $attempt) {
            if (array_key_exists($attempt->attemptId, $attempts)) {
                $this->fail($this->child($path, "attempts/{$index}/attempt_id"), 'must be unique');
            }
            if (! array_key_exists($attempt->slotId, $slots)) {
                $this->fail($this->child($path, "attempts/{$index}/slot_id"), 'references an unknown slot');
            }
            if ($attempt->candidateId !== null) {
                if (isset($candidateIds[$attempt->candidateId])) {
                    $this->fail($this->child($path, "attempts/{$index}/candidate_id"), 'fallback candidates may be consumed at most once per response');
                }
                $candidateIds[$attempt->candidateId] = true;
            }
            /** @var array<string, mixed> $identity */
            $identity = $attempt->profile['identity'];
            $profileKey = $this->opaqueId($identity['provider_id'], $this->child($path, "attempts/{$index}/profile/identity/provider_id"))
                ."\0"
                .$this->opaqueId($identity['profile_id'], $this->child($path, "attempts/{$index}/profile/identity/profile_id"));
            if (isset($profileKeys[$profileKey])) {
                $this->fail($this->child($path, "attempts/{$index}/profile/identity"), 'provider profiles may execute at most once per response');
            }
            $profileKeys[$profileKey] = true;
            if ($attempt->replacesAttemptId !== null) {
                $replaced = $attempts[$attempt->replacesAttemptId] ?? null;
                if ($replaced === null || $replaced->slotId !== $attempt->slotId) {
                    $this->fail($this->child($path, "attempts/{$index}/replaces_attempt_id"), 'must reference an earlier attempt in the same slot');
                }
                if (! in_array($replaced->attemptStatus, [AttemptStatus::Failed, AttemptStatus::TimedOut], true)) {
                    $this->fail($this->child($path, "attempts/{$index}/replaces_attempt_id"), 'may only replace a failed or timed-out attempt');
                }
                if ($replaced->error?->fallbackAllowed !== true) {
                    $this->fail($this->child($path, "attempts/{$index}/replaces_attempt_id"), 'requires fallback_allowed=true on the replaced error');
                }
            }
            $attempts[$attempt->attemptId] = $attempt;
        }

        $this->validateResponseStatus($response, $path);
        $this->validateSelectedAttemptStatuses($response, $attempts, $path);

        /** @var array<string, Result> $results */
        $results = [];
        foreach ($response->results as $index => $result) {
            if (array_key_exists($result->resultId, $results)) {
                $this->fail($this->child($path, "results/{$index}/result_id"), 'must be unique');
            }
            $attempt = $attempts[$result->attemptId] ?? null;
            if ($attempt?->attemptStatus !== AttemptStatus::Succeeded || $attempt->slotId !== $result->slotId || $attempt->resultId !== $result->resultId) {
                $this->fail($this->child($path, "results/{$index}/attempt_id"), 'must reference its matching succeeded attempt and slot');
            }
            if ($result->provenance['request_id'] !== $response->requestId || $result->provenance['slot_id'] !== $result->slotId || $result->provenance['attempt_id'] !== $result->attemptId) {
                $this->fail($this->child($path, "results/{$index}/provenance"), 'identifiers must match the enclosing response and result');
            }
            $slot = $slots[$result->slotId] ?? null;
            if (($slot['slot_status'] ?? null) !== 'succeeded' || ($slot['selected_attempt_id'] ?? null) !== $result->attemptId || ($slot['result_id'] ?? null) !== $result->resultId) {
                $this->fail($this->child($path, "results/{$index}/slot_id"), 'must be selected by its matching succeeded slot');
            }
            if (! $this->jsonValuesEqual($result->provenance['effective_profile'], $attempt->profile)) {
                $this->fail($this->child($path, "results/{$index}/provenance/effective_profile"), 'must match the producing attempt profile');
            }
            /** @var array<string, mixed> $collection */
            $collection = $result->provenance['collection'];
            if (! $this->jsonValuesEqual($collection['provider'], $attempt->profile['identity'])) {
                $this->fail($this->child($path, "results/{$index}/provenance/collection/provider"), 'must match the producing attempt provider');
            }
            if (($result->provenance['replaced_attempt_id'] ?? null) !== $attempt->replacesAttemptId) {
                $this->fail($this->child($path, "results/{$index}/provenance/replaced_attempt_id"), 'must match the attempt replacement chain');
            }
            $requestedAttempt = $attempt;
            while ($requestedAttempt->replacesAttemptId !== null) {
                $requestedAttempt = $attempts[$requestedAttempt->replacesAttemptId];
            }
            if (! $this->jsonValuesEqual($result->provenance['requested_profile'], $requestedAttempt->profile)) {
                $this->fail($this->child($path, "results/{$index}/provenance/requested_profile"), 'must match the first profile in the attempt chain');
            }
            $results[$result->resultId] = $result;
        }

        foreach ($response->attempts as $index => $attempt) {
            if ($attempt->attemptStatus === AttemptStatus::Succeeded) {
                $result = $attempt->resultId === null ? null : ($results[$attempt->resultId] ?? null);
                if ($result === null || $result->attemptId !== $attempt->attemptId || $result->slotId !== $attempt->slotId) {
                    $this->fail($this->child($path, "attempts/{$index}/result_id"), 'must identify the result produced by a succeeded attempt');
                }
            }
        }

        $this->validateSlotSelections($response, $attempts, $results, $path);
    }

    /** @param array<string, Attempt> $attempts */
    private function validateSelectedAttemptStatuses(Response $response, array $attempts, string $path): void
    {
        foreach ($response->slots as $index => $slot) {
            $selectedAttemptId = $slot['selected_attempt_id'] ?? null;
            if (! is_string($selectedAttemptId)) {
                continue;
            }
            $attempt = $attempts[$selectedAttemptId] ?? null;
            if ($attempt === null || $attempt->slotId !== $slot['slot_id']) {
                $this->fail($this->child($path, "slots/{$index}/selected_attempt_id"), 'must reference an attempt in the same slot');
            }
            $compatible = match ($slot['slot_status']) {
                'pending' => in_array($attempt->attemptStatus, [AttemptStatus::Started, AttemptStatus::Submitted], true),
                'succeeded' => $attempt->attemptStatus === AttemptStatus::Succeeded,
                'failed' => in_array($attempt->attemptStatus, [AttemptStatus::Failed, AttemptStatus::TimedOut], true),
                'cancelled' => $attempt->attemptStatus === AttemptStatus::Cancelled,
                default => false,
            };
            if (! $compatible) {
                $this->fail($this->child($path, "slots/{$index}/selected_attempt_id"), 'status must match the slot outcome status');
            }
        }
    }

    /**
     * @param  array<string, Attempt>  $attempts
     * @param  array<string, Result>  $results
     */
    private function validateSlotSelections(Response $response, array $attempts, array $results, string $path): void
    {
        foreach ($response->slots as $index => $slot) {
            $selectedAttemptId = $slot['selected_attempt_id'] ?? null;
            if (is_string($selectedAttemptId)) {
                $attempt = $attempts[$selectedAttemptId] ?? null;
                if ($attempt === null || $attempt->slotId !== $slot['slot_id']) {
                    $this->fail($this->child($path, "slots/{$index}/selected_attempt_id"), 'must reference an attempt in the same slot');
                }
                $compatible = match ($slot['slot_status']) {
                    'pending' => in_array($attempt->attemptStatus, [AttemptStatus::Started, AttemptStatus::Submitted], true),
                    'succeeded' => $attempt->attemptStatus === AttemptStatus::Succeeded,
                    'failed' => in_array($attempt->attemptStatus, [AttemptStatus::Failed, AttemptStatus::TimedOut], true),
                    'cancelled' => $attempt->attemptStatus === AttemptStatus::Cancelled,
                    default => false,
                };
                if (! $compatible) {
                    $this->fail($this->child($path, "slots/{$index}/selected_attempt_id"), 'status must match the slot outcome status');
                }
            }
            if ($slot['slot_status'] === 'succeeded') {
                /** @var string $resultId */
                $resultId = $slot['result_id'];
                $result = $results[$resultId] ?? null;
                $attempt = is_string($selectedAttemptId) ? $attempts[$selectedAttemptId] : null;
                if ($result === null || $attempt?->attemptStatus !== AttemptStatus::Succeeded || $result->slotId !== $slot['slot_id'] || $result->attemptId !== $selectedAttemptId || $attempt->resultId !== $resultId) {
                    $this->fail($this->child($path, "slots/{$index}/result_id"), 'must identify the result produced by the selected succeeded attempt');
                }
            }
        }
    }

    private function validateResponseStatus(Response $response, string $path): void
    {
        $statuses = [];
        foreach ($response->slots as $slot) {
            $statuses[] = $this->boundedString($slot['slot_status'], $this->child($path, 'slots'), 1, 32);
        }
        $valid = match ($response->responseStatus) {
            ResponseStatus::Partial => in_array('succeeded', $statuses, true)
                && (in_array('failed', $statuses, true) || in_array('cancelled', $statuses, true))
                && ! in_array('pending', $statuses, true),
            ResponseStatus::Succeeded => count(array_filter($statuses, static fn ($status): bool => $status !== 'succeeded')) === 0,
            ResponseStatus::Failed => count(array_filter($statuses, static fn ($status): bool => $status !== 'failed')) === 0,
            ResponseStatus::Cancelled => count(array_filter($statuses, static fn ($status): bool => $status !== 'cancelled')) === 0,
            ResponseStatus::Unsuccessful => in_array('failed', $statuses, true)
                && in_array('cancelled', $statuses, true)
                && count(array_filter($statuses, static fn ($status): bool => in_array($status, ['pending', 'succeeded'], true))) === 0,
            ResponseStatus::Pending => in_array('pending', $statuses, true),
        };
        if (! $valid) {
            $this->fail($this->child($path, 'response_status'), 'does not match the slot outcome statuses');
        }
    }

    /** @param list<LifecycleEvent> $events */
    private function validateLifecycleTrace(array $events): void
    {
        if ($events === []) {
            $this->fail('/', 'must contain at least one lifecycle event');
        }
        $requestId = $events[0]->requestId;
        $eventIds = [];
        $priorSequence = -1;
        $priorTimestamp = null;
        $terminalSeen = false;
        foreach ($events as $index => $event) {
            if ($event->requestId !== $requestId) {
                $this->fail("/{$index}/request_id", 'lifecycle traces may contain only one request_id');
            }
            if (isset($eventIds[$event->eventId])) {
                $this->fail("/{$index}/event_id", 'must be unique');
            }
            $eventIds[$event->eventId] = true;
            if ($event->sequence <= $priorSequence) {
                $this->fail("/{$index}/sequence", 'must increase monotonically');
            }
            $priorSequence = $event->sequence;
            $this->dateTime($event->occurredAt);
            if ($priorTimestamp !== null && $this->compareTimestamps($event->occurredAt, $priorTimestamp) < 0) {
                $this->fail("/{$index}/occurred_at", 'must not move backwards');
            }
            $priorTimestamp = $event->occurredAt;
            if ($terminalSeen) {
                $this->fail("/{$index}/event_kind", 'no event may follow a terminal request event');
            }
            $terminalSeen = in_array($event->eventKind, [LifecycleEventKind::RequestCompleted, LifecycleEventKind::RequestFailed, LifecycleEventKind::RequestCancelled], true);
        }
        if ($events[0]->eventKind !== LifecycleEventKind::RequestStarted) {
            $this->fail('/0/event_kind', 'lifecycle traces must begin with request_started');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, string>|null
     */
    private function optionalCorrelationKeys(array $value, string $key, string $path): ?array
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }
        $object = $this->assertObject($value[$key], $this->child($path, $key));
        if (count($object) > 32) {
            $this->fail($this->child($path, $key), 'must contain at most 32 entries');
        }
        $mapped = [];
        foreach ($object as $name => $correlationValue) {
            $namePath = $this->child($path, "{$key}/{$name}");
            $this->assertNamespacedKey($name, $namePath);
            $mapped[$name] = $this->boundedString($correlationValue, $namePath, 1, 512);
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function optionalExtensions(array $value, string $key, string $path): ?array
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }
        $extensionsPath = $this->child($path, $key);
        $extensions = $this->assertObject($value[$key], $extensionsPath);
        if (count($extensions) > 32) {
            $this->fail($extensionsPath, 'must contain at most 32 namespaced keys');
        }
        foreach ($extensions as $name => $extensionValue) {
            $namePath = $this->child($extensionsPath, $name);
            $this->assertNamespacedKey($name, $namePath);
            $this->assertExtensionKeySafe($name, $namePath);
            $this->assertJsonValue($extensionValue, $namePath, 1);
        }
        try {
            $encoded = json_encode($extensions, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            $this->fail($extensionsPath, 'must contain only JSON-safe values');
        }
        if (strlen($encoded) > 16_384) {
            $this->fail($extensionsPath, 'must serialize to at most 16384 UTF-8 bytes');
        }

        return $extensions;
    }

    private function assertJsonValue(mixed $value, string $path, int $depth): void
    {
        if ($depth > 6) {
            $this->fail($path, 'nesting exceeds 6 levels');
        }
        if (is_string($value)) {
            if (mb_strlen($value) > 8192) {
                $this->fail($path, 'strings must be at most 8192 characters');
            }

            return;
        }
        if (is_int($value)) {
            if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
                $this->fail($path, 'integers must be within the cross-language safe range');
            }

            return;
        }
        if (is_bool($value) || $value === null) {
            return;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                $this->fail($path, 'numbers must be finite');
            }

            return;
        }
        if (! is_array($value)) {
            $this->fail($path, 'must contain only JSON-safe values');
        }
        if (array_is_list($value)) {
            if (count($value) > 100) {
                $this->fail($path, 'arrays must contain at most 100 items');
            }
            foreach ($value as $index => $child) {
                $this->assertJsonValue($child, $this->child($path, (string) $index), $depth + 1);
            }

            return;
        }
        if (count($value) > 32) {
            $this->fail($path, 'objects must contain at most 32 keys');
        }
        foreach ($value as $key => $child) {
            if (! is_string($key)) {
                $this->fail($path, 'object keys must be strings');
            }
            $childPath = $this->literalChild($path, $key);
            $this->assertExtensionKeySafe($key, $childPath);
            $this->assertJsonValue($child, $childPath, $depth + 1);
        }
    }

    private function assertExtensionKeySafe(string $key, string $path): void
    {
        $normalized = $this->normalizeExtensionKey($key);
        $candidates = [$normalized, preg_replace('/_?\d+\z/', '', $normalized) ?? $normalized];
        $separator = strrpos($key, ':');
        if ($separator !== false) {
            $localName = $this->normalizeExtensionKey(substr($key, $separator + 1));
            $candidates[] = $localName;
            $candidates[] = preg_replace('/_?\d+\z/', '', $localName) ?? $localName;
        }
        foreach ($candidates as $candidate) {
            if ($this->isForbiddenExtensionName($candidate)) {
                $this->fail($path, 'must not contain secrets, credentials, raw responses, headers, stacks, or binary payloads');
            }
        }
    }

    private function normalizeExtensionKey(string $key): string
    {
        $normalized = preg_replace('/URLs/', '_urls', $key) ?? $key;
        $normalized = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $normalized) ?? $normalized;
        $normalized = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $normalized) ?? $normalized;
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z\d]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    private function isForbiddenExtensionName(string $key): bool
    {
        foreach (self::FORBIDDEN_EXTENSION_KEYS as $forbidden) {
            if ($key === $forbidden || str_ends_with($key, "_{$forbidden}")) {
                if (($forbidden === 'token' || $forbidden === 'tokens') && ! $this->hasSensitiveTokenSuffix($key)) {
                    continue;
                }

                return true;
            }
        }
        $fused = str_replace('_', '', $key);
        $benignToken = false;
        foreach (self::BENIGN_TOKEN_QUALIFIERS as $qualifier) {
            $fusedQualifier = str_replace('_', '', $qualifier);
            if (str_ends_with($fused, $fusedQualifier.'token') || str_ends_with($fused, $fusedQualifier.'tokens')) {
                $benignToken = true;
                break;
            }
        }
        if (! $benignToken && (str_ends_with($fused, 'token') || str_ends_with($fused, 'tokens'))) {
            return true;
        }
        foreach (self::FORBIDDEN_EXTENSION_KEYS as $forbidden) {
            $forbiddenFused = str_replace('_', '', $forbidden);
            if ($fused === $forbiddenFused || (strlen($forbiddenFused) >= 6 && $forbiddenFused !== 'tokens' && str_ends_with($fused, $forbiddenFused))) {
                return true;
            }
        }

        return false;
    }

    private function hasSensitiveTokenSuffix(string $key): bool
    {
        $words = explode('_', $key);
        $suffix = array_pop($words);
        if ($suffix !== 'token' && $suffix !== 'tokens') {
            return false;
        }
        $qualifier = implode('_', $words);
        foreach (self::BENIGN_TOKEN_QUALIFIERS as $benign) {
            if ($qualifier === $benign || str_ends_with($qualifier, "_{$benign}")) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $value */
    private function optionalOpaqueId(array $value, string $key, string $path): ?string
    {
        return array_key_exists($key, $value) ? $this->opaqueId($value[$key], $this->child($path, $key)) : null;
    }

    /** @param array<string, mixed> $value */
    private function optionalTimestamp(array $value, string $key, string $path): ?string
    {
        return array_key_exists($key, $value) ? $this->timestamp($value[$key], $this->child($path, $key)) : null;
    }

    /** @param array<string, mixed> $value */
    private function optionalString(array $value, string $key, string $path, int $minimum, int $maximum): ?string
    {
        return array_key_exists($key, $value)
            ? $this->boundedString($value[$key], $this->child($path, $key), $minimum, $maximum)
            : null;
    }

    /** @param array<string, mixed> $value */
    private function optionalNonNegativeInteger(array $value, string $key, string $path): ?int
    {
        return array_key_exists($key, $value)
            ? $this->nonNegativeInteger($value[$key], $this->child($path, $key))
            : null;
    }

    private function opaqueId(mixed $value, string $path): string
    {
        $identifier = $this->boundedString($value, $path, 1, 255);
        if (trim($identifier) !== $identifier || preg_match('/[\x00-\x1F\x7F]/u', $identifier) === 1) {
            $this->fail($path, 'must not contain surrounding whitespace or control characters');
        }

        return $identifier;
    }

    private function timestamp(mixed $value, string $path): string
    {
        $timestamp = $this->boundedString($value, $path, 20, 255);
        $this->assertPattern($timestamp, self::RFC3339_UTC_PATTERN, $path, 'must include seconds and use the RFC 3339 UTC Z form');
        $this->dateTime($timestamp, $path);

        return $timestamp;
    }

    private function dateTime(string $value, string $path = '/'): DateTimeImmutable
    {
        $base = substr($value, 0, 19).'Z';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $base);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $this->fail($path, 'must be a real RFC 3339 UTC date and time');
        }

        return $date;
    }

    private function compareTimestamps(string $left, string $right): int
    {
        $seconds = strcmp(substr($left, 0, 19), substr($right, 0, 19));
        if ($seconds !== 0) {
            return $seconds;
        }
        $leftFraction = $this->timestampFraction($left);
        $rightFraction = $this->timestampFraction($right);
        $length = max(strlen($leftFraction), strlen($rightFraction));

        return strcmp(str_pad($leftFraction, $length, '0'), str_pad($rightFraction, $length, '0'));
    }

    private function timestampFraction(string $timestamp): string
    {
        $dot = strpos($timestamp, '.');

        return $dot === false ? '' : substr($timestamp, $dot + 1, -1);
    }

    private function decimal(mixed $value, string $path): string
    {
        $decimal = $this->boundedString($value, $path, 1, 128);
        $this->assertPattern($decimal, self::DECIMAL_PATTERN, $path, 'must be a non-negative base-10 decimal string with at most 18 fractional digits');

        return $decimal;
    }

    private function jsonPointer(mixed $value, string $path): string
    {
        $pointer = $this->boundedString($value, $path, 0, 512);
        $this->assertPattern($pointer, self::JSON_POINTER_PATTERN, $path, 'must be an RFC 6901 JSON Pointer');

        return $pointer;
    }

    private function boundedString(mixed $value, string $path, int $minimum, int $maximum): string
    {
        if (! is_string($value)) {
            $this->fail($path, 'must be a string');
        }
        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum) {
            $this->fail($path, "must contain between {$minimum} and {$maximum} characters");
        }

        return $value;
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            $this->fail($path, 'must be a boolean');
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 0 || $value > self::MAX_SAFE_INTEGER) {
            $this->fail($path, 'must be a non-negative safe integer');
        }

        return $value;
    }

    private function positiveInteger(mixed $value, string $path): int
    {
        $integer = $this->nonNegativeInteger($value, $path);
        if ($integer === 0) {
            $this->fail($path, 'must be a positive safe integer');
        }

        return $integer;
    }

    /** @param list<string> $allowed */
    private function closedString(mixed $value, array $allowed, string $path): string
    {
        $string = $this->boundedString($value, $path, 1, 255);
        if (! in_array($string, $allowed, true)) {
            $this->fail($path, 'must be one of: '.implode(', ', $allowed));
        }

        return $string;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function closedStringList(mixed $value, array $allowed, string $path, int $minimum, int $maximum): array
    {
        $list = $this->assertList($value, $path, $minimum, $maximum);
        $mapped = [];
        foreach ($list as $index => $item) {
            $mapped[] = $this->closedString($item, $allowed, $this->child($path, (string) $index));
        }

        return $mapped;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enum(string $enum, mixed $value, string $path): \BackedEnum
    {
        $string = $this->boundedString($value, $path, 1, 255);
        $case = $enum::tryFrom($string);
        if ($case === null) {
            $allowed = array_map(static fn (\BackedEnum $item): string => (string) $item->value, $enum::cases());
            $this->fail($path, 'must be one of: '.implode(', ', $allowed));
        }

        return $case;
    }

    private function assertLiteral(mixed $value, string $expected, string $path): void
    {
        if ($value !== $expected) {
            $this->fail($path, "must equal {$expected}");
        }
    }

    private function assertPattern(string $value, string $pattern, string $path, string $message): void
    {
        if (preg_match($pattern, $value) !== 1) {
            $this->fail($path, $message);
        }
    }

    private function assertNamespacedKey(string $value, string $path): void
    {
        if (mb_strlen($value) < 3 || mb_strlen($value) > 128 || preg_match(self::NAMESPACED_KEY_PATTERN, $value) !== 1) {
            $this->fail($path, 'must be a namespaced key such as com.example:fieldName');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $required
     * @param  list<string>  $allowed
     */
    private function assertKeys(array $value, array $required, array $allowed, string $path): void
    {
        $this->assertKeysPresent($value, $required, $path);
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->fail($this->literalChild($path, $key), 'is an unknown semantic field');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $required
     */
    private function assertKeysPresent(array $value, array $required, string $path): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                $this->fail($this->child($path, $key), 'is required');
            }
        }
    }

    /** @return array<string, mixed> */
    private function assertObject(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($path, 'must be a JSON object');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<mixed> */
    private function assertList(mixed $value, string $path, int $minimum, int $maximum): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'must be a JSON array');
        }
        $count = count($value);
        if ($count < $minimum || $count > $maximum) {
            $this->fail($path, "must contain between {$minimum} and {$maximum} items");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function withoutNulls(array $value): array
    {
        return array_filter($value, static fn (mixed $item): bool => $item !== null);
    }

    private function jsonValuesEqual(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }
        if (array_is_list($left) || array_is_list($right)) {
            if (! array_is_list($left) || ! array_is_list($right) || count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $index => $value) {
                if (! $this->jsonValuesEqual($value, $right[$index])) {
                    return false;
                }
            }

            return true;
        }
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! $this->jsonValuesEqual($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    private function child(string $path, string $key): string
    {
        foreach (explode('/', $key) as $segment) {
            $path = $this->literalChild($path, $segment);
        }

        return $path;
    }

    private function literalChild(string $path, string $key): string
    {
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $key);

        return ($path === '' ? '' : $path).'/'.$escaped;
    }

    private function fail(string $path, string $message): never
    {
        throw new InterchangeValidationException($path === '' ? '/' : $path, $message);
    }
}
