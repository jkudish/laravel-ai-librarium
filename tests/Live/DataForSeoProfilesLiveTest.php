<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoCredential;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Personalization;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;

beforeEach(function (): void {
    $login = getenv('DATAFORSEO_LOGIN');
    $password = getenv('DATAFORSEO_PASSWORD');
    if (! is_string($login) || trim($login) === '' || ! is_string($password) || trim($password) === '') {
        $this->markTestSkipped('Set DATAFORSEO_LOGIN and DATAFORSEO_PASSWORD to run a live DataForSEO canary.');
    }

    config()->set('librarium.providers.dataforseo.login', $login);
    config()->set('librarium.providers.dataforseo.password', $password);
});

it('runs one explicitly approved DataForSEO surface canary', function (string $profile, array $options, string $surface, string $cost): void {
    config()->set("librarium.profiles.{$profile}.enabled", true);
    config()->set("librarium.profiles.{$profile}.options", $options);
    config()->set("librarium.profiles.{$profile}.credential", DataForSeoCredential::encode(
        config('librarium.providers.dataforseo.login'),
        config('librarium.providers.dataforseo.password'),
    ));

    $response = Librarium::query('What is Laravel? Cite the official documentation when available.')
        ->using($profile)
        ->timeout(str_ends_with($profile, '-live') ? 180 : 6900)
        ->maxCost($cost)
        ->run();

    if ($response->status !== ResponseStatus::Succeeded) {
        $errors = $response->errors
            ->map(fn ($error): string => "{$error->code}: {$error->message}")
            ->implode('; ');

        $this->fail("Profile [{$profile}] failed: {$errors}");
    }

    $result = $response->results->sole();

    expect($result->profile)->toBe($profile)
        ->and($result->provider)->toBe('dataforseo')
        ->and($result->provenance->collector)->toBe('dataforseo')
        ->and($result->provenance->surface)->toBe($surface)
        ->and($result->provenance->context)->toBe([
            'authentication' => Authentication::Unknown,
            'personalization' => Personalization::Unknown,
        ])
        ->and($result->content)->not->toBeEmpty()
        ->and($result->usage?->actualCost)->not->toBeNull();
})->with([
    'DataForSEO ChatGPT Standard' => ['dataforseo-chatgpt', ['location_code' => 2840, 'language_code' => 'en', 'force_web_search' => true], 'chatgpt', '0.0012'],
    'DataForSEO ChatGPT Live' => ['dataforseo-chatgpt-live', ['location_code' => 2840, 'language_code' => 'en', 'force_web_search' => true], 'chatgpt', '0.004'],
    'DataForSEO Gemini Standard' => ['dataforseo-gemini', ['location_code' => 2840, 'language_code' => 'en'], 'gemini', '0.0012'],
    'DataForSEO Gemini Live' => ['dataforseo-gemini-live', ['location_code' => 2840, 'language_code' => 'en'], 'gemini', '0.004'],
    'DataForSEO Google AI Mode Standard' => ['dataforseo-google-ai-mode', ['location_code' => 2840, 'language_code' => 'en', 'device' => 'mobile', 'os' => 'ios'], 'google_ai_mode', '0.0012'],
    'DataForSEO Google AI Mode Live' => ['dataforseo-google-ai-mode-live', ['location_code' => 2840, 'language_code' => 'en', 'device' => 'mobile', 'os' => 'ios'], 'google_ai_mode', '0.004'],
]);
