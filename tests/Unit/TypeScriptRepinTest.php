<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Tools\TypeScriptPin;
use Jkudish\LaravelAiLibrarium\Tools\TypeScriptRepin;

require_once dirname(__DIR__, 2).'/tools/TypeScriptRepin.php';

/** @param list<string> $arguments */
function repinGit(string $directory, array $arguments): string
{
    return Process::path($directory)
        ->run(['git', '-c', 'user.name=Repin Test', '-c', 'user.email=repin@example.com', '-c', 'commit.gpgsign=false', ...$arguments])
        ->throw()
        ->output();
}

/** @param array<string, string> $files */
function repinWrite(string $root, array $files): void
{
    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        File::put($root.'/'.$path, $contents);
    }
}

/** @return array<string, string> */
function repinUpstreamContracts(): array
{
    $json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $files = [
        'schema/interchange.schema.json' => $json(['$defs' => ['research_response' => [], 'usage' => []]]),
        'fixtures/index.json' => $json(['fixtures' => [
            ['id' => 'ok', 'path' => 'fixtures/valid/ok.json', 'valid' => true],
            ['id' => 'bad', 'path' => 'fixtures/invalid/bad.json', 'valid' => false],
        ]]),
        'fixtures/valid/ok.json' => $json(['status' => 'succeeded']),
        'fixtures/invalid/bad.json' => $json(['status' => 'nope']),
    ];
    $files['manifest.json'] = $json([
        'semantic_rules' => [['rule_id' => 'rule.one', 'description' => 'One.']],
        'files' => [
            ['path' => 'fixtures/index.json', 'role' => 'fixture_index'],
            ['path' => 'fixtures/invalid/bad.json', 'role' => 'fixture'],
            ['path' => 'fixtures/valid/ok.json', 'role' => 'fixture'],
            ['path' => 'manifest.json', 'role' => 'manifest'],
            ['path' => 'schema/interchange.schema.json', 'role' => 'schema'],
        ],
    ]);
    ksort($files);
    $files['checksums.sha256'] = implode('', array_map(
        static fn (string $path, string $contents): string => hash('sha256', $contents)."  {$path}\n",
        array_keys($files),
        $files,
    ));

    return $files;
}

beforeEach(function (): void {
    $base = sys_get_temp_dir().'/librarium-repin-'.Str::uuid();
    $this->upstream = $base.'/librarium';
    $this->root = $base.'/package';
    $this->base = $base;

    $upstream = [
        'package.json' => "{\"name\": \"librarium\", \"version\": \"2.0.0\"}\n",
        'src/core/provider-profiles.ts' => "export const catalog = [];\n",
        'src/core/profile-bindings.ts' => "export const bindings = [];\n",
    ];
    foreach (repinUpstreamContracts() as $path => $contents) {
        $upstream['contracts/v1/'.$path] = $contents;
    }

    repinWrite($this->upstream, $upstream);
    repinGit($this->upstream, ['init', '-q', '-b', 'main']);
    repinGit($this->upstream, ['add', '-A']);
    repinGit($this->upstream, ['commit', '-q', '-m', 'snapshot']);
    repinGit($this->upstream, ['tag', 'v2.0.0']);
    $this->commit = trim(repinGit($this->upstream, ['rev-parse', 'HEAD']));

    repinWrite($this->root, [
        'tests/Support/Contracts/v1/stale.json' => "{}\n",
        'tests/Support/Contracts/README.md' => "old\n",
        'tests/Support/Profiles/v2/catalog.tsv' => "old\n",
        'docs/profile-bindings.md' => "# Matrix\n\n<!-- typescript-pin:start -->\nold\n<!-- typescript-pin:end -->\n\nRest.\n",
    ]);

    $this->rows = [
        ['serpbase/search', 'search_results', 'none', 'api_output', 'web', 'search_endpoint', 'inline', 'none', '', '{"primary":{"model_selection":"not_applicable"}}'],
        ['brave-search/search', 'search_results', 'none', 'api_output', 'web', 'search_endpoint', 'inline', 'none', 'quick,deep', '{"primary":{"model_selection":"not_applicable"}}'],
    ];
    $this->repin = new TypeScriptRepin($this->root, fn (string $checkout): array => $this->rows);
});

afterEach(function (): void {
    File::deleteDirectory($this->base);
});

it('vendors the exact upstream contract and writes the sorted inventory, pin, README, and docs block', function (): void {
    $pin = $this->repin->repin($this->upstream, 'v2.0.0');
    $contracts = repinUpstreamContracts();

    expect($pin->tag)->toBe('v2.0.0')
        ->and($pin->ref)->toBe('v2.0.0')
        ->and($pin->commit)->toBe($this->commit)
        ->and($pin->packageVersion)->toBe('2.0.0')
        ->and($pin->contractChecksumsSha256)->toBe(hash('sha256', $contracts['checksums.sha256']))
        ->and($pin->contractManifestSha256)->toBe(hash('sha256', $contracts['manifest.json']))
        ->and($pin->contractTotals)->toBe([
            'schemas' => 1,
            'definitions' => 2,
            'valid_fixtures' => 1,
            'invalid_fixtures' => 1,
            'semantic_rules' => 1,
            'manifest_entries' => 5,
            'checksum_entries' => 5,
            'snapshot_files' => 6,
        ])
        ->and($pin->profileCount)->toBe(2)
        ->and(TypeScriptPin::load($this->root))->toEqual($pin)
        ->and(File::exists($this->root.'/tests/Support/Contracts/v1/stale.json'))->toBeFalse()
        ->and(File::glob($this->root.'/tests/Support/Contracts/.v1-*'))->toBe([]);

    foreach ($contracts as $path => $contents) {
        expect(File::get($this->root.'/tests/Support/Contracts/v1/'.$path))->toBe($contents);
    }

    $inventory = File::get($this->root.'/'.TypeScriptPin::PROFILES_PATH);
    expect($inventory)->toBe(implode("\n", [
        '# schema-version: 1',
        '# repository: jkudish/librarium',
        "# commit: {$this->commit}",
        '# package-version: 2.0.0',
        '# catalog: src/core/provider-profiles.ts sha256:'.hash('sha256', "export const catalog = [];\n"),
        '# bindings: src/core/profile-bindings.ts sha256:'.hash('sha256', "export const bindings = [];\n"),
        "profile\tresult_kind\tgrounding\tobservation\tcorpora\tretrieval_methods\tinvocation\tresumability\tworkflows\ttarget",
        implode("\t", $this->rows[1]),
        implode("\t", $this->rows[0]),
    ])."\n")
        ->and($pin->profilesSha256)->toBe(hash('sha256', $inventory))
        ->and(File::get($this->root.'/tests/Support/Contracts/README.md'))->toContain("`{$this->commit}`")
        ->toContain('Release tag: `v2.0.0`')
        ->and(File::get($this->root.'/docs/profile-bindings.md'))
        ->toBe("# Matrix\n\n".TypeScriptRepin::docsBlock($pin)."\n\nRest.\n");
});

it('records no release tag for a branch or commit ref', function (): void {
    $pin = $this->repin->repin($this->upstream, $this->commit);

    expect($pin->tag)->toBeNull()
        ->and(File::get($this->root.'/tests/Support/Contracts/README.md'))->toContain('Release tag: `none`');
});

it('refuses a checkout whose HEAD is not the named ref', function (): void {
    repinWrite($this->upstream, ['README.md' => "next\n"]);
    repinGit($this->upstream, ['add', '-A']);
    repinGit($this->upstream, ['commit', '-q', '-m', 'next']);

    expect(fn () => $this->repin->repin($this->upstream, 'v2.0.0'))
        ->toThrow(RuntimeException::class, 'Check out [v2.0.0]');
});

it('refuses a checkout with uncommitted tracked changes', function (): void {
    File::append($this->upstream.'/src/core/provider-profiles.ts', "// local edit\n");

    expect(fn () => $this->repin->repin($this->upstream, 'v2.0.0'))
        ->toThrow(RuntimeException::class, 'uncommitted changes');
});

it('refuses an upstream file that does not match its checksum and leaves the vendored snapshot untouched', function (): void {
    File::put($this->upstream.'/contracts/v1/fixtures/valid/ok.json', "{\"status\":\"tampered\"}\n");
    repinGit($this->upstream, ['commit', '-q', '-am', 'tamper']);

    expect(fn () => $this->repin->repin($this->upstream, 'main'))
        ->toThrow(RuntimeException::class, 'does not match its checksum')
        ->and(File::exists($this->root.'/tests/Support/Contracts/v1/stale.json'))->toBeTrue()
        ->and(File::exists($this->root.'/'.TypeScriptPin::PATH))->toBeFalse();
});

it('rejects a malformed or duplicate inventory row', function (array $rows, string $message): void {
    $repin = new TypeScriptRepin($this->root, fn (string $checkout): array => $rows);

    expect(fn () => $repin->repin($this->upstream, 'v2.0.0'))->toThrow(RuntimeException::class, $message);
})->with([
    'duplicate key' => [[
        ['exa/search', 'search_results', 'none', 'api_output', 'web', 'search_endpoint', 'inline', 'none', '', '{"primary":{}}'],
        ['exa/search', 'search_results', 'none', 'api_output', 'web', 'search_endpoint', 'inline', 'none', '', '{"primary":{}}'],
    ], 'invalid or duplicate key'],
    'tab in a field' => [[
        ['exa/search', "search\tresults", 'none', 'api_output', 'web', 'search_endpoint', 'inline', 'none', '', '{"primary":{}}'],
    ], 'tab or line break'],
    'list target' => [[
        ['exa/search', 'search_results', 'none', 'api_output', 'web', 'search_endpoint', 'inline', 'none', '', '[1]'],
    ], 'must be an object'],
]);
