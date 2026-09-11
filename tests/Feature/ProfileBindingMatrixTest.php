<?php

declare(strict_types=1);

it('locks the accepted TypeScript Profile inventory and its PHP classifications', function (): void {
    $matrix = file_get_contents(__DIR__.'/../../docs/profile-bindings.md');

    expect($matrix)->not->toBeFalse();
    assert(is_string($matrix));

    expect($matrix)
        ->toContain('889b0366b5baafff117198632e04b24e521ac93d')
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
        'parallel/research',
        'parallel/search',
        'parallel/turbo',
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
        ->toHaveCount(39)
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
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #3604 core parity')))->toHaveCount(15)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback')))->toBeEmpty()
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '#2830')))->toHaveCount(6);
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
        'brave-search/search' => 'brave-search',
        'exa/research' => 'exa-research',
        'tavily/search' => 'tavily-search',
        'gemini-deep/research' => 'gemini-deep-research',
        'perplexity-deep-research/research' => 'perplexity-deep-research',
        'perplexity-search/search' => 'perplexity-search',
        'grok-x-only/x' => 'grok-x-only',
        'grok-combined/combined' => 'grok-combined',
        'jina-search/search' => 'jina-search',
        'serpapi/search' => 'serpapi',
        'parallel/search' => 'parallel-search',
        'parallel/turbo' => 'parallel-turbo',
        'parallel/research' => 'parallel-research',
        'valyu/search' => 'valyu-search',
        'valyu/research' => 'valyu-research',
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
    $expectedProfiles = [
        ...array_values($landed),
        'dataforseo-chatgpt',
        'dataforseo-chatgpt-live',
        'dataforseo-gemini',
        'dataforseo-gemini-live',
        'dataforseo-google-ai-mode',
        'dataforseo-google-ai-mode-live',
    ];
    sort($expectedProfiles);

    expect($matches)->toHaveCount(39)
        ->and($documentedLanded)->toBe($landed)
        ->and($documentedAbsent)->toBe(['firecrawl-search/search'])
        ->and($configuredProfiles)->toBe($expectedProfiles);
});

it('keeps one separately gated live canary for every landed PHP Profile', function (): void {
    $canaries = file_get_contents(__DIR__.'/../../docs/live-provider-canaries.md');
    $receipt = file_get_contents(__DIR__.'/../../docs/provider-parity-live-2026-09-11.md');

    expect($canaries)->not->toBeFalse()
        ->and($receipt)->not->toBeFalse();
    assert(is_string($canaries));
    assert(is_string($receipt));

    preg_match_all(
        '/^\| C(?<id>\d{2}) \| `(?<profile>[^`]+)` \|/m',
        $canaries,
        $matches,
        PREG_SET_ORDER,
    );

    $ids = array_column($matches, 'id');
    $profiles = array_column($matches, 'profile');
    $configuredProfiles = array_keys(config('librarium.profiles'));
    $configuredProfiles[] = 'firecrawl-search';
    sort($profiles);
    sort($configuredProfiles);

    expect($matches)->toHaveCount(45)
        ->and($ids)->toBe(array_map(static fn (int $id): string => sprintf('%02d', $id), range(1, 45)))
        ->and($profiles)->toBe($configuredProfiles)
        ->and($canaries)->toContain('unless an approval names a')
        ->toContain('| Monthly Search | C30–C34, C40–C41, C43, C45 | 0.02, 0.10, 0.02, 0.10, 0.10, 0.10, 0.01, 0.15, 0.10 | 0.70 |')
        ->toContain('| Full provider parity | C30–C45 | 0.02, 0.10, 0.02, 0.10, 0.10, 0.60, 2.00, 0.50, 0.20, 0.25, 0.10, 0.01, 0.15, 0.15, 0.60, 0.10 | 5.00 |')
        ->toContain('Cadence is not standing spend')
        ->toContain('tier approvals always record one attempt and no retry')
        ->toContain('It never authorizes a retry')
        ->toContain('Unknown cost is not zero.')
        ->toContain('C01–C13 passed separately authorized live checks')
        ->toContain('C14–C29 remain unverified live.')
        ->toContain('C30–C37 and C39–C45 passed on 2026-09-11')
        ->toContain('new candidate SHA and separately approved')
        ->toContain('env -i HOME="$HOME" PATH="$PATH"')
        ->toContain('"${credential_name}=${credential_value}"')
        ->toContain("--filter='<exact dataset name from the table>' --fail-on-skipped")
        ->and($receipt)->toContain('dfce4d2b3f06147ff0e1f94bafd54eb44141d179')
        ->toContain('7caa14b59ef56724718c63e2f9b170b296ba8e9e')
        ->toContain('67dfb86f7086e0fbe961298f0d81d171d2b50b55')
        ->toContain('| `grok-x-only` | 0.20 | FAIL | 2 |')
        ->toContain('No failed submission was retried.');
});
