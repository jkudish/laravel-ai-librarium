<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;

it('runs explicitly funded You.com Answer and standard Research canaries', function (): void {
    if (getenv('LIBRARIUM_YOU_LIVE_SPEND_USD') !== '0.055') {
        $this->markTestSkipped('Set LIBRARIUM_YOU_LIVE_SPEND_USD=0.055 to acknowledge the maximum documented spend.');
    }

    $credential = config('librarium.profiles.you-answer.credential');
    if (! is_string($credential) || trim($credential) === '') {
        $this->markTestSkipped('YOU_COM_API_KEY or YDC_API_KEY is required for the You.com live canaries.');
    }

    config()->set('librarium.profiles.you-answer.credential', $credential);
    config()->set('librarium.profiles.you-research.credential', $credential);

    $answer = Librarium::query('What is Laravel 13? Answer in one sentence with citations.')
        ->using('you-answer')
        ->maxCost('0.005')
        ->timeout(60)
        ->run();
    $research = Librarium::query('Summarize the official Laravel 13 release highlights with citations.')
        ->using('you-research')
        ->maxCost('0.05')
        ->timeout(180)
        ->run();

    expect($answer->status)->toBe(ResponseStatus::Succeeded)
        ->and($answer->results->sole()->provider)->toBe('you-answer')
        ->and($answer->results->sole()->citations)->not->toBeEmpty()
        ->and($research->status)->toBe(ResponseStatus::Succeeded)
        ->and($research->results->sole()->provider)->toBe('you-research')
        ->and($research->results->sole()->citations)->not->toBeEmpty();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.you.com/v1/answer');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.you.com/v1/research'
        && data_get($request->data(), 'background') === true);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://api.you.com/v1/research/'));
});
