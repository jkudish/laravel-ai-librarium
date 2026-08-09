<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Interchange\InterchangeMapper;
use Jkudish\LaravelAiLibrarium\Interchange\InterchangeValidationException;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Attempt;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Citation;
use Jkudish\LaravelAiLibrarium\Interchange\Values\DurableHandle;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Response;
use Jkudish\LaravelAiLibrarium\Interchange\Values\StructuredError;
use Jkudish\LaravelAiLibrarium\Interchange\Values\Usage;

/** @return array<string, mixed> */
function interchangeFixture(string $path): array
{
    $contents = file_get_contents(__DIR__.'/../Support/Contracts/v1/fixtures/'.$path);

    expect($contents)->toBeString();

    /** @var array<string, mixed> */
    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function lifecycleFixture(string $path): array
{
    $contents = file_get_contents(__DIR__.'/../Support/Contracts/v1/fixtures/'.$path);

    expect($contents)->toBeString();

    /** @var list<array<string, mixed>> */
    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

it('round trips valid response fixtures through typed leaves', function (string $fixture): void {
    $wire = interchangeFixture("valid/{$fixture}");
    $mapper = app(InterchangeMapper::class);

    $response = $mapper->responseFromArray($wire);

    expect($mapper->responseToArray($response))->toEqual($wire)
        ->and($response->attempts[2]->durableHandle)->toBeInstanceOf(DurableHandle::class)
        ->and($response->attempts[2]->usage)->toBeInstanceOf(Usage::class)
        ->and($response->results[0]->citations[0])->toBeInstanceOf(Citation::class)
        ->and($response->errors[0])->toBeInstanceOf(StructuredError::class);
})->with([
    'partial response' => 'partial-response.json',
    'specialized data-record response' => 'specialized-data-record-response.json',
]);

it('round trips an unsuccessful response without inventing usage or cost facts', function (): void {
    $wire = interchangeFixture('valid/unsuccessful-response.json');
    $mapper = app(InterchangeMapper::class);

    $response = $mapper->responseFromArray($wire);

    expect($mapper->responseToArray($response))->toEqual($wire)
        ->and($response->attempts[0]->usage)->toBeNull()
        ->and($mapper->responseToArray($response)['attempts'][0])->not->toHaveKey('usage');
});

it('round trips the lifecycle trace and validates its semantic order', function (): void {
    $wire = lifecycleFixture('valid/lifecycle-trace.json');
    $mapper = app(InterchangeMapper::class);

    $events = $mapper->lifecycleTraceFromArray($wire);

    expect($mapper->lifecycleTraceToArray($events))->toEqual($wire)
        ->and($events)->toHaveCount(11)
        ->and($events[7]->data['handle']['provider_task_id'])->toBe('provider-task-public-001');
});

it('preserves extension key spelling and precision-safe decimal strings', function (): void {
    $wire = interchangeFixture('valid/partial-response.json');
    $wire['results'][0]['usage']['actual_cost']['amount']['amount_decimal'] = '0.010000000000000001';
    $wire['extensions'] = [
        'com.example:camelCaseKey' => [
            'NestedCAPS' => true,
            'inputTokens' => 42,
            'precise' => '0.010000000000000001',
        ],
    ];
    $mapper = app(InterchangeMapper::class);

    $roundTrip = $mapper->responseToArray($mapper->responseFromArray($wire));

    expect($roundTrip)->toEqual($wire)
        ->and($roundTrip['extensions'])->toHaveKey('com.example:camelCaseKey')
        ->and($roundTrip['extensions']['com.example:camelCaseKey'])->toHaveKey('NestedCAPS')
        ->and($roundTrip['results'][0]['usage']['actual_cost']['amount']['amount_decimal'])->toBe('0.010000000000000001');
});

it('maps a normalized source without adding a provider catalog type', function (): void {
    $wire = [
        'source_id' => 'source-public-001',
        'provider_reference' => 'record-public-001',
        'source_kind' => 'data_record',
        'source_category' => 'patent_record',
        'dataset_id' => 'dataset-public-001',
        'citation_ids' => ['citation-001'],
        'correlation_keys' => ['com.example:recordId' => 'record-public-001'],
        'extensions' => ['com.example:publicMetadata' => ['recordType' => 'patent']],
    ];
    $mapper = app(InterchangeMapper::class);

    expect($mapper->sourceToArray($mapper->sourceFromArray($wire)))->toEqual($wire);
});

it('rejects representative invalid response fixtures with the expected field path', function (string $fixture, string $path): void {
    $mapper = app(InterchangeMapper::class);

    try {
        $mapper->responseFromArray(interchangeFixture("invalid/{$fixture}"));
        test()->fail('Expected invalid interchange fixture to be rejected.');
    } catch (InterchangeValidationException $exception) {
        expect($exception->fieldPath)->toBe($path)
            ->and($exception->getMessage())->toContain($path);
    }
})->with([
    'missing fallback permission' => ['error-missing-fallback-allowed.json', '/attempts/0/error/fallback_allowed'],
    'monetary float' => ['monetary-float.json', '/attempts/2/usage/actual_cost/amount/amount_decimal'],
    'overlong decimal' => ['decimal-too-long.json', '/attempts/2/usage/actual_cost/amount/amount_decimal'],
    'unknown citation kind' => ['citation-source-kind.json', '/results/0/citations/0/source_kind'],
    'non-http citation url' => ['citation-non-http-url.json', '/results/0/citations/0/url'],
    'citation without locator' => ['citation-missing-locator.json', '/results/0/citations/0/url'],
    'non-utc handle timestamp' => ['durable-handle-timestamp.json', '/attempts/2/durable_handle/submitted_at'],
    'overlong source category' => ['source-category-too-long.json', '/results/0/citations/0/source_category'],
    'incoherent result facts' => ['response-semantic-facts.json', '/results/0/semantic_facts/result_kinds'],
    'dangling result attempt' => ['result-attempt-reference.json', '/results/0/attempt_id'],
]);

it('rejects every remaining invalid response fixture at its accepted issue path', function (string $fixture, string $path): void {
    try {
        app(InterchangeMapper::class)->responseFromArray(interchangeFixture("invalid/{$fixture}"));
        test()->fail('Expected invalid response fixture to be rejected.');
    } catch (InterchangeValidationException $exception) {
        expect($exception->fieldPath)->toBe($path);
    }
})->with([
    'provenance request reference' => ['provenance-request-reference.json', '/results/0/provenance'],
    'partial outcome shape' => ['partial-outcome-shape.json', '/response_status'],
    'selected attempt status' => ['response-selected-attempt-status.json', '/slots/0/selected_attempt_id'],
    'effective profile' => ['response-effective-profile.json', '/results/0/provenance/effective_profile'],
    'collection provider' => ['response-collection-provider.json', '/results/0/provenance/collection/provider'],
    'replacement provenance' => ['response-replacement-provenance.json', '/results/0/provenance/replaced_attempt_id'],
    'replacement of succeeded' => ['response-replacement-of-succeeded.json', '/attempts/1/replaces_attempt_id'],
    'attempt handle provider' => ['response-attempt-handle-provider.json', '/attempts/2/durable_handle/provider'],
    'observation mode' => ['response-observation-mode.json', '/results/0/semantic_facts/observation_mode'],
    'measured surface' => ['response-measured-surface.json', '/results/0/semantic_facts/measured_surface_id'],
    'required grounding' => ['response-required-grounding.json', '/results/0/semantic_facts/grounding_outcome'],
    'none grounding' => ['response-none-grounding.json', '/results/0/semantic_facts/grounding_outcome'],
    'fallback candidate reuse' => ['response-fallback-candidate-reuse.json', '/attempts/3/candidate_id'],
    'mixed unsuccessful status' => ['response-mixed-unsuccessful-status.json', '/response_status'],
]);

it('rejects invalid lifecycle variants and trace ordering', function (string $fixture, string $path): void {
    $mapper = app(InterchangeMapper::class);

    try {
        $mapper->lifecycleTraceFromArray(lifecycleFixture("invalid/{$fixture}"));
        test()->fail('Expected invalid lifecycle fixture to be rejected.');
    } catch (InterchangeValidationException $exception) {
        expect($exception->fieldPath)->toBe($path);
    }
})->with([
    'out of order' => ['lifecycle-out-of-order.json', '/2/sequence'],
    'failed without error' => ['lifecycle-failed-without-error.json', '/2/data/error'],
    'succeeded with error' => ['lifecycle-succeeded-with-error.json', '/5/data/error'],
]);

it('rejects unknown semantic fields before use', function (): void {
    $wire = interchangeFixture('valid/partial-response.json');
    $wire['effectiveProfile'] = 'must-not-be-recased';

    expect(fn () => app(InterchangeMapper::class)->responseFromArray($wire))
        ->toThrow(InterchangeValidationException::class, '/effectiveProfile');
});

it('revalidates hand-built values before serialization', function (): void {
    $mapper = app(InterchangeMapper::class);
    $mapped = $mapper->responseFromArray(interchangeFixture('valid/partial-response.json'));
    $unsafe = new Response(
        requestId: $mapped->requestId,
        responseStatus: $mapped->responseStatus,
        emittedAt: $mapped->emittedAt,
        slots: $mapped->slots,
        attempts: $mapped->attempts,
        results: $mapped->results,
        errors: $mapped->errors,
        extensions: ['com.example:authorization' => 'Bearer secret'],
    );

    expect(fn () => $mapper->responseToArray($unsafe))
        ->toThrow(InterchangeValidationException::class, '/extensions/com.example:authorization');

    $attempt = $mapped->attempts[0];
    $unsafeAttempt = new Attempt(
        attemptId: $attempt->attemptId,
        slotId: $attempt->slotId,
        attemptNumber: $attempt->attemptNumber,
        profile: $attempt->profile,
        startedAt: $attempt->startedAt,
        attemptStatus: $attempt->attemptStatus,
        replacesAttemptId: $attempt->replacesAttemptId,
        candidateId: $attempt->candidateId,
        finishedAt: $attempt->finishedAt,
        resultId: $attempt->resultId,
        error: $attempt->error,
        durableHandle: $attempt->durableHandle,
        usage: $attempt->usage,
        extensions: ['com.example:refresh_token' => 'secret'],
    );
    $unsafeNested = new Response(
        requestId: $mapped->requestId,
        responseStatus: $mapped->responseStatus,
        emittedAt: $mapped->emittedAt,
        slots: $mapped->slots,
        attempts: [$unsafeAttempt, ...array_slice($mapped->attempts, 1)],
        results: $mapped->results,
        errors: $mapped->errors,
        extensions: $mapped->extensions,
    );

    expect(fn () => $mapper->responseToArray($unsafeNested))
        ->toThrow(InterchangeValidationException::class, '/attempts/0/extensions/com.example:refresh_token');
});

it('uses strict JSON equality for profile identity facts', function (): void {
    $wire = interchangeFixture('valid/partial-response.json');
    $wire['attempts'][1]['profile']['extensions'] = ['com.example:value' => 42];
    $wire['results'][0]['provenance']['effective_profile']['extensions'] = ['com.example:value' => '42'];

    expect(fn () => app(InterchangeMapper::class)->responseFromArray($wire))
        ->toThrow(InterchangeValidationException::class, '/results/0/provenance/effective_profile');
});

it('bounds extension integers to the cross-language safe range', function (int $value, bool $accepted): void {
    $wire = interchangeFixture('valid/partial-response.json');
    $wire['extensions'] = ['com.example:value' => $value];
    $mapper = app(InterchangeMapper::class);

    if ($accepted) {
        expect($mapper->responseToArray($mapper->responseFromArray($wire))['extensions']['com.example:value'])->toBe($value);

        return;
    }

    expect(fn () => $mapper->responseFromArray($wire))
        ->toThrow(InterchangeValidationException::class, '/extensions/com.example:value');
})->with([
    'largest safe integer' => [9_007_199_254_740_991, true],
    'smallest safe integer' => [-9_007_199_254_740_991, true],
    'above safe range' => [9_007_199_254_740_992, false],
    'below safe range' => [-9_007_199_254_740_992, false],
]);

it('rejects secret-bearing and unbounded extensions at any depth', function (array $extensions, string $path): void {
    $wire = interchangeFixture('valid/partial-response.json');
    $wire['extensions'] = $extensions;

    try {
        app(InterchangeMapper::class)->responseFromArray($wire);
        test()->fail('Expected unsafe extensions to be rejected.');
    } catch (InterchangeValidationException $exception) {
        expect($exception->fieldPath)->toBe($path);
    }
})->with([
    'top-level authorization' => [
        ['com.example:authorization' => 'Bearer secret'],
        '/extensions/com.example:authorization',
    ],
    'nested refresh token' => [
        ['com.example:publicMetadata' => ['accountContext' => ['refreshToken' => 'secret']]],
        '/extensions/com.example:publicMetadata/accountContext/refreshToken',
    ],
    'overlong nested string' => [
        ['com.example:publicMetadata' => ['summary' => str_repeat('x', 8193)]],
        '/extensions/com.example:publicMetadata/summary',
    ],
]);
