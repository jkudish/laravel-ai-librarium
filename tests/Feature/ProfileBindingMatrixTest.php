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

    expect(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback; #2841')))->toHaveCount(5)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback; #2842')))->toHaveCount(4)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback; #2843')))->toHaveCount(2)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback; #2844')))->toHaveCount(3)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback; #2845')))->toHaveCount(2);
});

it('reconciles every TypeScript matrix row with the landed PHP catalog', function (): void {
    $matrix = file_get_contents(__DIR__.'/../../docs/profile-bindings.md');

    expect($matrix)->not->toBeFalse();
    assert(is_string($matrix));

    preg_match(
        '/^## Landed PHP reconciliation\R(?<body>.*?)(?=^## )/ms',
        $matrix,
        $section,
    );
    preg_match_all(
        '/^\| `(?<typescript>[^`]+\/[^`]+)` \| (?<disposition>Landed|Separate task|Holdback) \| (?<php>.*?) \|/m',
        $section['body'] ?? '',
        $matches,
        PREG_SET_ORDER,
    );

    $landed = [
        'gemini-grounded/grounded' => 'gemini-grounded',
        'openrouter/grounded' => 'openrouter-grounded',
        'openrouter/chat' => 'openrouter-chat',
        'claude/chat' => 'claude-chat',
        'openai-chat/chat' => 'openai-chat',
        'gemini-chat/chat' => 'gemini-chat',
        'brave-answers/grounded' => 'brave-answers',
        'exa/search' => 'exa',
        'kagi-fastgpt/grounded' => 'kagi-fastgpt',
        'perplexity-sonar-pro/grounded' => 'perplexity-sonar-pro',
        'grok/web' => 'grok',
        'openai-research/research' => 'openai-research',
        'perplexity-sonar-deep/research' => 'perplexity-sonar-deep',
        'searchapi/search' => 'searchapi',
        'searchapi-chatgpt/surface' => 'searchapi-chatgpt',
        'searchapi-gemini/surface' => 'searchapi-gemini',
        'searchapi-perplexity/surface' => 'searchapi-perplexity',
        'searchapi-google-ai-mode/surface' => 'searchapi-google-ai-mode',
        'searchapi-bing-copilot/surface' => 'searchapi-bing-copilot',
        'searchapi-google-ai-overview/surface' => 'searchapi-google-ai-overview',
        'you-research/grounded' => 'you-research-grounded',
        'you-research/research' => 'you-research-research',
        'you-answer/grounded' => 'you-answer',
    ];

    $documentedLanded = [];
    $documentedAbsent = [];

    foreach ($matches as $row) {
        if ($row['disposition'] === 'Landed') {
            preg_match('/^`(?<profile>[^`]+)`$/', $row['php'], $profile);
            $documentedLanded[$row['typescript']] = $profile['profile'] ?? null;
        } else {
            $documentedAbsent[] = $row['typescript'];
        }
    }

    $configuredProfiles = array_keys(config('librarium.profiles'));
    sort($configuredProfiles);
    $expectedProfiles = array_values($landed);
    sort($expectedProfiles);

    expect($matches)->toHaveCount(40)
        ->and($documentedLanded)->toBe($landed)
        ->and($documentedAbsent)->toHaveCount(17)
        ->and($configuredProfiles)->toBe($expectedProfiles);
});

it('keeps one separately gated live canary for every landed PHP Profile', function (): void {
    $canaries = file_get_contents(__DIR__.'/../../docs/live-provider-canaries.md');

    expect($canaries)->not->toBeFalse();
    assert(is_string($canaries));

    preg_match_all(
        '/^\| C(?<id>\d{2}) \| `(?<profile>[^`]+)` \|/m',
        $canaries,
        $matches,
        PREG_SET_ORDER,
    );

    $ids = array_column($matches, 'id');
    $profiles = array_column($matches, 'profile');
    $configuredProfiles = array_keys(config('librarium.profiles'));
    sort($profiles);
    sort($configuredProfiles);

    expect($matches)->toHaveCount(23)
        ->and($ids)->toBe(array_map(static fn (int $id): string => sprintf('%02d', $id), range(1, 23)))
        ->and($profiles)->toBe($configuredProfiles)
        ->and($canaries)->toContain('Approval for one ID does')
        ->toContain('No live provider or')
        ->toContain('Unknown cost is not zero.')
        ->toContain('C01–C13 passed separately authorized live checks')
        ->toContain('C14–C23 remain unverified live.')
        ->toContain("--filter='<exact dataset name from the table>' --fail-on-skipped");
});
