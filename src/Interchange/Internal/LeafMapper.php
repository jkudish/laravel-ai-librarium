<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Internal;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\DurableHandleStatus;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\ErrorCategory;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Citation;
use Jkudish\LaravelAiLibrarium\Interchange\Values\CollectionProvenance;
use Jkudish\LaravelAiLibrarium\Interchange\Values\DurableHandle;
use Jkudish\LaravelAiLibrarium\Interchange\Values\NormalizedSource;
use Jkudish\LaravelAiLibrarium\Interchange\Values\ProviderIdentity;
use Jkudish\LaravelAiLibrarium\Interchange\Values\StructuredError;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Usage;

/** @internal */
final readonly class LeafMapper
{
    public function __construct(private WireValueReader $wire) {}

    /** @param array<string, mixed> $value */
    public function citationFromArray(array $value): Citation
    {
        return $this->mapCitation($value, '');
    }

    /** @return array<string, mixed> */
    public function citationToArray(Citation $citation): array
    {
        $value = $this->wire->withoutNulls([
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
        $value = $this->wire->withoutNulls([
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
        $value = $this->wire->withoutNulls([
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
        $value = $this->wire->withoutNulls([
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
        $value = $this->wire->withoutNulls([
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
        $value = $this->wire->withoutNulls([
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
    public function mapCitation(array $value, string $path): Citation
    {
        $this->wire->assertKeys(
            $value,
            ['citation_id', 'source_kind', 'derivation', 'provenance'],
            ['citation_id', 'source_kind', 'source_category', 'dataset_id', 'derivation', 'url', 'title', 'snippet', 'provider_reference', 'location', 'retrieved_at', 'provenance', 'extensions'],
            $path,
        );

        $url = $this->wire->optionalString($value, 'url', $path, 1, 4096);
        if ($url !== null) {
            $this->wire->assertPattern($url, WireValueReader::HTTP_URL_PATTERN, $this->wire->child($path, 'url'), 'must use the strict HTTP(S) wire format');
        }

        $providerReference = $this->wire->optionalOpaqueId($value, 'provider_reference', $path);
        if ($url === null && $providerReference === null) {
            $this->wire->fail($this->wire->child($path, 'url'), 'requires an HTTP(S) url or provider_reference');
        }

        $sourceCategory = $this->wire->optionalString($value, 'source_category', $path, 1, 128);
        if ($sourceCategory !== null) {
            $this->wire->assertPattern($sourceCategory, WireValueReader::SNAKE_CASE_PATTERN, $this->wire->child($path, 'source_category'), 'must be snake_case');
        }

        $retrievedAt = $this->wire->optionalTimestamp($value, 'retrieved_at', $path);
        $extensions = $this->wire->optionalExtensions($value, 'extensions', $path);

        return new Citation(
            citationId: $this->wire->opaqueId($value['citation_id'], $this->wire->child($path, 'citation_id')),
            sourceKind: $this->wire->enum(SourceKind::class, $value['source_kind'], $this->wire->child($path, 'source_kind')),
            derivation: $this->wire->enum(CitationDerivation::class, $value['derivation'], $this->wire->child($path, 'derivation')),
            provenance: $this->mapProvenance($this->wire->assertObject($value['provenance'], $this->wire->child($path, 'provenance')), $this->wire->child($path, 'provenance')),
            sourceCategory: $sourceCategory,
            datasetId: $this->wire->optionalOpaqueId($value, 'dataset_id', $path),
            url: $url,
            title: $this->wire->optionalString($value, 'title', $path, 1, 1024),
            snippet: $this->wire->optionalString($value, 'snippet', $path, 1, 8192),
            providerReference: $providerReference,
            location: $this->wire->optionalString($value, 'location', $path, 1, 512),
            retrievedAt: $retrievedAt,
            extensions: $extensions,
        );
    }

    /** @param array<string, mixed> $value */
    public function mapSource(array $value, string $path): NormalizedSource
    {
        $this->wire->assertKeys(
            $value,
            ['source_id', 'source_kind', 'citation_ids'],
            ['source_id', 'canonical_url', 'provider_reference', 'source_kind', 'source_category', 'dataset_id', 'publisher_id', 'publisher_name', 'citation_ids', 'correlation_keys', 'extensions'],
            $path,
        );

        $canonicalUrl = $this->wire->optionalString($value, 'canonical_url', $path, 1, 4096);
        if ($canonicalUrl !== null) {
            $this->wire->assertPattern($canonicalUrl, WireValueReader::HTTP_URL_PATTERN, $this->wire->child($path, 'canonical_url'), 'must use the strict HTTP(S) wire format');
        }
        $providerReference = $this->wire->optionalOpaqueId($value, 'provider_reference', $path);
        if ($canonicalUrl === null && $providerReference === null) {
            $this->wire->fail($this->wire->child($path, 'canonical_url'), 'requires an HTTP(S) canonical_url or provider_reference');
        }

        $citationValues = $this->wire->assertList($value['citation_ids'], $this->wire->child($path, 'citation_ids'), 1, 1000);
        $citationIds = [];
        foreach ($citationValues as $index => $citationId) {
            $citationIds[] = $this->wire->opaqueId($citationId, $this->wire->child($path, "citation_ids/{$index}"));
        }

        $sourceCategory = $this->wire->optionalString($value, 'source_category', $path, 1, 128);
        if ($sourceCategory !== null) {
            $this->wire->assertPattern($sourceCategory, WireValueReader::SNAKE_CASE_PATTERN, $this->wire->child($path, 'source_category'), 'must be snake_case');
        }

        return new NormalizedSource(
            sourceId: $this->wire->opaqueId($value['source_id'], $this->wire->child($path, 'source_id')),
            sourceKind: $this->wire->enum(SourceKind::class, $value['source_kind'], $this->wire->child($path, 'source_kind')),
            citationIds: $citationIds,
            canonicalUrl: $canonicalUrl,
            providerReference: $providerReference,
            sourceCategory: $sourceCategory,
            datasetId: $this->wire->optionalOpaqueId($value, 'dataset_id', $path),
            publisherId: $this->wire->optionalOpaqueId($value, 'publisher_id', $path),
            publisherName: $this->wire->optionalString($value, 'publisher_name', $path, 1, 512),
            correlationKeys: $this->wire->optionalCorrelationKeys($value, 'correlation_keys', $path),
            extensions: $this->wire->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    public function mapProvenance(array $value, string $path): CollectionProvenance
    {
        $this->wire->assertKeys(
            $value,
            ['provider', 'access_mode', 'operator_id'],
            ['provider', 'access_mode', 'operator_id', 'collector_id', 'surface_id', 'surface_context', 'origin_key', 'correlation_keys', 'extensions'],
            $path,
        );

        $accessMode = $this->wire->closedString($value['access_mode'], ['direct', 'brokered', 'collected'], $this->wire->child($path, 'access_mode'));
        $surfaceContext = null;
        if (array_key_exists('surface_context', $value)) {
            $surfaceContext = $this->mapSurfaceContext(
                $this->wire->assertObject($value['surface_context'], $this->wire->child($path, 'surface_context')),
                $this->wire->child($path, 'surface_context'),
            );
        }

        return new CollectionProvenance(
            provider: $this->mapProvider($this->wire->assertObject($value['provider'], $this->wire->child($path, 'provider')), $this->wire->child($path, 'provider')),
            accessMode: $accessMode,
            operatorId: $this->wire->opaqueId($value['operator_id'], $this->wire->child($path, 'operator_id')),
            collectorId: $this->wire->optionalOpaqueId($value, 'collector_id', $path),
            surfaceId: $this->wire->optionalOpaqueId($value, 'surface_id', $path),
            surfaceContext: $surfaceContext,
            originKey: $this->wire->optionalOpaqueId($value, 'origin_key', $path),
            correlationKeys: $this->wire->optionalCorrelationKeys($value, 'correlation_keys', $path),
            extensions: $this->wire->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    public function mapUsage(array $value, string $path): Usage
    {
        $this->wire->assertKeys(
            $value,
            ['completeness'],
            ['input_tokens', 'output_tokens', 'total_tokens', 'billable_units', 'actual_cost', 'estimated_cost', 'completeness', 'extensions'],
            $path,
        );

        $billableUnits = null;
        if (array_key_exists('billable_units', $value)) {
            $billableUnits = [];
            foreach ($this->wire->assertList($value['billable_units'], $this->wire->child($path, 'billable_units'), 0, 32) as $index => $unit) {
                $unitPath = $this->wire->child($path, "billable_units/{$index}");
                $unit = $this->wire->assertObject($unit, $unitPath);
                $this->wire->assertKeys($unit, ['unit', 'quantity_decimal', 'source'], ['unit', 'quantity_decimal', 'source'], $unitPath);
                $unitName = $this->wire->boundedString($unit['unit'], $this->wire->child($unitPath, 'unit'), 1, 128);
                $this->wire->assertPattern($unitName, WireValueReader::SNAKE_CASE_PATTERN, $this->wire->child($unitPath, 'unit'), 'must be snake_case');
                $billableUnits[] = [
                    'unit' => $unitName,
                    'quantity_decimal' => $this->wire->decimal($unit['quantity_decimal'], $this->wire->child($unitPath, 'quantity_decimal')),
                    'source' => $this->wire->closedString($unit['source'], ['provider_reported', 'computed', 'configured', 'estimated'], $this->wire->child($unitPath, 'source')),
                ];
            }
        }

        $actualCost = array_key_exists('actual_cost', $value)
            ? $this->mapCost($this->wire->assertObject($value['actual_cost'], $this->wire->child($path, 'actual_cost')), $this->wire->child($path, 'actual_cost'), true)
            : null;
        $estimatedCost = array_key_exists('estimated_cost', $value)
            ? $this->mapCost($this->wire->assertObject($value['estimated_cost'], $this->wire->child($path, 'estimated_cost')), $this->wire->child($path, 'estimated_cost'), false)
            : null;

        return new Usage(
            completeness: $this->wire->closedString($value['completeness'], ['complete', 'partial', 'unknown'], $this->wire->child($path, 'completeness')),
            inputTokens: $this->wire->optionalNonNegativeInteger($value, 'input_tokens', $path),
            outputTokens: $this->wire->optionalNonNegativeInteger($value, 'output_tokens', $path),
            totalTokens: $this->wire->optionalNonNegativeInteger($value, 'total_tokens', $path),
            billableUnits: $billableUnits,
            actualCost: $actualCost,
            estimatedCost: $estimatedCost,
            extensions: $this->wire->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    public function mapError(array $value, string $path): StructuredError
    {
        $this->wire->assertKeys(
            $value,
            ['code', 'message', 'category', 'retryable', 'fallback_allowed'],
            ['code', 'message', 'category', 'retryable', 'fallback_allowed', 'provider_code', 'field_path', 'details'],
            $path,
        );

        $code = $this->wire->boundedString($value['code'], $this->wire->child($path, 'code'), 1, 128);
        $this->wire->assertPattern($code, WireValueReader::SNAKE_CASE_PATTERN, $this->wire->child($path, 'code'), 'must be snake_case');
        $details = null;
        if (array_key_exists('details', $value)) {
            $details = [];
            foreach ($this->wire->assertList($value['details'], $this->wire->child($path, 'details'), 0, 16) as $index => $detail) {
                $detailPath = $this->wire->child($path, "details/{$index}");
                $detail = $this->wire->assertObject($detail, $detailPath);
                $this->wire->assertKeys($detail, ['detail_code', 'message'], ['detail_code', 'message', 'field_path', 'related_id'], $detailPath);
                $detailCode = $this->wire->boundedString($detail['detail_code'], $this->wire->child($detailPath, 'detail_code'), 1, 128);
                $this->wire->assertPattern($detailCode, WireValueReader::SNAKE_CASE_PATTERN, $this->wire->child($detailPath, 'detail_code'), 'must be snake_case');
                $mappedDetail = [
                    'detail_code' => $detailCode,
                    'message' => $this->wire->boundedString($detail['message'], $this->wire->child($detailPath, 'message'), 1, 512),
                ];
                if (array_key_exists('field_path', $detail)) {
                    $mappedDetail['field_path'] = $this->wire->jsonPointer($detail['field_path'], $this->wire->child($detailPath, 'field_path'));
                }
                if (array_key_exists('related_id', $detail)) {
                    $mappedDetail['related_id'] = $this->wire->opaqueId($detail['related_id'], $this->wire->child($detailPath, 'related_id'));
                }
                $details[] = $mappedDetail;
            }
        }

        return new StructuredError(
            code: $code,
            message: $this->wire->boundedString($value['message'], $this->wire->child($path, 'message'), 1, 2048),
            category: $this->wire->enum(ErrorCategory::class, $value['category'], $this->wire->child($path, 'category')),
            retryable: $this->wire->boolean($value['retryable'], $this->wire->child($path, 'retryable')),
            fallbackAllowed: $this->wire->boolean($value['fallback_allowed'], $this->wire->child($path, 'fallback_allowed')),
            providerCode: $this->wire->optionalString($value, 'provider_code', $path, 1, 128),
            fieldPath: array_key_exists('field_path', $value) ? $this->wire->jsonPointer($value['field_path'], $this->wire->child($path, 'field_path')) : null,
            details: $details,
        );
    }

    /** @param array<string, mixed> $value */
    public function mapDurableHandle(array $value, string $path): DurableHandle
    {
        $this->wire->assertKeys(
            $value,
            ['handle_id', 'provider_task_id', 'provider', 'submitted_at', 'status'],
            ['handle_id', 'provider_task_id', 'provider', 'submitted_at', 'last_observed_at', 'status', 'extensions'],
            $path,
        );

        return new DurableHandle(
            handleId: $this->wire->opaqueId($value['handle_id'], $this->wire->child($path, 'handle_id')),
            providerTaskId: $this->wire->opaqueId($value['provider_task_id'], $this->wire->child($path, 'provider_task_id')),
            provider: $this->mapProvider($this->wire->assertObject($value['provider'], $this->wire->child($path, 'provider')), $this->wire->child($path, 'provider')),
            submittedAt: $this->wire->timestamp($value['submitted_at'], $this->wire->child($path, 'submitted_at')),
            status: $this->wire->enum(DurableHandleStatus::class, $value['status'], $this->wire->child($path, 'status')),
            lastObservedAt: $this->wire->optionalTimestamp($value, 'last_observed_at', $path),
            extensions: $this->wire->optionalExtensions($value, 'extensions', $path),
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{amount: array{amount_decimal: string, currency: string}, source: string, pricing_version?: string}
     */
    public function mapCost(array $value, string $path, bool $actual): array
    {
        $this->wire->assertKeys($value, ['amount', 'source'], ['amount', 'source', 'pricing_version'], $path);
        $amountPath = $this->wire->child($path, 'amount');
        $amount = $this->wire->assertObject($value['amount'], $amountPath);
        $this->wire->assertKeys($amount, ['amount_decimal', 'currency'], ['amount_decimal', 'currency'], $amountPath);
        $source = $this->wire->closedString(
            $value['source'],
            ['provider_reported', 'computed_from_tokens', 'computed_from_request', 'computed_from_credits', 'account_usage_delta', 'configured', 'pricing_snapshot'],
            $this->wire->child($path, 'source'),
        );
        if ($actual && in_array($source, ['configured', 'pricing_snapshot'], true)) {
            $this->wire->fail($this->wire->child($path, 'source'), 'must identify reported or computed actual-cost provenance');
        }
        if (! $actual && ! in_array($source, ['configured', 'pricing_snapshot'], true)) {
            $this->wire->fail($this->wire->child($path, 'source'), 'must identify configured or pricing_snapshot provenance');
        }
        if (! $actual && $source === 'pricing_snapshot' && ! array_key_exists('pricing_version', $value)) {
            $this->wire->fail($this->wire->child($path, 'pricing_version'), 'is required for pricing_snapshot estimates');
        }

        $currency = $this->wire->boundedString($amount['currency'], $this->wire->child($amountPath, 'currency'), 3, 3);
        $this->wire->assertPattern($currency, '/\A[A-Z]{3}\z/D', $this->wire->child($amountPath, 'currency'), 'must be a three-letter uppercase currency');
        $mapped = [
            'amount' => [
                'amount_decimal' => $this->wire->decimal($amount['amount_decimal'], $this->wire->child($amountPath, 'amount_decimal')),
                'currency' => $currency,
            ],
            'source' => $source,
        ];
        if (array_key_exists('pricing_version', $value)) {
            $version = $this->wire->boundedString($value['pricing_version'], $this->wire->child($path, 'pricing_version'), 5, 128);
            $this->wire->assertPattern($version, '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $this->wire->child($path, 'pricing_version'), 'must be major.minor.patch');
            $mapped['pricing_version'] = $version;
        }

        return $mapped;
    }

    /** @param array<string, mixed> $value */
    public function mapProvider(array $value, string $path): ProviderIdentity
    {
        $this->wire->assertKeys($value, ['provider_id', 'profile_id'], ['provider_id', 'profile_id', 'model_id'], $path);

        return new ProviderIdentity(
            providerId: $this->wire->opaqueId($value['provider_id'], $this->wire->child($path, 'provider_id')),
            profileId: $this->wire->opaqueId($value['profile_id'], $this->wire->child($path, 'profile_id')),
            modelId: $this->wire->optionalOpaqueId($value, 'model_id', $path),
        );
    }

    /** @return array{provider_id: string, profile_id: string, model_id?: string} */
    public function providerToArray(ProviderIdentity $provider): array
    {
        /** @var array{provider_id: string, profile_id: string, model_id?: string} */
        return $this->wire->withoutNulls([
            'provider_id' => $provider->providerId,
            'profile_id' => $provider->profileId,
            'model_id' => $provider->modelId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function mapSurfaceContext(array $value, string $path): array
    {
        $this->wire->assertKeys($value, ['account_context', 'personalization'], ['account_context', 'locale', 'country', 'device', 'personalization', 'extensions'], $path);
        $country = $this->wire->optionalString($value, 'country', $path, 2, 2);
        if ($country !== null) {
            $this->wire->assertPattern($country, '/\A[A-Z]{2}\z/D', $this->wire->child($path, 'country'), 'must be a two-letter uppercase country');
        }

        return $this->wire->withoutNulls([
            'account_context' => $this->wire->closedString($value['account_context'], ['anonymous', 'authenticated', 'managed', 'unknown'], $this->wire->child($path, 'account_context')),
            'locale' => $this->wire->optionalString($value, 'locale', $path, 1, 64),
            'country' => $country,
            'device' => $this->wire->optionalString($value, 'device', $path, 1, 128),
            'personalization' => $this->wire->closedString($value['personalization'], ['present', 'absent', 'unknown'], $this->wire->child($path, 'personalization')),
            'extensions' => $this->wire->optionalExtensions($value, 'extensions', $path),
        ]);
    }
}
