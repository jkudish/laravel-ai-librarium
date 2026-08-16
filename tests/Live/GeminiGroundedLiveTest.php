<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;

it('runs a live Gemini grounded query through the public API', function (): void {
    if (blank(config('ai.providers.gemini.key'))) {
        $this->markTestSkipped('Set GEMINI_API_KEY to run the live Gemini canary.');
    }

    $response = Librarium::query('What is the latest stable Laravel major version? Cite the official Laravel documentation that supports your answer.')
        ->using('gemini-grounded')
        ->timeout(180)
        ->run();

    expect($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($response->results)->toHaveCount(1)
        ->and($response->errors)->toBeEmpty();

    $result = $response->results->sole();

    expect($result->provider)->toBe('gemini')
        ->and($result->profile)->toBe('gemini-grounded')
        ->and($result->model)->toBe('gemini-3.6-flash')
        ->and($result->content)->toBeString()->not->toBeEmpty()
        ->and($result->citations)->not->toBeEmpty();
});
