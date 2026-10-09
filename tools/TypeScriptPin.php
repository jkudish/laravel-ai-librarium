<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tools;

use JsonException;
use RuntimeException;

/**
 * The one record of which jkudish/librarium snapshot this package vendors:
 * the terminal contract directory and the implemented Profile inventory.
 *
 * `composer typescript:repin` is the only writer. The contract verifier, the
 * Profile matrix test, and the release-readiness check read it, so moving the
 * pin never requires editing a hard-coded commit or digest.
 */
final readonly class TypeScriptPin
{
    public const string PATH = 'tests/Support/typescript-pin.json';

    public const string REPOSITORY = 'jkudish/librarium';

    public const string CONTRACTS_PATH = 'tests/Support/Contracts/v1';

    public const string CONTRACTS_README_PATH = 'tests/Support/Contracts/README.md';

    public const string PROFILES_PATH = 'tests/Support/Profiles/v2/catalog.tsv';

    /** @var list<string> */
    public const array CONTRACT_TOTALS = [
        'schemas',
        'definitions',
        'valid_fixtures',
        'invalid_fixtures',
        'semantic_rules',
        'manifest_entries',
        'checksum_entries',
        'snapshot_files',
    ];

    /**
     * @param  array{schemas: int, definitions: int, valid_fixtures: int, invalid_fixtures: int, semantic_rules: int, manifest_entries: int, checksum_entries: int, snapshot_files: int}  $contractTotals
     */
    public function __construct(
        public string $ref,
        public ?string $tag,
        public string $commit,
        public string $packageVersion,
        public string $contractChecksumsSha256,
        public string $contractManifestSha256,
        public string $contractInterchangeSchemaSha256,
        public array $contractTotals,
        public string $profilesSha256,
        public int $profileCount,
    ) {
        if (
            $ref === ''
            || preg_match('/[\x00-\x20\x7F]/', $ref) === 1
            || str_starts_with($ref, '-')
            || ($tag !== null && preg_match('/\Av\d+\.\d+\.\d+\z/D', $tag) !== 1)
            || ($tag !== null && $tag !== $ref)
            || preg_match('/\A[a-f0-9]{40}\z/D', $commit) !== 1
            || preg_match('/\A\d+\.\d+\.\d+\z/D', $packageVersion) !== 1
            || ! self::isSha256($contractChecksumsSha256)
            || ! self::isSha256($contractManifestSha256)
            || ! self::isSha256($contractInterchangeSchemaSha256)
            || ! self::isSha256($profilesSha256)
            || $profileCount < 1
            || min($contractTotals) < 1
            || $contractTotals['checksum_entries'] !== $contractTotals['manifest_entries']
            || $contractTotals['snapshot_files'] !== $contractTotals['checksum_entries'] + 1
        ) {
            throw new RuntimeException('The TypeScript pin is malformed.');
        }
    }

    public static function load(string $root): self
    {
        $path = rtrim($root, '/').'/'.self::PATH;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new RuntimeException('The TypeScript pin is unreadable: '.self::PATH.'.');
        }

        try {
            $value = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The TypeScript pin contains invalid JSON.', previous: $exception);
        }

        if (! is_array($value)) {
            throw new RuntimeException('The TypeScript pin is malformed.');
        }

        $pin = self::fromArray($value);

        if ($pin->toJson() !== $contents) {
            throw new RuntimeException('The TypeScript pin is not in canonical form; regenerate it with composer typescript:repin.');
        }

        return $pin;
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $contracts = $value['contracts'] ?? null;
        $profiles = $value['profiles'] ?? null;
        $totals = is_array($contracts) ? ($contracts['totals'] ?? null) : null;

        if (
            array_keys($value) !== ['schema_version', 'repository', 'ref', 'tag', 'commit', 'package_version', 'contracts', 'profiles']
            || $value['schema_version'] !== 1
            || $value['repository'] !== self::REPOSITORY
            || ! is_string($value['ref'])
            || ! (is_string($value['tag']) || $value['tag'] === null)
            || ! is_string($value['commit'])
            || ! is_string($value['package_version'])
            || ! is_array($contracts)
            || array_keys($contracts) !== ['checksums_sha256', 'manifest_sha256', 'interchange_schema_sha256', 'totals']
            || ! is_string($contracts['checksums_sha256'])
            || ! is_string($contracts['manifest_sha256'])
            || ! is_string($contracts['interchange_schema_sha256'])
            || ! is_array($totals)
            || array_keys($totals) !== self::CONTRACT_TOTALS
            || array_filter($totals, static fn (mixed $total): bool => ! is_int($total)) !== []
            || ! is_array($profiles)
            || array_keys($profiles) !== ['sha256', 'count']
            || ! is_string($profiles['sha256'])
            || ! is_int($profiles['count'])
        ) {
            throw new RuntimeException('The TypeScript pin is malformed.');
        }

        /** @var array{schemas: int, definitions: int, valid_fixtures: int, invalid_fixtures: int, semantic_rules: int, manifest_entries: int, checksum_entries: int, snapshot_files: int} $totals */
        return new self(
            ref: $value['ref'],
            tag: $value['tag'],
            commit: $value['commit'],
            packageVersion: $value['package_version'],
            contractChecksumsSha256: $contracts['checksums_sha256'],
            contractManifestSha256: $contracts['manifest_sha256'],
            contractInterchangeSchemaSha256: $contracts['interchange_schema_sha256'],
            contractTotals: $totals,
            profilesSha256: $profiles['sha256'],
            profileCount: $profiles['count'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'repository' => self::REPOSITORY,
            'ref' => $this->ref,
            'tag' => $this->tag,
            'commit' => $this->commit,
            'package_version' => $this->packageVersion,
            'contracts' => [
                'checksums_sha256' => $this->contractChecksumsSha256,
                'manifest_sha256' => $this->contractManifestSha256,
                'interchange_schema_sha256' => $this->contractInterchangeSchemaSha256,
                'totals' => $this->contractTotals,
            ],
            'profiles' => [
                'sha256' => $this->profilesSha256,
                'count' => $this->profileCount,
            ],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public function sourceUrl(): string
    {
        return 'https://github.com/'.self::REPOSITORY.'/tree/'.$this->commit;
    }

    private static function isSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
