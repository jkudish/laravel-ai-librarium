<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Tools\ReleaseReadiness;

require_once dirname(__DIR__, 2).'/tools/ReleaseReadiness.php';

const RELEASE_PROFILE_SNAPSHOT_SHA256 = '83739ba138c2db2c4673aada5834a5586f085a6c971253b155339eb570dbb9f9';

/** @return array<string, mixed> */
function releaseReadinessComposerFixture(): array
{
    return [
        'require' => [
            'php' => '^8.4',
            'illuminate/console' => '^13.0',
            'illuminate/contracts' => '^13.0',
            'illuminate/http' => '^13.0',
            'illuminate/routing' => '^13.0',
            'illuminate/support' => '^13.0',
            'illuminate/validation' => '^13.0',
            'jkudish/laravel-ai-pricing' => '^0.1.1',
            'laravel/ai' => '^1.0',
        ],
        'require-dev' => [
            'orchestra/testbench' => '^11.0',
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
            'commit' => '889b0366b5baafff117198632e04b24e521ac93d',
            'profile_snapshot_sha256' => RELEASE_PROFILE_SNAPSHOT_SHA256,
        ],
        'gates' => [
            ['id' => 'php-8.4-lowest-compatibility', 'title' => 'PHP 8.4 lowest-dependency compatibility', 'status' => 'complete', 'evidence' => $evidence('compatibility-lowest')],
            ['id' => 'php-8.5-highest-compatibility', 'title' => 'PHP 8.5 highest-dependency compatibility', 'status' => 'complete', 'evidence' => $evidence('compatibility-highest')],
            ['id' => '2593', 'title' => 'SearchAPI and Firecrawl fidelity bake-off', 'status' => 'complete', 'evidence' => $evidence('2593')],
            ['id' => '2594', 'title' => 'PlanMode and Agentsy dogfood', 'status' => 'complete', 'evidence' => $evidence('2594')],
            ['id' => '2986', 'title' => 'SearchAPI live canaries', 'status' => 'complete', 'evidence' => $evidence('2986')],
            ['id' => '3471', 'title' => 'DataForSEO acceptance', 'status' => 'complete', 'evidence' => $evidence('3471')],
            ['id' => 'remaining-live-canaries', 'title' => 'Remaining release-required live canaries', 'status' => 'complete', 'evidence' => $evidence('remaining-live-canaries')],
            ['id' => 'stable-pricing-release', 'title' => 'Stable laravel-ai-pricing release and constraint', 'status' => 'complete', 'evidence' => $evidence('stable-pricing-release')],
        ],
    ];
}

it('reports every current local and external 1.0 release blocker', function (): void {
    expect((new ReleaseReadiness)->blockers())->toBe([
        'The accepted TypeScript v2 release tag is not recorded.',
        'Gate [2594] is pending: PlanMode and Agentsy dogfood.',
    ]);
});

it('passes only stable versioned metadata with completed evidenced gates', function (): void {
    $composer = releaseReadinessComposerFixture();
    $manifest = releaseReadinessManifestFixture();

    expect(ReleaseReadiness::evaluate(
        $composer,
        "# Changelog\n\n## [1.0.0] - 2026-09-11\n",
        $manifest,
        RELEASE_PROFILE_SNAPSHOT_SHA256,
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
        RELEASE_PROFILE_SNAPSHOT_SHA256,
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
        RELEASE_PROFILE_SNAPSHOT_SHA256,
    ))->toContain('Composer minimum-stability must remain [stable].');
})->with(['dev', 'alpha', 'beta', 'RC']);

it('requires the exact supported PHP, Laravel, and Laravel AI compatibility policy', function (string $section, string $package, string $constraint, string $message): void {
    $composer = releaseReadinessComposerFixture();
    $composer[$section][$package] = $constraint;

    expect(ReleaseReadiness::evaluate(
        $composer,
        "## 1.0.0 - 2026-09-11\n",
        releaseReadinessManifestFixture(),
        RELEASE_PROFILE_SNAPSHOT_SHA256,
    ))->toBe([$message]);
})->with([
    'PHP floor' => ['require', 'php', '^8.3', 'Runtime dependency php must use the release compatibility constraint [^8.4].'],
    'Laravel major' => ['require', 'illuminate/support', '^12.0', 'Runtime dependency illuminate/support must use the release compatibility constraint [^13.0].'],
    'pricing release' => ['require', 'jkudish/laravel-ai-pricing', '^0.2', 'Runtime dependency jkudish/laravel-ai-pricing must use the release compatibility constraint [^0.1.1].'],
    'Laravel AI range' => ['require', 'laravel/ai', '^0.11.0 || ^0.10.3', 'Runtime dependency laravel/ai must use the release compatibility constraint [^1.0].'],
    'Testbench major' => ['require-dev', 'orchestra/testbench', '^10.0', 'Development dependency orchestra/testbench must use the release compatibility constraint [^11.0].'],
]);

it('rejects a completed gate without durable evidence', function (): void {
    $manifest = releaseReadinessManifestFixture();
    $manifest['gates'][2]['evidence'] = null;

    expect(ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        RELEASE_PROFILE_SNAPSHOT_SHA256,
    ))->toBe(['Gate [2593] is missing evidence: SearchAPI and Firecrawl fidelity bake-off.']);
});

it('rejects evidence that is not a durable HTTPS reference with a content digest', function (mixed $evidence): void {
    $manifest = releaseReadinessManifestFixture();
    $manifest['gates'][0]['evidence'] = $evidence;

    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        RELEASE_PROFILE_SNAPSHOT_SHA256,
    ))->toThrow(RuntimeException::class, 'The release-readiness manifest contains an invalid gate.');
})->with([
    'arbitrary text' => ['x'],
    'non-HTTPS URI' => [['uri' => 'file:///tmp/evidence.txt', 'sha256' => str_repeat('a', 64)]],
    'credential-bearing URI' => [['uri' => 'https://user:secret@example.com/evidence', 'sha256' => str_repeat('a', 64)]],
    'query-bearing URI' => [['uri' => 'https://example.com/evidence?token=secret', 'sha256' => str_repeat('a', 64)]],
    'fragment-bearing URI' => [['uri' => 'https://example.com/evidence#token=secret', 'sha256' => str_repeat('a', 64)]],
    'malformed digest' => [['uri' => 'https://example.com/evidence', 'sha256' => 'abc123']],
]);

it('rejects a Profile snapshot and manifest that drift together from the accepted digest', function (): void {
    $manifest = releaseReadinessManifestFixture();
    $manifest['typescript']['profile_snapshot_sha256'] = str_repeat('b', 64);

    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        str_repeat('b', 64),
    ))->toThrow(RuntimeException::class, 'The release-readiness manifest is malformed or does not match the accepted v2 Profile snapshot.');
});

it('rejects Profile file drift while the manifest retains the accepted digest', function (): void {
    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        releaseReadinessManifestFixture(),
        str_repeat('b', 64),
    ))->toThrow(RuntimeException::class, 'The release-readiness manifest is malformed or does not match the accepted v2 Profile snapshot.');
});

it('rejects missing, replaced, or retitled canonical release gates', function (string $mutation): void {
    $manifest = releaseReadinessManifestFixture();

    match ($mutation) {
        'missing' => array_pop($manifest['gates']),
        'replaced' => $manifest['gates'][7] = [
            'id' => 'proof',
            'title' => 'Proof gate',
            'status' => 'complete',
            'evidence' => [
                'uri' => 'https://example.com/proof',
                'sha256' => str_repeat('a', 64),
            ],
        ],
        'retitled' => $manifest['gates'][7]['title'] = 'Pricing is stable',
    };

    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        RELEASE_PROFILE_SNAPSHOT_SHA256,
    ))->toThrow(RuntimeException::class, 'The release-readiness manifest must contain the canonical 1.0 release gates.');
})->with(['missing', 'replaced', 'retitled']);

it('rejects any TypeScript tag other than the exact accepted v2 release tag', function (): void {
    $manifest = releaseReadinessManifestFixture();
    $manifest['typescript']['tag'] = '2.0.0';

    expect(fn (): array => ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - 2026-09-11\n",
        $manifest,
        RELEASE_PROFILE_SNAPSHOT_SHA256,
    ))->toThrow(RuntimeException::class);
});

it('rejects impossible changelog release dates', function (string $date): void {
    expect(ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "## 1.0.0 - {$date}\n",
        releaseReadinessManifestFixture(),
        RELEASE_PROFILE_SNAPSHOT_SHA256,
    ))->toBe(['CHANGELOG.md does not contain a dated 1.0.0 release heading.']);
})->with(['2026-02-29', '2026-13-01', '2026-99-99']);

it('requires the Unreleased changelog section to be converted into the dated release', function (): void {
    expect(ReleaseReadiness::evaluate(
        releaseReadinessComposerFixture(),
        "# Changelog\n\n## Unreleased\n\n- Pending.\n\n## [1.0.0] - 2026-09-11\n",
        releaseReadinessManifestFixture(),
        RELEASE_PROFILE_SNAPSHOT_SHA256,
    ))->toBe(['CHANGELOG.md retains an Unreleased heading after the 1.0.0 release.']);
});
