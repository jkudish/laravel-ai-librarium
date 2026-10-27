<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoCredential;

/**
 * @phpstan-type Variant array{php: ?string, env?: string, requires?: array<string, string>, options?: array<string, mixed>}
 * @phpstan-type Option array{type?: string, default?: mixed, values?: list<string>, items?: list<string>, variants?: array<string, Variant>}
 * @phpstan-type CatalogEntry array{id: string, name: string, kind: string, php_credential?: string, variants?: array<string, Variant>, options?: array<string, Option>, collectors?: array<string, Variant>}
 */
final class SourceCatalog
{
    /** @var array<string, Closure(string, array<string, mixed>): Profile> */
    private array $collectors = [];

    public function __construct(private readonly Repository $config, private readonly ProfileCatalog $profiles) {}

    /** @param Closure(string, array<string, mixed>): Profile $factory */
    public function extendCollector(string $name, Closure $factory): void
    {
        $this->collectors[$name] = $factory;
    }

    /** @return array<string, CatalogEntry> */
    public function entries(): array
    {
        /** @var array{sources: list<CatalogEntry>, engines: list<CatalogEntry>} $data */
        $data = Spec::read('sources.json');
        $entries = [];
        foreach ([...$data['sources'], ...$data['engines']] as $entry) {
            $entries[$entry['id']] = $entry;
        }
        $custom = $this->config->get('librarium.sources', []);
        if (is_array($custom)) {
            foreach ($custom as $name => $value) {
                if (is_string($name) && ! isset($entries[$name]) && is_array($value)) {
                    $entries[$name] = ['id' => $name, 'name' => $name, 'kind' => 'research', 'variants' => [], 'options' => []];
                }
            }
        }
        $customEngines = $this->config->get('librarium.engines.custom', []);
        if (is_array($customEngines)) {
            foreach ($customEngines as $name => $value) {
                if (is_string($name) && ! isset($entries[$name])) {
                    $entries[$name] = ['id' => $name, 'name' => $name, 'kind' => 'engine', 'collectors' => []];
                }
            }
        }

        return $entries;
    }

    /** @return array<string, array{configured: bool, deep: bool, collector: bool, engine: bool}> */
    public function selectionCatalog(): array
    {
        $items = [];
        foreach ($this->entries() as $id => $entry) {
            $settings = $this->settings($id);
            $collector = $settings['collector'] ?? null;
            $engine = $entry['kind'] === 'engine';
            $available = ! $engine || (is_string($collector) && (isset($this->collectors[$collector]) || in_array($collector, ['searchapi', 'dataforseo'], true) && isset($entry['collectors'][$collector])));
            $items[$id] = [
                'configured' => ($settings['enabled'] ?? true) !== false && filled($settings['key'] ?? null) && (! $engine || is_string($collector) && $available),
                'deep' => isset($entry['variants']['deep']) || isset($settings['deep']),
                'collector' => $available,
                'engine' => $engine,
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    public function settings(string $id): array
    {
        $entry = $this->entries()[$id];
        if ($entry['kind'] === 'engine') {
            $global = $this->config->get('librarium.engines', []);
            $global = is_array($global) ? $global : [];
            $override = $global[$id] ?? data_get($global, 'custom.'.$id, []);
            $settings = array_replace($global, is_array($override) ? $override : []);
            $collector = $settings['collector'] ?? null;
            $settings['key'] ??= is_string($collector) && $collector === 'dataforseo'
                ? DataForSeoCredential::encode($this->config->get('services.dataforseo.login'), $this->config->get('services.dataforseo.password'))
                : (is_string($collector) ? $this->config->get('services.'.$collector.'.key') : null);

            /** @var array<string, mixed> $settings */
            return $settings;
        }
        $settings = $this->config->get('librarium.sources.'.$id, []);
        $settings = is_array($settings) ? $settings : [];
        $credentialPath = str_replace('<collector>', is_string($settings['collector'] ?? null) ? $settings['collector'] : 'searchapi', $entry['php_credential'] ?? '');
        $settings['key'] ??= $credentialPath === '' ? null : $this->config->get($credentialPath);

        /** @var array<string, mixed> $settings */
        return $settings;
    }

    /**
     * Validate request options without requiring a selected or configured source.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function validateOptions(string $id, string $depth, array $overrides): void
    {
        $entry = $this->entries()[$id] ?? null;
        if ($entry === null) {
            throw ValidationException::withMessages(['options.'.$id => 'Unknown source options.']);
        }
        if ($depth === 'deep' && ! isset($entry['variants']['deep']) && ! isset($this->settings($id)['deep'])) {
            $depth = 'quick';
        }
        $this->profile($id, $depth, $overrides, true);
    }

    /** @param array<string, mixed> $overrides */
    public function profile(string $id, string $depth, array $overrides = [], bool $validateOnly = false): Profile
    {
        $entry = $this->entries()[$id];
        $settings = $this->settings($id);
        if ($validateOnly) {
            $settings['enabled'] = true;
            $settings['key'] = null;
            if ($entry['kind'] === 'engine' && ! is_string($settings['collector'] ?? null)) {
                $settings['collector'] = array_key_first($entry['collectors'] ?? []);
            }
        }
        if ($id === 'google' && isset($overrides['collector'])) {
            $configuredKey = $this->config->get('librarium.sources.google.key');
            $settings['key'] = $configuredKey ?? (is_string($overrides['collector']) ? $this->config->get('services.'.$overrides['collector'].'.key') : null);
        }
        $options = [];
        $variant = $entry['variants'][$depth]['php'] ?? null;
        foreach ($entry['options'] ?? [] as $name => $rule) {
            $value = $overrides[$name] ?? $settings[$name] ?? $rule['default'] ?? null;
            if ($value === null) {
                continue;
            }
            $valid = (! isset($rule['values']) || in_array($value, $rule['values'], true))
                && match ($rule['type'] ?? null) {
                    'string' => is_string($value), 'integer' => is_int($value), 'array' => is_array($value), default => true,
                };
            if (! $valid) {
                throw ValidationException::withMessages(['options.'.$id.'.'.$name => 'Unsupported source option.']);
            }
            if (is_string($value) && isset($rule['variants'][$value]) && ! ($name === 'quick' && $depth !== 'quick') && ! ($name === 'deep_effort' && $depth !== 'deep')) {
                $mapping = $rule['variants'][$value];
                foreach ($mapping['requires'] ?? [] as $required => $expected) {
                    if (($overrides[$required] ?? $settings[$required] ?? $entry['options'][$required]['default'] ?? null) !== $expected) {
                        throw ValidationException::withMessages(['options.'.$id => "{$name} requires {$required}={$expected}."]);
                    }
                }
                $variant = $mapping['php'];
            }
            $options[$name] = $value;
        }
        if (array_diff(array_keys($overrides), array_keys($entry['options'] ?? [])) !== [] && $entry['kind'] !== 'engine' && ! isset($settings[$depth])) {
            throw ValidationException::withMessages(['options.'.$id => 'Unknown source option.']);
        }
        if ($entry['kind'] === 'engine') {
            if (array_intersect(array_keys($overrides), ['key', 'collector', 'enabled', 'url', 'custom']) !== []) {
                throw ValidationException::withMessages(['options.'.$id => 'Engine credentials, collector and URL belong in configuration.']);
            }
            $collector = $settings['collector'] ?? null;
            if (is_string($collector) && isset($this->collectors[$collector])) {
                $profile = ($this->collectors[$collector])($id, array_replace($settings, $overrides));
                $errors = $this->profiles->reasons($profile, ! $validateOnly);
                if ($errors !== []) {
                    throw ValidationException::withMessages(['options.'.$id => $errors]);
                }

                return $profile;
            }
            $variant = is_string($collector) ? ($entry['collectors'][$collector]['php'] ?? null) : null;
            if ($collector === 'dataforseo') {
                $priority = $overrides['priority'] ?? $settings['priority'] ?? 'standard';
                if (! in_array($priority, ['standard', 'live'], true)) {
                    throw ValidationException::withMessages(['options.'.$id.'.priority' => 'Use standard or live.']);
                }
                if ($priority === 'live' && is_string($variant)) {
                    $variant .= '-live';
                }
            }
        }
        /** @var array{profiles: array<string, array<string, mixed>>} $bindings */
        $bindings = require __DIR__.'/Profiles/bindings.php';
        $binding = is_string($variant) ? ($bindings['profiles'][$variant] ?? null) : null;
        $custom = $settings[$depth] ?? null;
        if (is_array($custom)) {
            $binding = $custom;
            $variant = $id.'-'.$depth;
        }
        if ($id === 'firecrawl') {
            $variant = 'firecrawl-search';
            $binding = ['driver' => Drivers\Firecrawl\FirecrawlSearchDriver::class, 'provider' => 'firecrawl', 'model' => null, 'result_kind' => 'search_results', 'grounding' => 'none', 'observation' => 'api_output', 'corpora' => ['web'], 'retrieval_methods' => ['search_endpoint'], 'prompt' => '{{ query }}', 'options' => []];
        }
        if (! is_array($binding) || ! is_string($variant)) {
            throw ValidationException::withMessages(['source' => 'Source variant unavailable in PHP.']);
        }
        $binding['credential'] = $settings['key'] ?? null;
        $binding['enabled'] = ($settings['enabled'] ?? true) !== false;
        $driverOptions = is_array($binding['options'] ?? null) ? $binding['options'] : [];
        if ($entry['kind'] === 'engine' || is_array($custom)) {
            $configuredOptions = $settings['options'] ?? [];
            $driverOptions = array_replace($driverOptions, is_array($configuredOptions) ? $configuredOptions : [], $overrides);
            foreach (array_keys($driverOptions) as $name) {
                if (is_string($name) && array_key_exists($name, $settings)) {
                    $driverOptions[$name] = $settings[$name];
                }
            }
            unset($driverOptions['priority']);
        }
        foreach ($options as $name => $value) {
            if (in_array($name, ['quick', 'results', 'search', 'collector', 'vertical', 'deep_effort'], true)) {
                continue;
            }
            if ($name === 'model' || ($name === 'processor' && $depth === 'deep')) {
                if ($name === 'model' && $value !== ($binding['model'] ?? null)) {
                    // Billing SKUs include route-specific suffixes: never price a
                    // model override as the shipped default's billing identity.
                    unset($binding['pricing']);
                }
                $binding['model'] = $value;
                if ($name === 'processor') {
                    $binding['pricing'] = array_replace(is_array($binding['pricing'] ?? null) ? $binding['pricing'] : [], ['model' => 'research-'.(is_string($value) ? $value : '')]);
                }
            } elseif ($name === 'effort') {
                $driverOptions['reasoning_effort'] = $value;
            } elseif ($name === 'output') {
                if ($depth === 'deep') {
                    $driverOptions['output_formats'] = $value;
                }
            } elseif ($name === 'mode') {
                if ($depth === 'deep') {
                    $driverOptions['mode'] = $value;
                    $binding['pricing'] = array_replace(is_array($binding['pricing'] ?? null) ? $binding['pricing'] : [], ['model' => 'research-'.(is_string($value) ? $value : '')]);
                }
            } elseif ($name !== 'processor' || $depth === 'deep') {
                $driverOptions[$name] = $value;
            }
        }
        $binding['options'] = $driverOptions;
        /** @var array<string, mixed> $binding */
        $profile = $this->profiles->profile($variant, $binding);
        $errors = $this->profiles->reasons($profile, ! $validateOnly);
        if ($errors !== []) {
            throw ValidationException::withMessages(['options.'.$id => $errors]);
        }

        return $profile;
    }
}
