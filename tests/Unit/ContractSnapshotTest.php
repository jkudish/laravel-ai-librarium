<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Tests\Support\ContractSnapshot;

/**
 * @return array<string, mixed>
 */
function contractSnapshotJson(string $relativePath): array
{
    $contents = File::get(__DIR__.'/../Support/Contracts/v1/'.$relativePath);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

beforeEach(function (): void {
    $this->workingSnapshotDirectory = sys_get_temp_dir().'/laravel-ai-librarium-contract-'.Str::uuid();

    expect(File::copyDirectory(
        __DIR__.'/../Support/Contracts/'.ContractSnapshot::CONTRACT_VERSION,
        $this->workingSnapshotDirectory,
    ))->toBeTrue();
});

afterEach(function (): void {
    File::deleteDirectory($this->workingSnapshotDirectory);
});

it('verifies the pinned upstream snapshot offline and reports machine-readable totals', function (): void {
    $pin = ContractSnapshot::pin();
    $report = ContractSnapshot::vendored()->verify();

    expect($report)->toBe([
        'contract_version' => 'v1',
        'snapshot_version' => 1,
        'upstream_commit' => $pin->commit,
        'manifest_sha256' => $pin->contractManifestSha256,
        'checksums_sha256' => $pin->contractChecksumsSha256,
        'interchange_schema_sha256' => $pin->contractInterchangeSchemaSha256,
        'totals' => $pin->contractTotals,
    ])->and(hash_file('sha256', __DIR__.'/../Support/Contracts/v1/checksums.sha256'))
        ->toBe($pin->contractChecksumsSha256)
        ->and(json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))
        ->toBe($report);
});

it('documents the pinned snapshot in its generated README', function (): void {
    $pin = ContractSnapshot::pin();
    $readme = File::get(__DIR__.'/../Support/Contracts/README.md');

    expect($readme)->toContain("`{$pin->commit}`")
        ->toContain("Checksum index SHA-256: `{$pin->contractChecksumsSha256}`")
        ->toContain("Manifest SHA-256: `{$pin->contractManifestSha256}`")
        ->toContain("Interchange schema SHA-256: `{$pin->contractInterchangeSchemaSha256}`")
        ->toContain("Valid fixtures: {$pin->contractTotals['valid_fixtures']}")
        ->toContain("Invalid fixtures: {$pin->contractTotals['invalid_fixtures']}")
        ->toContain('It carries no PHP-local adjustments.');
});

it('accepts a snapshot directory with a trailing separator', function (): void {
    $report = (new ContractSnapshot($this->workingSnapshotDirectory.DIRECTORY_SEPARATOR))->verify();

    expect($report['snapshot_version'])->toBe(ContractSnapshot::SNAPSHOT_VERSION);
});

it('rejects a snapshot root that is a symbolic link', function (): void {
    $symbolicLink = sys_get_temp_dir().'/laravel-ai-librarium-contract-link-'.Str::uuid();

    if (! function_exists('symlink') || ! @symlink($this->workingSnapshotDirectory, $symbolicLink)) {
        $this->markTestSkipped('The platform does not support symbolic links for this test.');
    }

    try {
        expect(fn (): array => (new ContractSnapshot($symbolicLink))->verify())
            ->toThrow(
                UnexpectedValueException::class,
                "Contract snapshot root must not be a symbolic link: {$symbolicLink}.",
            );
    } finally {
        File::delete($symbolicLink);
    }
});

it('contains only the terminal interchange schema and its seven definitions', function (): void {
    $manifest = contractSnapshotJson('manifest.json');
    $schema = contractSnapshotJson('schema/interchange.schema.json');

    $schemaPaths = array_column(
        array_values(array_filter(
            $manifest['files'],
            static fn (array $file): bool => $file['role'] === 'schema',
        )),
        'path',
    );
    $definitionNames = array_keys($schema['$defs']);
    sort($definitionNames);

    expect($schemaPaths)->toBe(['schema/interchange.schema.json'])
        ->and($definitionNames)->toBe(ContractSnapshot::DEFINITION_NAMES)
        ->and($schema)->not->toHaveKeys(['execution', 'lifecycle', 'artifacts'])
        ->and($schema['$defs']['research_response']['required'])->toBe([
            'generator',
            'generator_version',
            'request_id',
            'status',
            'completed_at',
            'results',
            'errors',
        ]);
});

it('keeps source URLs nonempty and untrusted', function (): void {
    $schema = contractSnapshotJson('schema/interchange.schema.json');
    $fixture = contractSnapshotJson('fixtures/valid/untrusted-url-identifier.json');
    $urlSchema = $schema['$defs']['source']['properties']['url'];
    $url = $fixture['results'][0]['citations'][0]['source']['url'];

    expect($urlSchema)->toBe([
        'type' => 'string',
        'minLength' => 1,
    ])->and($url)->toBe('provider-native:source/123')
        ->not->toStartWith('http');
});

it('pins every closed terminal vocabulary', function (): void {
    $schema = contractSnapshotJson('schema/interchange.schema.json');
    $fixture = contractSnapshotJson('fixtures/valid/enum-branch-coverage.json');
    $definitions = $schema['$defs'];
    $contentFormats = array_column(array_map(
        static fn (array $branch): array => $branch['properties']['content_format'],
        $definitions['research_result']['anyOf'],
    ), 'const');

    expect($definitions['research_response']['properties']['status']['enum'])->toBe([
        'succeeded',
        'partial',
        'failed',
    ])->and($definitions['result_provenance']['properties']['result_kind']['enum'])->toBe([
        'search_results',
        'grounded_answer',
        'research_report',
        'model_answer',
        'surface_observation',
    ])->and($definitions['result_provenance']['properties']['retrieval_methods']['items']['enum'])->toBe([
        'search_endpoint',
        'model_search_tool',
        'research_agent',
        'model_only',
        'surface_collector',
    ])->and($definitions['result_provenance']['properties']['corpora']['items']['enum'])->toBe([
        'web',
        'news',
        'x',
        'files',
        'places',
        'specialized',
    ])->and($fixture['results'][0]['provenance']['corpora'])->toBe([
        'web',
        'news',
        'x',
        'files',
        'places',
        'specialized',
    ])->and($definitions['result_provenance']['properties']['context']['properties']['authentication']['enum'])->toBe([
        'anonymous',
        'authenticated',
        'managed',
        'unknown',
    ])->and($definitions['result_provenance']['properties']['context']['properties']['personalization']['enum'])->toBe([
        'present',
        'absent',
        'unknown',
    ])->and($definitions['result_provenance']['properties']['observation_mode']['enum'])->toBe([
        'api_output',
        'surface_snapshot',
    ])->and($definitions['citation']['properties']['derivation']['enum'])->toBe([
        'provider_reported',
        'collector_extracted',
        'librarium_inferred',
    ])->and($definitions['source']['properties']['kind']['enum'])->toBe([
        'web_page',
        'news_article',
        'x_post',
        'file',
        'place',
        'video',
        'forum_post',
        'unknown',
    ])->and($contentFormats)->toBe([
        'markdown',
        'text',
        'json',
    ]);
});

it('covers provider metadata safety without the removed size and depth caps', function (): void {
    $schemaContents = File::get(__DIR__.'/../Support/Contracts/v1/schema/interchange.schema.json');
    $fixtureIndex = contractSnapshotJson('fixtures/index.json');
    $largeMetadataFixture = contractSnapshotJson('fixtures/valid/provider-meta-namespaces.json');
    $providerMetadata = $largeMetadataFixture['results'][0]['provider_meta'];
    $semanticFixtureIds = array_column(array_values(array_filter(
        $fixtureIndex['fixtures'],
        static fn (array $fixture): bool => ($fixture['semantic_rule_id'] ?? null) === 'provider_meta.safe_metadata',
    )), 'id');
    sort($semanticFixtureIds);

    expect($schemaContents)->not->toContain('maxProperties')
        ->not->toContain('maxItems')
        ->and(strlen($providerMetadata['io.other:metrics']['large']))->toBeGreaterThan(8_000)
        ->and($providerMetadata['com.example:public']['nested']['value'])->toBeTrue()
        ->and($semanticFixtureIds)->toBe([
            'provider-meta-fused-access-token',
            'provider-meta-fused-api-key',
            'provider-meta-fused-raw-response',
            'provider-meta-raw',
            'provider-meta-secret',
            'provider-meta-wrapped-api-key',
            'provider-meta-wrapped-binary-payload',
            'provider-meta-wrapped-credentials',
            'provider-meta-wrapped-raw-response',
            'provider-meta-wrapped-session-token',
        ]);
});

it('pins all fixtures and semantic rules in the generated inventories', function (): void {
    $manifest = contractSnapshotJson('manifest.json');
    $fixtureIndex = contractSnapshotJson('fixtures/index.json');
    $validFixtures = array_filter($fixtureIndex['fixtures'], static fn (array $fixture): bool => $fixture['valid']);
    $invalidFixtures = array_filter($fixtureIndex['fixtures'], static fn (array $fixture): bool => ! $fixture['valid']);
    $semanticRuleIds = array_column($manifest['semantic_rules'], 'rule_id');
    sort($semanticRuleIds);

    $totals = ContractSnapshot::pin()->contractTotals;

    expect($manifest['files'])->toHaveCount($totals['manifest_entries'])
        ->and($fixtureIndex['fixtures'])->toHaveCount($totals['valid_fixtures'] + $totals['invalid_fixtures'])
        ->and($validFixtures)->toHaveCount($totals['valid_fixtures'])
        ->and($invalidFixtures)->toHaveCount($totals['invalid_fixtures'])
        ->and($semanticRuleIds)->toBe(ContractSnapshot::SEMANTIC_RULE_IDS);
});

it('reports missing required files', function (): void {
    File::delete($this->workingSnapshotDirectory.'/schema/interchange.schema.json');

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot is missing required file(s): schema/interchange.schema.json.',
        );
});

it('reports files modified after the checksum snapshot was accepted', function (): void {
    $relativePath = 'fixtures/valid/markdown-success.json';
    File::append($this->workingSnapshotDirectory.'/'.$relativePath, "\n");

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(UnexpectedValueException::class, "Contract snapshot checksum mismatch for {$relativePath}");
});

it('reports a modified interchange schema through its dedicated checksum pin', function (): void {
    File::append($this->workingSnapshotDirectory.'/schema/interchange.schema.json', "\n");

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot interchange schema does not match the pinned upstream baseline',
        );
});

it('reports unexpected extra files', function (): void {
    File::put($this->workingSnapshotDirectory.'/unexpected.json', '{}');

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot contains unexpected file(s): unexpected.json.',
        );
});

it('reports unexpected empty directories', function (): void {
    File::makeDirectory($this->workingSnapshotDirectory.'/unexpected-empty');

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot contains unexpected directory: unexpected-empty.',
        );
});

it('reports unsupported special filesystem entries', function (): void {
    if (! function_exists('posix_mkfifo')) {
        $this->markTestSkipped('The platform does not support FIFO creation for this test.');
    }

    $fifoPath = $this->workingSnapshotDirectory.'/unexpected.pipe';

    if (! @posix_mkfifo($fifoPath, 0o600)) {
        $this->markTestSkipped('The platform could not create a FIFO for this test.');
    }

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot contains unsupported special entry: unexpected.pipe.',
        );
});

it('rejects a tampered checksum index before trusting its entries', function (): void {
    File::append($this->workingSnapshotDirectory.'/checksums.sha256', "\n");

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot checksum index does not match the pinned upstream baseline',
        );
});
