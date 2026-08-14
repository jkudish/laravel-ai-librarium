<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

/** @return array<string, mixed> */
function responseFixture(string $path): array
{
    $contents = File::get(__DIR__.'/../Support/Contracts/v1/fixtures/'.$path);

    /** @var array<string, mixed> $fixture */
    $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    return $fixture;
}

it('round trips every accepted terminal response fixture without a mapper', function (string $fixture): void {
    $value = responseFixture("valid/{$fixture}");

    $response = ResearchResponse::fromArray($value);

    expect($response->toArray())->toBe($value)
        ->and($response->results)->each->toBeInstanceOf(ResearchResult::class);
})->with([
    'markdown result' => ['markdown-success.json'],
    'text result' => ['php-text-success.json'],
    'JSON object result' => ['json-object-success.json'],
    'JSON array result' => ['json-array-success.json'],
    'partial response usage' => ['partial-top-level-usage.json'],
    'failed response' => ['failed.json'],
    'provider reference source' => ['provider-reference-source.json'],
    'untrusted URL identifier' => ['untrusted-url-identifier.json'],
    'closed enum coverage' => ['enum-branch-coverage.json'],
    'empty retrieval facts' => ['empty-retrieval-and-corpora.json'],
    'anonymous surface' => ['surface-context-authentication-anonymous.json'],
    'authenticated surface' => ['surface-context-authentication-authenticated.json'],
    'managed surface' => ['surface-context-authentication-managed.json'],
    'unknown authentication surface' => ['surface-context-authentication-unknown.json'],
    'optional result values' => ['optional-result-fields.json'],
    'provider metadata namespaces' => ['provider-meta-namespaces.json'],
    'provider metadata benign keys' => ['provider-meta-benign-keys.json'],
]);

it('rejects every invalid terminal response fixture through Laravel validation', function (string $fixture): void {
    expect(fn (): ResearchResponse => ResearchResponse::fromArray(responseFixture("invalid/{$fixture}")))
        ->toThrow(ValidationException::class);
})->with([
    'terminal version' => ['terminal-version.json'],
    'terminal source registry' => ['terminal-source-registry.json'],
    'terminal extensions' => ['terminal-extensions.json'],
    'terminal execution' => ['terminal-execution-field.json'],
    'legacy result' => ['result-legacy-fields.json'],
    'plain text content format' => ['result-plain-text-format.json'],
    'semantic facts' => ['result-semantic-facts.json'],
    'profile graph' => ['result-profile-graph.json'],
    'target graph' => ['result-target-graph.json'],
    'collection graph' => ['result-collection-graph.json'],
    'wrong JSON content' => ['result-wrong-content.json'],
    'citation source' => ['citation-no-source.json'],
    'source locator' => ['source-no-locator.json'],
    'top level provider metadata' => ['provider-meta-top-level.json'],
    'non-object provider metadata' => ['provider-meta-non-object.json'],
    'secret provider metadata' => ['provider-meta-secret.json'],
    'raw provider metadata' => ['provider-meta-raw.json'],
    'fused API key metadata' => ['provider-meta-fused-api-key.json'],
    'fused access token metadata' => ['provider-meta-fused-access-token.json'],
    'fused raw response metadata' => ['provider-meta-fused-raw-response.json'],
    'rich error' => ['error-rich.json'],
    'not namespaced error' => ['error-not-namespaced.json'],
    'rich usage' => ['usage-rich.json'],
    'negative usage' => ['usage-negative.json'],
    'malformed decimal usage' => ['usage-malformed-decimal.json'],
    'cost missing currency' => ['usage-cost-no-currency.json'],
    'provenance context' => ['provenance-context-unknown.json'],
    'surface missing collector' => ['provenance-surface-no-collector.json'],
    'closed enum' => ['invalid-enum.json'],
    'timestamp' => ['invalid-timestamp.json'],
    'identifier' => ['invalid-id.json'],
    'succeeded terminal shape' => ['succeeded-shape.json'],
    'partial terminal shape' => ['partial-shape.json'],
    'failed terminal shape' => ['failed-shape.json'],
]);

it('does not introduce an interchange mapper or terminal execution model', function (): void {
    $files = collect(File::allFiles(__DIR__.'/../../src/Responses'))
        ->map(static fn (SplFileInfo $file): string => $file->getFilenameWithoutExtension())
        ->sort()
        ->values()
        ->all();

    expect($files)->toBe([
        'Authentication',
        'Citation',
        'CitationDerivation',
        'ContentFormat',
        'Corpus',
        'ResearchError',
        'ResearchResponse',
        'ResearchResult',
        'ResponseStatus',
        'ResultKind',
        'ResultProvenance',
        'RetrievalMethod',
        'Source',
        'SourceKind',
        'Usage',
    ]);
});

it('rejects secret-bearing serializable metadata and non-JSON-safe content', function (): void {
    $metadataFixture = responseFixture('valid/provider-meta-benign-keys.json');
    $metadataFixture['results'][0]['provider_meta']['com.example:usage']['sdkResponse'] = new stdClass;

    $tokenFixture = responseFixture('valid/provider-meta-benign-keys.json');
    $tokenFixture['results'][0]['provider_meta']['com.example:usage']['sessionToken'] = 'secret';

    $jsonFixture = responseFixture('valid/json-object-success.json');
    $jsonFixture['results'][0]['content']['cost'] = INF;

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($metadataFixture))
        ->toThrow(ValidationException::class)
        ->and(fn (): ResearchResponse => ResearchResponse::fromArray($tokenFixture))
        ->toThrow(ValidationException::class)
        ->and(fn (): ResearchResponse => ResearchResponse::fromArray($jsonFixture))
        ->toThrow(ValidationException::class);
});

it('preserves accepted fractional UTC timestamp precision at every terminal level', function (): void {
    $fixture = responseFixture('valid/markdown-success.json');
    $fixture['completed_at'] = '2026-08-09T12:00:02.1Z';
    $fixture['results'][0]['completed_at'] = '2026-08-09T12:00:01.12Z';
    $fixture['results'][0]['provenance']['observed_at'] = '2026-08-09T12:00:00.123Z';
    $fixture['results'][0]['citations'][0]['source']['published_at'] = '2026-08-09T11:00:00.1234Z';

    expect(ResearchResponse::fromArray($fixture)->toArray())->toBe($fixture);
});
