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

    public const string SNAPSHOT_FORMAT_VERSION = '1.0.0';

    public const string UPSTREAM_COMMIT = '04f1b512e1cd19b03f48b794d13c165ccfc5c81a';

    public const string MANIFEST_SHA256 = '23c12e55ab3f99ff8c4aa4cfd70b55411bcc831636305ea01f6c1cd811237af3';

    public const string CHECKSUMS_SHA256 = 'c49bd2ad000ce861d3e15908939e0b473da698e63a6f0c555463dcea9f6f30c5';

    public const int MANIFEST_FILE_COUNT = 78;

    public const int CHECKSUM_ENTRY_COUNT = 79;

    public const int SNAPSHOT_FILE_COUNT = 80;

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
     * @return array<string, mixed>
     */
    public function verify(): array
    {
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
        $actualFiles = $this->loadFileInventory();

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

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadManifest(): array
    {
        $manifest = $this->decodeJsonFile('manifest.json');
        $validator = Validator::make($manifest, [
            'snapshot_format_version' => ['required', 'string', Rule::in([self::SNAPSHOT_FORMAT_VERSION])],
            'contract_set' => ['required', 'string', Rule::in(['librarium_contracts'])],
            'generated_at' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'owner' => ['required', 'string', Rule::in(['typescript_librarium'])],
            'ownership_policy' => ['required', 'string', Rule::in(['canonical_upstream'])],
            'checksum_algorithm' => ['required', 'string', Rule::in(['sha256'])],
            'checksum_file' => ['required', 'string', Rule::in(['checksums.sha256'])],
            'versions' => ['required', 'array:domain,artifacts,custom_provider,interchange'],
            'versions.domain' => ['required', 'string'],
            'versions.artifacts' => ['required', 'string'],
            'versions.custom_provider' => ['required', 'string'],
            'versions.interchange' => ['required', 'string'],
            'semantic_rules' => ['required', 'array'],
            'semantic_rules.*' => ['required', 'array:rule_id,version,description'],
            'semantic_rules.*.rule_id' => ['required', 'string', 'distinct'],
            'semantic_rules.*.version' => ['required', 'string'],
            'semantic_rules.*.description' => ['required', 'string'],
            'files' => ['required', 'array', 'size:'.self::MANIFEST_FILE_COUNT],
            'files.*' => ['required', 'array:path,role,areas'],
            'files.*.path' => ['required', 'string', 'distinct'],
            'files.*.role' => ['required', 'string'],
            'files.*.areas' => ['required', 'array'],
            'files.*.areas.*' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException('Contract snapshot manifest is invalid: '.$validator->errors()->first());
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

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

            if ($relativePath === 'checksums.sha256' || preg_match('#(?:\A|/)\.{1,2}(?:/|\z)#', $relativePath) === 1) {
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
     * @return list<string>
     */
    private function loadFileInventory(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($this->directory) + 1));

            if ($file->isLink()) {
                throw new UnexpectedValueException("Contract snapshot contains unsupported symbolic link: {$relativePath}.");
            }

            if ($file->isFile()) {
                $files[] = $relativePath;
            }
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $checksums
     */
    private function verifyManifestInventory(array $manifest, array $checksums): void
    {
        /** @var list<array{path: string}> $manifestFiles */
        $manifestFiles = $manifest['files'];
        $manifestPaths = array_column($manifestFiles, 'path');
        $checksummedPayloadPaths = array_values(array_diff(array_keys($checksums), ['manifest.json']));

        sort($manifestPaths);
        sort($checksummedPayloadPaths);

        if ($manifestPaths !== $checksummedPayloadPaths) {
            throw new UnexpectedValueException('Contract snapshot manifest file inventory does not match the checksum index.');
        }
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
