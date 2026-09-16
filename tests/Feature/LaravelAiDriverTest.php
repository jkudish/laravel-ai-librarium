<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
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
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
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
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage as AiUsage;
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
            new AiUsage(promptTokens: 10, completionTokens: 6),
            new Meta($provider, $model, collect([
                new UrlCitation('https://example.com/source', 'Example source'),
            ])),
        ),
    ])->preventStrayPrompts();

    $response = Librarium::query('Research this question.')
        ->using($profile)
        ->timeout(120)
        ->run();

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

    expect($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($response->results)->toHaveCount(1)
        ->and($response->results->sole()->provider)->toBe($provider)
        ->and($response->results->sole()->profile)->toBe($profile)
        ->and($response->results->sole()->model)->toBe($model)
        ->and($response->results->sole()->citations)->toHaveCount(1);
})->with([
    'Gemini grounded' => ['gemini-grounded', 'gemini', 'gemini-3.6-flash'],
    'OpenRouter grounded' => ['openrouter-grounded', 'openrouter', 'openai/gpt-5.6-luna'],
    'OpenRouter chat' => ['openrouter-chat', 'openrouter', 'openai/gpt-5.6-terra'],
    'Claude chat' => ['claude-chat', 'anthropic', 'claude-sonnet-5'],
    'OpenAI chat' => ['openai-chat', 'openai', 'gpt-5.6-luna'],
    'Gemini chat' => ['gemini-chat', 'gemini', 'gemini-3.7-flash'],
]);

it('rejects an unexpected SDK provider identity for an exact built-in Profile', function (): void {
    ResearchAgent::fake([
        new TextResponse(
            'A misdirected grounded answer.',
            new AiUsage(promptTokens: 10, completionTokens: 6),
            new Meta('anthropic', 'claude-sonnet-5', collect([
                new UrlCitation('https://example.com/source', 'Example source'),
            ])),
        ),
    ])->preventStrayPrompts();

    expect(fn () => Librarium::query('Research this question.')
        ->using('gemini-grounded')
        ->run())
        ->toThrow(LogicException::class, 'unexpected provider identity for Profile [gemini-grounded]');
});

it('returns an honest model answer when optional web search is disabled', function (): void {
    config()->set('librarium.profiles.gemini-chat.options.web_search', false);
    ResearchAgent::fake([
        new TextResponse(
            'A direct model answer.',
            new AiUsage(promptTokens: 5, completionTokens: 4),
            new Meta('gemini', 'gemini-3.7-flash'),
        ),
    ])->preventStrayPrompts();

    $preview = Librarium::query('Answer without web search.')
        ->using('gemini-chat')
        ->preview();
    $profile = $preview->profiles->sole();
    $matching = Librarium::query('Answer without web search.')
        ->matching(
            resultKind: ResultKind::ModelAnswer,
            grounding: GroundingPolicy::None,
            observation: ObservationMode::ApiOutput,
            retrievalMethods: [RetrievalMethod::ModelOnly],
        )
        ->preview();
    $response = Librarium::query('Answer without web search.')
        ->using('gemini-chat')
        ->run();

    ResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent instanceof ResearchAgent
        && collect($prompt->agent->tools())->isEmpty());

    $result = $response->results->sole();

    expect($profile->resultKind)->toBe(ResultKind::ModelAnswer)
        ->and($profile->grounding)->toBe(GroundingPolicy::None)
        ->and($profile->corpora)->toBeEmpty()
        ->and($profile->retrievalMethods->all())->toBe([RetrievalMethod::ModelOnly])
        ->and($matching->profiles->pluck('id')->all())->toContain('gemini-chat')
        ->and($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($result->provenance->resultKind)->toBe(ResultKind::ModelAnswer)
        ->and($result->provenance->corpora)->toBeEmpty()
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ModelOnly])
        ->and($result->citations)->toBeEmpty();
});

it('rejects required grounding with web search disabled before execution', function (): void {
    config()->set('librarium.profiles.gemini-grounded.options.web_search', false);
    ResearchAgent::fake()->preventStrayPrompts();

    expect(fn () => Librarium::query('Research this question.')
        ->using('gemini-grounded')
        ->preview())
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
            new AiUsage(promptTokens: 12, completionTokens: 8, cacheReadInputTokens: 3, reasoningTokens: 2),
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
        new AiUsage(30, 20, 4, 5, 6),
        new Meta('gemini', 'gemini-2.5-pro'),
    ))->withSteps(collect([
        sdkStep(
            citations: [new UrlCitation('https://example.com/first', 'First source', 10, 20)],
            usage: new AiUsage(10, 4),
        ),
        sdkStep(
            citations: [
                new UrlCitation('https://example.com/first', 'Repeated source', 10, 20),
                new UrlCitation('https://example.com/second', 'Second source', 30, 45),
            ],
            usage: new AiUsage(20, 16, 4, 5, 6),
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
        new Meta('openrouter', 'openai/gpt-5.6-luna'),
    );
    $reported = (new AgentResponse(
        'invocation-reported',
        'Answer.',
        new AiUsage,
        new Meta('openrouter', 'openai/gpt-5.6-luna'),
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
            new AiUsage(promptTokens: 5, completionTokens: 3),
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

    $collected = Librarium::query('Research this question.')
        ->using('gemini-grounded')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    expect($collected->coverage)->toBe(CaptureCoverage::NoResponse)
        ->and($diagnostic->category)->toBe(FailureCategory::RateLimit)
        ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
        ->and($diagnostic->httpStatus)->toBeNull();
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
        prompt: 'Research Laravel AI.',
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
