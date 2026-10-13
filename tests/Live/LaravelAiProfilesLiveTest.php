<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;

it('runs a live grounded query through each Laravel AI Profile', function (string $profile, string $source, array $options, string $provider, string $credential): void {
    if (blank(config("ai.providers.{$credential}.key"))) {
        $this->markTestSkipped("Configure the {$credential} provider credential to run [{$profile}].");
    }

    config()->set('librarium.timeout', 180);
    $run = Librarium::research('What is the latest stable Laravel major version? Cite the official Laravel documentation that supports your answer.')
        ->only([$source])
        ->options([$source => $options])
        ->run();

    $result = $run->result($source);

    expect($run->status())->toBe('completed')
        ->and($run->toArray()['results'])->toHaveCount(1)
        ->and($result['provenance']['provider'])->toBe($provider)
        ->and($result['provenance']['variant'])->toBe($profile)
        ->and($result['provenance']['model'])->toBeString()->not->toBeEmpty()
        ->and($result['observation'])->toBe('api')
        ->and($result['error'])->toBeNull()
        ->and($result['answer'])->toBeString()->not->toBeEmpty()
        ->and($result['citations'])->not->toBeEmpty();
})->with([
    'Gemini grounded' => ['gemini-grounded', 'gemini', [], 'gemini', 'gemini'],
    'OpenRouter grounded' => ['openrouter-grounded', 'openrouter', [], 'openrouter', 'openrouter'],
    'OpenRouter chat' => ['openrouter-chat', 'openrouter', ['quick' => 'chat'], 'openrouter', 'openrouter'],
    'Claude chat' => ['claude-chat', 'claude', [], 'anthropic', 'anthropic'],
    'OpenAI chat' => ['openai-chat', 'openai', [], 'openai', 'openai'],
    'Gemini chat' => ['gemini-chat', 'gemini', ['quick' => 'chat'], 'gemini', 'gemini'],
]);
