<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Tests\Support\ApplicationDefinedDriver;

beforeEach(function (): void {
    ApplicationDefinedDriver::$container = null;
    ApplicationDefinedDriver::$request = null;
    ApplicationDefinedDriver::$runs = 0;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('executes an application-defined Driver through the public query runtime without provider access', function (): void {
    Http::fake();
    CarbonImmutable::setTestNow('2026-09-11T12:00:00Z');
    config()->set('librarium.profiles', [
        'application-search' => [
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

    $pending = Librarium::query('Verify the extension seam.')
        ->using('application-search')
        ->timeout(45);
    $requestId = $pending->requestId;
    $response = $pending->run();
    $result = $response->results->sole();

    expect(ApplicationDefinedDriver::$runs)->toBe(1)
        ->and(ApplicationDefinedDriver::$container)->toBe(app())
        ->and(ApplicationDefinedDriver::$request)->not->toBeNull()
        ->and(ApplicationDefinedDriver::$request?->requestId)->toBe($requestId)
        ->and(ApplicationDefinedDriver::$request?->prompt)->toBe('Application query: Verify the extension seam.')
        ->and(ApplicationDefinedDriver::$request?->requestedProfile->id)->toBe('application-search')
        ->and(ApplicationDefinedDriver::$request?->profile->options)->toBe(['mode' => 'offline'])
        ->and(ApplicationDefinedDriver::$request?->deadline->toIso8601ZuluString())->toBe('2026-09-11T12:00:45Z')
        ->and($response->requestId)->toBe($requestId)
        ->and($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($result->content)->toBe('Result supplied by the consuming application.')
        ->and($result->requestedProfile)->toBe('application-search')
        ->and($result->provider)->toBe('application')
        ->and($result->profile)->toBe('application-search')
        ->and($result->model)->toBe('application-v1')
        ->and(Librarium::status($requestId)?->state)->toBe(ResearchState::Completed);

    Http::assertNothingSent();
});
