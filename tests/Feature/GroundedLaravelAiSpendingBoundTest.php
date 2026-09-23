<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\Enums\SubmissionState;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchAgent;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchResultMapper;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage as AiUsage;
use Laravel\Ai\Responses\TextResponse;

beforeEach(function (): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
});

it('bounds exact grounded OpenRouter quantities from the documented server-tool budget', function (int $budget, string $inputTokens, string $outputTokens, string $searchRequests): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => $budget,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    $bound = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($bound->pricingIdentity?->key())->toBe('openrouter:openai/gpt-5.6-terra-272k')
        ->and($bound->quantities?->toArray())->toBe([
            'cache_write_input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'exa_search_requests' => $searchRequests,
        ]);
    Http::assertNothingSent();
})->with([
    'minimum budget' => [1, '2100000', '256000', '1'],
    'moderate budget' => [4, '5250000', '640000', '4'],
    'canary budget' => [5, '6300000', '768000', '5'],
    'maximum documented budget' => [30, '32550000', '3968000', '30'],
]);

it('sends the grounded OpenRouter budget, endpoint pin, max_price, Exa mode, and revision controls through the real SDK', function (string $reportedModel): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(catalogFake() + [
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-grounded',
            'model' => $reportedModel,
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'A grounded OpenRouter answer.',
                    'annotations' => [[
                        'type' => 'url_citation',
                        'url_citation' => ['url' => 'https://example.com/source', 'title' => 'Example source'],
                    ]],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 900,
                'completion_tokens' => 120,
                'cost' => 0.002,
                'server_tool_use_details' => ['web_search_requests' => 4],
            ],
        ]),
    ]);

    $response = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->run();

    expect($response->status->value)->toBe('succeeded')
        ->and($response->results->sole()->citations)->toHaveCount(1)
        ->and($response->results->sole()->model)->toBe($reportedModel)
        ->and($response->results->sole()->usage?->serverToolSearchRequests)->toBe(4);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $body['model'] === 'openai/gpt-5.6-terra'
            && $body['max_tool_calls'] === 4
            && ! array_key_exists('max_tokens', $body)
            && $body['tool_choice'] === 'auto'
            && $body['tools'] === [[
                'type' => 'openrouter:web_search',
                'parameters' => [
                    'engine' => 'exa',
                    'mode' => 'auto',
                    'max_results' => 5,
                ],
            ]]
            && $body['provider'] === [
                'order' => ['openai'],
                'ignore' => ['openai/flex'],
                'max_price' => ['prompt' => '2', 'completion' => '12'],
                'require_parameters' => true,
                'allow_fallbacks' => false,
            ]
            && ! array_key_exists('reasoning', $body)
            && str_contains((string) json_encode($body), 'Bound this grounded OpenRouter answer.');
    });
    Http::assertSentCount(2);
})->with([
    'requested alias reported by OpenRouter' => ['openai/gpt-5.6-terra'],
    'reviewed dated revision reported by OpenRouter' => ['openai/gpt-5.6-terra-20260709'],
]);

it('maps asymmetric actual cache partitions and the engine search count through the real SDK', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(catalogFake() + [
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-grounded-partitions',
            'model' => 'openai/gpt-5.6-terra-20260709',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'A grounded OpenRouter answer.',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 900,
                'completion_tokens' => 120,
                'prompt_tokens_details' => [
                    'cached_tokens' => 100,
                    'cache_write_tokens' => 50,
                ],
                'server_tool_use' => ['web_search_requests' => 4],
            ],
        ]),
    ]);

    $response = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->run();

    // The SDK gateway normalizes the inclusive provider total into exclusive
    // partitions — uncached input, cache-read, and cache-write — so every
    // token is represented exactly once for settlement.
    expect($response->results->sole()->usage?->toArray())->toBe([
        'prompt_tokens' => 750,
        'completion_tokens' => 120,
        'cache_write_input_tokens' => 50,
        'cache_read_input_tokens' => 100,
        'server_tool_search_requests' => 4,
    ]);
    Http::assertSentCount(2);
});

it('keeps the engine search count null when the provider does not report it', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(catalogFake() + [
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-grounded-nocount',
            'model' => 'openai/gpt-5.6-terra-20260709',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'A grounded OpenRouter answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 900,
                'completion_tokens' => 120,
            ],
        ]),
    ]);

    $response = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->run();

    expect($response->results->sole()->usage?->toArray())->toBe([
        'prompt_tokens' => 900,
        'completion_tokens' => 120,
    ])
        ->not->toHaveKey('server_tool_search_requests');
    Http::assertSentCount(2);
});

it('sums the engine search count only when every step reports a complete count', function (array $rawSteps, ?int $expected): void {
    $steps = [];
    foreach ($rawSteps as $rawStep) {
        $step = new Step(
            text: '',
            toolCalls: [],
            toolResults: [],
            finishReason: FinishReason::Stop,
            usage: new AiUsage(10, 4),
            meta: new Meta('openrouter', 'openai/gpt-5.6-terra'),
        );
        if (is_string($rawStep)) {
            $step->withRawResponse(new HttpResponse(new PsrResponse(200, ['Content-Type' => 'application/json'], $rawStep)));
        }
        $steps[] = $step;
    }

    $response = (new AgentResponse(
        'invocation-metered',
        'A grounded OpenRouter answer.',
        new AiUsage(10, 4),
        new Meta('openrouter', 'openai/gpt-5.6-terra'),
    ))->withSteps(collect($steps));

    $usage = (new ResearchResultMapper)->map($response, groundedBoundRequest(model: 'openai/gpt-5.6-terra', provider: 'openrouter'))->usage;

    expect($usage?->serverToolSearchRequests)->toBe($expected);
})->with([
    'current fields on every step' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use_details":{"web_search_requests":2}}}',
        '{"usage":{"prompt_tokens":10,"server_tool_use_details":{"web_search_requests":3}}}',
    ], 5],
    'legacy fields on every step' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":2}}}',
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":3}}}',
    ], 5],
    'current field wins without double counting when both are present' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use_details":{"web_search_requests":2},"server_tool_use":{"web_search_requests":20}}}',
        '{"usage":{"prompt_tokens":10,"server_tool_use_details":{"web_search_requests":3},"server_tool_use":{"web_search_requests":30}}}',
    ], 5],
    'malformed current field is not masked by legacy' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use_details":{"web_search_requests":"2"},"server_tool_use":{"web_search_requests":20}}}',
    ], null],
    'every step reports zero' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use_details":{"web_search_requests":0}}}',
        '{"usage":{"prompt_tokens":10,"server_tool_use_details":{"web_search_requests":0}}}',
    ], 0],
    'no step reports the count' => [[
        '{"usage":{"prompt_tokens":10}}',
        '{"usage":{"prompt_tokens":10}}',
    ], null],
    'one step missing the count' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":2}}}',
        '{"usage":{"prompt_tokens":10}}',
    ], null],
    'one step missing its raw response' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":2}}}',
        null,
    ], null],
    'a step reports a malformed count' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":2}}}',
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":"3"}}}',
    ], null],
    'a step reports a negative count' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":-1}}}',
    ], null],
    'the summed count overflows the integer range' => [[
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":'.PHP_INT_MAX.'}}}',
        '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":1}}}',
    ], null],
]);

it('keeps a single-step engine search count strict through the raw aggregate response', function (): void {
    $response = (new AgentResponse(
        'invocation-partial',
        'A grounded OpenRouter answer.',
        new AiUsage(10, 4),
        new Meta('openrouter', 'openai/gpt-5.6-terra'),
    ))->withRawResponse(new HttpResponse(new PsrResponse(200, ['Content-Type' => 'application/json'], '{"usage":{"prompt_tokens":10,"server_tool_use":{"web_search_requests":"4"}}}')));

    $usage = (new ResearchResultMapper)->map($response, groundedBoundRequest(model: 'openai/gpt-5.6-terra', provider: 'openrouter'))->usage;

    expect($usage?->serverToolSearchRequests)->toBeNull();
});

it('keeps the pinned OpenRouter endpoint routing off unconfigured requests', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'max_steps' => 1,
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-unpinned',
            'model' => 'openai/gpt-5.6-terra',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]),
    ]);

    Librarium::query('Unpinned defaults.')
        ->using('openrouter-chat')
        ->run();

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['provider'] === ['require_parameters' => true, 'allow_fallbacks' => false]
            && ! array_key_exists('max_tool_calls', $body);
    });
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra');
    Http::assertSentCount(1);
});

it('never reroutes a budget on a non-bounded OpenRouter model to the pinned endpoint', function (): void {
    config()->set('librarium.profiles.openrouter-grounded.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'max_tool_calls' => 4,
        'max_steps' => 1,
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-luna',
            'model' => 'openai/gpt-5.6-luna',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'A grounded Luna answer.',
                    'annotations' => [[
                        'type' => 'url_citation',
                        'url_citation' => ['url' => 'https://example.com/source', 'title' => 'Example source'],
                    ]],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]),
    ]);

    $response = Librarium::query('Budget a non-bounded model.')
        ->using('openrouter-grounded')
        ->run();

    $bound = Librarium::query('Budget a non-bounded model.')
        ->using('openrouter-grounded')
        ->spendingBound();

    expect($response->status->value)->toBe('succeeded')
        ->and($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['model'] === 'openai/gpt-5.6-luna'
            && $body['max_tool_calls'] === 4
            && $body['provider'] === ['require_parameters' => true, 'allow_fallbacks' => false];
    });
    Http::assertSentCount(1);
});

it('names the engine-specific Exa search unit instead of the aliased model web search rate', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    $bound = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->quantities?->toArray())->toHaveKeys(['cache_write_input_tokens', 'output_tokens', 'exa_search_requests'])
        ->and($bound->quantities?->toArray())->not->toHaveKey('web_searches')
        ->and($bound->pricingIdentity?->key())->not->toBe('openrouter:openai/gpt-5.6-terra');
    Http::assertNothingSent();
});

it('fails closed when the ambiguous max_uses budget interaction is configured', function (int $maxSearches): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'max_tool_calls' => 30,
        'max_searches' => $maxSearches,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    $bound = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertNothingSent();
})->with([
    'a search cap below the budget' => [5],
    'a search cap above the budget' => [35],
]);

it('treats the documented server-tool budget maximum as the bounded boundary', function (int $budget, string $kind): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => $budget,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    if ($kind === 'rejected') {
        try {
            groundedBoundDriver()->run(groundedBoundRequest(
                provider: 'openrouter',
                model: 'openai/gpt-5.6-terra',
                options: config('librarium.profiles.openrouter-chat.options'),
            ));
            $this->fail('A budget beyond the documented maximum should be rejected before execution.');
        } catch (DriverException $exception) {
            expect($exception->errorCode)->toBe('laravel_ai.invalid_options')
                ->and($exception->fallbackAllowed)->toBeFalse();
        }
        Http::assertNothingSent();

        return;
    }

    $bound = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->kind->value)->toBe($kind);
    Http::assertNothingSent();
})->with([
    'exactly the documented maximum' => [30, 'billable_quantities'],
    'one beyond the documented maximum' => [31, 'unknown'],
]);

it('treats the Exa per-request result allowance as the additional-result fee boundary', function (int $maxResults, string $kind): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => $maxResults,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    if ($kind === 'rejected') {
        expect(fn () => Librarium::query('Bound this grounded OpenRouter answer.')
            ->using('openrouter-chat')
            ->spendingBound())
            ->toThrow(ValidationException::class);
        Http::assertNothingSent();

        return;
    }

    $bound = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->kind->value)->toBe($kind);
    Http::assertNothingSent();
})->with([
    'inside the included allowance' => [10, 'billable_quantities'],
    'one result beyond the allowance' => [11, 'unknown'],
    'at the parameter limit' => [25, 'unknown'],
    'beyond the parameter limit' => [26, 'rejected'],
]);

it('keeps grounded OpenRouter configurations unknown until the budget contract is complete', function (Closure $configure): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);
    $configure();

    $bound = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertNothingSent();
})->with([
    'missing budget' => fn () => removeProfileOption('openrouter-chat', 'max_tool_calls'),
    'missing result cap' => fn () => removeProfileOption('openrouter-chat', 'max_results'),
    'missing SDK step cap' => fn () => removeProfileOption('openrouter-chat', 'max_steps'),
    'multiple SDK steps' => fn () => config()->set('librarium.profiles.openrouter-chat.options.max_steps', 2),
    'missing prompt contract' => fn () => removeProfileOption('openrouter-chat', 'max_prompt_bytes'),
    'prompt exceeds contract' => fn () => config()->set('librarium.profiles.openrouter-chat.options.max_prompt_bytes', 10),
    'implicit web search flag' => fn () => removeProfileOption('openrouter-chat', 'web_search'),
    'wrong engine' => fn () => config()->set('librarium.profiles.openrouter-chat.options.search_engine', 'parallel'),
    'missing explicit Exa search mode' => fn () => removeProfileOption('openrouter-chat', 'search_mode'),
    'fast Exa search mode' => fn () => config()->set('librarium.profiles.openrouter-chat.options.search_mode', 'fast'),
    'deep Exa search mode' => fn () => config()->set('librarium.profiles.openrouter-chat.options.search_mode', 'deep'),
    'wrong model' => fn () => config()->set('librarium.profiles.openrouter-chat.model', 'openai/gpt-5.6-luna'),
]);

it('keeps the OpenRouter bound independent of the optional output-token run control', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_output_tokens' => 16_000,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    $bound = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($bound->quantities?->toArray())->toBe([
            'cache_write_input_tokens' => '5250000',
            'output_tokens' => '640000',
            'exa_search_requests' => '4',
        ]);
    Http::assertNothingSent();
});

it('keeps grounded Claude unknown because max_uses errors continue unbilled internal iterations', function (): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => true,
        'max_searches' => 5,
        'max_output_tokens' => 64_000,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    $bound = Librarium::query('Bound this grounded Claude answer.')
        ->using('claude-chat')
        ->spendingBound();

    expect($bound->toArray())->toBe(['kind' => 'unknown']);
    Http::assertNothingSent();
});

it('still enforces grounded Claude run controls when the provider continues past max_uses', function (): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => true,
        'max_searches' => 5,
        'max_output_tokens' => 64_000,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded Claude answer.'),
    ]);
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_max_uses',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [
                ['type' => 'server_tool_use', 'id' => 'srvu_1', 'name' => 'web_search', 'input' => ['query' => 'first']],
                [
                    'type' => 'web_search_tool_result',
                    'tool_use_id' => 'srvu_1',
                    'content' => [['type' => 'web_search_result_error', 'error_code' => 'max_uses_exceeded']],
                ],
                ['type' => 'text', 'text' => 'Claude answered after the cap was exceeded.'],
            ],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 7,
                'server_tool_use' => ['web_search_requests' => 1],
            ],
        ]),
    ]);

    $response = Librarium::query('Bound this grounded Claude answer.')
        ->using('claude-chat')
        ->run();

    expect($response->status->value)->toBe('succeeded')
        ->and($response->results->sole()->content)->toBe('Claude answered after the cap was exceeded.');
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['model'] === 'claude-sonnet-5'
            && $body['max_tokens'] === 64_000
            && $body['tools'] === [[
                'type' => 'web_search_20250305',
                'name' => 'web_search',
                'max_uses' => 5,
            ]];
    });
    Http::assertSentCount(1);
});

it('keeps grounded OpenAI unknown because ignored tool attempts do not prove inference stops', function (): void {
    config()->set('librarium.profiles.openai-chat.options', [
        'web_search' => true,
        'max_tool_calls' => 3,
        'max_output_tokens' => 32_768,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    $bound = Librarium::query('Bound this grounded OpenAI answer.')
        ->using('openai-chat')
        ->spendingBound();

    expect($bound->toArray())->toBe(['kind' => 'unknown']);
    Http::assertNothingSent();
});

it('still enforces grounded OpenAI run controls while the bound stays unknown', function (): void {
    config()->set('librarium.profiles.openai-chat.options', [
        'web_search' => true,
        'max_tool_calls' => 3,
        'max_output_tokens' => 32_768,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenAI answer.'),
    ]);
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_grounded',
            'status' => 'completed',
            'model' => 'gpt-5.6-luna',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'A grounded OpenAI answer.',
                    'annotations' => [[
                        'type' => 'url_citation',
                        'url' => 'https://example.com/source',
                        'title' => 'Example source',
                    ]],
                ]],
            ]],
            'usage' => ['input_tokens' => 500, 'output_tokens' => 60],
        ]),
    ]);

    $response = Librarium::query('Bound this grounded OpenAI answer.')
        ->using('openai-chat')
        ->run();

    expect($response->status->value)->toBe('succeeded')
        ->and($response->results->sole()->citations)->toHaveCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $body['model'] === 'gpt-5.6-luna'
            && $body['max_output_tokens'] === 32_768
            && $body['max_tool_calls'] === 3
            && $body['tool_choice'] === 'auto'
            && $body['tools'] === [['type' => 'web_search']];
    });
    Http::assertSentCount(1);
});

it('rejects OpenAI output-token controls below the documented Responses floor before execution', function (): void {
    try {
        groundedBoundDriver()->run(groundedBoundRequest(
            provider: 'openai',
            model: 'gpt-5.6-luna',
            options: ['web_search' => true, 'max_tool_calls' => 3, 'max_output_tokens' => 15],
        ));
        $this->fail('An output-token control below the Responses floor should be rejected before execution.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.invalid_options')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }
    Http::assertNothingSent();
});

it('keeps Gemini grounding unknown because Google bills every generated search query without a request cap', function (Closure $configure): void {
    config()->set('librarium.profiles.gemini-grounded.options', [
        'max_output_tokens' => 4_096,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);
    $configure();

    $bound = Librarium::query('Bound this grounded Gemini answer.')
        ->using('gemini-grounded')
        ->spendingBound();

    expect($bound->toArray())->toBe(['kind' => 'unknown']);
    Http::assertNothingSent();
})->with([
    'token controls alone' => fn () => null,
    'explicit web search flag' => fn () => config()->set('librarium.profiles.gemini-grounded.options.web_search', true),
    'an attempted search cap' => fn () => config()->set('librarium.profiles.gemini-grounded.options.max_searches', 5),
    'an attempted tool-call budget' => fn () => config()->set('librarium.profiles.gemini-grounded.options.max_tool_calls', 3),
]);

it('still enforces Gemini token controls on the real outbound request while the bound stays unknown', function (): void {
    config()->set('librarium.profiles.gemini-grounded.options', [
        'max_output_tokens' => 4_096,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded Gemini answer.'),
    ]);
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['text' => 'A grounded Gemini answer.']],
                ],
                'finishReason' => 'STOP',
                'groundingMetadata' => [
                    'groundingChunks' => [['web' => ['uri' => 'https://example.com/source', 'title' => 'Example source']]],
                    'groundingSupports' => [['groundingChunkIndices' => [0]]],
                ],
            ]],
            'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 10],
        ]),
    ]);

    $response = Librarium::query('Bound this grounded Gemini answer.')
        ->using('gemini-grounded')
        ->run();

    expect($response->status->value)->toBe('succeeded')
        ->and($response->results->sole()->citations)->toHaveCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent'
            && data_get($body, 'generationConfig.maxOutputTokens') === 4_096
            && (array) data_get($body, 'tools.0.google_search') === [];
    });
    Http::assertSentCount(1);
});

it('rejects grounded controls the exact provider request cannot honor before execution', function (string $provider, ?string $model, array $options): void {
    try {
        groundedBoundDriver()->run(groundedBoundRequest(provider: $provider, model: $model, options: $options));
        $this->fail('Unsupported grounded controls should be rejected before execution.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.invalid_options')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }
    Http::assertNothingSent();
})->with([
    'Anthropic-style search cap on OpenAI' => ['openai', 'gpt-5.6-luna', ['web_search' => true, 'max_searches' => 5]],
    'Anthropic-style search cap on Gemini' => ['gemini', 'gemini-3.6-flash', ['web_search' => true, 'max_searches' => 5]],
    'OpenAI tool-call cap on Anthropic' => ['anthropic', 'claude-sonnet-5', ['web_search' => true, 'max_tool_calls' => 3]],
    'OpenAI tool-call cap on Gemini' => ['gemini', 'gemini-3.6-flash', ['web_search' => true, 'max_tool_calls' => 3]],
    'OpenRouter result cap on Anthropic' => ['anthropic', 'claude-sonnet-5', ['web_search' => true, 'max_results' => 5]],
    'OpenRouter result cap on OpenAI' => ['openai', 'gpt-5.6-luna', ['web_search' => true, 'max_results' => 5]],
    'search cap without web search' => ['anthropic', 'claude-sonnet-5', ['web_search' => false, 'max_searches' => 5]],
    'tool-call budget without web search' => ['openrouter', 'openai/gpt-5.6-terra', ['web_search' => false, 'search_engine' => 'exa', 'max_tool_calls' => 3]],
    'search mode without web search' => ['openrouter', 'openai/gpt-5.6-terra', ['web_search' => false, 'search_engine' => 'exa', 'search_mode' => 'auto']],
    'Exa search mode on Gemini' => ['gemini', 'gemini-3.6-flash', ['web_search' => true, 'search_mode' => 'auto']],
    'Exa search mode on Anthropic' => ['anthropic', 'claude-sonnet-5', ['web_search' => true, 'search_mode' => 'auto']],
    'Exa search mode with the Perplexity engine' => ['openrouter', 'openai/gpt-5.6-terra', ['web_search' => true, 'search_engine' => 'perplexity', 'search_mode' => 'auto']],
    'OpenRouter budget beyond the documented maximum' => ['openrouter', 'openai/gpt-5.6-terra', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 31,
        'max_results' => 5,
    ]],
]);

it('rejects an undocumented Exa search mode value before execution', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'turbo',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    expect(fn () => Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->spendingBound())
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('fails closed when the pinned route reports neither the requested alias nor the reviewed revision', function (?string $servedModel): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    $completion = [
        'id' => 'gen-grounded-drift',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'A drifted revision answer.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120],
    ];

    if ($servedModel !== null) {
        $completion['model'] = $servedModel;
    }

    Http::fake(catalogFake() + [
        'https://openrouter.ai/api/v1/chat/completions' => Http::response($completion),
    ]);

    try {
        groundedBoundDriver()->run(groundedBoundRequest(
            provider: 'openrouter',
            model: 'openai/gpt-5.6-terra',
            prompt: 'Bound this grounded OpenRouter answer.',
            options: config('librarium.profiles.openrouter-chat.options'),
        ));
        $this->fail('A served model other than the requested alias or reviewed revision should fail closed.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_drift')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    // The pre-dispatch catalog check passed, so the inference request was
    // dispatched with the moving alias. The response-side compatibility
    // check then rejected an explicitly unexpected identity. An echoed alias
    // is accepted and cannot prove which revision served the request.
    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->data()['model'] === 'openai/gpt-5.6-terra'
            && $request->data()['provider']['max_price'] === ['prompt' => '2', 'completion' => '12'];
    });
    Http::assertSentCount(2);
})->with([
    'a newer dated revision' => ['openai/gpt-5.6-terra-20261001'],
    'a sibling model' => ['openai/gpt-5.6-terra-pro-20260709'],
    'an unknown model' => ['openai/unknown'],
    'a missing model' => [null],
]);

it('checks the pinned revision against the public catalog before dispatching the inference request', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(catalogFake() + [
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-grounded-preflight',
            'model' => 'openai/gpt-5.6-terra-20260709',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'A grounded OpenRouter answer.',
                    'annotations' => [[
                        'type' => 'url_citation',
                        'url_citation' => ['url' => 'https://example.com/source', 'title' => 'Example source'],
                    ]],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 900,
                'completion_tokens' => 120,
                'server_tool_use' => ['web_search_requests' => 4],
            ],
        ]),
    ]);

    $response = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->run();

    expect($response->status->value)->toBe('succeeded');
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra'
            && ! $request->hasHeader('Authorization');
    });
    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->data()['model'] === 'openai/gpt-5.6-terra';
    });
    Http::assertSentCount(2);

    // The catalog check must precede the billable inference POST, so ordering
    // is part of the contract: rejection happens before any spend can occur.
    $recorded = Http::recorded();
    expect($recorded[0][0]->url())->toBe('https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra')
        ->and($recorded[0][0]->method())->toBe('GET')
        ->and($recorded[1][0]->url())->toBe('https://openrouter.ai/api/v1/chat/completions');
});

it('fails closed before dispatch when the catalog resolves a different revision', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra' => Http::response([
            'data' => ['canonical_slug' => 'openai/gpt-5.6-terra-20261001'],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-never-dispatched',
            'model' => 'openai/gpt-5.6-terra-20261001',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'A drifted revision answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120],
        ]),
    ]);

    try {
        groundedBoundDriver()->run(groundedBoundRequest(
            provider: 'openrouter',
            model: 'openai/gpt-5.6-terra',
            prompt: 'Bound this grounded OpenRouter answer.',
            options: config('librarium.profiles.openrouter-chat.options'),
        ));
        $this->fail('A catalog revision other than the reviewed exact revision should fail closed before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_drift_before_dispatch')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    Http::assertSentCount(1);
});

it('fails closed before dispatch when the catalog cannot prove the revision', function (array|string $body, int $status): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra' => Http::response($body, $status),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-never-dispatched',
            'model' => 'openai/gpt-5.6-terra-20260709',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'A grounded OpenRouter answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120],
        ]),
    ]);

    try {
        groundedBoundDriver()->run(groundedBoundRequest(
            provider: 'openrouter',
            model: 'openai/gpt-5.6-terra',
            prompt: 'Bound this grounded OpenRouter answer.',
            options: config('librarium.profiles.openrouter-chat.options'),
        ));
        $this->fail('An unverifiable catalog answer should fail closed before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_unverified')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    Http::assertSentCount(1);
})->with([
    'server error' => [[], 500],
    'not found' => [[], 404],
    'non-json body' => ['upstream maintenance', 200],
    'missing data object' => [['error' => ['message' => 'boom']], 200],
    'missing canonical slug' => [['data' => ['id' => 'openai/gpt-5.6-terra']], 200],
    'non-string canonical slug' => [['data' => ['canonical_slug' => ['openai/gpt-5.6-terra-20261001']]], 200],
]);

it('fails closed before dispatch when the catalog is unreachable', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(function (): never {
        throw new ConnectionException('The model catalog could not be reached.');
    });

    try {
        groundedBoundDriver()->run(groundedBoundRequest(
            provider: 'openrouter',
            model: 'openai/gpt-5.6-terra',
            prompt: 'Bound this grounded OpenRouter answer.',
            options: config('librarium.profiles.openrouter-chat.options'),
        ));
        $this->fail('An unreachable catalog should fail closed before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_unverified')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
});

it('never persists catalog traffic as provider inference evidence', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra' => Http::response([
            'data' => ['canonical_slug' => 'openai/gpt-5.6-terra-20261001'],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-never-dispatched',
            'model' => 'openai/gpt-5.6-terra-20261001',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'A drifted revision answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120],
        ]),
    ]);

    $captured = [];
    $request = groundedBoundRequest(
        provider: 'openrouter',
        model: 'openai/gpt-5.6-terra',
        prompt: 'Bound this grounded OpenRouter answer.',
        options: config('librarium.profiles.openrouter-chat.options'),
        responseCallback: function (HttpResponse $response, Operation $operation) use (&$captured): void {
            $captured[] = [$response, $operation];
        },
    );

    try {
        groundedBoundDriver()->run($request);
        $this->fail('The drifted catalog answer should fail closed before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_drift_before_dispatch')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    // Catalog traffic is never recorded as provider inference evidence: the
    // preflight rejection records zero attempts, so collection diagnostics
    // state NotSent by construction — an explicit no-inference fact, not an
    // evidence-count inference — and the catalog exchange never persists as
    // a paid result.
    expect($captured)->toBeEmpty();
    Http::assertNotSent(fn (Request $sent): bool => $sent->url() === 'https://openrouter.ai/api/v1/chat/completions');
});

it('settles preflight rejections as not sent with no inference evidence', function (array $body, int $status, string $expectedCode): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra' => Http::response($body, $status),
    ] + [
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-never-dispatched',
            'model' => 'openai/gpt-5.6-terra-20261001',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'A drifted revision answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120],
        ]),
    ]);

    $collected = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    expect($collected->response->status->value)->toBe('failed')
        ->and($collected->response->errors->sole()->code)->toBe($expectedCode)
        ->and($diagnostic->code)->toBe($expectedCode)
        ->and($diagnostic->submissionState)->toBe(SubmissionState::NotSent)
        ->and($diagnostic->operation)->toBe(Operation::Inline)
        ->and($collected->coverage)->toBe(CaptureCoverage::Unavailable)
        ->and($collected->evidence)->toBeEmpty();
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
})->with([
    'catalog resolves a different revision' => [
        ['data' => ['canonical_slug' => 'openai/gpt-5.6-terra-20261001']], 200, 'laravel_ai.model_revision_drift_before_dispatch',
    ],
    'catalog server error' => [
        [], 500, 'laravel_ai.model_revision_unverified',
    ],
    'catalog not found' => [
        [], 404, 'laravel_ai.model_revision_unverified',
    ],
]);

it('settles an unreachable catalog as not sent', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(function (): never {
        throw new ConnectionException('The model catalog could not be reached.');
    });

    $collected = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    expect($collected->response->errors->sole()->code)->toBe('laravel_ai.model_revision_unverified')
        ->and($diagnostic->code)->toBe('laravel_ai.model_revision_unverified')
        ->and($diagnostic->submissionState)->toBe(SubmissionState::NotSent)
        ->and($collected->coverage)->toBe(CaptureCoverage::Unavailable)
        ->and($collected->evidence)->toBeEmpty();
    Http::assertNothingSent();
});

it('settles response-side drift without raw capture as possibly accepted, never not sent', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(catalogFake());
    ResearchAgent::fake([
        new TextResponse(
            'A drifted revision answer.',
            new AiUsage(promptTokens: 900, completionTokens: 120),
            new Meta('openrouter', 'openai/gpt-5.6-terra-20261001'),
        ),
    ])->preventStrayPrompts();

    $collected = Librarium::query('Bound this grounded OpenRouter answer.')
        ->using('openrouter-chat')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    // The preflight passed, so the inference prompt definitely ran before the
    // drift was rejected. With no raw SDK exchange to capture, the dispatch
    // is still recorded, so settlement stays PossiblyAccepted — a spent
    // reservation is never falsely released as NotSent.
    expect($collected->response->errors->sole()->code)->toBe('laravel_ai.model_revision_drift')
        ->and($diagnostic->code)->toBe('laravel_ai.model_revision_drift')
        ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
        ->and($diagnostic->operation)->toBe(Operation::Inline)
        ->and($collected->coverage)->toBe(CaptureCoverage::NoResponse)
        ->and($collected->evidence)->toBeEmpty();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
});

it('settles a deadline that expires after the catalog lookup as not sent', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra' => function () {
            // The shared deadline is now + 1s. Advancing the frozen clock here
            // expires it strictly after the bounded catalog lookup completed
            // and strictly before the inference POST, deterministically.
            CarbonImmutable::setTestNow('2026-09-19T00:00:05Z');

            return Http::response(['data' => ['canonical_slug' => 'openai/gpt-5.6-terra-20260709']]);
        },
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-never-dispatched',
            'model' => 'openai/gpt-5.6-terra-20260709',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'A grounded OpenRouter answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120],
        ]),
    ]);

    try {
        CarbonImmutable::setTestNow('2026-09-19T00:00:00Z');

        // A one-second shared deadline expires after the bounded catalog
        // lookup but before the inference POST: the catalog exchange leaves
        // no inference evidence, so the timeout settles NotSent and nothing
        // billable ran.
        $collected = Librarium::query('Bound this grounded OpenRouter answer.')
            ->using('openrouter-chat')
            ->timeout(1)
            ->collect();
        $diagnostic = $collected->diagnostics->sole();

        expect($collected->response->errors->sole()->code)->toBe('librarium.timeout')
            ->and($diagnostic->code)->toBe('librarium.timeout')
            ->and($diagnostic->submissionState)->toBe(SubmissionState::NotSent)
            ->and($collected->coverage)->toBe(CaptureCoverage::Unavailable)
            ->and($collected->evidence)->toBeEmpty();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('settles a runtime timeout after a conforming no-raw response as possibly accepted, never not sent', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(catalogFake());
    CarbonImmutable::setTestNow('2026-09-22T00:00:00Z');

    ResearchAgent::fake(function (): TextResponse {
        // The dispatch itself pushes the frozen clock past the shared 2s
        // deadline; the SDK then returns a fully conforming dated response
        // with no raw exchange to capture.
        CarbonImmutable::setTestNow('2026-09-22T00:00:03Z');

        return new TextResponse(
            'A grounded OpenRouter answer.',
            new AiUsage(promptTokens: 900, completionTokens: 120),
            new Meta('openrouter', 'openai/gpt-5.6-terra-20260709'),
        );
    })->preventStrayPrompts();

    try {
        $collected = Librarium::query('Bound this grounded OpenRouter answer.')
            ->using('openrouter-chat')
            ->timeout(2)
            ->collect();
        $diagnostic = $collected->diagnostics->sole();

        // The inference dispatch definitely happened before the runtime's
        // post-response deadline check rejected the result. With no raw SDK
        // exchange, the dispatch is still recorded as an unanswered attempt,
        // so the timeout settles PossiblyAccepted — a spent reservation is
        // never released as NotSent.
        expect($collected->response->errors->sole()->code)->toBe('librarium.timeout')
            ->and($diagnostic->code)->toBe('librarium.timeout')
            ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
            ->and($diagnostic->operation)->toBe(Operation::Inline)
            ->and($collected->coverage)->toBe(CaptureCoverage::NoResponse)
            ->and($collected->evidence)->toBeEmpty();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('settles an ungrounded required-grounding response without raw capture as possibly accepted, never not sent', function (): void {
    ResearchAgent::fake([
        new TextResponse(
            'An answer without provider-reported evidence.',
            new AiUsage(promptTokens: 900, completionTokens: 120),
            new Meta('openrouter', 'openai/gpt-5.6-luna'),
        ),
    ])->preventStrayPrompts();

    $collected = Librarium::query('Answer without provider-reported evidence.')
        ->using('openrouter-grounded')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    // The inference dispatch ran before the grounding check rejected the
    // mapped result, and the SDK exposed no raw exchange: the recorded
    // unanswered attempt keeps the settlement PossiblyAccepted instead of
    // falsely releasing a spent reservation as NotSent.
    expect($collected->response->errors->sole()->code)->toBe('laravel_ai.ungrounded_response')
        ->and($diagnostic->code)->toBe('laravel_ai.ungrounded_response')
        ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
        ->and($collected->coverage)->toBe(CaptureCoverage::NoResponse)
        ->and($collected->evidence)->toBeEmpty();
});

it('fails the preflight closed before the catalog lookup when the shared deadline is exhausted', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded OpenRouter answer.'),
    ]);
    Http::fake(catalogFake());
    ResearchAgent::fake()->preventStrayPrompts();

    try {
        groundedBoundDriver()->run(groundedBoundRequest(
            deadline: CarbonImmutable::now()->subSecond(),
            provider: 'openrouter',
            model: 'openai/gpt-5.6-terra',
            prompt: 'Bound this grounded OpenRouter answer.',
            options: config('librarium.profiles.openrouter-chat.options'),
        ));
        $this->fail('An exhausted shared deadline should fail closed before the catalog lookup.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('librarium.timeout')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNothingSent();
    ResearchAgent::assertNeverPrompted();
});

it('keeps unpinned and non-bounded OpenRouter routes free of the revision check', function (): void {
    // Covered by the unpinned default-routing and Luna tests above; this test
    // pins the contract explicitly: without the full bounded pin (provider +
    // exact model + budget), neither revision check runs — no catalog GET is
    // made before dispatch and the served model is not revision-checked —
    // and the request keeps its default routing without max_price.
    config()->set('librarium.profiles.openrouter-grounded.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_steps' => 1,
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-luna-unchecked',
            'model' => 'openai/gpt-5.6-luna',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'A Luna answer.',
                    'annotations' => [[
                        'type' => 'url_citation',
                        'url_citation' => ['url' => 'https://example.com/source', 'title' => 'Example source'],
                    ]],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]),
    ]);

    $response = Librarium::query('Answer on a non-bounded model.')
        ->using('openrouter-grounded')
        ->run();

    expect($response->status->value)->toBe('succeeded')
        ->and($response->results->sole()->model)->toBe('openai/gpt-5.6-luna');
    Http::assertSent(function (Request $request): bool {
        $provider = $request->data()['provider'];

        return $provider === ['require_parameters' => true, 'allow_fallbacks' => false]
            && ! array_key_exists('max_price', $provider);
    });
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra');
    Http::assertSentCount(1);
});

it('keeps every shipped Laravel AI Profile unbounded under its default configuration', function (string $profile): void {
    $bound = Librarium::query('Inventory the shipped defaults.')->using($profile)->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertNothingSent();
})->with([
    'claude-chat' => 'claude-chat',
    'openai-chat' => 'openai-chat',
    'gemini-grounded' => 'gemini-grounded',
    'gemini-chat' => 'gemini-chat',
    'openrouter-chat' => 'openrouter-chat',
    'openrouter-grounded' => 'openrouter-grounded',
]);

it('preserves the bounded no-search Claude path beside the withdrawn grounded authority', function (): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => false,
        'max_output_tokens' => 64_000,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    $bound = Librarium::query('Bound this direct Claude answer.')
        ->using('claude-chat')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($bound->pricingIdentity?->key())->toBe('anthropic:claude-sonnet-5-global')
        ->and($bound->quantities?->toArray())->toBe([
            'input_tokens' => '1000000',
            'output_tokens' => '64000',
        ]);
    Http::assertNothingSent();
});

function groundedBoundDriver(): LaravelAiDriver
{
    return new LaravelAiDriver(new ResearchResultMapper);
}

/**
 * Public-catalog fake entry proving the bounded alias still resolves to the
 * reviewed exact revision, so the pre-dispatch check passes and the pinned
 * inference request is dispatched.
 */
function catalogFake(): array
{
    return ['https://openrouter.ai/api/v1/model/openai/gpt-5.6-terra' => Http::response([
        'data' => ['canonical_slug' => 'openai/gpt-5.6-terra-20260709'],
    ])];
}

/**
 * @param  array<string, mixed>  $options
 */
function groundedBoundRequest(
    ?CarbonImmutable $deadline = null,
    ?string $model = 'gemini-3.6-flash',
    string $provider = 'gemini',
    array $options = [],
    string $prompt = 'Research Laravel AI.',
    ?Closure $responseCallback = null,
): DriverRequest {
    $profile = new Profile(
        id: 'grounded-probe',
        driver: LaravelAiDriver::class,
        provider: $provider,
        model: $model,
        resultKind: ResultKind::GroundedAnswer,
        grounding: GroundingPolicy::Required,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::ModelSearchTool]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
    );

    return new DriverRequest(
        requestId: 'request-123',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(42),
        progressCallback: static fn (): null => null,
        responseCallback: $responseCallback,
    );
}

/** @param array<string, mixed> $options */
function removeProfileOption(string $profile, string $key): void
{
    $options = config("librarium.profiles.{$profile}.options");
    config()->set("librarium.profiles.{$profile}.options", array_diff_key($options, [$key => true]));
}
