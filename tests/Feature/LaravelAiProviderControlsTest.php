<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;

beforeEach(function (): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
});

/**
 * A Claude Sonnet 5.5 Messages API body: adaptive thinking with the default
 * omitted display, a server web search, and the progress note between tool
 * calls returned as an empty thinking block instead of text.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sonnet55Message(array $overrides = []): array
{
    return [
        'id' => 'msg_sonnet_55',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-5-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-up-front'],
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'laravel ai sdk']],
            [
                'type' => 'web_search_tool_result',
                'tool_use_id' => 'srvtoolu_1',
                'content' => [[
                    'type' => 'web_search_result',
                    'url' => 'https://example.com/laravel-ai',
                    'title' => 'Laravel AI',
                    'encrypted_content' => 'opaque',
                ]],
            ],
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-progress-update'],
            ['type' => 'text', 'text' => 'Laravel AI ships '],
            [
                'type' => 'text',
                'text' => 'a first-party SDK.',
                'citations' => [[
                    'type' => 'web_search_result_location',
                    'url' => 'https://example.com/laravel-ai',
                    'title' => 'Laravel AI',
                    'cited_text' => 'first-party SDK',
                    'encrypted_index' => 'opaque',
                ]],
            ],
        ],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => [
            'input_tokens' => 120,
            'output_tokens' => 80,
            'server_tool_use' => ['web_search_requests' => 1],
        ],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function openRouterControlsCompletion(): array
{
    return [
        'id' => 'gen-controls',
        'model' => 'openai/gpt-6-luna',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'A direct OpenRouter answer.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];
}

it('sends the shipped Sonnet 5.5 effort, adaptive thinking and max_tokens and reads the answer around thinking blocks', function (): void {
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::response(sonnet55Message())]);

    $result = variantRun('claude-chat', 'What is Laravel AI?');

    expect($result->model)->toBe('claude-sonnet-5-5')
        ->and($result->content)->toBe('Laravel AI ships a first-party SDK.')
        ->and($result->citations->map(fn ($citation): ?string => $citation->source->url)->all())->toBe(['https://example.com/laravel-ai'])
        ->and($result->usage?->serverToolSearchRequests)->toBe(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['model'] === 'claude-sonnet-5-5'
            && $body['max_tokens'] === 64_000
            && $body['thinking'] === ['type' => 'adaptive']
            && $body['output_config'] === ['effort' => 'high']
            && $body['tool_choice'] === ['type' => 'auto']
            && data_get($body, 'tools.0.type') === 'web_search_20250305'
            && ! array_key_exists('temperature', $body)
            && ! array_key_exists('top_p', $body);
    });
    Http::assertSentCount(1);
});

it('sends configured Claude controls, including between_tools thinking at high effort or below', function (): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => true,
        'effort' => 'low',
        'thinking' => 'between_tools',
        'max_output_tokens' => 16_000,
    ]);
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::response(sonnet55Message())]);

    variantRun('claude-chat', 'What is Laravel AI?');

    Http::assertSent(fn (Request $request): bool => $request['thinking'] === ['type' => 'between_tools']
        && $request['output_config'] === ['effort' => 'low']
        && $request['max_tokens'] === 16_000);
});

it('fails a Sonnet 5.5 refusal instead of returning its text as an answer', function (): void {
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::response(sonnet55Message([
        'content' => [['type' => 'text', 'text' => 'I can not help with that.']],
        'stop_reason' => 'refusal',
        'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
    ]))]);

    $run = variantResearch('claude-chat', 'What is Laravel AI?')->run();

    expect($run->status())->toBe('failed')
        ->and($run->result('probe')['answer'])->toBeNull()
        ->and($run->result('probe')['error']['code'])->toBe('laravel_ai.refused');
    Http::assertSentCount(1);
});

it('rejects Claude controls the provider or model cannot honor before any request', function (string $profile, array $options, string $message): void {
    config()->set("librarium.profiles.{$profile}.options", $options);
    Http::fake();

    expect(fn () => variantRun($profile, 'What is Laravel AI?'))
        ->toThrow(DriverException::class, $message);
    Http::assertNothingSent();
})->with([
    'between_tools at xhigh' => ['claude-chat', ['web_search' => true, 'effort' => 'xhigh', 'thinking' => 'between_tools'], 'between_tools'],
    'between_tools at max' => ['claude-chat', ['web_search' => true, 'effort' => 'max', 'thinking' => 'between_tools'], 'between_tools'],
    'Claude effort on OpenAI' => ['openai-chat', ['web_search' => true, 'effort' => 'high'], 'only by Anthropic'],
    'OpenRouter routing on Claude' => ['claude-chat', ['web_search' => true, 'allow_fallbacks' => true], 'only by OpenRouter'],
]);

it('makes a Profile unavailable for Claude and OpenRouter controls outside the strict contract', function (string $profile, array $options): void {
    config()->set("librarium.profiles.{$profile}.options", $options);

    expect(app(ProfileCatalog::class)->find($profile)?->reasons)->not->toBeEmpty();
})->with([
    'Sonnet 5.5 rejects disabled thinking' => ['claude-chat', ['thinking' => 'disabled']],
    'unknown effort' => ['claude-chat', ['effort' => 'extreme']],
    'string fallback flag' => ['openrouter-chat', ['web_search' => true, 'search_engine' => 'exa', 'allow_fallbacks' => 'false']],
    'integer zdr flag' => ['openrouter-chat', ['web_search' => false, 'zdr' => 1]],
    'empty provider order' => ['openrouter-chat', ['web_search' => true, 'search_engine' => 'exa', 'provider_order' => []]],
    'duplicate provider order' => ['openrouter-chat', ['web_search' => true, 'search_engine' => 'exa', 'provider_order' => ['openai', 'openai']]],
    'unknown data collection' => ['openrouter-chat', ['web_search' => true, 'search_engine' => 'exa', 'data_collection' => 'never']],
    'numeric-string reasoning budget' => ['openrouter-chat', ['web_search' => true, 'search_engine' => 'exa', 'reasoning_max_tokens' => '2048']],
]);

it('keeps exact OpenRouter routing and the model reasoning default when no control is configured', function (): void {
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(openRouterControlsCompletion())]);

    variantRun('openrouter-chat', 'Default routing.');

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'openai/gpt-6-luna'
        && $request['provider'] === ['require_parameters' => true, 'allow_fallbacks' => false]
        && ! array_key_exists('reasoning', $request->data()));
});

it('sends configured OpenRouter routing, privacy and reasoning controls', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => false,
        'provider_order' => ['azure', 'openai'],
        'allow_fallbacks' => true,
        'require_parameters' => false,
        'data_collection' => 'deny',
        'zdr' => true,
        'reasoning_max_tokens' => 2_048,
        'reasoning_exclude' => true,
    ]);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(openRouterControlsCompletion())]);

    $response = variantRun('openrouter-chat', 'Private routing.');

    expect($response->content)->not->toBeEmpty();
    Http::assertSent(fn (Request $request): bool => $request['provider'] === [
        'order' => ['azure', 'openai'],
        'require_parameters' => false,
        'allow_fallbacks' => true,
        'data_collection' => 'deny',
        'zdr' => true,
    ] && $request['reasoning'] === ['max_tokens' => 2_048, 'exclude' => true]);
});

it('sends a configured OpenRouter reasoning effort unmodified', function (string $effort): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'reasoning_effort' => $effort,
    ]);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(openRouterControlsCompletion())]);

    variantRun('openrouter-chat', 'Reason first.');

    Http::assertSent(fn (Request $request): bool => $request['reasoning'] === ['effort' => $effort]);
})->with(['none', 'minimal', 'xhigh', 'max']);

it('rejects OpenRouter controls that conflict before any request', function (array $options, string $message): void {
    config()->set('librarium.profiles.openrouter-chat.options', $options);
    Http::fake();

    expect(fn () => variantRun('openrouter-chat', 'Conflicting controls.'))
        ->toThrow(DriverException::class, $message);
    Http::assertNothingSent();
})->with([
    'zdr with web search' => [['web_search' => true, 'search_engine' => 'exa', 'zdr' => true], 'disable web search before enabling zdr'],
    'zdr with the default web search' => [['search_engine' => 'exa', 'zdr' => true], 'disable web search before enabling zdr'],
    'effort and token budget' => [['web_search' => false, 'reasoning_effort' => 'low', 'reasoning_max_tokens' => 1_024], 'mutually exclusive'],
    'override of the pinned bounded route' => [[
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_searches' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
        'provider_order' => ['azure'],
    ], 'bounded OpenRouter route'],
]);

it('rejects zdr on the always-grounded OpenRouter Profile', function (): void {
    config()->set('librarium.profiles.openrouter-grounded.options.zdr', true);
    Http::fake();

    $run = variantResearch('openrouter-grounded', 'Grounded and private.')->run();

    expect($run->result('probe')['error']['code'])->toBe('laravel_ai.invalid_options');
    Http::assertNothingSent();
});

it('keeps the bounded OpenRouter route unknown when a control overrides its pinned facts', function (array $override): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_tool_calls' => 4,
        'max_searches' => 4,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);
    $bounded = variantBound('openrouter-chat', 'Bound this.');

    config()->set('librarium.profiles.openrouter-chat.options', [...config('librarium.profiles.openrouter-chat.options'), ...$override]);
    $overridden = variantBound('openrouter-chat', 'Bound this.');

    expect($bounded->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($overridden->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertNothingSent();
})->with([
    'provider order' => [['provider_order' => ['azure']]],
    'fallbacks' => [['allow_fallbacks' => true]],
    'loose parameters' => [['require_parameters' => false]],
    'reasoning effort' => [['reasoning_effort' => 'low']],
]);

it('keeps the no-search Sonnet 5.5 bound unknown for a thinking mode the model rejects', function (): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => false,
        'effort' => 'max',
        'thinking' => 'between_tools',
        'max_output_tokens' => 64_000,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);

    expect(variantBound('claude-chat', 'Bound this.')->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertNothingSent();
});
