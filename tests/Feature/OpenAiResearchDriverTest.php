<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\OpenAi\OpenAiResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\OpenAi\OpenAiResearchResultMapper;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\UrlCitation;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-20T12:00:00Z');
    Sleep::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('submits, polls, and normalizes one grounded background research response', function (): void {
    $sequence = Http::sequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push(['id' => 'resp_research_1', 'status' => 'in_progress'])
        ->push(openAiCompletedResponse());
    Http::fake(['https://api.openai.com/*' => $sequence]);
    $progress = [];

    $result = openAiResearchDriver()->run(openAiResearchRequest(progress: $progress));

    expect($result->content)->toBe('A researched report with a cited claim.')
        ->and($result->provider)->toBe('openai')
        ->and($result->profile)->toBe('openai-research')
        ->and($result->model)->toBe('gpt-5.6-sol-reported')
        ->and($result->provenance->resultKind)->toBe(ResultKind::ResearchReport)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ResearchAgent])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->citations)->toHaveCount(1)
        ->and($result->citations->first()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->first()->source->url)->toBe('https://example.com/source')
        ->and($result->usage?->promptTokens)->toBe(100)
        ->and($result->usage?->completionTokens)->toBe(80)
        ->and($result->usage?->cacheReadInputTokens)->toBe(20)
        ->and($result->usage?->reasoningTokens)->toBe(30)
        ->and((array) $result->providerMeta)->toBe([
            'invocation_id' => 'request-1',
            'step_count' => 0,
        ])
        ->and($progress)->toBe(['OpenAI is researching in the background.']);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer testing-openai-key')
            && ! $request->hasHeader('Idempotency-Key')
            && $request->data() === [
                'model' => 'gpt-5.6-sol',
                'input' => 'Research current PHP agent frameworks.',
                'background' => true,
                'reasoning' => ['effort' => 'xhigh'],
                'tools' => [[
                    'type' => 'web_search',
                    'return_token_budget' => 'unlimited',
                ]],
                'tool_choice' => 'required',
                'include' => ['web_search_call.action.sources'],
            ];
    });
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.openai.com/v1/responses/resp_research_1');
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('resp_research_1')
        ->not->toContain('provider-secret')
        ->not->toContain('raw-secret.example.com');
});

it('normalizes completed research into a Laravel AI AgentResponse before interchange mapping', function (): void {
    $response = app(OpenAiResearchResultMapper::class)->toAgentResponse(
        openAiCompletedResponse(),
        openAiResearchRequest(),
    );

    expect($response)->toBeInstanceOf(AgentResponse::class)
        ->and($response->invocationId)->toBe('request-1')
        ->and($response->text)->toBe('A researched report with a cited claim.')
        ->and($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->cacheReadInputTokens)->toBe(20)
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('gpt-5.6-sol-reported')
        ->and($response->meta->citations)->toHaveCount(1)
        ->and($response->meta->citations->sole())->toBeInstanceOf(UrlCitation::class)
        ->and($response->meta->citations->sole()->url)->toBe('https://example.com/source')
        ->and($response->meta->citations->sole()->startIndex)->toBe(28)
        ->and($response->meta->citations->sole()->endIndex)->toBe(39);
});

it('accepts completed web search calls when optional source expansion is absent', function (): void {
    $completed = openAiCompletedResponse();
    unset($completed['output'][0]['action']['sources']);

    $response = app(OpenAiResearchResultMapper::class)->toAgentResponse(
        $completed,
        openAiResearchRequest(),
    );

    expect($response->text)->toBe('A researched report with a cited claim.')
        ->and($response->meta->citations)->toHaveCount(1);
});

it('uses a verified webhook signal as a wake hint and still retrieves the canonical response', function (): void {
    $webhooks = app(WebhookSignalStore::class);
    $webhooks->bind('openai', 'request-1', 'openai-research', 'resp_research_1');
    $webhooks->record('openai', 'resp_research_1', 'delivery-1', ResearchState::Completed);

    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push(openAiCompletedResponse());

    $result = openAiResearchDriver()->run(openAiResearchRequest());

    expect($result->content)->toContain('researched report');
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

it('retrieves the canonical response even when submission reports completion', function (): void {
    $submission = openAiCompletedResponse();
    data_set($submission, 'output.1.content.0.text', 'Untrusted submission content.');
    Http::fakeSequence()
        ->push($submission)
        ->push(openAiCompletedResponse());

    $result = openAiResearchDriver()->run(openAiResearchRequest());

    expect($result->content)->toBe('A researched report with a cited claim.');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.openai.com/v1/responses/resp_research_1');
    Sleep::assertNeverSlept();
});

it('reconciles transient retrieval failures until the response is available', function (): void {
    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push(['error' => ['code' => 'server_error']], 503)
        ->push(openAiCompletedResponse());

    $result = openAiResearchDriver()->run(openAiResearchRequest());

    expect($result->content)->toContain('researched report');
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('continues polling when the optional webhook signal store is unavailable', function (): void {
    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push(openAiCompletedResponse());
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('add', 'get')->andThrow(new RuntimeException('cache unavailable'));
    $webhooks = new WebhookSignalStore($cache, app('validator'), 10800);
    $driver = new OpenAiResearchDriver(app(OpenAiResearchResultMapper::class), $webhooks);

    $result = $driver->run(openAiResearchRequest());

    expect($result->content)->toContain('researched report');
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

it('maps every non-success terminal state without exposing provider errors', function (string $status, string $code): void {
    $terminal = [
        'id' => 'resp_research_1',
        'status' => $status,
        'error' => ['message' => 'provider-secret'],
    ];
    Http::fakeSequence()
        ->push($terminal)
        ->push($terminal);

    try {
        openAiResearchDriver()->run(openAiResearchRequest());
        $this->fail('A non-success terminal response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'failed' => ['failed', 'openai.research_failed'],
    'cancelled' => ['cancelled', 'openai.cancelled'],
    'incomplete' => ['incomplete', 'openai.incomplete'],
]);

it('rejects missing configuration and expired deadlines before submission', function (string $case, string $code): void {
    Http::fake();

    $request = match ($case) {
        'credential' => openAiResearchRequest(credential: null),
        'model' => openAiResearchRequest(model: null),
        default => openAiResearchRequest(deadline: CarbonImmutable::now()->subSecond()),
    };

    try {
        openAiResearchDriver()->run($request);
        $this->fail('Invalid local configuration should fail before submission.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'missing credential' => ['credential', 'openai.authentication'],
    'missing model' => ['model', 'openai.invalid_profile'],
    'expired deadline' => ['deadline', 'openai.deadline_exceeded'],
]);

it('rejects profiles with incompatible OpenAI research semantics', function (array $overrides): void {
    Http::fake();

    try {
        openAiResearchDriver()->run(openAiResearchRequest(...$overrides));
        $this->fail('An incompatible Profile should fail before submission.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('openai.invalid_profile')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNothingSent();
})->with([
    'provider' => [['provider' => 'anthropic']],
    'result kind' => [['resultKind' => ResultKind::GroundedAnswer]],
    'grounding' => [['grounding' => GroundingPolicy::Optional]],
    'observation' => [['observation' => ObservationMode::SurfaceSnapshot]],
    'corpus' => [['corpora' => [Corpus::Files]]],
    'retrieval method' => [['retrievalMethods' => [RetrievalMethod::ModelSearchTool]]],
]);

it('fails at the shared deadline without starting another retrieval', function (): void {
    Http::fakeSequence()->push(['id' => 'resp_research_1', 'status' => 'queued']);
    Sleep::whenFakingSleep(static function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));
    });

    try {
        openAiResearchDriver()->run(openAiResearchRequest(deadline: CarbonImmutable::now()->addSeconds(2)));
        $this->fail('The Driver should stop at the shared deadline.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('openai.deadline_exceeded');
    }

    Http::assertSentCount(1);
});

it('rejects a submit response that arrives after the shared deadline', function (): void {
    Http::fake(function (): array {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3));

        return ['id' => 'resp_research_1', 'status' => 'queued'];
    });

    try {
        openAiResearchDriver()->run(openAiResearchRequest(deadline: CarbonImmutable::now()->addSeconds(2)));
        $this->fail('A late submission response should not be accepted.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('openai.deadline_exceeded');
    }

    Http::assertSentCount(1);
});

it('rejects a retrieval response that arrives after the shared deadline', function (): void {
    Http::fake(function (Request $request): array {
        if ($request->method() === 'POST') {
            return ['id' => 'resp_research_1', 'status' => 'queued'];
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3));

        return openAiCompletedResponse();
    });

    try {
        openAiResearchDriver()->run(openAiResearchRequest(deadline: CarbonImmutable::now()->addSeconds(2)));
        $this->fail('A late retrieval response should not be accepted.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('openai.deadline_exceeded');
    }

    Http::assertSentCount(2);
});

it('requires a web search call, output text, and provider-reported web citations for a grounded report', function (array $mutation): void {
    $response = openAiCompletedResponse();
    data_set($response, $mutation['path'], $mutation['value']);
    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push($response);

    try {
        openAiResearchDriver()->run(openAiResearchRequest());
        $this->fail('An incomplete grounded report should fail normalization.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('openai.invalid_response');
    }
})->with([
    'no web search call' => [['path' => 'output.0.type', 'value' => 'reasoning']],
    'failed web search call' => [['path' => 'output.0.status', 'value' => 'failed']],
    'unrecognized web search action' => [['path' => 'output.0.action.type', 'value' => 'unknown']],
    'no output text' => [['path' => 'output.1.content.0.text', 'value' => '   ']],
    'no citations' => [['path' => 'output.1.content.0.annotations', 'value' => []]],
    'malformed citation' => [[
        'path' => 'output.1.content.0.annotations',
        'value' => [['type' => 'url_citation', 'url' => 'https://bad host/source']],
    ]],
]);

it('rejects a retrieval response for a different background request', function (): void {
    $completed = openAiCompletedResponse();
    $completed['id'] = 'resp_different';
    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push($completed);

    try {
        openAiResearchDriver()->run(openAiResearchRequest());
        $this->fail('A mismatched retrieval response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('openai.invalid_response');
    }
});

it('rejects invalid completion timestamps as provider response errors', function (mixed $timestamp): void {
    $response = openAiCompletedResponse();
    $response['completed_at'] = $timestamp;
    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push($response);

    try {
        openAiResearchDriver()->run(openAiResearchRequest());
        $this->fail('A malformed completion timestamp should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('openai.invalid_response');
    }
})->with([
    'malformed' => ['not-a-timestamp'],
    'above supported maximum' => [253_402_300_800],
]);

it('accepts the maximum supported completion timestamp', function (): void {
    $response = openAiCompletedResponse();
    $response['completed_at'] = 253_402_300_799;
    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push($response);

    $result = openAiResearchDriver()->run(openAiResearchRequest());

    expect($result->completedAt->getTimestamp())->toBe(253_402_300_799);
});

it('classifies submit HTTP failures without permitting fallback after an ambiguous server error', function (int $status, string $code, bool $fallbackAllowed): void {
    Http::fakeSequence()->push(['error' => ['message' => 'provider-secret']], $status);

    try {
        openAiResearchDriver()->run(openAiResearchRequest());
        $this->fail('The failed submission should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }

    Http::assertSentCount(1);
})->with([
    'authentication' => [401, 'openai.authentication', true],
    'authorization' => [403, 'openai.authorization', true],
    'rate limit' => [429, 'openai.rate_limited', true],
    'ambiguous unavailable submission' => [503, 'openai.unavailable', false],
    'invalid request' => [422, 'openai.invalid_request', false],
]);

it('retains absent usage as unknown and preserves only provider-reported usage fields', function (array $usage, ?array $expected): void {
    $response = openAiCompletedResponse();

    if ($usage === []) {
        unset($response['usage']);
    } else {
        $response['usage'] = $usage;
    }

    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push($response);

    $result = openAiResearchDriver()->run(openAiResearchRequest());

    expect($result->usage?->toArray())->toBe($expected)
        ->and(array_key_exists('usage', $result->toArray()))->toBe($expected !== null);
})->with([
    'absent usage remains unknown' => [[], null],
    'partial reported usage remains partial' => [[
        'input_tokens' => 120,
        'input_tokens_details' => ['cached_tokens' => 20],
    ], [
        'prompt_tokens' => 100,
        'cache_read_input_tokens' => 20,
    ]],
]);

it('rejects malformed present OpenAI usage fields', function (string $path, mixed $value): void {
    $response = openAiCompletedResponse();
    data_set($response, $path, $value);
    Http::fakeSequence()
        ->push(['id' => 'resp_research_1', 'status' => 'queued'])
        ->push($response);

    expect(fn () => openAiResearchDriver()->run(openAiResearchRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'openai.invalid_response');
})->with([
    'null usage record' => ['usage', null],
    'list usage record' => ['usage', []],
    'negative input tokens' => ['usage.input_tokens', -1],
    'fractional output tokens' => ['usage.output_tokens', 1.5],
    'malformed input details' => ['usage.input_tokens_details', 'not-an-object'],
    'malformed cache read tokens' => ['usage.input_tokens_details.cached_tokens', '20'],
    'negative reasoning tokens' => ['usage.output_tokens_details.reasoning_tokens', -1],
]);

function openAiResearchDriver(): OpenAiResearchDriver
{
    return app(OpenAiResearchDriver::class);
}

/** @param list<string> $progress */
function openAiResearchRequest(
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-openai-key',
    ?string $model = 'gpt-5.6-sol',
    array &$progress = [],
    string $provider = 'openai',
    ResultKind $resultKind = ResultKind::ResearchReport,
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ObservationMode $observation = ObservationMode::ApiOutput,
    array $corpora = [Corpus::Web],
    array $retrievalMethods = [RetrievalMethod::ResearchAgent],
): DriverRequest {
    $profile = new Profile(
        id: 'openai-research',
        driver: OpenAiResearchDriver::class,
        provider: $provider,
        model: $model,
        resultKind: $resultKind,
        grounding: $grounding,
        observation: $observation,
        corpora: collect($corpora),
        retrievalMethods: collect($retrievalMethods),
        prompt: '{{ query }}',
        enabled: true,
        options: [],
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: 'Research current PHP agent frameworks.',
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static function (string $message) use (&$progress): void {
            $progress[] = $message;
        },
    );
}

/** @return array<string, mixed> */
function openAiCompletedResponse(): array
{
    return [
        'id' => 'resp_research_1',
        'status' => 'completed',
        'completed_at' => CarbonImmutable::now()->getTimestamp(),
        'model' => 'gpt-5.6-sol-reported',
        'output' => [
            [
                'type' => 'web_search_call',
                'status' => 'completed',
                'action' => [
                    'type' => 'search',
                    'sources' => [
                        ['type' => 'url', 'url' => 'https://raw-secret.example.com/provider-secret'],
                    ],
                ],
            ],
            [
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'A researched report with a cited claim.',
                    'annotations' => [
                        [
                            'type' => 'url_citation',
                            'start_index' => 28,
                            'end_index' => 39,
                            'url' => 'https://example.com/source',
                            'title' => 'Example source',
                        ],
                        [
                            'type' => 'url_citation',
                            'url' => 'https://example.com/source',
                            'title' => 'Duplicate source',
                        ],
                        ['type' => 'file_citation', 'file_id' => 'provider-secret'],
                    ],
                ]],
            ],
        ],
        'usage' => [
            'input_tokens' => 120,
            'input_tokens_details' => ['cached_tokens' => 20],
            'output_tokens' => 80,
            'output_tokens_details' => ['reasoning_tokens' => 30],
            'total_tokens' => 200,
        ],
        'raw' => ['secret' => 'provider-secret'],
    ];
}
