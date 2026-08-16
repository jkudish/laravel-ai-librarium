<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;

it('runs a live grounded query through each Laravel AI Profile', function (string $profile, string $provider, string $credential): void {
    if (blank(config("ai.providers.{$credential}.key"))) {
        $this->markTestSkipped("Configure the {$credential} provider credential to run [{$profile}].");
    }

    $response = Librarium::query('What is the latest stable Laravel major version? Cite the official Laravel documentation that supports your answer.')
        ->using($profile)
        ->timeout(180)
        ->run();

    if ($response->status !== ResponseStatus::Succeeded) {
        $errors = $response->errors
            ->map(fn ($error): string => "{$error->code}: {$error->message}")
            ->implode('; ');

        $this->fail("Profile [{$profile}] failed: {$errors}");
    }

    expect($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($response->results)->toHaveCount(1)
        ->and($response->errors)->toBeEmpty();

    $result = $response->results->sole();

    expect($result->provider)->toBe($provider)
        ->and($result->profile)->toBe($profile)
        ->and($result->model)->toBeString()->not->toBeEmpty()
        ->and($result->content)->toBeString()->not->toBeEmpty()
        ->and($result->provenance->resultKind)->toBe(ResultKind::GroundedAnswer)
        ->and($result->citations)->not->toBeEmpty();
})->with([
    'Gemini grounded' => ['gemini-grounded', 'gemini', 'gemini'],
    'OpenRouter grounded' => ['openrouter-grounded', 'openrouter', 'openrouter'],
    'OpenRouter chat' => ['openrouter-chat', 'openrouter', 'openrouter'],
    'Claude chat' => ['claude-chat', 'anthropic', 'anthropic'],
    'OpenAI chat' => ['openai-chat', 'openai', 'openai'],
    'Gemini chat' => ['gemini-chat', 'gemini', 'gemini'],
]);
