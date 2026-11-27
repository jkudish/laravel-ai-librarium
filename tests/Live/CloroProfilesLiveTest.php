<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;

/*
 * About 47 Cloro credits for one pass (async 5/4/4/5, sync 7/6/6/5/5). The
 * free plan allows one concurrent request, so each case finishes before the
 * next starts; never run this file in parallel.
 */
beforeEach(function (): void {
    $key = getenv('CLORO_API_KEY');
    if (! is_string($key) || trim($key) === '') {
        $this->markTestSkipped('Set CLORO_API_KEY to run the live Cloro canary.');
    }

    config()->set('services.cloro.key', $key);
    config()->set('librarium.engines', ['collector' => 'cloro', 'options' => ['country' => 'US']]);
    config()->set('librarium.sources.google', ['collector' => 'cloro', 'options' => ['country' => 'US']]);
    config()->set('librarium.timeout', 600);
});

it('collects each Cloro surface and SERP once', function (string $source, array $options, string $variant): void {
    // Google News finds no articles for a how-to question, so its case asks for news.
    $question = $variant === 'cloro-google-news' ? 'Laravel framework release' : 'What is the latest stable Laravel major version? Cite the official documentation.';
    $run = Librarium::research($question)
        ->only([$source])
        ->options([$source => $options])
        ->run();
    $result = $run->result($source);
    $evidence = $run->evidence($source);

    expect($result['status'])->toBe('done', json_encode($result['error']) ?: '')
        ->and($result['provenance']['variant'])->toBe($variant)
        ->and($result['cost']['reported'])->toBeNull()
        ->and($evidence)->not->toBeEmpty();

    if ($source === 'google') {
        expect($result['observation'])->toBe('api')->and($result['citations'])->not->toBeEmpty();
    } else {
        expect($result['provenance']['collector'])->toBe('cloro')
            ->and($result['observation'])->toBe('surface')
            ->and($result['answer'])->toBeString()->not->toBeEmpty();
    }

    fwrite(STDERR, sprintf(
        "cloro %s: %d citations, %d ms, credits %s\n",
        $variant,
        count($result['citations']),
        (int) $result['duration_ms'],
        json_encode(collect($evidence)->map(fn (array $item): mixed => $item['headers']['x-credits-charged'][0] ?? data_get(json_decode((string) ($item['body'] ?? ''), true), 'credits.creditsCharged'))->filter()->values()),
    ));
})->with([
    'chatgpt standard' => ['chatgpt', [], 'cloro-chatgpt'],
    'gemini standard' => ['gemini-app', [], 'cloro-gemini'],
    'bing ai mode standard' => ['bing-ai-mode', [], 'cloro-bing-ai-mode'],
    'google ai overview standard' => ['google-ai-overview', [], 'cloro-google-ai-overview'],
    'chatgpt live' => ['chatgpt', ['priority' => 'live'], 'cloro-chatgpt-live'],
    'google ai mode live' => ['google-ai-mode', ['priority' => 'live'], 'cloro-google-ai-mode-live'],
    'bing ai mode live' => ['bing-ai-mode', ['priority' => 'live'], 'cloro-bing-ai-mode-live'],
    'google search' => ['google', [], 'cloro-google-search'],
    'google news' => ['google', ['vertical' => 'news'], 'cloro-google-news'],
]);
