<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Tests\Support\ApplicationDefinedDriver;

beforeEach(function (): void {
    ApplicationDefinedDriver::$container = null;
    ApplicationDefinedDriver::$request = null;
    ApplicationDefinedDriver::$runs = 0;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('executes an application-defined source through the public Run runtime without provider access', function (): void {
    Http::fake();
    CarbonImmutable::setTestNow('2026-09-11T12:00:00Z');
    config(['librarium.runs.store' => 'memory', 'librarium.timeout' => 45]);
    config()->set('librarium.sources.application-search', [
        'key' => 'placeholder',
        'quick' => [
            'driver' => ApplicationDefinedDriver::class,
            'provider' => 'application',
            'model' => 'application-v1',
            'result_kind' => 'search_results',
            'grounding' => 'none',
            'observation' => 'api_output',
            'corpora' => ['web'],
            'retrieval_methods' => ['search_endpoint'],
            'prompt' => 'Application query: {{ query }}',
            'enabled' => true,
            'options' => ['mode' => 'offline'],
        ],
    ]);

    $pending = Librarium::research('Verify the extension seam.')->only(['application-search']);
    $response = $pending->run();
    $result = $response->result('application-search');

    expect(ApplicationDefinedDriver::$runs)->toBe(1)
        ->and(ApplicationDefinedDriver::$container)->toBe(app())
        ->and(ApplicationDefinedDriver::$request)->not->toBeNull()
        ->and(ApplicationDefinedDriver::$request?->requestId)->toBe($response->id())
        ->and(ApplicationDefinedDriver::$request?->prompt)->toBe('Application query: Verify the extension seam.')
        ->and(ApplicationDefinedDriver::$request?->requestedProfile->id)->toBe('application-search-quick')
        ->and(ApplicationDefinedDriver::$request?->profile->options)->toBe(['mode' => 'offline'])
        ->and(ApplicationDefinedDriver::$request?->deadline->toIso8601ZuluString())->toBe('2026-09-11T12:00:45Z')
        ->and($response->status())->toBe('done')
        ->and($result['answer'])->toBeNull()
        ->and($result['provenance']['provider'])->toBe('application')
        ->and($result['provenance']['variant'])->toBe('application-search-quick')
        ->and($result['provenance']['model'])->toBe('application-v1')
        ->and(Librarium::find($response->id())?->status())->toBe('done');

    Http::assertNothingSent();
});
