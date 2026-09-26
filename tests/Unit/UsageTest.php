<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Responses\Usage;

it('keeps the historical nine positional constructor slots bound to their original meanings', function (): void {
    // Consumers constructed Usage positionally before tool_use_tokens
    // existed; the string cost and currency arguments must still land on
    // the cost and currency slots rather than an integer token count.
    $usage = new Usage(
        11,
        22,
        3,
        4,
        5,
        6,
        '0.51',
        '1.02',
        'EUR',
        toolUseTokens: 25,
    );

    expect($usage->promptTokens)->toBe(11)
        ->and($usage->completionTokens)->toBe(22)
        ->and($usage->cacheWriteInputTokens)->toBe(3)
        ->and($usage->cacheReadInputTokens)->toBe(4)
        ->and($usage->reasoningTokens)->toBe(5)
        ->and($usage->serverToolSearchRequests)->toBe(6)
        ->and($usage->actualCost)->toBe('0.51')
        ->and($usage->estimatedCost)->toBe('1.02')
        ->and($usage->currency)->toBe('EUR')
        ->and($usage->toolUseTokens)->toBe(25)
        ->and($usage->toArray())->toBe([
            'prompt_tokens' => 11,
            'completion_tokens' => 22,
            'cache_write_input_tokens' => 3,
            'cache_read_input_tokens' => 4,
            'reasoning_tokens' => 5,
            'server_tool_search_requests' => 6,
            'tool_use_tokens' => 25,
            'actual_cost' => '0.51',
            'estimated_cost' => '1.02',
            'currency' => 'EUR',
        ]);
});
