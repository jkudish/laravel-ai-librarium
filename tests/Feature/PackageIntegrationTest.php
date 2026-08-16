<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiDriver;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LaravelAiLibrariumServiceProvider;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;

it('registers the manager behind the package contract', function (): void {
    $contract = app(LibrariumContract::class);

    expect($contract)
        ->toBeInstanceOf(LibrariumManager::class)
        ->toBe(app(LibrariumManager::class));
});

it('merges the package configuration', function (): void {
    expect(config('librarium.default_group'))->toBe('quick')
        ->and(config('librarium.timeout'))->toBe(6900)
        ->and(config('librarium.queue.timeout'))->toBe(7200)
        ->and(config('librarium.status_ttl'))->toBe(10800)
        ->and(config('librarium.webhook_signal_ttl'))->toBe(10800)
        ->and(config('librarium.queue.name'))->toBe('research')
        ->and(app(WebhookSignalStore::class))->toBe(app(WebhookSignalStore::class));
});

it('resolves Laravel AI credentials after Laravel AI registers later', function (string $profileId, string $provider, string $model): void {
    $profile = config("librarium.profiles.{$profileId}");

    expect($profile)->toBeArray()
        ->and(data_get($profile, 'driver'))->toBe(LaravelAiDriver::class)
        ->and(data_get($profile, 'provider'))->toBe($provider)
        ->and(data_get($profile, 'model'))->toBe($model)
        ->and(config("ai.providers.{$provider}.key"))->toBeString()->not->toBeEmpty()
        ->and(data_get($profile, 'credential'))->toBe(config("ai.providers.{$provider}.key"))
        ->and(data_get($profile, 'credential'))->not->toBe("ai.providers.{$provider}.key");
})->with([
    'Gemini grounded' => ['gemini-grounded', 'gemini', 'gemini-3.6-flash'],
    'OpenRouter grounded' => ['openrouter-grounded', 'openrouter', 'openai/gpt-4o-mini'],
    'OpenRouter chat' => ['openrouter-chat', 'openrouter', 'openai/gpt-5.6-terra'],
    'Claude chat' => ['claude-chat', 'anthropic', 'claude-sonnet-5'],
    'OpenAI chat' => ['openai-chat', 'openai', 'gpt-5-mini'],
    'Gemini chat' => ['gemini-chat', 'gemini', 'gemini-3.6-flash'],
]);

it('resolves Laravel AI credentials for Profile IDs containing dots', function (): void {
    $profiles = config('librarium.profiles');
    $profiles['custom.chat'] = [
        ...$profiles['gemini-chat'],
        'credential' => null,
    ];
    config()->set('librarium.profiles', $profiles);

    (new LaravelAiLibrariumServiceProvider(app()))->boot();

    $resolved = config('librarium.profiles');

    expect($resolved['custom.chat']['credential'])->toBe(config('ai.providers.gemini.key'))
        ->and($resolved)->not->toHaveKey('custom');
});

it('starts a research query through the facade', function (): void {
    $research = Librarium::query('Compare current agent memory architectures.');

    expect($research)
        ->toBeInstanceOf(PendingResearch::class)
        ->input->toBe('Compare current agent memory architectures.');
});

it('rejects empty input through the facade', function (string $input): void {
    expect(fn (): PendingResearch => Librarium::query($input))
        ->toThrow(ValidationException::class);
})->with(['', '   ', "\n\t"]);
