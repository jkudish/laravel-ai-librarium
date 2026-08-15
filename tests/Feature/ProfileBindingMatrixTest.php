<?php

declare(strict_types=1);

it('locks the accepted TypeScript Profile inventory and its PHP classifications', function (): void {
    $matrix = file_get_contents(__DIR__.'/../../docs/profile-bindings.md');

    expect($matrix)->not->toBeFalse();
    assert(is_string($matrix));

    expect($matrix)
        ->toContain('5ec53f2517ffab4db2e4446d458f168f0388879b')
        ->toContain('`laravel/ai` v0.10.3 at `c3848aae389f45c605eefb0dda5bd5fa7df76eaa`');

    preg_match_all(
        '/^### `(?<key>[^`]+\/[^`]+)`\R(?<body>.*?)(?=^### |^## |\z)/ms',
        $matrix,
        $matches,
        PREG_SET_ORDER,
    );

    $expectedKeys = [
        'brave-answers/grounded',
        'brave-search/search',
        'claude/chat',
        'exa/research',
        'exa/search',
        'firecrawl-search/search',
        'gemini-chat/chat',
        'gemini-deep/research',
        'gemini-grounded/grounded',
        'grok-combined/combined',
        'grok-x-only/x',
        'grok/web',
        'jina-search/search',
        'kagi-fastgpt/grounded',
        'openai-chat/chat',
        'openai-research/research',
        'openrouter/chat',
        'openrouter/grounded',
        'parallel/chat',
        'parallel/research',
        'parallel/search',
        'perplexity-deep-research/research',
        'perplexity-search/search',
        'perplexity-sonar-deep/research',
        'perplexity-sonar-pro/grounded',
        'searchapi-bing-copilot/surface',
        'searchapi-chatgpt/surface',
        'searchapi-gemini/surface',
        'searchapi-google-ai-mode/surface',
        'searchapi-google-ai-overview/surface',
        'searchapi-perplexity/surface',
        'searchapi/search',
        'serpapi/search',
        'tavily/research',
        'tavily/search',
        'valyu/research',
        'valyu/search',
        'you-answer/grounded',
        'you-research/grounded',
        'you-research/research',
    ];

    $actualKeys = array_column($matches, 'key');
    $uniqueKeys = array_values(array_unique($actualKeys));
    sort($actualKeys);
    sort($uniqueKeys);
    sort($expectedKeys);

    expect($actualKeys)
        ->toHaveCount(40)
        ->toBe($uniqueKeys)
        ->toBe($expectedKeys);

    $requiredFields = [
        '**Workflow:**',
        '**Semantics:**',
        '**Target:**',
        '**PHP path:**',
        '**Binding:**',
        '**Execution:**',
        '**Terminal:**',
        '**Pricing:**',
        '**Test and proof:**',
    ];

    foreach ($matches as $row) {
        foreach ($requiredFields as $field) {
            $this->assertStringContainsString(
                $field,
                $row['body'],
                "Matrix row {$row['key']} is missing {$field}",
            );
        }
    }

    $rows = array_column($matches, 'body', 'key');

    expect(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #2591 core SDK')))->toHaveCount(6)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #2591 core HTTP')))->toHaveCount(14)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #2592')))->toHaveCount(1)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #2694')))->toHaveCount(3)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback')))->toHaveCount(16)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '#2830')))->toHaveCount(6);

    foreach (array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback')) as $key => $row) {
        $this->assertStringContainsString(
            '**Holdback reason:**',
            $row,
            "Holdback row {$key} needs an explicit reason.",
        );
    }
});
