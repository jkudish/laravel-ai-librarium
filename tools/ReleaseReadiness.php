<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tools;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class ReleaseReadiness
{
    /** @var list<array{id: string, title: string}> */
    private const array GATES = [
        ['id' => 'php-8.4-lowest-compatibility', 'title' => 'PHP 8.4 lowest-dependency compatibility'],
        ['id' => 'php-8.5-highest-compatibility', 'title' => 'PHP 8.5 highest-dependency compatibility'],
        ['id' => '2593', 'title' => 'SearchAPI and Firecrawl fidelity bake-off'],
        ['id' => '2986', 'title' => 'SearchAPI live canaries'],
        ['id' => '3471', 'title' => 'DataForSEO acceptance'],
        ['id' => 'remaining-live-canaries', 'title' => 'Remaining release-required live canaries'],
        ['id' => 'stable-pricing-release', 'title' => 'Stable laravel-ai-pricing release and constraint'],
    ];

    /** @var array<string, string> */
    private const array RUNTIME_CONSTRAINTS = [
        'php' => '^8.4',
        'illuminate/console' => '^13.30',
        'illuminate/contracts' => '^13.30',
        'illuminate/http' => '^13.30',
        'illuminate/routing' => '^13.30',
        'illuminate/support' => '^13.30',
        'illuminate/validation' => '^13.30',
        'jkudish/laravel-ai-pricing' => '^0.3.1',
        'laravel/ai' => '^1.0.1',
        'laravel/prompts' => '^0.3.15',
        'league/commonmark' => '^2.10.2',
        'standard-webhooks/standard-webhooks' => '^1.0',
    ];

    /** @var array<string, string> */
    private const array DEVELOPMENT_CONSTRAINTS = [
        'orchestra/testbench' => '^11.0',
    ];

    public function __construct(private string $root = __DIR__.'/..') {}

    /** @return list<string> */
    public function blockers(): array
    {
        $composer = $this->json($this->root.'/composer.json');
        $manifest = $this->json($this->root.'/tools/release-readiness.json');
        $changelog = file_get_contents($this->root.'/CHANGELOG.md');

        if (! is_string($changelog)) {
            throw new RuntimeException('CHANGELOG.md is unreadable.');
        }

        return self::evaluate($composer, $changelog, $manifest);
    }

    public function check(): void
    {
        $blockers = $this->blockers();

        if ($blockers !== []) {
            throw new RuntimeException("Release readiness is blocked:\n- ".implode("\n- ", $blockers));
        }
    }

    /**
     * @param  array<string, mixed>  $composer
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public static function evaluate(
        array $composer,
        string $changelog,
        array $manifest,
    ): array {
        self::validateManifest($manifest);

        $blockers = [];
        $requirements = $composer['require'] ?? null;
        $developmentRequirements = $composer['require-dev'] ?? null;

        if (! is_array($requirements) || ! is_array($developmentRequirements)) {
            throw new RuntimeException('composer.json must contain runtime and development requirements.');
        }

        if (($composer['minimum-stability'] ?? 'stable') !== 'stable') {
            $blockers[] = 'Composer minimum-stability must remain [stable].';
        }

        foreach ($requirements as $package => $constraint) {
            if (! is_string($package) || ! is_string($constraint)) {
                throw new RuntimeException('composer.json contains an invalid runtime requirement.');
            }

            if (preg_match('/(?:^|[^a-z])(?:dev|alpha|beta|rc|a|b)(?:[^a-z]|$)/i', $constraint) === 1) {
                $blockers[] = "Runtime dependency {$package} uses non-stable constraint [{$constraint}].";
            }
        }

        foreach (self::RUNTIME_CONSTRAINTS as $package => $constraint) {
            if (($requirements[$package] ?? null) !== $constraint) {
                $blockers[] = "Runtime dependency {$package} must use the release compatibility constraint [{$constraint}].";
            }
        }

        foreach (self::DEVELOPMENT_CONSTRAINTS as $package => $constraint) {
            if (($developmentRequirements[$package] ?? null) !== $constraint) {
                $blockers[] = "Development dependency {$package} must use the release compatibility constraint [{$constraint}].";
            }
        }

        if (! self::hasDatedReleaseHeading($changelog)) {
            $blockers[] = 'CHANGELOG.md does not contain a dated 1.0.0 release heading.';
        } elseif (preg_match('/^## \[?Unreleased\]?$/mi', $changelog) === 1) {
            $blockers[] = 'CHANGELOG.md retains an Unreleased heading after the 1.0.0 release.';
        }

        preg_match_all('/^## \[?(\d+\.\d+\.\d+[^\]\s]*)\]?/m', $changelog, $versions);
        $otherVersions = array_values(array_unique(array_diff($versions[1], ['1.0.0'])));
        if ($otherVersions !== [] || count(array_keys($versions[1], '1.0.0', true)) > 1) {
            $found = $otherVersions === [] ? 'a repeated 1.0.0 section' : implode(', ', $otherVersions);
            $blockers[] = "CHANGELOG.md must contain only the first 1.0.0 release section; found [{$found}].";
        }

        if (! isset($requirements['jkudish/librarium-spec'])) {
            $blockers[] = 'The shared librarium-spec package must be required; its conformance fixtures replace TypeScript snapshots.';
        }

        /** @var list<array{id: string, title: string, status: string, evidence: mixed}> $gates */
        $gates = $manifest['gates'];
        foreach ($gates as $gate) {
            if ($gate['status'] !== 'complete') {
                $blockers[] = "Gate [{$gate['id']}] is {$gate['status']}: {$gate['title']}.";

                continue;
            }

            if ($gate['evidence'] === null) {
                $blockers[] = "Gate [{$gate['id']}] is missing evidence: {$gate['title']}.";
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function validateManifest(array $manifest): void
    {
        $gates = $manifest['gates'] ?? null;

        if (
            array_keys($manifest) !== ['schema_version', 'target_version', 'gates']
            || $manifest['schema_version'] !== 1
            || $manifest['target_version'] !== '1.0.0'
            || ! is_array($gates)
            || ! array_is_list($gates)
            || $gates === []
        ) {
            throw new RuntimeException('The release-readiness manifest is malformed.');
        }

        $ids = [];
        $identities = [];
        foreach ($gates as $gate) {
            if (
                ! is_array($gate)
                || array_keys($gate) !== ['id', 'title', 'status', 'evidence']
                || ! is_string($gate['id'])
                || $gate['id'] === ''
                || isset($ids[$gate['id']])
                || ! is_string($gate['title'])
                || $gate['title'] === ''
                || ! in_array($gate['status'], ['pending', 'blocked', 'complete'], true)
                || ! self::validEvidence($gate['evidence'])
            ) {
                throw new RuntimeException('The release-readiness manifest contains an invalid gate.');
            }

            $ids[$gate['id']] = true;
            $identities[] = ['id' => $gate['id'], 'title' => $gate['title']];
        }

        if ($identities !== self::GATES) {
            throw new RuntimeException('The release-readiness manifest must contain the canonical 1.0 release gates.');
        }
    }

    private static function validEvidence(mixed $evidence): bool
    {
        if ($evidence === null) {
            return true;
        }

        if (
            ! is_array($evidence)
            || array_keys($evidence) !== ['uri', 'sha256']
            || ! is_string($evidence['uri'])
            || ! is_string($evidence['sha256'])
            || trim($evidence['uri']) !== $evidence['uri']
            || filter_var($evidence['uri'], FILTER_VALIDATE_URL) === false
            || parse_url($evidence['uri'], PHP_URL_SCHEME) !== 'https'
            || parse_url($evidence['uri'], PHP_URL_USER) !== null
            || parse_url($evidence['uri'], PHP_URL_PASS) !== null
            || parse_url($evidence['uri'], PHP_URL_QUERY) !== null
            || parse_url($evidence['uri'], PHP_URL_FRAGMENT) !== null
        ) {
            return false;
        }

        return preg_match('/\A[a-f0-9]{64}\z/D', $evidence['sha256']) === 1;
    }

    private static function hasDatedReleaseHeading(string $changelog): bool
    {
        preg_match_all('/^## \[?1\.0\.0\]? - (?<date>\d{4}-\d{2}-\d{2})$/m', $changelog, $matches);

        foreach ($matches['date'] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException("Release-readiness input is unreadable: {$path}.");
        }

        try {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Release-readiness input contains invalid JSON: {$path}.", previous: $exception);
        }

        if (! is_array($value)) {
            throw new RuntimeException("Release-readiness input must contain an object: {$path}.");
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException("Release-readiness input must contain an object with named properties: {$path}.");
            }

            $object[$key] = $item;
        }

        return $object;
    }
}
