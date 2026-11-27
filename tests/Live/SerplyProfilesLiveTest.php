<?php

declare(strict_types=1);

// One request, one credit. The Profile reads services.serply.key when the catalog loads.
beforeEach(function (): void {
    $key = getenv('SERPLY_API_KEY');
    if (! is_string($key) || trim($key) === '') {
        $this->markTestSkipped('Set SERPLY_API_KEY to run the live Serply canary.');
    }

    config()->set('services.serply.key', $key);
});

it('runs one live Serply search', function (): void {
    $result = variantRun('serply-search', 'Laravel documentation');

    expect($result->provider)->toBe('serply')->and($result->citations)->not->toBeEmpty();
});
