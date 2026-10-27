<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\SourceCatalog;
use Jkudish\LaravelAiLibrarium\Spec;

it('resolves every shared PHP variant and collector mapping through the source catalog', function (): void {
    config(['services.firecrawl.key' => 'placeholder', 'services.dataforseo.login' => 'placeholder-login', 'services.dataforseo.password' => 'placeholder-password']);
    $data = Spec::read('sources.json');
    $catalog = app(SourceCatalog::class);
    $resolved = [];
    foreach ($data['sources'] as $entry) {
        foreach ($entry['variants'] as $depth => $variant) {
            $binding = $variant['php'] ?? ($entry['id'] === 'firecrawl' ? 'firecrawl-search' : null);
            expect($catalog->profile($entry['id'], $depth)->id)->toBe($binding);
            $resolved[] = $binding;
        }
        foreach ($entry['options'] as $name => $rule) {
            foreach ($rule['variants'] ?? [] as $value => $variant) {
                if ($variant['php'] === null) {
                    continue;
                }
                $depth = $name === 'deep_effort' ? 'deep' : 'quick';
                $options = [$name => $value, ...($variant['requires'] ?? [])];
                expect($catalog->profile($entry['id'], $depth, $options)->id)->toBe($variant['php']);
                $resolved[] = $variant['php'];
            }
        }
    }
    foreach ($data['engines'] as $entry) {
        foreach (['searchapi', 'dataforseo'] as $collector) {
            if (! isset($entry['collectors'][$collector])) {
                continue;
            }
            config(['librarium.engines' => ['collector' => $collector, 'options' => $collector === 'dataforseo' ? ['location_code' => 2840, 'language_code' => 'en'] : []]]);
            $variant = $entry['collectors'][$collector];
            expect($catalog->profile($entry['id'], 'quick')->id)->toBe($variant['php']);
            $resolved[] = $variant['php'];
            if (isset($variant['options']['priority']['php_live'])) {
                expect($catalog->profile($entry['id'], 'quick', ['priority' => 'live'])->id)->toBe($variant['options']['priority']['php_live']);
                $resolved[] = $variant['options']['priority']['php_live'];
            }
        }
    }
    expect(array_unique($resolved))->toHaveCount(47); // All 46 retained bindings plus new core Firecrawl.
});
