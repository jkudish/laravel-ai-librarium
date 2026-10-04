<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\Perplexity\PerplexityAgentDriver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-18T12:00:00Z');
    Sleep::fake();

    foreach ([
        'perplexity-sonar-pro',
        'perplexity-low-frozen',
    ] as $profile) {
        config()->set("librarium.profiles.{$profile}.enabled", true);
        config()->set("librarium.profiles.{$profile}.credential", 'testing-perplexity-key');
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('keeps the exact managed low Profile unproven without any HTTP request', function (): void {
    $bound = Librarium::query('An exact managed request.')->using('perplexity-sonar-pro')->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($bound->maximumCost)->toBeNull()
        ->and($bound->pricingIdentity)->toBeNull()
        ->and($bound->quantities)->toBeNull()
        ->and($bound->toArray())->toBe(['kind' => 'unknown']);
    Http::assertNothingSent();
});

it('keeps the exact frozen low configuration unproven without any HTTP request', function (): void {
    $bound = Librarium::query('An exact frozen request.')->using('perplexity-low-frozen')->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($bound->maximumCost)->toBeNull()
        ->and($bound->pricingIdentity)->toBeNull()
        ->and($bound->quantities)->toBeNull()
        ->and($bound->toArray())->toBe(['kind' => 'unknown']);
    Http::assertNothingSent();
});

it('serializes the managed low request with no client cap the evidence relies on', function (): void {
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response(perplexitySpendingBoundCompletedResponse())]);

    $result = perplexitySpendingBoundDriver()->run(perplexitySpendingBoundRequest('perplexity-sonar-pro'));

    expect($result->profile)->toBe('perplexity-sonar-pro');
    Http::assertSent(function (Request $http): bool {
        $payload = $http->data();

        return $http->method() === 'POST'
            && $http->url() === 'https://api.perplexity.ai/v1/agent'
            && $payload === [
                'input' => 'Research current agent APIs.',
                'preset' => 'low',
            ]
            && ! array_key_exists('model', $payload)
            && ! array_key_exists('parallel_tool_calls', $payload)
            && ! array_key_exists('max_tool_calls', $payload)
            && ! array_key_exists('service_tier', $payload);
    });
});

it('serializes the frozen low request with its exact enforced limits and no invocation or monetary control', function (): void {
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response(perplexitySpendingBoundCompletedResponse())]);

    $result = perplexitySpendingBoundDriver()->run(perplexitySpendingBoundRequest('perplexity-low-frozen'));

    expect($result->profile)->toBe('perplexity-low-frozen')
        ->and($result->model)->toBe('openai/gpt-6-luna');
    Http::assertSent(function (Request $http): bool {
        $payload = $http->data();

        return $http->method() === 'POST'
            && $http->url() === 'https://api.perplexity.ai/v1/agent'
            && ! array_key_exists('preset', $payload)
            && ! array_key_exists('background', $payload)
            && data_get($payload, 'model') === 'openai/gpt-6-luna'
            && data_get($payload, 'prompt_cache_key') === 'low'
            && data_get($payload, 'max_steps') === 5
            && data_get($payload, 'max_output_tokens') === 32768
            && data_get($payload, 'reasoning') === ['effort' => 'minimal']
            && str_starts_with((string) data_get($payload, 'instructions'), 'Today is 2026-09-18.')
            && data_get($payload, 'tools') === [
                [
                    'type' => 'web_search',
                    'max_results' => 15,
                    'max_tokens' => 2000,
                    'max_tokens_per_page' => 2000,
                ],
                ['type' => 'fetch_url', 'max_urls' => 1],
            ]
            && ! array_key_exists('parallel_tool_calls', $payload)
            && ! array_key_exists('max_tool_calls', $payload)
            && ! array_key_exists('service_tier', $payload);
    });
});

function perplexitySpendingBoundDriver(): PerplexityAgentDriver
{
    return app(PerplexityAgentDriver::class);
}

function perplexitySpendingBoundRequest(
    string $profileId,
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-perplexity-key',
): DriverRequest {
    $frozen = $profileId === 'perplexity-low-frozen';
    $profile = new Profile(
        id: $profileId,
        driver: PerplexityAgentDriver::class,
        provider: 'perplexity',
        model: $frozen ? 'openai/gpt-6-luna' : null,
        resultKind: ResultKind::GroundedAnswer,
        grounding: GroundingPolicy::Required,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::ModelSearchTool]),
        prompt: '{{ query }}',
        enabled: true,
        options: $frozen ? ['configuration' => 'frozen-low-2026-10-03'] : [],
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-bound-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: 'Research current agent APIs.',
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}

/** @return array<string, mixed> */
function perplexitySpendingBoundCompletedResponse(): array
{
    return [
        'id' => 'resp_private_1',
        'status' => 'completed',
        'completed_at' => CarbonImmutable::now()->getTimestamp(),
        'model' => 'openai/gpt-6-luna',
        'output' => [
            [
                'type' => 'search_results',
                'results' => [[
                    'id' => 1,
                    'url' => 'https://example.com/first',
                    'title' => 'First source',
                    'snippet' => 'First snippet',
                    'source' => 'web',
                ]],
            ],
            [
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'A current answer [web:1].',
                ]],
            ],
        ],
        'usage' => [
            'input_tokens' => 100,
            'output_tokens' => 40,
            'total_tokens' => 140,
            'cost' => ['currency' => 'USD', 'total_cost' => 0.0125],
        ],
    ];
}
