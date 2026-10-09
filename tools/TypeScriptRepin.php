<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tools;

use Closure;
use JsonException;
use RuntimeException;

require_once __DIR__.'/TypeScriptPin.php';

/**
 * Repins the vendored jkudish/librarium terminal contract and implemented
 * Profile inventory to one exact checkout.
 *
 * The checkout must be clean and have the named ref checked out. Contract
 * bytes are read from Git objects at the resolved commit, verified against
 * the upstream checksum index, and replace tests/Support/Contracts/v1
 * wholesale. The Profile inventory reproduces the generator that produced the
 * original 39-row snapshot: it evaluates BUILTIN_PROVIDER_CATALOG from
 * src/core-entry.ts, keeps implemented Profiles, and sorts rows by key. That
 * step bundles the catalog with the checkout's own esbuild into a temporary
 * directory outside both repositories, so it needs Node and the checkout's
 * installed dependencies but never writes into the checkout.
 *
 * Finally it rewrites the pin record, the snapshot README, and the generated
 * authority block in docs/profile-bindings.md. It makes no network calls.
 */
final readonly class TypeScriptRepin
{
    public const string DOCS_PATH = 'docs/profile-bindings.md';

    public const string DOCS_START = '<!-- typescript-pin:start -->';

    public const string DOCS_END = '<!-- typescript-pin:end -->';

    /** @var list<string> */
    private const array INVENTORY_COLUMNS = [
        'profile',
        'result_kind',
        'grounding',
        'observation',
        'corpora',
        'retrieval_methods',
        'invocation',
        'resumability',
        'workflows',
        'target',
    ];

    private const string INVENTORY_SCRIPT = <<<'JS'
        import { createRequire } from 'node:module';
        import { join } from 'node:path';
        import { pathToFileURL } from 'node:url';

        const [checkout, outputDirectory] = process.argv.slice(2);
        const esbuild = createRequire(join(checkout, 'package.json'))('esbuild');
        const outfile = join(outputDirectory, 'core.mjs');

        await esbuild.build({
          entryPoints: [join(checkout, 'src/core-entry.ts')],
          absWorkingDir: checkout,
          bundle: true,
          format: 'esm',
          platform: 'node',
          outfile,
          logLevel: 'error',
        });

        const { BUILTIN_PROVIDER_CATALOG } = await import(pathToFileURL(outfile).href);
        const rows = BUILTIN_PROVIDER_CATALOG.flatMap((entry) => entry.profiles
          .filter((profile) => profile.status === 'implemented')
          .map((profile) => [
            `${entry.provider_id}/${profile.profile_id}`,
            profile.result_kind,
            profile.grounding_policy ?? 'none',
            profile.observation_mode,
            profile.corpora.join(','),
            profile.retrieval_method,
            profile.invocation,
            profile.resumability,
            profile.workflows.join(','),
            JSON.stringify(profile.target),
          ]));

        process.stdout.write(JSON.stringify(rows));
        JS;

    private string $root;

    /** @var Closure(string): list<list<string>> */
    private Closure $inventory;

    /**
     * @param  (Closure(string): list<list<string>>)|null  $inventory  Evaluates the checkout's implemented Profile rows.
     */
    public function __construct(string $root = __DIR__.'/..', ?Closure $inventory = null)
    {
        $this->root = rtrim($root, '/');
        $this->inventory = $inventory ?? self::nodeInventory(...);
    }

    public function repin(string $checkout, string $ref): TypeScriptPin
    {
        $checkout = $this->checkout($checkout);

        if ($ref === '' || str_starts_with($ref, '-') || preg_match('/[\x00-\x20\x7F]/', $ref) === 1) {
            throw new RuntimeException('The ref must be a non-empty Git ref name without whitespace.');
        }

        $commit = trim(self::git($checkout, ['rev-parse', '--verify', '--quiet', '--end-of-options', $ref.'^{commit}']));
        if (preg_match('/\A[a-f0-9]{40}\z/D', $commit) !== 1) {
            throw new RuntimeException("The ref [{$ref}] does not resolve to a commit in {$checkout}.");
        }

        if (trim(self::git($checkout, ['rev-parse', 'HEAD'])) !== $commit) {
            throw new RuntimeException("Check out [{$ref}] ({$commit}) in {$checkout} before repinning.");
        }

        if (trim(self::git($checkout, ['status', '--porcelain', '--untracked-files=no'])) !== '') {
            throw new RuntimeException("The checkout {$checkout} has uncommitted changes to tracked files.");
        }

        $package = self::decodeObject(self::blob($checkout, $commit, 'package.json'), 'package.json');
        if (($package['name'] ?? null) !== 'librarium' || ! is_string($package['version'] ?? null)) {
            throw new RuntimeException("{$checkout} is not a jkudish/librarium checkout.");
        }

        $tagCommit = trim(self::git($checkout, ['rev-parse', '--verify', '--quiet', '--end-of-options', 'refs/tags/'.$ref.'^{commit}'], allowFailure: true));
        $tag = $tagCommit === $commit ? $ref : null;

        $contracts = $this->vendorContracts($checkout, $commit);
        [$profilesSha256, $profileCount] = $this->writeInventory($checkout, $commit, $package['version']);

        $pin = new TypeScriptPin(
            ref: $ref,
            tag: $tag,
            commit: $commit,
            packageVersion: $package['version'],
            contractChecksumsSha256: $contracts['checksums_sha256'],
            contractManifestSha256: $contracts['manifest_sha256'],
            contractInterchangeSchemaSha256: $contracts['interchange_schema_sha256'],
            contractTotals: $contracts['totals'],
            profilesSha256: $profilesSha256,
            profileCount: $profileCount,
        );

        $this->write(TypeScriptPin::PATH, $pin->toJson());
        $this->write(TypeScriptPin::CONTRACTS_README_PATH, self::contractsReadme($pin));
        $this->writeDocsBlock($pin);

        return $pin;
    }

    public static function docsBlock(TypeScriptPin $pin): string
    {
        $release = $pin->tag === null
            ? 'no release tag yet; the release check blocks until the pin names the accepted tag'
            : "release tag `{$pin->tag}`";

        return implode("\n", [
            self::DOCS_START,
            '- TypeScript catalog: [`'.TypeScriptPin::REPOSITORY."` at `{$pin->commit}`]({$pin->sourceUrl()}), ref `{$pin->ref}`, package version `{$pin->packageVersion}`, {$release}",
            '- Catalog source: `src/core/provider-profiles.ts` and its exact profile bindings',
            '- Vendored inventory: `'.TypeScriptPin::PROFILES_PATH.'`, SHA-256',
            "  `{$pin->profilesSha256}`, {$pin->profileCount} implemented Profiles",
            '- Pin record: `'.TypeScriptPin::PATH.'`, written only by `composer typescript:repin`',
            self::DOCS_END,
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    public static function inventory(string $commit, string $packageVersion, string $catalogSha256, string $bindingsSha256, array $rows): string
    {
        $profiles = [];

        foreach ($rows as $row) {
            if (count($row) !== count(self::INVENTORY_COLUMNS)) {
                throw new RuntimeException('The TypeScript Profile inventory contains a malformed row.');
            }

            foreach ($row as $field) {
                if (preg_match('/[\t\r\n]/', $field) === 1) {
                    throw new RuntimeException('The TypeScript Profile inventory contains a field with a tab or line break.');
                }
            }

            $key = $row[0];
            if (preg_match('/\A[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\z/D', $key) !== 1 || isset($profiles[$key])) {
                throw new RuntimeException("The TypeScript Profile inventory contains an invalid or duplicate key [{$key}].");
            }

            try {
                $target = json_decode($row[9], true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException("The TypeScript Profile target [{$key}] is invalid JSON.", previous: $exception);
            }

            if (! is_array($target) || array_is_list($target)) {
                throw new RuntimeException("The TypeScript Profile target [{$key}] must be an object.");
            }

            $profiles[$key] = implode("\t", $row);
        }

        if ($profiles === []) {
            throw new RuntimeException('The TypeScript Profile inventory is empty.');
        }

        ksort($profiles, SORT_STRING);

        return implode("\n", [
            '# schema-version: 1',
            '# repository: '.TypeScriptPin::REPOSITORY,
            "# commit: {$commit}",
            "# package-version: {$packageVersion}",
            "# catalog: src/core/provider-profiles.ts sha256:{$catalogSha256}",
            "# bindings: src/core/profile-bindings.ts sha256:{$bindingsSha256}",
            implode("\t", self::INVENTORY_COLUMNS),
            ...array_values($profiles),
        ])."\n";
    }

    private function checkout(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("The jkudish/librarium checkout does not exist: {$path}.");
        }

        if (realpath(trim(self::git($resolved, ['rev-parse', '--show-toplevel']))) !== $resolved) {
            throw new RuntimeException("{$path} is not the root of a Git checkout.");
        }

        return $resolved;
    }

    /**
     * @return array{checksums_sha256: string, manifest_sha256: string, interchange_schema_sha256: string, totals: array{schemas: int, definitions: int, valid_fixtures: int, invalid_fixtures: int, semantic_rules: int, manifest_entries: int, checksum_entries: int, snapshot_files: int}}
     */
    private function vendorContracts(string $checkout, string $commit): array
    {
        $files = [];
        $listing = self::git($checkout, ['ls-tree', '-r', '-z', '--full-tree', $commit, '--', 'contracts/v1']);

        foreach (array_filter(explode("\0", $listing), static fn (string $entry): bool => $entry !== '') as $entry) {
            if (preg_match('/\A100644 blob ([a-f0-9]{40,64})\tcontracts\/v1\/(.+)\z/sD', $entry, $matches) !== 1) {
                throw new RuntimeException('The upstream contract snapshot may contain only regular files.');
            }

            $relative = $matches[2];
            if (preg_match('#(?:\A|/)\.{1,2}(?:/|\z)|\\\\|[\x00-\x1F\x7F]#', $relative) === 1) {
                throw new RuntimeException("The upstream contract snapshot contains an unsafe path: {$relative}.");
            }

            $files[$relative] = self::git($checkout, ['cat-file', 'blob', $matches[1]]);
        }

        if (! isset($files['checksums.sha256'], $files['manifest.json'], $files['fixtures/index.json'], $files['schema/interchange.schema.json'])) {
            throw new RuntimeException('The upstream contract snapshot is missing a required file.');
        }

        $checksums = [];
        foreach (explode("\n", rtrim($files['checksums.sha256'], "\n")) as $line) {
            if (preg_match('/\A([a-f0-9]{64}) {2}([^\r\n]+)\z/D', $line, $matches) !== 1 || isset($checksums[$matches[2]])) {
                throw new RuntimeException('The upstream contract checksum index is malformed.');
            }

            $checksums[$matches[2]] = $matches[1];
        }

        $expected = array_keys($files);
        $indexed = [...array_keys($checksums), 'checksums.sha256'];
        sort($expected);
        sort($indexed);

        if ($expected !== $indexed) {
            throw new RuntimeException('The upstream contract checksum index does not list exactly the snapshot files.');
        }

        foreach ($checksums as $relative => $checksum) {
            if (! hash_equals($checksum, hash('sha256', $files[$relative]))) {
                throw new RuntimeException("The upstream contract file {$relative} does not match its checksum.");
            }
        }

        $manifest = self::decodeObject($files['manifest.json'], 'manifest.json');
        $index = self::decodeObject($files['fixtures/index.json'], 'fixtures/index.json');
        $schema = self::decodeObject($files['schema/interchange.schema.json'], 'schema/interchange.schema.json');
        $manifestFiles = $manifest['files'] ?? null;
        $fixtures = $index['fixtures'] ?? null;
        $definitions = $schema['$defs'] ?? null;
        $semanticRules = $manifest['semantic_rules'] ?? null;

        if (! is_array($manifestFiles) || ! is_array($fixtures) || ! is_array($definitions) || ! is_array($semanticRules)) {
            throw new RuntimeException('The upstream contract inventories are malformed.');
        }

        $roles = array_count_values(array_map(static fn (mixed $file): string => is_array($file) && is_string($file['role'] ?? null) ? $file['role'] : '', $manifestFiles));
        $valid = count(array_filter($fixtures, static fn (mixed $fixture): bool => is_array($fixture) && ($fixture['valid'] ?? null) === true));

        $this->replaceDirectory(TypeScriptPin::CONTRACTS_PATH, $files);

        return [
            'checksums_sha256' => hash('sha256', $files['checksums.sha256']),
            'manifest_sha256' => $checksums['manifest.json'],
            'interchange_schema_sha256' => $checksums['schema/interchange.schema.json'],
            'totals' => [
                'schemas' => $roles['schema'] ?? 0,
                'definitions' => count($definitions),
                'valid_fixtures' => $valid,
                'invalid_fixtures' => count($fixtures) - $valid,
                'semantic_rules' => count($semanticRules),
                'manifest_entries' => count($manifestFiles),
                'checksum_entries' => count($checksums),
                'snapshot_files' => count($files),
            ],
        ];
    }

    /** @return array{0: string, 1: int} */
    private function writeInventory(string $checkout, string $commit, string $packageVersion): array
    {
        $rows = ($this->inventory)($checkout);
        $contents = self::inventory(
            commit: $commit,
            packageVersion: $packageVersion,
            catalogSha256: hash('sha256', self::blob($checkout, $commit, 'src/core/provider-profiles.ts')),
            bindingsSha256: hash('sha256', self::blob($checkout, $commit, 'src/core/profile-bindings.ts')),
            rows: $rows,
        );

        $this->write(TypeScriptPin::PROFILES_PATH, $contents);

        return [hash('sha256', $contents), count($rows)];
    }

    private function writeDocsBlock(TypeScriptPin $pin): void
    {
        $path = $this->root.'/'.self::DOCS_PATH;
        $contents = file_get_contents($path);

        if (! is_string($contents) || substr_count($contents, self::DOCS_START) !== 1 || substr_count($contents, self::DOCS_END) !== 1) {
            throw new RuntimeException('docs/profile-bindings.md must contain exactly one generated TypeScript pin block.');
        }

        $start = strpos($contents, self::DOCS_START);
        $end = strpos($contents, self::DOCS_END);
        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('docs/profile-bindings.md has a malformed generated TypeScript pin block.');
        }

        $this->write(self::DOCS_PATH, substr($contents, 0, $start).self::docsBlock($pin).substr($contents, $end + strlen(self::DOCS_END)));
    }

    /** @param array<string, string> $files */
    private function replaceDirectory(string $relative, array $files): void
    {
        $target = $this->root.'/'.$relative;
        $staging = dirname($target).'/.'.basename($target).'-repin-'.sprintf('%x', random_int(0, PHP_INT_MAX));
        $retired = $staging.'-previous';

        foreach ($files as $path => $contents) {
            $file = $staging.'/'.$path;
            if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0755, true)) {
                throw new RuntimeException("Unable to stage {$relative}/{$path}.");
            }

            if (file_put_contents($file, $contents) !== strlen($contents)) {
                throw new RuntimeException("Unable to stage {$relative}/{$path}.");
            }
        }

        if (is_dir($target) && ! rename($target, $retired)) {
            throw new RuntimeException("Unable to replace {$relative}.");
        }

        if (! rename($staging, $target)) {
            throw new RuntimeException("Unable to replace {$relative}.");
        }

        if (is_dir($retired)) {
            self::removeDirectory($retired);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) && ! is_link($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }

    private function write(string $relative, string $contents): void
    {
        if (file_put_contents($this->root.'/'.$relative, $contents) !== strlen($contents)) {
            throw new RuntimeException("Unable to write {$relative}.");
        }
    }

    private static function contractsReadme(TypeScriptPin $pin): string
    {
        $totals = $pin->contractTotals;
        $tag = $pin->tag ?? 'none';
        $provenance = $pin->tag === null
            ? "This is a development pin to ref `{$pin->ref}`. Before the final package\nrelease, rerun `composer typescript:repin` against the accepted Librarium\n`v{$pin->packageVersion}` Git tag; the release check blocks until the pin names it."
            : "This pin is the accepted Librarium release tag `{$pin->tag}`.";

        return <<<MD
            # Development Contract Snapshot

            `v1/` is a byte-for-byte copy of `contracts/v1` from
            [`jkudish/librarium`]({$pin->sourceUrl()}) commit
            `{$pin->commit}`. It carries no PHP-local adjustments.

            Pinned facts (from `tests/Support/typescript-pin.json`):

            - Upstream ref: `{$pin->ref}`
            - Release tag: `{$tag}`
            - Package version: `{$pin->packageVersion}`
            - Contract directory: `v1`
            - Contract scope: terminal interchange only
            - Manifest SHA-256: `{$pin->contractManifestSha256}`
            - Checksum index SHA-256: `{$pin->contractChecksumsSha256}`
            - Interchange schema SHA-256: `{$pin->contractInterchangeSchemaSha256}`
            - Schemas: {$totals['schemas']}
            - Top-level schema definitions: {$totals['definitions']}
            - Valid fixtures: {$totals['valid_fixtures']}
            - Invalid fixtures: {$totals['invalid_fixtures']}
            - Semantic rules: {$totals['semantic_rules']}
            - Manifest entries: {$totals['manifest_entries']}, including `manifest.json`
            - Checksum entries: {$totals['checksum_entries']}, including `manifest.json`
            - Total snapshot files: {$totals['snapshot_files']}, including `checksums.sha256`

            Do not edit these files by hand. Regenerate the snapshot, the Profile
            inventory, and every pin with:

            ```bash
            composer typescript:repin -- /path/to/librarium <ref>
            ```

            The checkout must be clean with `<ref>` checked out and its npm
            dependencies installed. The PHP verifier itself runs offline and adds no
            TypeScript, npm, or Node runtime dependency. The snapshot is test support
            only and is not part of the package's public API.

            {$provenance}

            MD;
    }

    private static function blob(string $checkout, string $commit, string $path): string
    {
        return self::git($checkout, ['show', "{$commit}:{$path}"]);
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $contents, string $label): array
    {
        try {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The upstream file {$label} contains invalid JSON.", previous: $exception);
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException("The upstream file {$label} must contain a JSON object.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<list<string>> */
    private static function nodeInventory(string $checkout): array
    {
        $directory = sys_get_temp_dir().'/librarium-inventory-'.sprintf('%x', random_int(0, PHP_INT_MAX));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create a temporary directory for the Profile inventory.');
        }

        try {
            $script = $directory.'/inventory.mjs';
            file_put_contents($script, self::INVENTORY_SCRIPT);
            $output = self::run('node', [$script, $checkout, $directory], $checkout, 'Evaluating the TypeScript Profile catalog requires Node and the checkout\'s installed npm dependencies');
        } finally {
            self::removeDirectory($directory);
        }

        try {
            $rows = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The TypeScript Profile catalog returned invalid JSON.', previous: $exception);
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('The TypeScript Profile catalog returned a malformed inventory.');
        }

        $inventory = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row) || array_filter($row, static fn (mixed $field): bool => ! is_string($field)) !== []) {
                throw new RuntimeException('The TypeScript Profile catalog returned a malformed row.');
            }

            /** @var list<string> $row */
            $inventory[] = $row;
        }

        return $inventory;
    }

    /** @param list<string> $arguments */
    private static function git(string $checkout, array $arguments, bool $allowFailure = false): string
    {
        return self::run('git', ['-C', $checkout, ...$arguments], $checkout, 'Git failed', $allowFailure);
    }

    /** @param list<string> $arguments */
    private static function run(string $command, array $arguments, string $workingDirectory, string $failure, bool $allowFailure = false): string
    {
        $pipes = [];
        $process = proc_open([$command, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory, null, ['bypass_shell' => true]);

        if (! is_resource($process)) {
            throw new RuntimeException("{$failure}: {$command} could not start.");
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0 && ! $allowFailure) {
            throw new RuntimeException("{$failure}: {$command} exited {$status}. ".trim(substr($stderr, 0, 2000)));
        }

        return $status === 0 ? $stdout : '';
    }
}
