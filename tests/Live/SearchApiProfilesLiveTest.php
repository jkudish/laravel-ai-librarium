<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Drivers\SearchApi\SearchApiDriver;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

beforeEach(function (): void {
    $key = getenv('SEARCHAPI_KEY');
    if (! is_string($key) || trim($key) === '') {
        $this->markTestSkipped('Set SEARCHAPI_KEY to run the live SearchAPI canary.');
    }

    config()->set('services.searchapi.key', $key);

    collect(config('librarium.profiles'))
        ->filter(fn (array $profile): bool => data_get($profile, 'driver') === SearchApiDriver::class)
        ->keys()
        ->each(fn (string $id) => config()->set("librarium.profiles.{$id}.credential", $key));
});

it('runs a live SearchAPI query through each profile', function (string $profile): void {
    $response = Librarium::query('What is the latest stable Laravel major version? Cite the official documentation.')
        ->using($profile)
        ->timeout(120)
        ->run();

    if ($response->status !== ResponseStatus::Succeeded) {
        $errors = $response->errors
            ->map(fn ($error): string => "{$error->code}: {$error->message}")
            ->implode('; ');

        $this->fail("Profile [{$profile}] failed: {$errors}");
    }

    $result = $response->results->sole();

    expect($result->profile)->toBe($profile)
        ->and($result->content)->toBeString()->not->toBeEmpty();

    if (in_array($profile, ['searchapi'], true)) {
        expect($result->provenance->collector)->toBeNull()
            ->and($result->provenance->surface)->toBeNull();
    } else {
        expect($result->provenance->collector)->toBe('searchapi')
            ->and($result->provenance->surface)->not->toBeNull()
            ->and($result->provenance->resultKind)->toBe(ResultKind::SurfaceObservation)
            ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SurfaceCollector])
            ->and($result->provenance->observationMode)->toBe(ObservationMode::SurfaceSnapshot);
    }
})->with([
    'raw google' => ['searchapi'],
    'chatgpt' => ['searchapi-chatgpt'],
    'gemini' => ['searchapi-gemini'],
    'perplexity' => ['searchapi-perplexity'],
    'google ai mode' => ['searchapi-google-ai-mode'],
    'bing copilot' => ['searchapi-bing-copilot'],
    'google ai overview' => ['searchapi-google-ai-overview'],
]);
