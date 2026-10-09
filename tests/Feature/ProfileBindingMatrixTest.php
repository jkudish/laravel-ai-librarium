<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\Tests\Support\ContractSnapshot;
use Jkudish\LaravelAiLibrarium\Tools\TypeScriptRepin;

require_once dirname(__DIR__, 2).'/tools/TypeScriptRepin.php';

/**
 * @return array<string, array{
 *     profile: string,
 *     result_kind: string,
 *     grounding: string,
 *     observation: string,
 *     corpora: list<string>,
 *     retrieval_methods: list<string>,
 *     invocation: string,
 *     resumability: string,
 *     workflows: list<string>,
 *     target: array<string, mixed>
 * }>
 */
function acceptedTypeScriptProfiles(): array
{
    $path = __DIR__.'/../Support/Profiles/v2/catalog.tsv';
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException('The accepted TypeScript Profile snapshot is unreadable.');
    }

    $pin = ContractSnapshot::pin();

    if (! hash_equals($pin->profilesSha256, hash('sha256', $contents))) {
        throw new RuntimeException('The accepted TypeScript Profile snapshot checksum has drifted from the pin.');
    }

    $lines = explode("\n", rtrim($contents, "\n"));
    $metadata = array_splice($lines, 0, 6);
    $header = array_shift($lines);

    if (array_slice($metadata, 0, 4) !== [
        '# schema-version: 1',
        '# repository: jkudish/librarium',
        "# commit: {$pin->commit}",
        "# package-version: {$pin->packageVersion}",
    ] || preg_match('/\A# catalog: src\/core\/provider-profiles\.ts sha256:[a-f0-9]{64}\z/D', $metadata[4] ?? '') !== 1
        || preg_match('/\A# bindings: src\/core\/profile-bindings\.ts sha256:[a-f0-9]{64}\z/D', $metadata[5] ?? '') !== 1
        || $header !== "profile\tresult_kind\tgrounding\tobservation\tcorpora\tretrieval_methods\tinvocation\tresumability\tworkflows\ttarget") {
        throw new RuntimeException('The accepted TypeScript Profile snapshot metadata is invalid.');
    }

    $profiles = [];

    foreach ($lines as $line) {
        $fields = explode("\t", $line, 10);

        if (count($fields) !== 10 || isset($profiles[$fields[0]])) {
            throw new RuntimeException('The accepted TypeScript Profile snapshot inventory is invalid.');
        }

        [$profile, $resultKind, $grounding, $observation, $corpora, $retrievalMethods, $invocation, $resumability, $workflows, $target] = $fields;
        $decodedTarget = json_decode($target, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decodedTarget)) {
            throw new RuntimeException("The accepted TypeScript Profile target [{$profile}] is invalid.");
        }

        $profiles[$profile] = [
            'profile' => $profile,
            'result_kind' => $resultKind,
            'grounding' => $grounding,
            'observation' => $observation,
            'corpora' => $corpora === '' ? [] : explode(',', $corpora),
            'retrieval_methods' => $retrievalMethods === '' ? [] : explode(',', $retrievalMethods),
            'invocation' => $invocation,
            'resumability' => $resumability,
            'workflows' => $workflows === '' ? [] : explode(',', $workflows),
            'target' => $decodedTarget,
        ];
    }

    $sortedProfiles = $profiles;
    ksort($sortedProfiles);

    if ($profiles !== $sortedProfiles || count($profiles) !== $pin->profileCount) {
        throw new RuntimeException("The accepted TypeScript Profile snapshot must contain {$pin->profileCount} sorted unique Profiles.");
    }

    return $profiles;
}

it('locks the accepted TypeScript Profile inventory and its PHP classifications', function (): void {
    $matrix = file_get_contents(__DIR__.'/../../docs/profile-bindings.md');

    expect($matrix)->not->toBeFalse();
    assert(is_string($matrix));

    $pin = ContractSnapshot::pin();

    expect($matrix)
        ->toContain(TypeScriptRepin::docsBlock($pin))
        ->toContain('`laravel/ai` v0.10.3 at `c3848aae389f45c605eefb0dda5bd5fa7df76eaa`')
        ->toContain("| **Total** | **{$pin->profileCount}** |");

    preg_match_all(
        '/^### `(?<key>[^`]+\/[^`]+)`\R(?<body>.*?)(?=^### |^## |\z)/ms',
        $matrix,
        $matches,
        PREG_SET_ORDER,
    );

    $expectedKeys = array_keys(acceptedTypeScriptProfiles());

    $actualKeys = array_column($matches, 'key');
    $uniqueKeys = array_values(array_unique($actualKeys));
    sort($actualKeys);
    sort($uniqueKeys);
    sort($expectedKeys);

    expect($actualKeys)
        ->toHaveCount($pin->profileCount)
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
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #2591 core HTTP')))->toHaveCount(13)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** retired')))->toHaveCount(1)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #2592')))->toHaveCount(1)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #2694')))->toHaveCount(3)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #3604 core parity')))->toHaveCount(15)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** #3650 core parity')))->toHaveCount(2)
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '**PHP path:** explicit holdback')))->toBeEmpty()
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, '#2830')))->toHaveCount(5);
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
        '/^\| `(?<typescript>[^`]+\/[^`]+)` \| (?<disposition>Landed|Separate task|Holdback|Retired) \| (?<php>.*?) \|/m',
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
        'serpbase/news' => 'serpbase-news',
        'serpbase/search' => 'serpbase-search',
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
        'perplexity-low-frozen',
        'dataforseo-chatgpt',
        'dataforseo-chatgpt-live',
        'dataforseo-gemini',
        'dataforseo-gemini-live',
        'dataforseo-google-ai-mode',
        'dataforseo-google-ai-mode-live',
    ];
    sort($expectedProfiles);

    $upstream = acceptedTypeScriptProfiles();
    $semanticKeys = [
        'result_kind',
        'grounding',
        'observation',
        'corpora',
        'retrieval_methods',
    ];

    foreach ($landed as $typescriptProfile => $phpProfile) {
        $configuration = config("librarium.profiles.{$phpProfile}");

        expect($configuration)->toBeArray();
        assert(is_array($configuration));

        $this->assertSame(
            [
                'result_kind' => $upstream[$typescriptProfile]['result_kind'],
                'grounding' => $upstream[$typescriptProfile]['grounding'],
                'observation' => $upstream[$typescriptProfile]['observation'],
                'corpora' => $upstream[$typescriptProfile]['corpora'],
                'retrieval_methods' => $upstream[$typescriptProfile]['retrieval_methods'],
            ],
            array_replace(
                array_fill_keys($semanticKeys, null),
                array_intersect_key($configuration, array_flip($semanticKeys)),
            ),
            "PHP Profile {$phpProfile} has drifted from accepted TypeScript semantics {$typescriptProfile}.",
        );
    }

    config()->set('librarium.profiles.gemini-grounded.enabled', false);

    foreach (['quick', 'visibility', 'deep'] as $workflow) {
        $expectedWorkflowProfiles = [];

        foreach ($landed as $typescriptProfile => $phpProfile) {
            if (in_array($workflow, $upstream[$typescriptProfile]['workflows'], true)) {
                $expectedWorkflowProfiles[] = $phpProfile;
            }
        }

        $resolvedWorkflow = app(ProfileCatalog::class)->group($workflow);
        $actualWorkflowProfiles = [
            ...$resolvedWorkflow['profiles']->pluck('id')->all(),
            ...array_keys($resolvedWorkflow['unavailable']),
        ];
        sort($expectedWorkflowProfiles);
        sort($actualWorkflowProfiles);

        $this->assertSame(
            $expectedWorkflowProfiles,
            $actualWorkflowProfiles,
            "PHP workflow {$workflow} has drifted from the accepted TypeScript membership.",
        );
    }

    expect($matches)->toHaveCount(ContractSnapshot::pin()->profileCount)
        ->and($documentedLanded)->toBe($landed)
        ->and($documentedAbsent)->toBe(['searchapi-perplexity/surface', 'firecrawl-search/search'])
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
    $configuredProfiles[] = 'searchapi-perplexity';
    sort($profiles);
    sort($configuredProfiles);

    expect($matches)->toHaveCount(48)
        ->and($ids)->toBe(array_map(static fn (int $id): string => sprintf('%02d', $id), range(1, 48)))
        ->and($profiles)->toBe($configuredProfiles)
        ->and($canaries)->toContain('unless an approval names a')
        ->toContain('| Monthly Search | C30–C34, C40–C41, C43, C45 | 0.02, 0.10, 0.02, 0.10, 0.10, 0.10, 0.01, 0.15, 0.10 | 0.70 |')
        ->toContain('| Full provider parity | C30–C45 | 0.02, 0.10, 0.02, 0.10, 0.10, 0.60, 2.00, 0.50, 0.20, 0.25, 0.10, 0.01, 0.15, 0.15, 0.60, 0.10 | 5.00 |')
        ->toContain('Cadence is not standing spend')
        ->toContain('tier approvals always record one attempt and no retry')
        ->toContain('It never authorizes a retry')
        ->toContain('Unknown cost is not zero.')
        ->toContain('C01–C13 passed separately authorized live checks')
        ->toContain('C14–C20 passed on 2026-09-12')
        ->toContain('C21–C23 passed on 2026-09-15 under #2694.')
        ->toContain('C46 passed on 2026-09-15 under #2595')
        ->toContain('This completes the release-required live')
        ->toContain('C24–C29 passed on 2026-09-12')
        ->toContain('C30–C37 and C39–C45 passed on 2026-09-11')
        ->toContain('all C30–C45 Profiles have now passed their live canary')
        ->toContain('env -i HOME="$HOME" PATH="$PATH"')
        ->toContain('"${credential_name}=${credential_value}"')
        ->toContain("--filter='<exact dataset name from the table>' --fail-on-skipped")
        ->and($receipt)->toContain('dfce4d2b3f06147ff0e1f94bafd54eb44141d179')
        ->toContain('7caa14b59ef56724718c63e2f9b170b296ba8e9e')
        ->toContain('67dfb86f7086e0fbe961298f0d81d171d2b50b55')
        ->toContain('05943e28e47b77deca6dec0ad5404653bf97eaf9')
        ->toContain('| `grok-x-only` | 0.20 | FAIL | 2 |')
        ->toContain('| `grok-x-only` | `05943e28e47b77deca6dec0ad5404653bf97eaf9` | 0.20 | PASS | 7 |')
        ->toContain('Separately authorized corrected C38 reservation: USD 0.20.')
        ->toContain('Total reservation across both approvals: USD 5.20.')
        ->toContain('No failed submission was retried.');
});
