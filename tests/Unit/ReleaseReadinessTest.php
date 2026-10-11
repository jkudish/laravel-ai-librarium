<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Tools\ReleaseReadiness;
use Jkudish\LaravelAiLibrarium\Tools\TypeScriptPin;

require_once dirname(__DIR__, 2).'/tools/ReleaseReadiness.php';

function releaseReadinessPin(?string $tag = 'v2.0.0', string $packageVersion = '2.0.0'): TypeScriptPin
{
    return new TypeScriptPin(
        ref: $tag ?? 'feature-branch',
        tag: $tag,
        commit: str_repeat('c', 40),
        packageVersion: $packageVersion,
        contractChecksumsSha256: str_repeat('d', 64),
        contractManifestSha256: str_repeat('e', 64),
        contractInterchangeSchemaSha256: str_repeat('f', 64),
        contractTotals: [
            'schemas' => 1,
            'definitions' => 7,
            'valid_fixtures' => 18,
            'invalid_fixtures' => 41,
            'semantic_rules' => 6,
            'manifest_entries' => 62,
            'checksum_entries' => 62,
            'snapshot_files' => 63,
        ],
        profilesSha256: str_repeat('a', 64),
        profileCount: 41,
    );
}

/** @return array{0: TypeScriptPin, 1: string, 2: string} */
function releaseReadinessPinned(?TypeScriptPin $pin = null): array
{
    $pin ??= releaseReadinessPin();

    return [$pin, $pin->profilesSha256, $pin->contractChecksumsSha256];
}

/** @return array<string, mixed> */
function releaseReadinessComposerFixture(): array
{
    return [
        'require' => [
            'php' => '^8.4',
            'illuminate/console' => '^13.35',
            'illuminate/contracts' => '^13.35',
            'illuminate/http' => '^13.35',
            'illuminate/routing' => '^13.35',
            'illuminate/support' => '^13.35',
            'illuminate/validation' => '^13.35',
            'jkudish/laravel-ai-pricing' => '^0.3',
            'laravel/ai' => '^1.2',
        ],
        'require-dev' => [
            'orchestra/testbench' => '^11.3',
        ],
    ];
}

/** @return array<string, mixed> */
function releaseReadinessManifestFixture(): array
{
    $evidence = fn (string $id): array => [
        'uri' => "https://ampcode.com/threads/{$id}",
        'sha256' => str_repeat('a', 64),
    ];

    return [
        'schema_version' => 1,
        'target_version' => '1.0.0',
        'typescript' => [
            'version' => '2.0.0',
            'tag' => 'v2.0.0',
        ],
        'gates' => [
            ['id' => 'php-8.4-lowest-compatibility', 'title' => 'PHP 8.4 lowest-dependency compatibility', 'status' => 'complete', 'evidence' => $evidence('compatibility-lowest')],
            ['id' => 'php-8.5-highest-compatibility', 'title' => 'PHP 8.5 highest-dependency compatibility', 'status' => 'complete', 'evidence' => $evidence('compatibility-highest')],
            ['id' => '2593', 'title' => 'SearchAPI and Firecrawl fidelity bake-off', 'status' => 'complete', 'evidence' => $evidence('2593')],
            ['id' => '2986', 'title' => 'SearchAPI live canaries', 'status' => 'complete', 'evidence' => $evidence('2986')],
            ['id' => '3471', 'title' => 'DataForSEO acceptance', 'status' => 'complete', 'evidence' => $evidence('3471')],
            ['id' => 'remaining-live-canaries', 'title' => 'Remaining release-required live canaries', 'status' => 'complete', 'evidence' => $evidence('remaining-live-canaries')],
            ['id' => 'stable-pricing-release', 'title' => 'Stable laravel-ai-pricing release and constraint', 'status' => 'complete', 'evidence' => $evidence('stable-pricing-release')],
        ],
    ];
}

it('reports every current local and external 1.0 release blocker', function (): void {
    $pin = TypeScriptPin::load(dirname(__DIR__, 2));

    expect($pin->tag)->toBeNull()
        ->and((new ReleaseReadiness)->blockers())->toBe([
            "The accepted TypeScript v2.0.0 release tag is not pinned; the contract and Profile inventory are pinned to commit {$pin->commit} (ref {$pin->ref}). Rerun composer typescript:repin against v2.0.0.",
            'Gate [stable-pricing-release] is pending: Stable laravel-ai-pricing release and constraint.',
        ]);
});

it('blocks until the TypeScript pin names the accepted release tag and package version', function (?string $tag, string $version, string $message): void {
    $pin = releaseReadinessPin($tag, $version);

    expect(ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-10-09\n",
        releaseReadinessManifestFixture(),
        ...releaseReadinessPinned($pin),
    ))->toBe([str_replace(['{commit}', '{ref}'], [$pin->commit, $pin->ref], $message)]);
})->with([
    'development commit' => [null, '2.0.0', 'The accepted TypeScript v2.0.0 release tag is not pinned; the contract and Profile inventory are pinned to commit {commit} (ref {ref}). Rerun composer typescript:repin against v2.0.0.'],
    'wrong package version' => ['v2.0.0', '2.1.0', 'The TypeScript pin is package version 2.1.0, not the accepted 2.0.0.'],
]);

it('requires a single first-release changelog section', function (string $changelog, string $found): void {
    expect(ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        $changelog,
        releaseReadinessManifestFixture(),
        ...releaseReadinessPinned(),
    ))->toBe(["CHANGELOG.md must contain only the first 1.0.0 release section; found [{$found}]."]);
})->with([
    'later major' => ["## 1.0.0 - 2026-10-09\n\n## 2.0.0 - 2026-09-26\n", '2.0.0'],
    'bracketed prior version' => ["## [1.0.0] - 2026-10-09\n\n## [0.9.0] - 2026-08-01\n", '0.9.0'],
    'repeated release' => ["## 1.0.0 - 2026-10-09\n\n## 1.0.0 - 2026-09-15\n", 'a repeated 1.0.0 section'],
]);

it('passes only stable versioned metadata with completed evidenced gates', function (): void {
    $composer = releaseReadinessComposerFixture();
    $manifest = releaseReadinessManifestFixture();

    expect(ReleaseReadiness::evaluate(
        $composer,
        "# Changelog\n\n## [1.0.0] - 2026-09-11\n",
        $manifest,
        ...releaseReadinessPinned(),
    ))->toBe([]);
});

it('blocks every Composer development or prerelease runtime constraint', function (string $constraint): void {
    $composer = releaseReadinessComposerFixture();
    $composer['require']['example/package'] = $constraint;
    $manifest = releaseReadinessManifestFixture();

    expect(ReleaseReadiness::evaluate(
        $composer,
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        ...releaseReadinessPinned(),
    ))->toBe(["Runtime dependency example/package uses non-stable constraint [{$constraint}]."]);
})->with([
    'development branch' => 'dev-main',
    'development suffix' => '1.0.x-dev',
    'development stability flag' => '^1.0@dev',
    'alpha stability flag' => '^1.0@alpha',
    'beta release' => '1.0.0-beta.2',
    'release candidate' => '1.0.0-RC1',
    'short alpha release' => '1.0.0-a1',
    'short beta release' => '1.0.0b2',
]);

it('requires stable Composer minimum stability', function (string $stability): void {
    $composer = releaseReadinessComposerFixture();
    $composer['minimum-stability'] = $stability;

    expect(ReleaseReadiness::evaluate(
        $composer,
        "## 1.0.0 - 2026-09-11\n",
        releaseReadinessManifestFixture(),
        ...releaseReadinessPinned(),
    ))->toContain('Composer minimum-stability must remain [stable].');
})->with(['dev', 'alpha', 'beta', 'RC']);

it('requires the exact supported PHP, Laravel, and Laravel AI compatibility policy', function (string $section, string $package, string $constraint, string $message): void {
    $composer = releaseReadinessComposerFixture();
    $composer[$section][$package] = $constraint;

    expect(ReleaseReadiness::evaluate(
        $composer,
        "## 1.0.0 - 2026-09-11\n",
        releaseReadinessManifestFixture(),
        ...releaseReadinessPinned(),
    ))->toBe([$message]);
})->with([
    'PHP floor' => ['require', 'php', '^8.3', 'Runtime dependency php must use the release compatibility constraint [^8.4].'],
    'Laravel major' => ['require', 'illuminate/support', '^12.0', 'Runtime dependency illuminate/support must use the release compatibility constraint [^13.35].'],
    'Laravel floor' => ['require', 'illuminate/support', '^13.0', 'Runtime dependency illuminate/support must use the release compatibility constraint [^13.35].'],
    'pricing without rate windows' => ['require', 'jkudish/laravel-ai-pricing', '^0.2.2', 'Runtime dependency jkudish/laravel-ai-pricing must use the release compatibility constraint [^0.3].'],
    'pricing legacy range' => ['require', 'jkudish/laravel-ai-pricing', '^0.1.1 || ^0.2.0', 'Runtime dependency jkudish/laravel-ai-pricing must use the release compatibility constraint [^0.3].'],
    'Laravel AI range' => ['require', 'laravel/ai', '^0.11.0 || ^0.10.3', 'Runtime dependency laravel/ai must use the release compatibility constraint [^1.2].'],
    'Laravel AI vulnerable floor' => ['require', 'laravel/ai', '^1.0', 'Runtime dependency laravel/ai must use the release compatibility constraint [^1.2].'],
    'Testbench major' => ['require-dev', 'orchestra/testbench', '^10.0', 'Development dependency orchestra/testbench must use the release compatibility constraint [^11.3].'],
]);

it('rejects a completed gate without durable evidence', function (): void {
    $manifest = releaseReadinessManifestFixture();
    $manifest['gates'][2]['evidence'] = null;

    expect(ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        ...releaseReadinessPinned(),
    ))->toBe(['Gate [2593] is missing evidence: SearchAPI and Firecrawl fidelity bake-off.']);
});

it('rejects evidence that is not a durable HTTPS reference with a content digest', function (mixed $evidence): void {
    $manifest = releaseReadinessManifestFixture();
    $manifest['gates'][0]['evidence'] = $evidence;

    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        ...releaseReadinessPinned(),
    ))->toThrow(RuntimeException::class, 'The release-readiness manifest contains an invalid gate.');
})->with([
    'arbitrary text' => ['x'],
    'non-HTTPS URI' => [['uri' => 'file:///tmp/evidence.txt', 'sha256' => str_repeat('a', 64)]],
    'credential-bearing URI' => [['uri' => 'https://user:secret@example.com/evidence', 'sha256' => str_repeat('a', 64)]],
    'query-bearing URI' => [['uri' => 'https://example.com/evidence?token=secret', 'sha256' => str_repeat('a', 64)]],
    'fragment-bearing URI' => [['uri' => 'https://example.com/evidence#token=secret', 'sha256' => str_repeat('a', 64)]],
    'malformed digest' => [['uri' => 'https://example.com/evidence', 'sha256' => 'abc123']],
]);

it('rejects vendored files that drift from the TypeScript pin', function (string $profileHash, string $contractHash): void {
    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        releaseReadinessManifestFixture(),
        releaseReadinessPin(),
        $profileHash,
        $contractHash,
    ))->toThrow(RuntimeException::class, 'does not match tests/Support/typescript-pin.json');
})->with([
    'Profile inventory' => [str_repeat('b', 64), str_repeat('d', 64)],
    'contract checksum index' => [str_repeat('a', 64), str_repeat('b', 64)],
]);

it('rejects a malformed or hand-edited TypeScript pin', function (): void {
    $value = releaseReadinessPin()->toArray();
    $value['commit'] = '889b036';

    expect(fn (): TypeScriptPin => TypeScriptPin::fromArray($value))
        ->toThrow(RuntimeException::class, 'The TypeScript pin is malformed.');
});

it('loads the committed pin in canonical form', function (): void {
    $pin = TypeScriptPin::load(dirname(__DIR__, 2));

    expect($pin->toJson())->toBe(file_get_contents(dirname(__DIR__, 2).'/'.TypeScriptPin::PATH));
});

it('rejects missing, replaced, or retitled canonical release gates', function (string $mutation): void {
    $manifest = releaseReadinessManifestFixture();

    match ($mutation) {
        'missing' => array_pop($manifest['gates']),
        'replaced' => $manifest['gates'][6] = [
            'id' => 'proof',
            'title' => 'Proof gate',
            'status' => 'complete',
            'evidence' => [
                'uri' => 'https://example.com/proof',
                'sha256' => str_repeat('a', 64),
            ],
        ],
        'retitled' => $manifest['gates'][6]['title'] = 'Pricing is stable',
    };

    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        ...releaseReadinessPinned(),
    ))->toThrow(RuntimeException::class, 'The release-readiness manifest must contain the canonical 1.0 release gates.');
})->with(['missing', 'replaced', 'retitled']);

it('requires the manifest to name exactly the accepted TypeScript v2.0.0 release', function (): void {
    $manifest = releaseReadinessManifestFixture();
    $manifest['typescript']['tag'] = '2.0.0';

    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        ...releaseReadinessPinned(),
    ))->toThrow(RuntimeException::class);
});

it('rejects impossible changelog release dates', function (string $date): void {
    expect(ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - {$date}\n",
        releaseReadinessManifestFixture(),
        ...releaseReadinessPinned(),
    ))->toBe(['CHANGELOG.md does not contain a dated 1.0.0 release heading.']);
})->with(['2026-02-29', '2026-13-01', '2026-99-99']);

it('requires the Unreleased changelog section to be converted into the dated release', function (): void {
    expect(ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "# Changelog\n\n## Unreleased\n\n- Pending.\n\n## [1.0.0] - 2026-09-11\n",
        releaseReadinessManifestFixture(),
        ...releaseReadinessPinned(),
    ))->toBe(['CHANGELOG.md retains an Unreleased heading after the 1.0.0 release.']);
});
