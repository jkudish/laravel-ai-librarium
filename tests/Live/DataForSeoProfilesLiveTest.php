<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoCredential;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

beforeEach(function (): void {
    $login = getenv('DATAFORSEO_LOGIN');
    $password = getenv('DATAFORSEO_PASSWORD');
    if (! is_string($login) || trim($login) === '' || ! is_string($password) || trim($password) === '') {
        $this->markTestSkipped('Set DATAFORSEO_LOGIN and DATAFORSEO_PASSWORD to run a live DataForSEO canary.');
    }

    config()->set('services.dataforseo.login', $login);
    config()->set('services.dataforseo.password', $password);
});

it('runs one explicitly approved DataForSEO surface canary', function (string $profile, array $options, string $engine, string $cost): void {
    $priority = str_ends_with($profile, '-live') ? 'live' : 'standard';
    config()->set('librarium.engines', [
        'collector' => 'dataforseo',
        'key' => DataForSeoCredential::encode(config('services.dataforseo.login'), config('services.dataforseo.password')),
    ]);
    config()->set('librarium.timeout', $priority === 'live' ? 180 : 6900);

    $run = Librarium::research('What is Laravel? Cite the official documentation when available.')
        ->only([$engine])
        ->options([$engine => ['priority' => $priority, ...$options]])
        ->maxCost($cost)
        ->run();

    $result = $run->result($engine);

    expect($run->status())->toBe('done')
        ->and($result['source'])->toBe($engine)
        ->and($result['provenance']['variant'])->toBe($profile)
        ->and($result['provenance']['provider'])->toBe('dataforseo')
        ->and($result['provenance']['collector'])->toBe('dataforseo')
        ->and($result['observation'])->toBe('surface')
        ->and($result['answer'])->toBeString()->not->toBeEmpty()
        ->and($result['cost']['reported'])->not->toBeNull();
})->with([
    'DataForSEO ChatGPT Standard' => ['dataforseo-chatgpt', ['location_code' => 2840, 'language_code' => 'en', 'force_web_search' => true], 'chatgpt', '0.0012'],
    'DataForSEO ChatGPT Live' => ['dataforseo-chatgpt-live', ['location_code' => 2840, 'language_code' => 'en', 'force_web_search' => true], 'chatgpt', '0.004'],
    'DataForSEO Gemini Standard' => ['dataforseo-gemini', ['location_code' => 2840, 'language_code' => 'en'], 'gemini-app', '0.0012'],
    'DataForSEO Gemini Live' => ['dataforseo-gemini-live', ['location_code' => 2840, 'language_code' => 'en'], 'gemini-app', '0.004'],
    'DataForSEO Google AI Mode Standard' => ['dataforseo-google-ai-mode', ['location_code' => 2840, 'language_code' => 'en', 'device' => 'mobile', 'os' => 'ios'], 'google-ai-mode', '0.0012'],
    'DataForSEO Google AI Mode Live' => ['dataforseo-google-ai-mode-live', ['location_code' => 2840, 'language_code' => 'en', 'device' => 'mobile', 'os' => 'ios'], 'google-ai-mode', '0.004'],
]);
