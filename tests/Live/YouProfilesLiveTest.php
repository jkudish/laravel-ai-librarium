<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

it('runs an explicitly funded You.com canary', function (
    string $profile,
    string $query,
    int $timeout,
    string $provider,
    string $endpoint,
    ?bool $background,
): void {
    $credential = config('services.you.key') ?: (getenv('YOU_COM_API_KEY') ?: getenv('YDC_API_KEY'));
    if (! is_string($credential) || trim($credential) === '') {
        $this->markTestSkipped('YOU_COM_API_KEY or YDC_API_KEY is required for the You.com live canary.');
    }

    config()->set('librarium.sources.you.key', $credential);
    config()->set('librarium.timeout', $timeout);
    $pending = Librarium::research($query)->only(['you']);
    if ($background === true) {
        $pending->deep();
    } elseif ($background === false) {
        $pending->options(['you' => ['quick' => 'research']]);
    }

    $estimate = $pending->dryRun()->result('you')['cost']['estimated'];

    if ($estimate === null) {
        $this->markTestSkipped('The current pricing quote does not support a maximum-cost preflight check for this canary.');
    }

    if (getenv('LIBRARIUM_YOU_LIVE_SPEND_USD') !== $estimate) {
        $this->markTestSkipped("Set LIBRARIUM_YOU_LIVE_SPEND_USD={$estimate} to acknowledge this canary's current pre-dispatch estimate.");
    }

    Http::record();

    $run = $pending->maxCost($estimate)->run();
    $result = $run->result('you');

    expect($run->status())->toBe('done')
        ->and($result['provenance']['variant'])->toBe($profile)
        ->and($result['provenance']['provider'])->toBe($provider)
        ->and($result['citations'])->not->toBeEmpty();

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
        60,
        'you-answer',
        'https://api.you.com/v1/answer',
        null,
    ],
    'You Research grounded' => [
        'you-research-grounded',
        'Summarize the official Laravel 13 release highlights with citations.',
        180,
        'you-research',
        'https://api.you.com/v1/research',
        false,
    ],
    'You Research durable' => [
        'you-research-research',
        'Compare the official Laravel 13 release highlights with Laravel 12.',
        180,
        'you-research',
        'https://api.you.com/v1/research',
        true,
    ],
]);
