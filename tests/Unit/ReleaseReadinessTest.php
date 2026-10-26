<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Tools\ReleaseReadiness;

require_once dirname(__DIR__, 2).'/tools/ReleaseReadiness.php';

/** @return array<string, mixed> */
function releaseReadinessComposerFixture(): array
{
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $composer['require']['jkudish/librarium-spec'] = '^0.1';

    return $composer;
}

/** @return array<string, mixed> */
function releaseReadinessManifestFixture(): array
{
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/tools/release-readiness.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest['gates'] as &$gate) {
        $gate['status'] = 'complete';
        $gate['evidence'] = ['uri' => 'https://example.com/evidence', 'sha256' => str_repeat('a', 64)];
    }

    return $manifest;
}

it('blocks release while the shared data dependency is a development pin', function (): void {
    expect((new ReleaseReadiness)->blockers())->toContain(
        'Runtime dependency jkudish/librarium-spec uses non-stable constraint [dev-spec-15c8a306].',
        'Gate [stable-pricing-release] is pending: Stable laravel-ai-pricing release and constraint.',
    );
});

it('passes stable metadata only after all canonical release gates have evidence', function (): void {
    expect(ReleaseReadiness::evaluate(releaseReadinessComposerFixture(), "## 1.0.0 - 2026-10-13\n", releaseReadinessManifestFixture()))->toBe([]);
});

it('rejects malformed, repeated, stale and undated changelog sections', function (string $changelog): void {
    expect(ReleaseReadiness::evaluate(releaseReadinessComposerFixture(), $changelog, releaseReadinessManifestFixture()))->not->toBeEmpty();
})->with([
    "## 1.0.0 - 2026-02-29\n", "## 1.0.0 - 2026-13-01\n", "## Unreleased\n",
    "## 1.0.0 - 2026-10-13\n## 0.9.0 - 2026-01-01\n",
    "## 1.0.0 - 2026-10-13\n## 1.0.0 - 2026-01-01\n",
    "## Unreleased\n## 1.0.0 - 2026-10-13\n",
]);

it('blocks development and prerelease runtime dependencies', function (string $constraint): void {
    $composer = releaseReadinessComposerFixture();
    $composer['require']['example/package'] = $constraint;
    expect(ReleaseReadiness::evaluate($composer, "## 1.0.0 - 2026-10-13\n", releaseReadinessManifestFixture()))->toContain("Runtime dependency example/package uses non-stable constraint [{$constraint}].");
})->with(['dev-main', '1.0.x-dev', '^1.0@dev', '^1.0@alpha', '1.0.0-beta.2', '1.0.0-RC1', '1.0.0-a1', '1.0.0b2']);

it('keeps the supported compatibility policy and stable minimum stability', function (string $section, string $package, string $constraint): void {
    $composer = releaseReadinessComposerFixture();
    $composer[$section][$package] = $constraint;
    expect(ReleaseReadiness::evaluate($composer, "## 1.0.0 - 2026-10-13\n", releaseReadinessManifestFixture()))->not->toBeEmpty();
})->with([
    ['require', 'php', '^8.3'], ['require', 'illuminate/support', '^12.0'], ['require', 'illuminate/support', '^13.0'],
    ['require', 'jkudish/laravel-ai-pricing', '^0.2.2'], ['require', 'jkudish/laravel-ai-pricing', '^0.3.1'],
    ['require', 'jkudish/laravel-ai-pricing', '^0.3.0 || ^0.4.1'], ['require', 'laravel/ai', '^1.0'],
    ['require', 'laravel/prompts', '^0.3.11'], ['require-dev', 'orchestra/testbench', '^10.0'],
    ['require', 'jkudish/librarium-spec', 'dev-main'],
]);

it('rejects release evidence with credentials, query strings, fragments or invalid digests', function (mixed $evidence): void {
    $manifest = releaseReadinessManifestFixture();
    $manifest['gates'][0]['evidence'] = $evidence;
    expect(fn () => ReleaseReadiness::evaluate(releaseReadinessComposerFixture(), "## 1.0.0 - 2026-10-13\n", $manifest))->toThrow(RuntimeException::class);
})->with([
    ['arbitrary text'], [['uri' => 'file:///tmp/evidence', 'sha256' => str_repeat('a', 64)]],
    [['uri' => 'https://user:secret@example.com/evidence', 'sha256' => str_repeat('a', 64)]],
    [['uri' => 'https://example.com/evidence?token=secret', 'sha256' => str_repeat('a', 64)]],
    [['uri' => 'https://example.com/evidence#secret', 'sha256' => str_repeat('a', 64)]],
    [['uri' => 'https://example.com/evidence', 'sha256' => 'bad']],
]);

it('requires all canonical gates unchanged and completed with evidence', function (string $mutation): void {
    $manifest = releaseReadinessManifestFixture();
    if ($mutation === 'evidence') {
        $manifest['gates'][0]['evidence'] = null;
        expect(ReleaseReadiness::evaluate(releaseReadinessComposerFixture(), "## 1.0.0 - 2026-10-13\n", $manifest))->not->toBeEmpty();
    } else {
        if ($mutation === 'missing') {
            array_pop($manifest['gates']);
        } else {
            $manifest['gates'][0][$mutation === 'retitled' ? 'title' : 'id'] = 'Replaced';
        }
        expect(fn () => ReleaseReadiness::evaluate(releaseReadinessComposerFixture(), "## 1.0.0 - 2026-10-13\n", $manifest))->toThrow(RuntimeException::class);
    }
})->with(['evidence', 'missing', 'retitled', 'replaced']);
