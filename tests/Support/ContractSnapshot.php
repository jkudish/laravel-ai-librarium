<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

final class ContractSnapshot
{
    public const string CONTRACT_VERSION = 'v1';

    public const int SNAPSHOT_VERSION = 1;

    public const string UPSTREAM_COMMIT = 'b3fbacef70ff41975eba131756f3f59cfddf8bd2';

    public const string MANIFEST_SHA256 = '32055999bc9a72cf9cc7a7fd5f14f1a40df36bd542dd1cb74d83da485b562404';

    public const string CHECKSUMS_SHA256 = '0d1e11fdc7e3461d0f692a3cd2aa18c2fd713ff639289602664d20c69677129a';

    public const string INTERCHANGE_SCHEMA_SHA256 = '637a0d93418bc742ecbcb07d84cecacd1f61168d8389645da349fdeebb6264cd';

    public const int SCHEMA_FILE_COUNT = 1;

    public const int DEFINITION_COUNT = 7;

    public const int VALID_FIXTURE_COUNT = 17;

    public const int INVALID_FIXTURE_COUNT = 34;

    public const int FIXTURE_COUNT = self::VALID_FIXTURE_COUNT + self::INVALID_FIXTURE_COUNT;

    public const int SEMANTIC_RULE_COUNT = 5;

    public const int MANIFEST_FILE_COUNT = 54;

    public const int CHECKSUM_ENTRY_COUNT = 54;

    public const int SNAPSHOT_FILE_COUNT = 55;

    /** @var list<string> */
    public const array DEFINITION_NAMES = [
        'citation',
        'research_error',
        'research_response',
        'research_result',
        'result_provenance',
        'source',
        'usage',
    ];

    /** @var list<string> */
    public const array SEMANTIC_RULE_IDS = [
        'provider_meta.safe_metadata',
        'research_response.terminal_shape',
        'result_provenance.surface_requires_collector',
        'source.locator_required',
        'usage.cost_requires_currency',
    ];

    private readonly string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public static function vendored(): self
    {
        return new self(__DIR__.'/Contracts/'.self::CONTRACT_VERSION);
    }

    /**
     * Verify the pinned snapshot without network or TypeScript runtime access.
     *
     * @return array{
     *     contract_version: string,
     *     snapshot_version: int,
     *     upstream_commit: string,
     *     manifest_sha256: string,
     *     checksums_sha256: string,
     *     interchange_schema_sha256: string,
     *     totals: array{
     *         schemas: int,
     *         definitions: int,
     *         valid_fixtures: int,
     *         invalid_fixtures: int,
     *         semantic_rules: int,
     *         manifest_entries: int,
     *         checksum_entries: int,
     *         snapshot_files: int
     *     }
     * }
     */
    public function verify(): array
    {
        if (is_link($this->directory)) {
            throw new UnexpectedValueException("Contract snapshot root must not be a symbolic link: {$this->directory}.");
        }

        if (! is_dir($this->directory)) {
            throw new UnexpectedValueException("Contract snapshot directory does not exist: {$this->directory}.");
        }

        $checksumFile = $this->directory.'/checksums.sha256';

        if (! is_file($checksumFile)) {
            throw new UnexpectedValueException('Contract snapshot is missing required file: checksums.sha256.');
        }

        $this->verifyChecksumIndex($checksumFile);

        $checksums = $this->loadChecksums($checksumFile);
        $expectedFiles = [...array_keys($checksums), 'checksums.sha256'];
        $actualFiles = $this->loadFileInventory($expectedFiles);

        sort($expectedFiles);
        sort($actualFiles);

        $missingFiles = array_values(array_diff($expectedFiles, $actualFiles));
        $unexpectedFiles = array_values(array_diff($actualFiles, $expectedFiles));

        if ($missingFiles !== []) {
            throw new UnexpectedValueException('Contract snapshot is missing required file(s): '.implode(', ', $missingFiles).'.');
        }

        if ($unexpectedFiles !== []) {
            throw new UnexpectedValueException('Contract snapshot contains unexpected file(s): '.implode(', ', $unexpectedFiles).'.');
        }

        if (count($actualFiles) !== self::SNAPSHOT_FILE_COUNT) {
            throw new UnexpectedValueException(
                'Contract snapshot contains '.count($actualFiles)
                .' files; expected '.self::SNAPSHOT_FILE_COUNT.'.'
            );
        }

        $this->verifyInterchangeSchemaChecksum();

        foreach ($checksums as $relativePath => $expectedChecksum) {
            $actualChecksum = hash_file('sha256', $this->directory.'/'.$relativePath);

            if ($actualChecksum === false) {
                throw new UnexpectedValueException("Unable to hash contract snapshot file: {$relativePath}.");
            }

            if (! hash_equals($expectedChecksum, $actualChecksum)) {
                throw new UnexpectedValueException(
                    "Contract snapshot checksum mismatch for {$relativePath}: expected {$expectedChecksum}, got {$actualChecksum}."
                );
            }
        }

        if (($checksums['manifest.json'] ?? null) !== self::MANIFEST_SHA256) {
            throw new UnexpectedValueException('Contract snapshot checksum index does not contain the pinned manifest checksum.');
        }

        $manifest = $this->loadManifest();
        $this->verifyManifestInventory($manifest, $checksums);

        $fixtureIndex = $this->loadFixtureIndex();
        $this->verifyFixtureInventory($manifest, $fixtureIndex);

        $schema = $this->decodeJsonFile('schema/interchange.schema.json');
        $this->verifySchema($schema);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'upstream_commit' => self::UPSTREAM_COMMIT,
            'manifest_sha256' => self::MANIFEST_SHA256,
            'checksums_sha256' => self::CHECKSUMS_SHA256,
            'interchange_schema_sha256' => self::INTERCHANGE_SCHEMA_SHA256,
            'totals' => [
                'schemas' => self::SCHEMA_FILE_COUNT,
                'definitions' => self::DEFINITION_COUNT,
                'valid_fixtures' => self::VALID_FIXTURE_COUNT,
                'invalid_fixtures' => self::INVALID_FIXTURE_COUNT,
                'semantic_rules' => self::SEMANTIC_RULE_COUNT,
                'manifest_entries' => self::MANIFEST_FILE_COUNT,
                'checksum_entries' => self::CHECKSUM_ENTRY_COUNT,
                'snapshot_files' => self::SNAPSHOT_FILE_COUNT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadManifest(): array
    {
        $manifest = $this->decodeJsonFile('manifest.json');
        $validator = Validator::make($manifest, [
            'snapshot_version' => ['required', 'integer', Rule::in([self::SNAPSHOT_VERSION])],
            'title' => ['required', 'string', Rule::in(['Librarium terminal interchange snapshot'])],
            'areas' => ['required', 'array', 'size:1'],
            'areas.*' => ['required', 'string', Rule::in(['interchange'])],
            'semantic_rules' => ['required', 'array', 'size:'.self::SEMANTIC_RULE_COUNT],
            'semantic_rules.*' => ['required', 'array:rule_id,description'],
            'semantic_rules.*.rule_id' => ['required', 'string', 'distinct'],
            'semantic_rules.*.description' => ['required', 'string'],
            'files' => ['required', 'array', 'size:'.self::MANIFEST_FILE_COUNT],
            'files.*' => ['required', 'array:path,role,areas'],
            'files.*.path' => ['required', 'string', 'distinct'],
            'files.*.role' => ['required', 'string', Rule::in(['schema', 'fixture_index', 'fixture', 'manifest'])],
            'files.*.areas' => ['required', 'array', 'size:1'],
            'files.*.areas.*' => ['required', 'string', Rule::in(['interchange'])],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException('Contract snapshot manifest is invalid: '.$validator->errors()->first());
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        /** @var list<array{rule_id: string}> $semanticRules */
        $semanticRules = $validated['semantic_rules'];
        $semanticRuleIds = array_column($semanticRules, 'rule_id');
        sort($semanticRuleIds);

        if ($semanticRuleIds !== self::SEMANTIC_RULE_IDS) {
            throw new UnexpectedValueException('Contract snapshot manifest does not contain the accepted semantic rule inventory.');
        }

        return $validated;
    }

    private function verifyChecksumIndex(string $checksumFile): void
    {
        $actualChecksum = hash_file('sha256', $checksumFile);

        if ($actualChecksum === false) {
            throw new UnexpectedValueException('Unable to hash contract snapshot checksum index.');
        }

        if (! hash_equals(self::CHECKSUMS_SHA256, $actualChecksum)) {
            throw new UnexpectedValueException(
                'Contract snapshot checksum index does not match the pinned upstream baseline: '
                .'expected '.self::CHECKSUMS_SHA256.", got {$actualChecksum}."
            );
        }
    }

    private function verifyInterchangeSchemaChecksum(): void
    {
        $schemaPath = $this->directory.'/schema/interchange.schema.json';
        $actualChecksum = hash_file('sha256', $schemaPath);

        if ($actualChecksum === false) {
            throw new UnexpectedValueException('Unable to hash contract snapshot interchange schema.');
        }

        if (! hash_equals(self::INTERCHANGE_SCHEMA_SHA256, $actualChecksum)) {
            throw new UnexpectedValueException(
                'Contract snapshot interchange schema does not match the pinned upstream baseline: '
                .'expected '.self::INTERCHANGE_SCHEMA_SHA256.", got {$actualChecksum}."
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function loadChecksums(string $checksumFile): array
    {
        $lines = file($checksumFile, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new UnexpectedValueException('Unable to read contract snapshot checksum index.');
        }

        $checksums = [];

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/\A([a-f0-9]{64}) {2}([^\r\n]+)\z/D', $line, $matches) !== 1) {
                $humanLineNumber = $lineNumber + 1;

                throw new UnexpectedValueException("Malformed contract snapshot checksum entry on line {$humanLineNumber}.");
            }

            $relativePath = $matches[2];

            if ($relativePath === 'checksums.sha256' || $this->isUnsafePath($relativePath)) {
                throw new UnexpectedValueException("Unsafe contract snapshot checksum path: {$relativePath}.");
            }

            if (array_key_exists($relativePath, $checksums)) {
                throw new UnexpectedValueException("Duplicate contract snapshot checksum path: {$relativePath}.");
            }

            $checksums[$relativePath] = $matches[1];
        }

        if (count($checksums) !== self::CHECKSUM_ENTRY_COUNT) {
            throw new UnexpectedValueException(
                'Contract snapshot checksum index contains '.count($checksums)
                .' entries; expected '.self::CHECKSUM_ENTRY_COUNT.'.'
            );
        }

        return $checksums;
    }

    /**
     * @param  list<string>  $expectedFiles
     * @return list<string>
     */
    private function loadFileInventory(array $expectedFiles): array
    {
        $files = [];
        $expectedDirectories = $this->expectedDirectories($expectedFiles);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($this->directory) + 1));

            if ($file->isLink()) {
                throw new UnexpectedValueException("Contract snapshot contains unsupported symbolic link: {$relativePath}.");
            }

            if ($file->isDir()) {
                if (! in_array($relativePath, $expectedDirectories, true)) {
                    throw new UnexpectedValueException("Contract snapshot contains unexpected directory: {$relativePath}.");
                }

                continue;
            }

            if ($file->isFile()) {
                $files[] = $relativePath;

                continue;
            }

            throw new UnexpectedValueException("Contract snapshot contains unsupported special entry: {$relativePath}.");
        }

        return $files;
    }

    /**
     * @param  list<string>  $expectedFiles
     * @return list<string>
     */
    private function expectedDirectories(array $expectedFiles): array
    {
        $directories = [];

        foreach ($expectedFiles as $expectedFile) {
            $directory = dirname($expectedFile);

            while ($directory !== '.') {
                $directories[] = $directory;
                $directory = dirname($directory);
            }
        }

        $directories = array_values(array_unique($directories));
        sort($directories);

        return $directories;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $checksums
     */
    private function verifyManifestInventory(array $manifest, array $checksums): void
    {
        /** @var list<array{path: string, role: string}> $manifestFiles */
        $manifestFiles = $manifest['files'];
        $manifestPaths = array_column($manifestFiles, 'path');
        $checksumPaths = array_keys($checksums);

        sort($manifestPaths);
        sort($checksumPaths);

        if ($manifestPaths !== $checksumPaths) {
            throw new UnexpectedValueException('Contract snapshot manifest file inventory does not match the checksum index.');
        }

        $roleTotals = array_count_values(array_column($manifestFiles, 'role'));
        $expectedRoleTotals = [
            'fixture' => self::FIXTURE_COUNT,
            'fixture_index' => 1,
            'manifest' => 1,
            'schema' => self::SCHEMA_FILE_COUNT,
        ];
        ksort($roleTotals);

        if ($roleTotals !== $expectedRoleTotals) {
            throw new UnexpectedValueException('Contract snapshot manifest does not contain the accepted role totals.');
        }

        foreach ($manifestPaths as $path) {
            if ($this->isUnsafePath($path)) {
                throw new UnexpectedValueException("Unsafe contract snapshot manifest path: {$path}.");
            }
        }
    }

    /**
     * @return array{fixtures: list<array<string, mixed>>}
     */
    private function loadFixtureIndex(): array
    {
        $fixtureIndex = $this->decodeJsonFile('fixtures/index.json');
        $validator = Validator::make($fixtureIndex, [
            'fixtures' => ['required', 'array', 'size:'.self::FIXTURE_COUNT],
            'fixtures.*' => ['required', 'array:id,path,valid,area,schema_path,schema_ref,enforcement,semantic_rule_id'],
            'fixtures.*.id' => ['required', 'string', 'distinct'],
            'fixtures.*.path' => ['required', 'string', 'distinct'],
            'fixtures.*.valid' => ['required', 'boolean'],
            'fixtures.*.area' => ['required', 'string', Rule::in(['interchange'])],
            'fixtures.*.schema_path' => ['required', 'string', Rule::in(['schema/interchange.schema.json'])],
            'fixtures.*.schema_ref' => ['required', 'string', Rule::in(['#/$defs/research_response'])],
            'fixtures.*.enforcement' => ['required', 'string', Rule::in(['structural', 'semantic_rule'])],
            'fixtures.*.semantic_rule_id' => ['required_if:fixtures.*.enforcement,semantic_rule', 'string', Rule::in(self::SEMANTIC_RULE_IDS)],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException('Contract snapshot fixture index is invalid: '.$validator->errors()->first());
        }

        /** @var array{fixtures: list<array<string, mixed>>} $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{fixtures: list<array<string, mixed>>}  $fixtureIndex
     */
    private function verifyFixtureInventory(array $manifest, array $fixtureIndex): void
    {
        /** @var list<array{path: string, role: string}> $manifestFiles */
        $manifestFiles = $manifest['files'];
        $manifestFixturePaths = array_column(
            array_values(array_filter($manifestFiles, static fn (array $file): bool => $file['role'] === 'fixture')),
            'path',
        );

        $fixturePaths = [];
        $validFixtureCount = 0;
        $invalidFixtureCount = 0;
        $semanticRuleIds = [];

        foreach ($fixtureIndex['fixtures'] as $fixture) {
            /** @var string $path */
            $path = $fixture['path'];
            /** @var bool $valid */
            $valid = $fixture['valid'];
            /** @var string $enforcement */
            $enforcement = $fixture['enforcement'];

            if ($this->isUnsafePath($path)) {
                throw new UnexpectedValueException("Unsafe contract snapshot fixture path: {$path}.");
            }

            $expectedPrefix = $valid ? 'fixtures/valid/' : 'fixtures/invalid/';

            if (! str_starts_with($path, $expectedPrefix)) {
                throw new UnexpectedValueException("Contract snapshot fixture validity does not match its path: {$path}.");
            }

            if ($enforcement === 'semantic_rule') {
                if ($valid) {
                    throw new UnexpectedValueException("Semantic-rule fixture must be invalid: {$path}.");
                }

                /** @var string $semanticRuleId */
                $semanticRuleId = $fixture['semantic_rule_id'];
                $semanticRuleIds[] = $semanticRuleId;
            } elseif (array_key_exists('semantic_rule_id', $fixture)) {
                throw new UnexpectedValueException("Structural fixture contains a semantic rule identifier: {$path}.");
            }

            $this->decodeJsonFile($path);
            $fixturePaths[] = $path;
            $valid ? $validFixtureCount++ : $invalidFixtureCount++;
        }

        sort($manifestFixturePaths);
        sort($fixturePaths);

        if ($manifestFixturePaths !== $fixturePaths) {
            throw new UnexpectedValueException('Contract snapshot fixture index does not match the manifest fixture inventory.');
        }

        if ($validFixtureCount !== self::VALID_FIXTURE_COUNT || $invalidFixtureCount !== self::INVALID_FIXTURE_COUNT) {
            throw new UnexpectedValueException(
                "Contract snapshot fixture index contains {$validFixtureCount} valid and {$invalidFixtureCount} invalid fixtures; "
                .'expected '.self::VALID_FIXTURE_COUNT.' valid and '.self::INVALID_FIXTURE_COUNT.' invalid fixtures.'
            );
        }

        $semanticRuleIds = array_values(array_unique($semanticRuleIds));
        sort($semanticRuleIds);

        if ($semanticRuleIds !== self::SEMANTIC_RULE_IDS) {
            throw new UnexpectedValueException('Contract snapshot fixtures do not cover every accepted semantic rule.');
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function verifySchema(array $schema): void
    {
        $validator = Validator::make($schema, [
            '$schema' => ['required', 'string', Rule::in(['https://json-schema.org/draft/2020-12/schema'])],
            '$id' => ['required', 'string', Rule::in(['https://librarium.dev/contracts/v1/interchange'])],
            'title' => ['required', 'string', Rule::in(['Librarium terminal interchange'])],
            '$defs' => ['required', 'array', 'size:'.self::DEFINITION_COUNT],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException('Contract snapshot schema is invalid: '.$validator->errors()->first());
        }

        /** @var array<string, mixed> $definitions */
        $definitions = $schema['$defs'];
        $definitionNames = array_keys($definitions);
        sort($definitionNames);

        if ($definitionNames !== self::DEFINITION_NAMES) {
            throw new UnexpectedValueException('Contract snapshot schema does not contain the accepted definition inventory.');
        }
    }

    private function isUnsafePath(string $relativePath): bool
    {
        return str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\')
            || preg_match('#(?:\A|/)\.{1,2}(?:/|\z)#', $relativePath) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFile(string $relativePath): array
    {
        $contents = file_get_contents($this->directory.'/'.$relativePath);

        if ($contents === false) {
            throw new UnexpectedValueException("Unable to read contract snapshot JSON file: {$relativePath}.");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                "Contract snapshot JSON file {$relativePath} is invalid: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("Contract snapshot JSON file {$relativePath} must contain an object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
