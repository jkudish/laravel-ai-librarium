<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;

beforeEach(function (): void {
    $key = getenv('SEARCHAPI_KEY');
    if (! is_string($key) || trim($key) === '') {
        $this->markTestSkipped('Set SEARCHAPI_KEY to run the live SearchAPI canary.');
    }

    config()->set('services.searchapi.key', $key);
    config()->set('librarium.engines', ['collector' => 'searchapi', 'key' => $key]);
    config()->set('librarium.timeout', 120);
});

it('runs a live SearchAPI query through each profile', function (string $profile, string $source): void {
    $run = Librarium::research('What is the latest stable Laravel major version? Cite the official documentation.')
        ->only([$source])
        ->run();
    $result = $run->result($source);

    expect($run->status())->toBe('completed')
        ->and($result['source'])->toBe($source)
        ->and($result['provenance']['variant'])->toBe($profile)
        ->and($run->evidence($source))->not->toBeEmpty();

    if ($source === 'google') {
        expect($result['provenance']['collector'])->toBeNull()
            ->and($result['observation'])->toBe('api')
            ->and($result['answer'])->toBeNull();
    } else {
        expect($result['provenance']['collector'])->toBe('searchapi')
            ->and($result['observation'])->toBe('surface')
            ->and($result['answer'])->toBeString()->not->toBeEmpty();
    }
})->with([
    'raw google' => ['searchapi', 'google'],
    'chatgpt' => ['searchapi-chatgpt', 'chatgpt'],
    'gemini' => ['searchapi-gemini', 'gemini-app'],
    'google ai mode' => ['searchapi-google-ai-mode', 'google-ai-mode'],
    'bing copilot' => ['searchapi-bing-copilot', 'copilot'],
    'google ai overview' => ['searchapi-google-ai-overview', 'google-ai-overview'],
]);
