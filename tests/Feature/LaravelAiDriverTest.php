<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\FailureCategory;
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
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage as AiUsage;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\TextResponse;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('runs each configured Laravel AI Profile through one exact SDK provider and model', function (string $profile, string $provider, string $model): void {
    ResearchAgent::fake([
        new TextResponse(
            'A grounded answer.',
            new AiUsage(inputTokens: 10, outputTokens: 6),
            new Meta($provider, $model, collect([
                new UrlCitation('https://example.com/source', 'Example source'),
            ])),
        ),
    ])->preventStrayPrompts();

    $response = variantRun($profile, 'Research this question.');

    ResearchAgent::assertPrompted(function (AgentPrompt $prompt) use ($provider, $model): bool {
        if (! $prompt->agent instanceof ResearchAgent) {
            return false;
        }

        $search = collect($prompt->agent->tools())->sole();

        return $prompt->provider()->name() === $provider
            && $prompt->model === $model
            && $prompt->prompt === 'Research this question.'
            && $search instanceof WebSearch
            && $search->providerOptions('openrouter') === ($provider === 'openrouter' ? ['engine' => 'exa'] : [])
            && $prompt->agent->providerOptions('openrouter') === ($provider === 'openrouter' ? [
                'provider' => ['require_parameters' => true, 'allow_fallbacks' => false],
            ] : []);
    });

    expect($response->provider)->toBe($provider)
        ->and($response->profile)->toBe($profile)
        ->and($response->model)->toBe($model)
        ->and($response->citations)->toHaveCount(1);
})->with([
    'Gemini grounded' => ['gemini-grounded', 'gemini', 'gemini-3.8-flash'],
    'OpenRouter grounded' => ['openrouter-grounded', 'openrouter', 'openai/gpt-6-luna'],
    'OpenRouter chat' => ['openrouter-chat', 'openrouter', 'openai/gpt-6-luna'],
    'Claude chat' => ['claude-chat', 'anthropic', 'claude-sonnet-5-5'],
    'OpenAI chat' => ['openai-chat', 'openai', 'gpt-6-luna'],
    'Gemini chat' => ['gemini-chat', 'gemini', 'gemini-3.8-flash'],
]);

it('forwards public quick OpenAI effort through the real SDK request', function (?string $effort): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
    config(['librarium.runs.store' => 'memory']);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response([
        'id' => 'resp_effort', 'status' => 'completed', 'model' => 'gpt-6-luna',
        'output' => [['type' => 'message', 'status' => 'completed', 'content' => [[
            'type' => 'output_text', 'text' => 'Effort controlled answer.', 'annotations' => [],
        ]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
    ])]);
    $pending = Librarium::research('Research')->only(['openai']);
    if ($effort !== null) {
        $pending->options(['openai' => ['effort' => $effort]]);
    }
    $run = $pending->run();
    expect($run->status())->toBe('done')->and($run->result('openai')['answer'])->toBe('Effort controlled answer.');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $effort === null
        ? ! array_key_exists('reasoning', $request->data())
        : data_get($request->data(), 'reasoning.effort') === $effort);
})->with(['low', 'high', null]);

it('rejects an unexpected SDK provider identity for an exact built-in Profile', function (): void {
    ResearchAgent::fake([
        new TextResponse(
            'A misdirected grounded answer.',
            new AiUsage(inputTokens: 10, outputTokens: 6),
            new Meta('anthropic', 'claude-sonnet-5', collect([
                new UrlCitation('https://example.com/source', 'Example source'),
            ])),
        ),
    ])->preventStrayPrompts();

    config(['librarium.runs.store' => 'memory']);
    $run = Librarium::research('Research this question.')->only(['gemini'])->run();
    expect($run->status())->toBe('failed')
        ->and($run->result('gemini')['answer'])->toBeNull()
        ->and($run->result('gemini')['error']['code'])->toBe('librarium.invalid_result');
});

it('returns an honest model answer when optional web search is disabled', function (): void {
    config()->set('librarium.profiles.gemini-chat.options.web_search', false);
    ResearchAgent::fake([
        new TextResponse(
            'A direct model answer.',
            new AiUsage(inputTokens: 5, outputTokens: 4),
            new Meta('gemini', 'gemini-3.8-flash'),
        ),
    ])->preventStrayPrompts();

    $profile = variantRequest('gemini-chat', 'Answer without web search.')->profile;
    $result = variantRun('gemini-chat', 'Answer without web search.');

    ResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent instanceof ResearchAgent
        && collect($prompt->agent->tools())->isEmpty());

    expect($profile->resultKind)->toBe(ResultKind::ModelAnswer)
        ->and($profile->grounding)->toBe(GroundingPolicy::None)
        ->and($profile->corpora)->toBeEmpty()
        ->and($profile->retrievalMethods->all())->toBe([RetrievalMethod::ModelOnly])
        ->and($result->provenance->resultKind)->toBe(ResultKind::ModelAnswer)
        ->and($result->provenance->corpora)->toBeEmpty()
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ModelOnly])
        ->and($result->citations)->toBeEmpty();
});

it('sends bounded no-search Claude controls through the real SDK and never continues pause turn', function (): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => false,
        'max_output_tokens' => 64_000,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Answer directly once.'),
    ]);
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => 'A paused direct answer.']],
            'stop_reason' => 'pause_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
        ]),
    ]);

    $response = variantRun('claude-chat', 'Answer directly once.');

    expect($response->content)->toBe('A paused direct answer.')
        ->and($response->model)->toBe('claude-sonnet-5-5');
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['model'] === 'claude-sonnet-5-5'
            && $body['max_tokens'] === 64_000
            && ! array_key_exists('inference_geo', $body)
            && ! array_key_exists('tools', $body)
            && ! array_key_exists('cache_control', $body)
            && data_get($body, 'system.0.cache_control') === ['type' => 'ephemeral']
            && data_get($body, 'messages.0.content.0.text') === 'Answer directly once.';
    });
    Http::assertSentCount(1);
});

it('caches Claude static prefixes and only caches the conversation with a multi-step budget', function (bool $search, ?int $steps): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
    config()->set('librarium.profiles.claude-chat.options', array_filter([
        'web_search' => $search, 'max_steps' => $steps, 'max_output_tokens' => 100,
    ], static fn (mixed $value): bool => $value !== null));
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
        'id' => 'msg_cache', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => 'Answer.']], 'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 13, 'output_tokens' => 7, 'cache_creation_input_tokens' => 31, 'cache_read_input_tokens' => 19],
    ])]);

    $result = variantRun('claude-chat', 'Unique question.');
    expect($result->usage?->promptTokens)->toBe(13)
        ->and($result->usage?->cacheReadInputTokens)->toBe(19)
        ->and($result->usage?->cacheWriteInputTokens)->toBe(31);
    Http::assertSent(function (Request $request) use ($search, $steps): bool {
        $body = $request->data();

        return data_get($body, 'system.0.cache_control') === ['type' => 'ephemeral']
            && (isset($body['cache_control']) === ($steps === null || $steps > 1))
            && (! $search || data_get($body, 'tools.0.cache_control') === ['type' => 'ephemeral'])
            && ! isset($body['messages'][0]['content'][0]['cache_control']);
    });
})->with([[false, 1], [true, 1], [true, 2], [true, null], [false, 2]]);

it('does not invent Claude token counts from missing or coerced raw usage', function (string $field, mixed $value, string $metric): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
    $usage = ['input_tokens' => 13, 'output_tokens' => 7, 'cache_creation_input_tokens' => 500, 'cache_read_input_tokens' => 19];
    if ($value === null) {
        unset($usage[$field]);
    } else {
        $usage[$field] = $value;
    }
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
        'id' => 'msg_incomplete', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => 'Keep this answer.']], 'stop_reason' => 'end_turn',
        'usage' => $usage,
    ])]);

    $result = variantRun('claude-chat', 'Unique question.');
    expect($result->content)->toBe('Keep this answer.')
        ->and($result->usage?->{$metric})->toBeNull();
    Http::assertSentCount(1);
})->with([
    'missing uncached input with a cache write' => ['input_tokens', null, 'promptTokens'],
    'string uncached input' => ['input_tokens', '13', 'promptTokens'],
    'string output' => ['output_tokens', '7', 'completionTokens'],
    'string cache write' => ['cache_creation_input_tokens', '500', 'cacheWriteInputTokens'],
    'string cache read' => ['cache_read_input_tokens', '19', 'cacheReadInputTokens'],
]);

it('requires every Claude step to report a raw integer before summing uncached input', function (mixed $firstInput, ?int $expected): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
    $body = ['id' => 'msg_steps', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => 'Answer.']]];
    $first = array_filter(['input_tokens' => $firstInput, 'output_tokens' => 7, 'cache_creation_input_tokens' => 31, 'cache_read_input_tokens' => 19], static fn (mixed $value): bool => $value !== null);
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::sequence()
        ->push([...$body, 'stop_reason' => 'pause_turn', 'usage' => $first])
        ->push([...$body, 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 17, 'output_tokens' => 11, 'cache_creation_input_tokens' => 5, 'cache_read_input_tokens' => 3]])]);

    $result = variantRun('claude-chat', 'Unique question.');
    expect($result->usage?->promptTokens)->toBe($expected)
        ->and($result->usage?->completionTokens)->toBe(18)
        ->and($result->usage?->cacheWriteInputTokens)->toBe(36)
        ->and($result->usage?->cacheReadInputTokens)->toBe(22);
    Http::assertSentCount(2);
})->with([[13, 30], [null, null], ['13', null]]);

it('accepts the exact rendered prompt byte limit and rejects one byte beyond it', function (): void {
    $prompt = 'é';
    ResearchAgent::fake([
        new TextResponse(
            'A grounded answer.',
            new AiUsage(inputTokens: 1, outputTokens: 1),
            new Meta('gemini', 'gemini-2.5-pro', collect([
                new UrlCitation('https://example.com/source', 'Example source'),
            ])),
        ),
    ])->preventStrayPrompts();

    $result = groundedDriver()->run(groundedDriverRequest(
        options: ['max_prompt_bytes' => strlen($prompt)],
        prompt: $prompt,
    ));

    expect($result->content)->toBe('A grounded answer.');

    try {
        groundedDriver()->run(groundedDriverRequest(
            options: ['max_prompt_bytes' => strlen($prompt) - 1],
            prompt: $prompt,
        ));
        $this->fail('A rendered prompt one byte over the limit should be rejected.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.prompt_too_long')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    ResearchAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => $agentPrompt->prompt === $prompt);
});

it('rejects required grounding with web search disabled before execution', function (): void {
    config()->set('librarium.profiles.gemini-grounded.options.web_search', false);
    ResearchAgent::fake()->preventStrayPrompts();

    expect(fn () => variantRequest('gemini-grounded', 'Research this question.'))
        ->toThrow(ValidationException::class, 'Required-grounding Profiles must enable web search.');

    ResearchAgent::assertNeverPrompted();
});

it('prompts one exact SDK provider and model with native web search and the remaining deadline', function (): void {
    $raw = new HttpResponse(new PsrResponse(
        200,
        ['Authorization' => 'Bearer should-not-leak'],
        '{"api_key":"should-not-leak","id":"provider-response-id"}',
    ));
    ResearchAgent::fake([
        (new TextResponse(
            'A grounded answer.',
            new AiUsage(inputTokens: 15, outputTokens: 8, cacheReadInputTokens: 3, reasoningTokens: 2),
            new Meta('gemini', 'gemini-2.5-pro', collect([
                new UrlCitation('https://laravel.com/docs/13.x/ai-sdk', 'Laravel AI SDK'),
            ])),
        ))->withRawResponse($raw),
    ])->preventStrayPrompts();
    $request = groundedDriverRequest();

    $result = groundedDriver()->run($request);

    ResearchAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $tools = collect($prompt->agent instanceof ResearchAgent ? $prompt->agent->tools() : []);
        $search = $tools->sole();

        return $prompt->prompt === 'Research Laravel AI.'
            && $prompt->provider()->name() === 'gemini'
            && $prompt->model === 'gemini-2.5-pro'
            && $prompt->timeout === 42
            && $search instanceof WebSearch;
    });

    expect($result->content)->toBe('A grounded answer.')
        ->and($result->provider)->toBe('gemini')
        ->and($result->model)->toBe('gemini-2.5-pro')
        ->and($result->citations)->toHaveCount(1)
        ->and($result->usage?->promptTokens)->toBe(12)
        ->and($result->usage?->completionTokens)->toBe(8)
        ->and($result->usage?->cacheReadInputTokens)->toBe(3)
        ->and($result->usage?->reasoningTokens)->toBe(2)
        ->and($result->providerMeta)->not->toBeNull();

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);

    expect($wire)->not->toContain('should-not-leak')
        ->not->toContain('api_key')
        ->not->toContain('provider-response-id')
        ->and(array_keys((array) $result->providerMeta))->toBe(['invocation_id', 'step_count']);
});

it('maps citations from every SDK response step and the aggregate usage', function (): void {
    $response = (new AgentResponse(
        'invocation-123',
        'Final grounded answer.',
        new AiUsage(inputTokens: 39, outputTokens: 20, cacheReadInputTokens: 5, cacheWriteInputTokens: 4, reasoningTokens: 6),
        new Meta('gemini', 'gemini-2.5-pro'),
    ))->withSteps(collect([
        sdkStep(
            citations: [new UrlCitation('https://example.com/first', 'First source', 10, 20)],
            usage: new AiUsage(inputTokens: 10, outputTokens: 4),
        ),
        sdkStep(
            citations: [
                new UrlCitation('https://example.com/first', 'Repeated source', 10, 20),
                new UrlCitation('https://example.com/second', 'Second source', 30, 45),
            ],
            usage: new AiUsage(inputTokens: 29, outputTokens: 16, cacheReadInputTokens: 5, cacheWriteInputTokens: 4, reasoningTokens: 6),
        ),
    ]));

    $result = (new ResearchResultMapper)->map($response, groundedDriverRequest());

    expect($result->citations)->toHaveCount(2)
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/first',
            'https://example.com/second',
        ])
        ->and($result->citations->pluck('locator')->all())->toBe(['characters:10-20', 'characters:30-45'])
        ->and($result->usage?->promptTokens)->toBe(30)
        ->and($result->usage?->completionTokens)->toBe(20)
        ->and($result->usage?->cacheWriteInputTokens)->toBe(4)
        ->and($result->usage?->cacheReadInputTokens)->toBe(5)
        ->and($result->usage?->reasoningTokens)->toBe(6)
        ->and((array) $result->providerMeta)->toBe([
            'invocation_id' => 'invocation-123',
            'step_count' => 2,
        ]);
});

it('keeps absent SDK usage unknown while preserving an explicitly reported zero', function (): void {
    $unknown = new AgentResponse(
        'invocation-unknown',
        'Answer.',
        new AiUsage,
        new Meta('openrouter', 'openai/gpt-6-luna'),
    );
    $reported = (new AgentResponse(
        'invocation-reported',
        'Answer.',
        new AiUsage,
        new Meta('openrouter', 'openai/gpt-6-luna'),
    ))->withRawResponse(new HttpResponse(new PsrResponse(
        200,
        ['Content-Type' => 'application/json'],
        '{"usage":{"prompt_tokens":0,"completion_tokens":0}}',
    )));

    $unknownUsage = (new ResearchResultMapper)->map($unknown, groundedDriverRequest())->usage;
    $reportedUsage = (new ResearchResultMapper)->map($reported, groundedDriverRequest())->usage;

    expect($unknownUsage?->promptTokens)->toBeNull()
        ->and($unknownUsage?->completionTokens)->toBeNull()
        ->and($unknownUsage?->cacheWriteInputTokens)->toBeNull()
        ->and($reportedUsage?->promptTokens)->toBe(0)
        ->and($reportedUsage?->completionTokens)->toBe(0)
        ->and($reportedUsage?->cacheWriteInputTokens)->toBeNull();
});

it('fails closed when SDK cache partitions exceed the inclusive input total', function (): void {
    $negative = new AgentResponse(
        'invocation-negative',
        'Answer.',
        new AiUsage(inputTokens: 10, cacheReadInputTokens: 30, outputTokens: 8),
        new Meta('gemini', 'gemini-2.5-pro'),
    );
    $zero = new AgentResponse(
        'invocation-zero',
        'Answer.',
        new AiUsage(inputTokens: 30, cacheReadInputTokens: 30, outputTokens: 8),
        new Meta('gemini', 'gemini-2.5-pro'),
    );
    $positive = new AgentResponse(
        'invocation-positive',
        'Answer.',
        new AiUsage(inputTokens: 39, cacheReadInputTokens: 5, cacheWriteInputTokens: 4, outputTokens: 8),
        new Meta('gemini', 'gemini-2.5-pro'),
    );

    $negativeUsage = (new ResearchResultMapper)->map($negative, groundedDriverRequest())->usage;
    $zeroUsage = (new ResearchResultMapper)->map($zero, groundedDriverRequest())->usage;
    $positiveUsage = (new ResearchResultMapper)->map($positive, groundedDriverRequest())->usage;

    // Cache partitions claiming more tokens than the inclusive input total
    // means the usage report is internally inconsistent, so the whole input
    // side fails closed to unknown instead of pricing unaccounted tokens.
    expect($negativeUsage?->promptTokens)->toBeNull()
        ->and($negativeUsage?->cacheReadInputTokens)->toBeNull()
        ->and($negativeUsage?->cacheWriteInputTokens)->toBeNull()
        ->and($negativeUsage?->toArray())->toBe(['completion_tokens' => 8])
        ->and($zeroUsage?->promptTokens)->toBe(0)
        ->and($zeroUsage?->cacheReadInputTokens)->toBe(30)
        ->and($positiveUsage?->promptTokens)->toBe(30)
        ->and($positiveUsage?->cacheReadInputTokens)->toBe(5)
        ->and($positiveUsage?->cacheWriteInputTokens)->toBe(4);
});

it('fails closed when one step is inconsistent even if the SDK aggregate looks consistent', function (): void {
    // Both responses fold to the same SDK aggregate — input 110 with cached
    // 30 — but the first comes from steps whose own partitions are
    // individually consistent, while the second hides a step reporting input
    // 10 with cached 30. The aggregate check alone cannot tell them apart,
    // so each step's own partitions are validated before it is trusted.
    $consistent = (new AgentResponse(
        'invocation-steps-consistent',
        'Answer.',
        new AiUsage(inputTokens: 110, cacheReadInputTokens: 30, outputTokens: 6),
        new Meta('gemini', 'gemini-2.5-pro'),
    ))->withSteps(collect([
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 40, cacheReadInputTokens: 30, outputTokens: 2)),
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 70, outputTokens: 4)),
    ]));
    $maskedStep = (new AgentResponse(
        'invocation-steps-masked',
        'Answer.',
        new AiUsage(inputTokens: 110, cacheReadInputTokens: 30, outputTokens: 6),
        new Meta('gemini', 'gemini-2.5-pro'),
    ))->withSteps(collect([
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 10, cacheReadInputTokens: 30, outputTokens: 2)),
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 100, outputTokens: 4)),
    ]));

    $consistentUsage = (new ResearchResultMapper)->map($consistent, groundedDriverRequest())->usage;
    $maskedUsage = (new ResearchResultMapper)->map($maskedStep, groundedDriverRequest())->usage;

    expect($consistentUsage?->promptTokens)->toBe(80)
        ->and($consistentUsage?->cacheReadInputTokens)->toBe(30)
        ->and($consistentUsage?->completionTokens)->toBe(6)
        ->and($maskedUsage?->promptTokens)->toBeNull()
        ->and($maskedUsage?->cacheReadInputTokens)->toBeNull()
        ->and($maskedUsage?->cacheWriteInputTokens)->toBeNull()
        ->and($maskedUsage?->completionTokens)->toBe(6)
        ->and($maskedUsage?->toArray())->toBe(['completion_tokens' => 6]);
});

it('sums the Gemini tool-use audit tokens from every step raw response', function (): void {
    $raw = static fn (string $body): HttpResponse => new HttpResponse(new PsrResponse(
        200,
        ['Content-Type' => 'application/json'],
        $body,
    ));
    $complete = (new AgentResponse(
        'invocation-tool-use',
        'Answer.',
        new AiUsage(inputTokens: 40, outputTokens: 6),
        new Meta('gemini', 'gemini-2.5-pro'),
    ))->withSteps(collect([
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 10, outputTokens: 2))
            ->withRawResponse($raw('{"usage":{"total_tool_use_tokens":3}}')),
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 30, outputTokens: 4))
            ->withRawResponse($raw('{"usage":{"total_tool_use_tokens":7}}')),
    ]));
    $missingRaw = (new AgentResponse(
        'invocation-missing-raw',
        'Answer.',
        new AiUsage(inputTokens: 40, outputTokens: 6),
        new Meta('gemini', 'gemini-2.5-pro'),
    ))->withSteps(collect([
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 40, outputTokens: 6))
            ->withRawResponse($raw('{"usage":{"total_tool_use_tokens":3}}')),
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 40, outputTokens: 6)),
    ]));
    $malformed = (new AgentResponse(
        'invocation-malformed',
        'Answer.',
        new AiUsage(inputTokens: 40, outputTokens: 6),
        new Meta('gemini', 'gemini-2.5-pro'),
    ))->withSteps(collect([
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 40, outputTokens: 6))
            ->withRawResponse($raw('{"usage":{"total_tool_use_tokens":3}}')),
        sdkStep(citations: [], usage: new AiUsage(inputTokens: 40, outputTokens: 6))
            ->withRawResponse($raw('{"usage":{"total_tool_use_tokens":"many"}}')),
    ]));

    $completeUsage = (new ResearchResultMapper)->map($complete, groundedDriverRequest())->usage;
    $missingRawUsage = (new ResearchResultMapper)->map($missingRaw, groundedDriverRequest())->usage;
    $malformedUsage = (new ResearchResultMapper)->map($malformed, groundedDriverRequest())->usage;

    // The count is a reported audit detail only: it is never added to the
    // priced input partitions, and a partial sum stays unknown.
    expect($completeUsage?->toolUseTokens)->toBe(10)
        ->and($completeUsage?->promptTokens)->toBe(40)
        ->and($missingRawUsage?->toolUseTokens)->toBeNull()
        ->and($malformedUsage?->toolUseTokens)->toBeNull();
});

it('labels citation offsets with the unit each provider surface documents', function (): void {
    // 'É' is two bytes and the rocket emoji four, so bytes 22-30 mark the
    // word "research", which a character-based locator would call 18-26.
    $citation = new UrlCitation('https://example.com/research', 'Research source', 22, 30);
    $gemini = new AgentResponse(
        'invocation-gemini',
        'Émile 🚀 published research.',
        new AiUsage(inputTokens: 15, outputTokens: 8),
        new Meta('gemini', 'gemini-2.5-pro', collect([$citation])),
    );
    $openAi = new AgentResponse(
        'invocation-openai',
        'Grounded answer.',
        new AiUsage(inputTokens: 15, outputTokens: 8),
        new Meta('openai', 'gpt-5.6', collect([
            new UrlCitation('https://example.com/research', 'Research source', 10, 20),
        ])),
    );

    $geminiLocator = (new ResearchResultMapper)->map($gemini, groundedDriverRequest(), citationByteOffsets: true)
        ->citations->first()->locator;
    $openAiLocator = (new ResearchResultMapper)->map($openAi, groundedDriverRequest(provider: 'openai'))->citations->first()->locator;

    expect($geminiLocator)->toBe('bytes:22-30')
        ->and($openAiLocator)->toBe('characters:10-20');
});

it('labels Gemini citation offsets as bytes through the driver', function (): void {
    ResearchAgent::fake([
        (new TextResponse(
            'Émile 🚀 published research.',
            new AiUsage(inputTokens: 15, outputTokens: 8),
            new Meta('gemini', 'gemini-2.5-pro', collect([
                new UrlCitation('https://example.com/research', 'Research source', 22, 30),
            ])),
        )),
    ])->preventStrayPrompts();

    $result = groundedDriver()->run(groundedDriverRequest());

    expect($result->citations->first()->locator)->toBe('bytes:22-30');
});

it('rejects missing or unsupported search engines before prompting', function (string $provider, array $options, string $code): void {
    ResearchAgent::fake()->preventStrayPrompts();

    expect(fn () => groundedDriver()->run(groundedDriverRequest(provider: $provider, options: $options)))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse();
        });

    ResearchAgent::assertNeverPrompted();
})->with([
    'OpenRouter requires an engine' => ['openrouter', [], 'laravel_ai.search_engine_required'],
    'other providers reject an engine' => ['gemini', ['search_engine' => 'exa'], 'laravel_ai.search_engine_unsupported'],
    'OpenRouter rejects an unknown engine' => ['openrouter', ['search_engine' => 'unknown'], 'laravel_ai.search_engine_required'],
]);

it('rejects a required-grounding response without provider-reported evidence', function (): void {
    ResearchAgent::fake([
        new TextResponse(
            'An unsupported answer.',
            new AiUsage(inputTokens: 5, outputTokens: 3),
            new Meta('gemini', 'gemini-2.5-pro'),
        ),
    ])->preventStrayPrompts();

    try {
        groundedDriver()->run(groundedDriverRequest());
        $this->fail('A required-grounding response without evidence should be rejected.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.ungrounded_response')
            ->and($exception->fallbackAllowed)->toBeTrue()
            ->and($exception->getMessage())->toBe('The AI provider returned no evidence for the grounded response.');
    }
});

it('rejects an expired deadline before prompting the SDK', function (): void {
    ResearchAgent::fake()->preventStrayPrompts();

    try {
        groundedDriver()->run(groundedDriverRequest(deadline: CarbonImmutable::now()->subSecond()));
        $this->fail('The expired deadline should reject the Driver request.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('librarium.timeout')
            ->and($exception->fallbackAllowed)->toBeFalse()
            ->and($exception->getMessage())->toBe('The shared research deadline was reached.');
    }

    ResearchAgent::assertNeverPrompted();
});

it('requires an exact model before prompting the SDK', function (): void {
    ResearchAgent::fake()->preventStrayPrompts();

    try {
        groundedDriver()->run(groundedDriverRequest(model: null));
        $this->fail('A profile without an exact model should be rejected.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_required')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    ResearchAgent::assertNeverPrompted();
});

it('maps expected SDK and HTTP failures without exposing provider details', function (string $failure, string $code, bool $fallbackAllowed): void {
    ResearchAgent::fake([
        fn (): never => throw expectedDriverFailure($failure),
    ])->preventStrayPrompts();

    try {
        groundedDriver()->run(groundedDriverRequest());
        $this->fail('The expected SDK failure should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'rate limit' => ['rate limit', 'laravel_ai.rate_limited', true],
    'provider overload' => ['provider overload', 'laravel_ai.provider_unavailable', true],
    'insufficient credits' => ['insufficient credits', 'laravel_ai.insufficient_credits', true],
    'authentication' => ['authentication', 'laravel_ai.authentication', true],
    'authorization' => ['authorization', 'laravel_ai.authorization', true],
    'invalid provider request' => ['invalid provider request', 'laravel_ai.invalid_request', false],
    'HTTP timeout' => ['HTTP timeout', 'laravel_ai.timeout', true],
    'HTTP rate limit' => ['HTTP rate limit', 'laravel_ai.rate_limited', true],
    'server failure' => ['server failure', 'laravel_ai.provider_unavailable', true],
    'provider timeout' => ['provider timeout', 'librarium.timeout', false],
    'network failure' => ['network failure', 'laravel_ai.network', true],
    'validation failure' => ['validation failure', 'laravel_ai.validation', false],
    'generic AI failure' => ['generic AI failure', 'laravel_ai.provider', true],
]);

it('treats an SDK failure without an exposed HTTP cause as a possibly accepted request', function (): void {
    ResearchAgent::fake([
        fn (): never => throw RateLimitedException::forProvider('gemini'),
    ])->preventStrayPrompts();

    config(['librarium.runs.store' => 'memory']);
    $run = Librarium::research('Research this question.')->only(['gemini'])->run();
    $diagnostic = $run->diagnostics()[0];

    expect($run->evidence('gemini'))->toBeEmpty()
        ->and($diagnostic['category'])->toBe(FailureCategory::RateLimit->value)
        ->and($diagnostic['submission_state'])->toBe(SubmissionState::PossiblyAccepted->value)
        ->and($diagnostic['http_status'] ?? null)->toBeNull();
});

it('does not hide unexpected programming failures', function (): void {
    ResearchAgent::fake([
        fn (): never => throw new RuntimeException('Unexpected mapper bug.'),
    ])->preventStrayPrompts();

    expect(fn () => groundedDriver()->run(groundedDriverRequest()))
        ->toThrow(RuntimeException::class, 'Unexpected mapper bug.');
});

function groundedDriver(): LaravelAiDriver
{
    return new LaravelAiDriver(new ResearchResultMapper);
}

/** @param array<string, mixed> $options */
function groundedDriverRequest(
    ?CarbonImmutable $deadline = null,
    ?string $model = 'gemini-2.5-pro',
    string $provider = 'gemini',
    array $options = [],
    string $prompt = 'Research Laravel AI.',
): DriverRequest {
    $profile = new Profile(
        id: 'gemini-grounded',
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
    );
}

/** @param list<UrlCitation> $citations */
function sdkStep(array $citations, AiUsage $usage): Step
{
    return new Step(
        text: '',
        toolCalls: [],
        toolResults: [],
        finishReason: FinishReason::Stop,
        usage: $usage,
        meta: new Meta('gemini', 'gemini-2.5-pro', collect($citations)),
        reasoning: '',
        replayBlocks: [],
    );
}

function requestException(int $status, string $body): RequestException
{
    return new RequestException(new HttpResponse(new PsrResponse($status, [], $body)));
}

function expectedDriverFailure(string $failure): Throwable
{
    return match ($failure) {
        'rate limit' => RateLimitedException::forProvider('gemini'),
        'provider overload' => ProviderOverloadedException::forProvider('gemini'),
        'insufficient credits' => InsufficientCreditsException::forProvider('gemini'),
        'authentication' => requestException(401, '{"secret":"provider-secret"}'),
        'authorization' => requestException(403, '{"secret":"provider-secret"}'),
        'invalid provider request' => requestException(400, '{"secret":"provider-secret"}'),
        'HTTP timeout' => requestException(408, '{"secret":"provider-secret"}'),
        'HTTP rate limit' => requestException(429, '{"secret":"provider-secret"}'),
        'server failure' => requestException(503, '{"secret":"provider-secret"}'),
        'provider timeout' => tap(new ConnectionException('cURL error 28: provider-secret timed out'), static function (): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(42));
        }),
        'network failure' => new ConnectionException('Could not resolve provider-secret'),
        'validation failure' => ValidationException::withMessages(['provider' => 'provider-secret']),
        'generic AI failure' => new AiException('provider-secret'),
        default => throw new LogicException("Unknown failure fixture [{$failure}]."),
    };
}
