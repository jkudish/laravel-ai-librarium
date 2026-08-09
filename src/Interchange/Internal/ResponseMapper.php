<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Internal;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\AttemptStatus;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Attempt;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Response;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Result;

/** @internal */
final readonly class ResponseMapper
{
    public function __construct(
        private WireValueReader $wire,
        private LeafMapper $leaf,
    ) {}

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
            $citations[] = $this->leaf->citationToArray($citation);
        }
        $value = $this->wire->withoutNulls([
            'result_id' => $result->resultId,
            'slot_id' => $result->slotId,
            'attempt_id' => $result->attemptId,
            'content_format' => $result->contentFormat,
            'content' => $result->content,
            'semantic_facts' => $result->semanticFacts,
            'citations' => $citations,
            'provenance' => $result->provenance,
            'usage' => $result->usage === null ? null : $this->leaf->usageToArray($result->usage),
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
            $errors[] = $this->leaf->errorToArray($error);
        }
        $value = $this->wire->withoutNulls([
            'interchange_version' => WireValueReader::VERSION,
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
        return $this->wire->withoutNulls([
            'attempt_id' => $attempt->attemptId,
            'slot_id' => $attempt->slotId,
            'attempt_number' => $attempt->attemptNumber,
            'profile' => $attempt->profile,
            'started_at' => $attempt->startedAt,
            'replaces_attempt_id' => $attempt->replacesAttemptId,
            'candidate_id' => $attempt->candidateId,
            'usage' => $attempt->usage === null ? null : $this->leaf->usageToArray($attempt->usage),
            'extensions' => $attempt->extensions,
            'attempt_status' => $attempt->attemptStatus->value,
            'finished_at' => $attempt->finishedAt,
            'result_id' => $attempt->resultId,
            'error' => $attempt->error === null ? null : $this->leaf->errorToArray($attempt->error),
            'durable_handle' => $attempt->durableHandle === null ? null : $this->leaf->durableHandleToArray($attempt->durableHandle),
        ]);
    }

    /** @param array<string, mixed> $value */
    private function mapAttempt(array $value, string $path): Attempt
    {
        $this->wire->assertKeysPresent($value, ['attempt_id', 'slot_id', 'attempt_number', 'profile', 'started_at', 'attempt_status'], $path);
        $status = $this->wire->enum(AttemptStatus::class, $value['attempt_status'], $this->wire->child($path, 'attempt_status'));
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
        $this->wire->assertKeys($value, $required, $allowed, $path);
        $this->wire->assertKeysPresent($value, $statusFields, $path);

        $profilePath = $this->wire->child($path, 'profile');
        $profile = $this->mapExecutionProfile($this->wire->assertObject($value['profile'], $profilePath), $profilePath);
        $handle = array_key_exists('durable_handle', $value)
            ? $this->leaf->mapDurableHandle($this->wire->assertObject($value['durable_handle'], $this->wire->child($path, 'durable_handle')), $this->wire->child($path, 'durable_handle'))
            : null;
        if ($handle !== null && ! $this->wire->jsonValuesEqual($this->leaf->providerToArray($handle->provider), $profile['identity'])) {
            $this->wire->fail($this->wire->child($path, 'durable_handle/provider'), 'must match the attempt profile identity');
        }

        return new Attempt(
            attemptId: $this->wire->opaqueId($value['attempt_id'], $this->wire->child($path, 'attempt_id')),
            slotId: $this->wire->opaqueId($value['slot_id'], $this->wire->child($path, 'slot_id')),
            attemptNumber: $this->wire->positiveInteger($value['attempt_number'], $this->wire->child($path, 'attempt_number')),
            profile: $profile,
            startedAt: $this->wire->timestamp($value['started_at'], $this->wire->child($path, 'started_at')),
            attemptStatus: $status,
            replacesAttemptId: $this->wire->optionalOpaqueId($value, 'replaces_attempt_id', $path),
            candidateId: $this->wire->optionalOpaqueId($value, 'candidate_id', $path),
            finishedAt: $this->wire->optionalTimestamp($value, 'finished_at', $path),
            resultId: $this->wire->optionalOpaqueId($value, 'result_id', $path),
            error: array_key_exists('error', $value) ? $this->leaf->mapError($this->wire->assertObject($value['error'], $this->wire->child($path, 'error')), $this->wire->child($path, 'error')) : null,
            durableHandle: $handle,
            usage: array_key_exists('usage', $value) ? $this->leaf->mapUsage($this->wire->assertObject($value['usage'], $this->wire->child($path, 'usage')), $this->wire->child($path, 'usage')) : null,
            extensions: $this->wire->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    private function mapResult(array $value, string $path): Result
    {
        $this->wire->assertKeys(
            $value,
            ['result_id', 'slot_id', 'attempt_id', 'content_format', 'content', 'semantic_facts', 'citations', 'provenance', 'completed_at'],
            ['result_id', 'slot_id', 'attempt_id', 'content_format', 'content', 'semantic_facts', 'citations', 'provenance', 'usage', 'completed_at', 'extensions'],
            $path,
        );
        $citations = [];
        foreach ($this->wire->assertList($value['citations'], $this->wire->child($path, 'citations'), 0, 10_000) as $index => $citation) {
            $citationPath = $this->wire->child($path, "citations/{$index}");
            $citations[] = $this->leaf->mapCitation($this->wire->assertObject($citation, $citationPath), $citationPath);
        }
        $semanticFactsPath = $this->wire->child($path, 'semantic_facts');
        $semanticFacts = $this->mapSemanticFacts($this->wire->assertObject($value['semantic_facts'], $semanticFactsPath), $semanticFactsPath);
        $provenancePath = $this->wire->child($path, 'provenance');
        $provenance = $this->mapResultProvenance($this->wire->assertObject($value['provenance'], $provenancePath), $provenancePath);
        $this->validateResultSemantics($semanticFacts, $provenance, $path);

        return new Result(
            resultId: $this->wire->opaqueId($value['result_id'], $this->wire->child($path, 'result_id')),
            slotId: $this->wire->opaqueId($value['slot_id'], $this->wire->child($path, 'slot_id')),
            attemptId: $this->wire->opaqueId($value['attempt_id'], $this->wire->child($path, 'attempt_id')),
            contentFormat: $this->wire->closedString($value['content_format'], ['plain_text', 'markdown'], $this->wire->child($path, 'content_format')),
            content: $this->wire->boundedString($value['content'], $this->wire->child($path, 'content'), 1, 2_000_000),
            semanticFacts: $semanticFacts,
            citations: $citations,
            provenance: $provenance,
            completedAt: $this->wire->timestamp($value['completed_at'], $this->wire->child($path, 'completed_at')),
            usage: array_key_exists('usage', $value) ? $this->leaf->mapUsage($this->wire->assertObject($value['usage'], $this->wire->child($path, 'usage')), $this->wire->child($path, 'usage')) : null,
            extensions: $this->wire->optionalExtensions($value, 'extensions', $path),
        );
    }

    /** @param array<string, mixed> $value */
    private function mapResponse(array $value, string $path): Response
    {
        $this->wire->assertKeys(
            $value,
            ['interchange_version', 'message_type', 'request_id', 'response_status', 'emitted_at', 'slots', 'attempts', 'results', 'errors'],
            ['interchange_version', 'message_type', 'request_id', 'response_status', 'emitted_at', 'slots', 'attempts', 'results', 'errors', 'extensions'],
            $path,
        );
        $this->wire->assertLiteral($value['interchange_version'], WireValueReader::VERSION, $this->wire->child($path, 'interchange_version'));
        $this->wire->assertLiteral($value['message_type'], 'response', $this->wire->child($path, 'message_type'));

        $slots = [];
        foreach ($this->wire->assertList($value['slots'], $this->wire->child($path, 'slots'), 1, 64) as $index => $slot) {
            $slotPath = $this->wire->child($path, "slots/{$index}");
            $slots[] = $this->mapSlot($this->wire->assertObject($slot, $slotPath), $slotPath);
        }
        $attempts = [];
        foreach ($this->wire->assertList($value['attempts'], $this->wire->child($path, 'attempts'), 0, 256) as $index => $attempt) {
            $attemptPath = $this->wire->child($path, "attempts/{$index}");
            $attempts[] = $this->mapAttempt($this->wire->assertObject($attempt, $attemptPath), $attemptPath);
        }
        $results = [];
        foreach ($this->wire->assertList($value['results'], $this->wire->child($path, 'results'), 0, 64) as $index => $result) {
            $resultPath = $this->wire->child($path, "results/{$index}");
            $results[] = $this->mapResult($this->wire->assertObject($result, $resultPath), $resultPath);
        }
        $errors = [];
        foreach ($this->wire->assertList($value['errors'], $this->wire->child($path, 'errors'), 0, 64) as $index => $error) {
            $errorPath = $this->wire->child($path, "errors/{$index}");
            $errors[] = $this->leaf->mapError($this->wire->assertObject($error, $errorPath), $errorPath);
        }

        $response = new Response(
            requestId: $this->wire->opaqueId($value['request_id'], $this->wire->child($path, 'request_id')),
            responseStatus: $this->wire->enum(ResponseStatus::class, $value['response_status'], $this->wire->child($path, 'response_status')),
            emittedAt: $this->wire->timestamp($value['emitted_at'], $this->wire->child($path, 'emitted_at')),
            slots: $slots,
            attempts: $attempts,
            results: $results,
            errors: $errors,
            extensions: $this->wire->optionalExtensions($value, 'extensions', $path),
        );
        $this->validateResponseReferences($response, $path);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapExecutionProfile(array $value, string $path): array
    {
        $this->wire->assertKeys(
            $value,
            ['identity', 'result_kind', 'observation_mode', 'corpora', 'retrieval_method', 'access_mode', 'operator_id', 'invocation', 'resumability'],
            ['identity', 'result_kind', 'grounding_policy', 'observation_mode', 'corpora', 'retrieval_method', 'access_mode', 'operator_id', 'collector_id', 'surface_id', 'surface_context', 'invocation', 'resumability', 'extensions'],
            $path,
        );
        $resultKind = $this->wire->closedString($value['result_kind'], ['search_results', 'grounded_answer', 'research_report', 'model_answer', 'surface_observation'], $this->wire->child($path, 'result_kind'));
        $groundingPolicy = array_key_exists('grounding_policy', $value)
            ? $this->wire->closedString($value['grounding_policy'], ['required', 'optional', 'none'], $this->wire->child($path, 'grounding_policy'))
            : null;
        if ($resultKind === 'search_results' && $groundingPolicy !== null) {
            $this->wire->fail($this->wire->child($path, 'grounding_policy'), 'must be omitted for search_results');
        }
        if ($resultKind !== 'search_results' && $groundingPolicy === null) {
            $this->wire->fail($this->wire->child($path, 'grounding_policy'), 'is required for non-search profiles');
        }
        $corpora = $this->wire->closedStringList($value['corpora'], ['web', 'news', 'x', 'files', 'places', 'specialized'], $this->wire->child($path, 'corpora'), 0, 8);
        $retrievalMethod = $this->wire->closedString($value['retrieval_method'], ['search_endpoint', 'model_search_tool', 'research_agent', 'surface_collector', 'model_only'], $this->wire->child($path, 'retrieval_method'));
        $accessMode = $this->wire->closedString($value['access_mode'], ['direct', 'brokered', 'collected'], $this->wire->child($path, 'access_mode'));
        $observationMode = $this->wire->closedString($value['observation_mode'], ['api_output', 'surface_snapshot'], $this->wire->child($path, 'observation_mode'));
        $invocation = $this->wire->closedString($value['invocation'], ['inline', 'background'], $this->wire->child($path, 'invocation'));
        $resumability = $this->wire->closedString($value['resumability'], ['none', 'process_local', 'durable'], $this->wire->child($path, 'resumability'));
        if ($invocation === 'inline' && $resumability !== 'none') {
            $this->wire->fail($this->wire->child($path, 'resumability'), 'must be none for inline profiles');
        }
        if ($invocation === 'background' && $resumability === 'none') {
            $this->wire->fail($this->wire->child($path, 'resumability'), 'must be process_local or durable for background profiles');
        }
        $surfaceId = $this->wire->optionalOpaqueId($value, 'surface_id', $path);
        $collectorId = $this->wire->optionalOpaqueId($value, 'collector_id', $path);
        if ($resultKind === 'surface_observation' && $surfaceId === null) {
            $this->wire->fail($this->wire->child($path, 'surface_id'), 'is required for surface observations');
        }
        if ($observationMode === 'surface_snapshot') {
            if ($resultKind !== 'surface_observation') {
                $this->wire->fail($this->wire->child($path, 'result_kind'), 'must be surface_observation for surface snapshots');
            }
            if ($retrievalMethod !== 'surface_collector') {
                $this->wire->fail($this->wire->child($path, 'retrieval_method'), 'must be surface_collector for surface snapshots');
            }
            if ($collectorId === null) {
                $this->wire->fail($this->wire->child($path, 'collector_id'), 'is required for surface snapshots');
            }
            if ($accessMode !== 'collected') {
                $this->wire->fail($this->wire->child($path, 'access_mode'), 'must be collected for surface snapshots');
            }
        }

        return $this->wire->withoutNulls([
            'identity' => $this->leaf->providerToArray($this->leaf->mapProvider($this->wire->assertObject($value['identity'], $this->wire->child($path, 'identity')), $this->wire->child($path, 'identity'))),
            'result_kind' => $resultKind,
            'grounding_policy' => $groundingPolicy,
            'observation_mode' => $observationMode,
            'corpora' => $corpora,
            'retrieval_method' => $retrievalMethod,
            'access_mode' => $accessMode,
            'operator_id' => $this->wire->opaqueId($value['operator_id'], $this->wire->child($path, 'operator_id')),
            'collector_id' => $collectorId,
            'surface_id' => $surfaceId,
            'surface_context' => array_key_exists('surface_context', $value)
                ? $this->leaf->mapSurfaceContext($this->wire->assertObject($value['surface_context'], $this->wire->child($path, 'surface_context')), $this->wire->child($path, 'surface_context'))
                : null,
            'invocation' => $invocation,
            'resumability' => $resumability,
            'extensions' => $this->wire->optionalExtensions($value, 'extensions', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapSemanticFacts(array $value, string $path): array
    {
        $this->wire->assertKeys(
            $value,
            ['result_kinds', 'observation_mode', 'corpora', 'retrieval_methods', 'observed_at'],
            ['result_kinds', 'grounding_outcome', 'observation_mode', 'corpora', 'retrieval_methods', 'observed_at', 'measured_surface_id', 'surface_context', 'extensions'],
            $path,
        );
        $resultKinds = $this->wire->closedStringList($value['result_kinds'], ['search_results', 'grounded_answer', 'research_report', 'model_answer', 'surface_observation'], $this->wire->child($path, 'result_kinds'), 1, 8);
        $groundingOutcome = array_key_exists('grounding_outcome', $value)
            ? $this->wire->closedString($value['grounding_outcome'], ['used', 'not_used', 'unknown'], $this->wire->child($path, 'grounding_outcome'))
            : null;
        $searchOnly = count(array_filter($resultKinds, static fn (string $kind): bool => $kind !== 'search_results')) === 0;
        if ($searchOnly && $groundingOutcome !== null) {
            $this->wire->fail($this->wire->child($path, 'grounding_outcome'), 'must be omitted for search-results-only facts');
        }
        if (! $searchOnly && $groundingOutcome === null) {
            $this->wire->fail($this->wire->child($path, 'grounding_outcome'), 'is required for answer and observation facts');
        }

        return $this->wire->withoutNulls([
            'result_kinds' => $resultKinds,
            'grounding_outcome' => $groundingOutcome,
            'observation_mode' => $this->wire->closedString($value['observation_mode'], ['api_output', 'surface_snapshot'], $this->wire->child($path, 'observation_mode')),
            'corpora' => $this->wire->closedStringList($value['corpora'], ['web', 'news', 'x', 'files', 'places', 'specialized'], $this->wire->child($path, 'corpora'), 0, 8),
            'retrieval_methods' => $this->wire->closedStringList($value['retrieval_methods'], ['search_endpoint', 'model_search_tool', 'research_agent', 'surface_collector', 'model_only'], $this->wire->child($path, 'retrieval_methods'), 1, 8),
            'observed_at' => $this->wire->timestamp($value['observed_at'], $this->wire->child($path, 'observed_at')),
            'measured_surface_id' => $this->wire->optionalOpaqueId($value, 'measured_surface_id', $path),
            'surface_context' => array_key_exists('surface_context', $value)
                ? $this->leaf->mapSurfaceContext($this->wire->assertObject($value['surface_context'], $this->wire->child($path, 'surface_context')), $this->wire->child($path, 'surface_context'))
                : null,
            'extensions' => $this->wire->optionalExtensions($value, 'extensions', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapResultProvenance(array $value, string $path): array
    {
        $this->wire->assertKeys(
            $value,
            ['request_id', 'slot_id', 'attempt_id', 'requested_profile', 'effective_profile', 'collection'],
            ['request_id', 'slot_id', 'attempt_id', 'requested_profile', 'effective_profile', 'collection', 'replaced_attempt_id', 'extensions'],
            $path,
        );

        return $this->wire->withoutNulls([
            'request_id' => $this->wire->opaqueId($value['request_id'], $this->wire->child($path, 'request_id')),
            'slot_id' => $this->wire->opaqueId($value['slot_id'], $this->wire->child($path, 'slot_id')),
            'attempt_id' => $this->wire->opaqueId($value['attempt_id'], $this->wire->child($path, 'attempt_id')),
            'requested_profile' => $this->mapExecutionProfile($this->wire->assertObject($value['requested_profile'], $this->wire->child($path, 'requested_profile')), $this->wire->child($path, 'requested_profile')),
            'effective_profile' => $this->mapExecutionProfile($this->wire->assertObject($value['effective_profile'], $this->wire->child($path, 'effective_profile')), $this->wire->child($path, 'effective_profile')),
            'collection' => $this->leaf->provenanceToArray($this->leaf->mapProvenance($this->wire->assertObject($value['collection'], $this->wire->child($path, 'collection')), $this->wire->child($path, 'collection'))),
            'replaced_attempt_id' => $this->wire->optionalOpaqueId($value, 'replaced_attempt_id', $path),
            'extensions' => $this->wire->optionalExtensions($value, 'extensions', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function mapSlot(array $value, string $path): array
    {
        $this->wire->assertKeysPresent($value, ['slot_id', 'slot_status'], $path);
        $status = $this->wire->closedString($value['slot_status'], ['pending', 'succeeded', 'failed', 'cancelled'], $this->wire->child($path, 'slot_status'));
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
        $this->wire->assertKeys($value, $required, $allowed, $path);

        return $this->wire->withoutNulls([
            'slot_id' => $this->wire->opaqueId($value['slot_id'], $this->wire->child($path, 'slot_id')),
            'slot_status' => $status,
            'selected_attempt_id' => $this->wire->optionalOpaqueId($value, 'selected_attempt_id', $path),
            'result_id' => $this->wire->optionalOpaqueId($value, 'result_id', $path),
            'error' => array_key_exists('error', $value)
                ? $this->leaf->errorToArray($this->leaf->mapError($this->wire->assertObject($value['error'], $this->wire->child($path, 'error')), $this->wire->child($path, 'error')))
                : null,
            'extensions' => $this->wire->optionalExtensions($value, 'extensions', $path),
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
            $this->wire->fail($this->wire->child($path, 'semantic_facts/result_kinds'), 'must include the effective profile result_kind');
        }
        if (! in_array($profile['retrieval_method'], $retrievalMethods, true)) {
            $this->wire->fail($this->wire->child($path, 'semantic_facts/retrieval_methods'), 'must include the effective profile retrieval_method');
        }
        foreach ($corpora as $index => $corpus) {
            if (! in_array($corpus, $profileCorpora, true)) {
                $this->wire->fail($this->wire->child($path, "semantic_facts/corpora/{$index}"), 'cannot claim a corpus outside the effective profile');
            }
        }
        if ($facts['observation_mode'] !== $profile['observation_mode']) {
            $this->wire->fail($this->wire->child($path, 'semantic_facts/observation_mode'), 'must match the effective profile observation_mode');
        }
        $profileSurface = $profile['surface_id'] ?? null;
        $measuredSurface = $facts['measured_surface_id'] ?? null;
        if ($profileSurface !== $measuredSurface) {
            $this->wire->fail($this->wire->child($path, 'semantic_facts/measured_surface_id'), 'must match the effective profile surface_id');
        }
        $groundingPolicy = $profile['grounding_policy'] ?? null;
        $groundingOutcome = $facts['grounding_outcome'] ?? null;
        if ($groundingPolicy === 'required' && $groundingOutcome !== 'used') {
            $this->wire->fail($this->wire->child($path, 'semantic_facts/grounding_outcome'), 'must be used when grounding is required');
        }
        if ($groundingPolicy === 'none' && $groundingOutcome === 'used') {
            $this->wire->fail($this->wire->child($path, 'semantic_facts/grounding_outcome'), 'cannot be used when grounding policy is none');
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
                $this->wire->fail($this->wire->child($path, "slots/{$index}/slot_id"), 'must be unique');
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
                $this->wire->fail($this->wire->child($path, "attempts/{$index}/attempt_id"), 'must be unique');
            }
            if (! array_key_exists($attempt->slotId, $slots)) {
                $this->wire->fail($this->wire->child($path, "attempts/{$index}/slot_id"), 'references an unknown slot');
            }
            if ($attempt->candidateId !== null) {
                if (isset($candidateIds[$attempt->candidateId])) {
                    $this->wire->fail($this->wire->child($path, "attempts/{$index}/candidate_id"), 'fallback candidates may be consumed at most once per response');
                }
                $candidateIds[$attempt->candidateId] = true;
            }
            /** @var array<string, mixed> $identity */
            $identity = $attempt->profile['identity'];
            $profileKey = $this->wire->opaqueId($identity['provider_id'], $this->wire->child($path, "attempts/{$index}/profile/identity/provider_id"))
                ."\0"
                .$this->wire->opaqueId($identity['profile_id'], $this->wire->child($path, "attempts/{$index}/profile/identity/profile_id"));
            if (isset($profileKeys[$profileKey])) {
                $this->wire->fail($this->wire->child($path, "attempts/{$index}/profile/identity"), 'provider profiles may execute at most once per response');
            }
            $profileKeys[$profileKey] = true;
            if ($attempt->replacesAttemptId !== null) {
                $replaced = $attempts[$attempt->replacesAttemptId] ?? null;
                if ($replaced === null || $replaced->slotId !== $attempt->slotId) {
                    $this->wire->fail($this->wire->child($path, "attempts/{$index}/replaces_attempt_id"), 'must reference an earlier attempt in the same slot');
                }
                if (! in_array($replaced->attemptStatus, [AttemptStatus::Failed, AttemptStatus::TimedOut], true)) {
                    $this->wire->fail($this->wire->child($path, "attempts/{$index}/replaces_attempt_id"), 'may only replace a failed or timed-out attempt');
                }
                if ($replaced->error?->fallbackAllowed !== true) {
                    $this->wire->fail($this->wire->child($path, "attempts/{$index}/replaces_attempt_id"), 'requires fallback_allowed=true on the replaced error');
                }
            }
            $attempts[$attempt->attemptId] = $attempt;
        }

        $this->validateResponseStatus($response, $path);
        $this->validateSelectedAttempts($response, $attempts, $path);

        /** @var array<string, Result> $results */
        $results = [];
        foreach ($response->results as $index => $result) {
            if (array_key_exists($result->resultId, $results)) {
                $this->wire->fail($this->wire->child($path, "results/{$index}/result_id"), 'must be unique');
            }
            $attempt = $attempts[$result->attemptId] ?? null;
            if ($attempt?->attemptStatus !== AttemptStatus::Succeeded || $attempt->slotId !== $result->slotId || $attempt->resultId !== $result->resultId) {
                $this->wire->fail($this->wire->child($path, "results/{$index}/attempt_id"), 'must reference its matching succeeded attempt and slot');
            }
            if ($result->provenance['request_id'] !== $response->requestId || $result->provenance['slot_id'] !== $result->slotId || $result->provenance['attempt_id'] !== $result->attemptId) {
                $this->wire->fail($this->wire->child($path, "results/{$index}/provenance"), 'identifiers must match the enclosing response and result');
            }
            $slot = $slots[$result->slotId] ?? null;
            if (($slot['slot_status'] ?? null) !== 'succeeded' || ($slot['selected_attempt_id'] ?? null) !== $result->attemptId || ($slot['result_id'] ?? null) !== $result->resultId) {
                $this->wire->fail($this->wire->child($path, "results/{$index}/slot_id"), 'must be selected by its matching succeeded slot');
            }
            if (! $this->wire->jsonValuesEqual($result->provenance['effective_profile'], $attempt->profile)) {
                $this->wire->fail($this->wire->child($path, "results/{$index}/provenance/effective_profile"), 'must match the producing attempt profile');
            }
            /** @var array<string, mixed> $collection */
            $collection = $result->provenance['collection'];
            if (! $this->wire->jsonValuesEqual($collection['provider'], $attempt->profile['identity'])) {
                $this->wire->fail($this->wire->child($path, "results/{$index}/provenance/collection/provider"), 'must match the producing attempt provider');
            }
            if (($result->provenance['replaced_attempt_id'] ?? null) !== $attempt->replacesAttemptId) {
                $this->wire->fail($this->wire->child($path, "results/{$index}/provenance/replaced_attempt_id"), 'must match the attempt replacement chain');
            }
            $requestedAttempt = $attempt;
            while ($requestedAttempt->replacesAttemptId !== null) {
                $requestedAttempt = $attempts[$requestedAttempt->replacesAttemptId];
            }
            if (! $this->wire->jsonValuesEqual($result->provenance['requested_profile'], $requestedAttempt->profile)) {
                $this->wire->fail($this->wire->child($path, "results/{$index}/provenance/requested_profile"), 'must match the first profile in the attempt chain');
            }
            $results[$result->resultId] = $result;
        }

        foreach ($response->attempts as $index => $attempt) {
            if ($attempt->attemptStatus === AttemptStatus::Succeeded) {
                $result = $attempt->resultId === null ? null : ($results[$attempt->resultId] ?? null);
                if ($result === null || $result->attemptId !== $attempt->attemptId || $result->slotId !== $attempt->slotId) {
                    $this->wire->fail($this->wire->child($path, "attempts/{$index}/result_id"), 'must identify the result produced by a succeeded attempt');
                }
            }
        }

    }

    /** @param array<string, Attempt> $attempts */
    private function validateSelectedAttempts(Response $response, array $attempts, string $path): void
    {
        foreach ($response->slots as $index => $slot) {
            $selectedAttemptId = $slot['selected_attempt_id'] ?? null;
            if (! is_string($selectedAttemptId)) {
                continue;
            }
            $attempt = $attempts[$selectedAttemptId] ?? null;
            if ($attempt === null || $attempt->slotId !== $slot['slot_id']) {
                $this->wire->fail($this->wire->child($path, "slots/{$index}/selected_attempt_id"), 'must reference an attempt in the same slot');
            }
            $compatible = match ($slot['slot_status']) {
                'pending' => in_array($attempt->attemptStatus, [AttemptStatus::Started, AttemptStatus::Submitted], true),
                'succeeded' => $attempt->attemptStatus === AttemptStatus::Succeeded,
                'failed' => in_array($attempt->attemptStatus, [AttemptStatus::Failed, AttemptStatus::TimedOut], true),
                'cancelled' => $attempt->attemptStatus === AttemptStatus::Cancelled,
                default => false,
            };
            if (! $compatible) {
                $this->wire->fail($this->wire->child($path, "slots/{$index}/selected_attempt_id"), 'status must match the slot outcome status');
            }
        }
    }

    private function validateResponseStatus(Response $response, string $path): void
    {
        $statuses = [];
        foreach ($response->slots as $slot) {
            $statuses[] = $this->wire->boundedString($slot['slot_status'], $this->wire->child($path, 'slots'), 1, 32);
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
            $this->wire->fail($this->wire->child($path, 'response_status'), 'does not match the slot outcome statuses');
        }
    }
}
