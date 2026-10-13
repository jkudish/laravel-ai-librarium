<?php

declare(strict_types=1);

return [
    // Keys normally live in services.php or the Laravel AI SDK's ai.php.
    // A per-source key here overrides that binding. A false enabled flag opts out.
    'sources' => [
        'brave' => [], 'claude' => [], 'exa' => [], 'firecrawl' => [],
        'gemini' => [], 'google' => ['collector' => 'searchapi'], 'grok' => [],
        'jina' => [], 'kagi' => [], 'openai' => [], 'openrouter' => [],
        'parallel' => ['processor' => 'pro'], 'perplexity' => [], 'tavily' => [],
        'valyu' => ['mode' => 'standard'], 'you' => [],
    ],
    'engines' => ['collector' => null, 'key' => null],
    'groups' => [],
    'max_cost' => null,
    'runs' => [
        'store' => 'filesystem',
        'disk' => null,
        'path' => 'librarium',
        'connection' => null,
        'table' => 'librarium_runs',
        'encrypt' => true,
        'keep_days' => null,
        'cache_store' => null,
        'ttl' => 604800,
    ],
    'timeout' => 6900,
    'queue' => ['connection' => null, 'name' => 'research', 'timeout' => 7200],
    'webhook_signal_ttl' => 10800,
    'webhooks' => ['openai' => ['secret' => config('services.openai.webhook_secret')]],
    'redaction' => ['paths' => [], 'patterns' => [], 'replacement' => '[REDACTED]'],
];
