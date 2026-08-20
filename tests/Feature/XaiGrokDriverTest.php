<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Xai\XaiGrokDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileOptionValidator;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-20T12:00:00Z');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('sends the exact configured model, user input, bounded options, and web-only tool', function (): void {
    Http::fake([
        'https://api.x.ai/v1/responses' => Http::response(xaiGrokResponse()),
    ]);

    $options = [
        'max_turns' => 8,
        'max_output_tokens' => 2048,
        'allowed_domains' => ['X.AI', 'docs.x.ai'],
        'enable_image_search' => true,
        'enable_image_understanding' => true,
    ];

    xaiGrokDriver()->run(xaiGrokRequest(model: 'grok-exact-configured', options: $options));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.x.ai/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer testing-xai-key')
            && $request->data() === [
                'model' => 'grok-exact-configured',
                'input' => [[
                    'role' => 'user',
                    'content' => 'Research current xAI information.',
                ]],
                'tools' => [[
                    'type' => 'web_search',
                    'filters' => [
                        'allowed_domains' => ['x.ai', 'docs.x.ai'],
                    ],
                    'enable_image_search' => true,
                    'enable_image_understanding' => true,
                ]],
                'max_turns' => 8,
                'max_output_tokens' => 2048,
            ];
    });
    Http::assertSentCount(1);
});

it('omits false and absent options while preserving one web search tool', function (): void {
    Http::fake(['https://api.x.ai/v1/responses' => Http::response(xaiGrokResponse())]);

    xaiGrokDriver()->run(xaiGrokRequest(options: [
        'enable_image_search' => false,
        'enable_image_understanding' => false,
    ]));

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'model' => 'grok-configured',
        'input' => [[
            'role' => 'user',
            'content' => 'Research current xAI information.',
        ]],
        'tools' => [['type' => 'web_search']],
    ]);
});

it('normalizes output text, strict deduplicated citations, usage, total tokens, and reported cost', function (): void {
    Http::fake(['https://api.x.ai/v1/responses' => Http::response(xaiGrokResponse())]);

    $result = xaiGrokDriver()->run(xaiGrokRequest());

    expect($result->content)->toBe("A grounded claim about xAI\nAdditional grounded context.")
        ->and($result->provider)->toBe('xai')
        ->and($result->profile)->toBe('grok-web')
        ->and($result->model)->toBe('grok-reported')
        ->and($result->provenance->resultKind)->toBe(ResultKind::GroundedAnswer)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ModelSearchTool])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->citations)->toHaveCount(2)
        ->and($result->citations->first()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->first()->source->url)->toBe('https://x.ai/source')
        ->and($result->citations->first()->source->title)->toBe('A grounded claim about xAI')
        ->and($result->citations->first()->locator)->toBe('characters:26-35')
        ->and($result->citations->last()->source->url)->toBe('https://docs.x.ai/source')
        ->and($result->citations->last()->source->title)->toBe('Official xAI docs')
        ->and($result->usage?->promptTokens)->toBe(120)
        ->and($result->usage?->completionTokens)->toBe(80)
        ->and($result->usage?->actualCost)->toBe('0.0300244')
        ->and($result->usage?->currency)->toBe('USD')
        ->and((array) $result->providerMeta)->toBe(['total_tokens' => 200]);

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('provider-secret')
        ->not->toContain('server_side_tool_usage')
        ->not->toContain('cost_in_usd_ticks')
        ->not->toContain('raw');
});

it('accepts output text without citations and does not invent sources', function (): void {
    $response = xaiGrokResponse();
    data_set($response, 'output.0.content.0.annotations', []);
    Http::fake(['https://api.x.ai/v1/responses' => Http::response($response)]);

    $result = xaiGrokDriver()->run(xaiGrokRequest());

    expect($result->content)->toContain('grounded claim')
        ->and($result->citations)->toHaveCount(0);
});

it('rejects invalid Profile options during preflight', function (array $options): void {
    $errors = app(ProfileOptionValidator::class)->errors(XaiGrokDriver::class, $options);

    expect($errors)->not->toBeEmpty();
})->with([
    'unknown option' => [['x_search' => true]],
    'zero max turns' => [['max_turns' => 0]],
    'zero max output tokens' => [['max_output_tokens' => 0]],
    'six allowed domains' => [['allowed_domains' => ['a.test', 'b.test', 'c.test', 'd.test', 'e.test', 'f.test']]],
    'empty domain list' => [['allowed_domains' => []]],
    'domain URL' => [['allowed_domains' => ['https://x.ai']]],
    'domain port' => [['allowed_domains' => ['x.ai:443']]],
    'duplicate domain' => [['allowed_domains' => ['x.ai', 'X.AI']]],
    'allowed and excluded domains' => [[
        'allowed_domains' => ['x.ai'],
        'excluded_domains' => ['example.com'],
    ]],
    'non-boolean image search' => [['enable_image_search' => 'true']],
    'non-boolean image understanding' => [['enable_image_understanding' => 1]],
]);

it('accepts every bounded Profile option during preflight', function (): void {
    $errors = app(ProfileOptionValidator::class)->errors(XaiGrokDriver::class, [
        'max_turns' => 4,
        'max_output_tokens' => 1024,
        'excluded_domains' => ['Example.com'],
        'enable_image_search' => true,
        'enable_image_understanding' => false,
    ]);

    expect($errors)->toBe([]);
});

it('rejects missing local configuration and incompatible Profile semantics before requesting', function (array $overrides, string $code): void {
    Http::fake();

    try {
        xaiGrokDriver()->run(xaiGrokRequest(...$overrides));
        $this->fail('Invalid local configuration should fail before requesting xAI.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'missing credential' => [['credential' => null], 'xai.authentication'],
    'missing model' => [['model' => null], 'xai.invalid_profile'],
    'wrong provider' => [['provider' => 'openai'], 'xai.invalid_profile'],
    'wrong result kind' => [['resultKind' => ResultKind::ResearchReport], 'xai.invalid_profile'],
    'optional grounding' => [['grounding' => GroundingPolicy::Optional], 'xai.invalid_profile'],
    'surface observation' => [['observation' => ObservationMode::SurfaceSnapshot], 'xai.invalid_profile'],
    'wrong corpus' => [['corpora' => [Corpus::X]], 'xai.invalid_profile'],
    'wrong retrieval' => [['retrievalMethods' => [RetrievalMethod::ModelOnly]], 'xai.invalid_profile'],
]);

it('validates every reported usage and cost scalar', function (string $path, mixed $value): void {
    $response = xaiGrokResponse();
    data_set($response, $path, $value);
    Http::fake(['https://api.x.ai/v1/responses' => Http::response($response)]);

    expect(fn () => xaiGrokDriver()->run(xaiGrokRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'xai.invalid_response');
})->with([
    'string input tokens' => ['usage.input_tokens', '120'],
    'negative output tokens' => ['usage.output_tokens', -1],
    'fractional total tokens' => ['usage.total_tokens', 200.5],
    'negative cost ticks' => ['usage.cost_in_usd_ticks', -1],
    'unsafe integer' => ['usage.total_tokens', 9_007_199_254_740_992],
]);

it('maps malformed successful responses to safe errors', function (array $response): void {
    Http::fake(['https://api.x.ai/v1/responses' => Http::response($response)]);

    try {
        xaiGrokDriver()->run(xaiGrokRequest());
        $this->fail('A malformed xAI response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('xai.invalid_response')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'list body' => [['provider-secret']],
    'provider error' => [['error' => ['message' => 'provider-secret']]],
    'missing output' => [['model' => 'grok-reported', 'usage' => []]],
    'blank output text' => [[
        'output' => [['type' => 'output_text', 'text' => '   ']],
        'raw' => ['provider-secret'],
    ]],
    'invalid completion timestamp' => [[
        'completed_at' => 'provider-secret',
        'output' => [['type' => 'output_text', 'text' => 'Answer']],
    ]],
]);

it('rejects a non-JSON successful response', function (): void {
    Http::fake(['https://api.x.ai/v1/responses' => Http::response('provider-secret', 200)]);

    expect(fn () => xaiGrokDriver()->run(xaiGrokRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'xai.invalid_response'
            && ! str_contains($exception->getMessage(), 'provider-secret'));
});

it('maps provider HTTP failures without exposing response bodies or credentials', function (int $status, array $body, string $code): void {
    Http::fake(['https://api.x.ai/v1/responses' => Http::response($body, $status)]);

    try {
        xaiGrokDriver()->run(xaiGrokRequest());
        $this->fail('A failed xAI request should be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->not->toContain('testing-xai-key');
    }

    Http::assertSentCount(1);
})->with([
    'observed key-like bad request' => [400, ['error' => ['message' => 'Incorrect API key provider-secret']], 'xai.authentication'],
    'authentication' => [401, ['error' => ['message' => 'provider-secret']], 'xai.authentication'],
    'authorization' => [403, ['error' => ['message' => 'provider-secret']], 'xai.authorization'],
    'rate limited' => [429, ['error' => ['message' => 'provider-secret']], 'xai.rate_limited'],
    'unavailable' => [503, ['error' => ['message' => 'provider-secret']], 'xai.unavailable'],
    'invalid request' => [422, ['error' => ['message' => 'provider-secret']], 'xai.invalid_request'],
]);

it('maps connection failures safely', function (): void {
    Http::fake(static fn (): never => throw new ConnectionException('provider-secret'));

    expect(fn () => xaiGrokDriver()->run(xaiGrokRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'xai.connection'
            && ! str_contains($exception->getMessage(), 'provider-secret'));
});

it('does not request after the shared deadline', function (): void {
    Http::fake();

    expect(fn () => xaiGrokDriver()->run(xaiGrokRequest(deadline: CarbonImmutable::now()->subSecond())))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'xai.deadline_exceeded');

    Http::assertNothingSent();
});

it('rejects a response that arrives after the shared deadline', function (): void {
    Http::fake(function (): array {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3));

        return xaiGrokResponse();
    });

    expect(fn () => xaiGrokDriver()->run(xaiGrokRequest(deadline: CarbonImmutable::now()->addSeconds(2))))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'xai.deadline_exceeded');

    Http::assertSentCount(1);
});

function xaiGrokDriver(): XaiGrokDriver
{
    return app(XaiGrokDriver::class);
}

/**
 * @param  array<string, mixed>  $options
 * @param  list<Corpus>  $corpora
 * @param  list<RetrievalMethod>  $retrievalMethods
 */
function xaiGrokRequest(
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-xai-key',
    ?string $model = 'grok-configured',
    array $options = [],
    string $provider = 'xai',
    ResultKind $resultKind = ResultKind::GroundedAnswer,
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ObservationMode $observation = ObservationMode::ApiOutput,
    array $corpora = [Corpus::Web],
    array $retrievalMethods = [RetrievalMethod::ModelSearchTool],
): DriverRequest {
    $profile = new Profile(
        id: 'grok-web',
        driver: XaiGrokDriver::class,
        provider: $provider,
        model: $model,
        resultKind: $resultKind,
        grounding: $grounding,
        observation: $observation,
        corpora: collect($corpora),
        retrievalMethods: collect($retrievalMethods),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-xai-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: 'Research current xAI information.',
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static function (): void {},
    );
}

/** @return array<string, mixed> */
function xaiGrokResponse(): array
{
    $claim = 'A grounded claim about xAI';

    return [
        'id' => 'resp_provider-secret',
        'status' => 'completed',
        'completed_at' => CarbonImmutable::now()->getTimestamp(),
        'model' => 'grok-reported',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => $claim,
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url' => 'https://x.ai/source',
                        'title' => '1',
                        'start_index' => strlen($claim),
                        'end_index' => 35,
                    ],
                    [
                        'type' => 'url_citation',
                        'url' => 'https://x.ai/source',
                        'title' => 'Duplicate title',
                    ],
                    [
                        'type' => 'url_citation',
                        'url' => 'https://docs.x.ai/source',
                        'title' => '  Official xAI docs  ',
                    ],
                    ['type' => 'url_citation', 'url' => 'javascript:provider-secret'],
                    ['type' => 'url_citation', 'url' => 'https://user:provider-secret@example.com/source'],
                    ['type' => 'file_citation', 'file_id' => 'provider-secret'],
                ],
            ], [
                'type' => 'output_text',
                'text' => 'Additional grounded context.',
                'annotations' => [],
            ]],
        ]],
        'usage' => [
            'input_tokens' => 120,
            'output_tokens' => 80,
            'total_tokens' => 200,
            'cost_in_usd_ticks' => 300_244_000,
            'raw_usage_secret' => 'provider-secret',
        ],
        'server_side_tool_usage' => ['web_search' => ['provider-secret']],
        'raw' => ['body' => 'provider-secret'],
    ];
}
