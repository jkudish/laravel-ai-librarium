<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;

it('runs an explicitly funded You.com canary', function (
    string $profile,
    string $query,
    string $spend,
    int $timeout,
    string $provider,
    string $endpoint,
    ?bool $background,
): void {
    if (getenv('LIBRARIUM_YOU_LIVE_SPEND_USD') !== $spend) {
        $this->markTestSkipped("Set LIBRARIUM_YOU_LIVE_SPEND_USD={$spend} to acknowledge this canary's documented spend.");
    }

    $credential = config("librarium.profiles.{$profile}.credential");
    if (! is_string($credential) || trim($credential) === '') {
        $this->markTestSkipped('YOU_COM_API_KEY or YDC_API_KEY is required for the You.com live canary.');
    }

    config()->set("librarium.profiles.{$profile}.credential", $credential);

    $response = Librarium::query($query)
        ->using($profile)
        ->maxCost($spend)
        ->timeout($timeout)
        ->run();

    expect($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($response->results->sole()->provider)->toBe($provider)
        ->and($response->results->sole()->citations)->not->toBeEmpty();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === $endpoint
        && ($background === null
            || ($background
                ? data_get($request->data(), 'background') === true
                : ! array_key_exists('background', $request->data()))));

    if ($background === true) {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.you.com/v1/research/'));
    }
})->with([
    'You Answer' => [
        'you-answer',
        'What is Laravel 13? Answer in one sentence with citations.',
        '0.005',
        60,
        'you-answer',
        'https://api.you.com/v1/answer',
        null,
    ],
    'You Research grounded' => [
        'you-research-grounded',
        'Summarize the official Laravel 13 release highlights with citations.',
        '0.05',
        180,
        'you-research',
        'https://api.you.com/v1/research',
        false,
    ],
    'You Research durable' => [
        'you-research-research',
        'Compare the official Laravel 13 release highlights with Laravel 12.',
        '0.05',
        180,
        'you-research',
        'https://api.you.com/v1/research',
        true,
    ],
]);
