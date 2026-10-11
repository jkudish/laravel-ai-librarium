<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Drivers\Xai\XaiGrokDriver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;

/*
 * Offline spending-bound coverage for the exact `grok` Profile (grok-4.7, web-only corpus,
 * model_search_tool, POST /v1/responses + `web_search`). Every request here is fake HTTP and
 * every usage fixture is a synthetic adversarial assumption: the response shapes assert what
 * the driver accepts and reports at boundary values (call counts above turns, cumulative
 * input beyond one context window). They are not observations of real provider behavior, and
 * they never promote settlement usage into a pre-dispatch bound. The provenance for the
 * unknown verdict is `docs/xai-grok-spending-bounds.md`, not these fixtures.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-18T12:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('retains an unknown pre-dispatch bound for the exact grok-4.7 web-only grounded profile', function (): void {
    $bound = Librarium::query('Research current xAI information.')
        ->using('grok')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($bound->toArray())->toBe(['kind' => 'unknown'])
        ->and($bound->maximumCost)->toBeNull()
        ->and($bound->pricingIdentity)->toBeNull()
        ->and($bound->quantities)->toBeNull();
    Http::assertNothingSent();
});

it('keeps the bound unknown even when every documented request control is explicit', function (array $options): void {
    config()->set('librarium.profiles.grok.options', $options);

    $bound = Librarium::query('Research current xAI information.')
        ->using('grok')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($bound->toArray())->toBe(['kind' => 'unknown']);
    Http::assertNothingSent();
})->with([
    'minimum turn and output caps' => [['max_turns' => 1, 'max_output_tokens' => 1]],
    'single turn at the documented output default ceiling' => [['max_turns' => 1, 'max_output_tokens' => 128_000]],
    'multi-turn with domain filters' => [[
        'max_turns' => 10,
        'max_output_tokens' => 64_000,
        'allowed_domains' => ['docs.x.ai'],
    ]],
    'image search billed at the web search rate' => [['max_turns' => 2, 'enable_image_search' => true]],
    'token-based image understanding' => [['max_turns' => 2, 'enable_image_understanding' => true]],
]);

it('keeps the X-only and combined search routes unknown too', function (string $profile): void {
    $bound = Librarium::query('Research current xAI information.')
        ->using($profile)
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($bound->toArray())->toBe(['kind' => 'unknown']);
    Http::assertNothingSent();
})->with([
    'x only' => 'grok-x-only',
    'combined' => 'grok-combined',
]);

it('fails the X-search pricing contract closed instead of quoting search calls', function (string $profile): void {
    // The September 21, 2026 xAI change bills X Search per fetched post and
    // profile, not per call (https://docs.x.ai/developers/pricing), and no
    // documented control bounds fetched items. Even with a catalog that
    // prices the new units, an X-search Profile must resolve an unavailable
    // quote rather than present a search-call line that prices none of the
    // route's billed spend.
    config()->set('ai-pricing.prices', [
        'xai:grok-4.7' => [
            'searches' => ['amount' => '5', 'per' => '1000', 'currency' => 'USD'],
            'x_search_posts' => ['amount' => '5', 'per' => '1000', 'currency' => 'USD'],
            'x_search_profiles' => ['amount' => '10', 'per' => '1000', 'currency' => 'USD'],
        ],
    ]);

    $preview = Librarium::query('Research current xAI information.')
        ->using($profile)
        ->preview();
    $entry = $preview->pricingQuotes->sole();

    expect($entry['identity'])->toBeNull()
        ->and($entry['usage'])->toBeNull()
        ->and($entry['quote']->completeness)->toBe(CostCompleteness::Unavailable)
        ->and($entry['quote']->cost)->toBeNull()
        ->and($preview->pricingStatus)->toBe('unavailable')
        ->and($preview->preflightCostCheckAvailable)->toBeFalse();

    expect(fn () => Librarium::query('Research current xAI information.')
        ->using($profile)
        ->maxCost('1.00')
        ->preview())->toThrow(ValidationException::class);

    Http::assertNothingSent();
})->with([
    'x only' => 'grok-x-only',
    'combined' => 'grok-combined',
]);

it('keeps pricing the web-only route on its native search-call count', function (): void {
    // Web Search still bills per call, so the grok Profile keeps its native
    // searches quantity and the catalog prices it; the token units stay
    // unpriced by the grok-4.7 snapshot, leaving the quote partial rather
    // than complete.
    config()->set('ai-pricing.prices', [
        'xai:grok-4.7' => [
            'searches' => ['amount' => '5', 'per' => '1000', 'currency' => 'USD'],
            'x_search_posts' => ['amount' => '5', 'per' => '1000', 'currency' => 'USD'],
            'x_search_profiles' => ['amount' => '10', 'per' => '1000', 'currency' => 'USD'],
        ],
    ]);

    $preview = Librarium::query('Research current xAI information.')
        ->using('grok')
        ->preview();
    $entry = $preview->pricingQuotes->sole();

    expect($entry['identity']?->provider.':'.$entry['identity']?->model)->toBe('xai:grok-4.7')
        ->and($entry['usage']?->toArray())->toBe([
            'input_tokens' => '100000',
            'output_tokens' => '100000',
            'searches' => '100',
        ])
        ->and($entry['quote']->completeness)->toBe(CostCompleteness::Partial)
        ->and($entry['quote']->cost?->toArray())->toBe(['amount' => '0.5', 'currency' => 'USD'])
        ->and($entry['quote']->missingUnits)->toBe(['input_tokens', 'output_tokens']);

    Http::assertNothingSent();
});

it('fails unknown for an unsupported grok model instead of borrowing another identity', function (): void {
    config()->set('librarium.profiles.grok.model', 'grok-4.5');

    $bound = Librarium::query('Research current xAI information.')
        ->using('grok')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($bound->toArray())->toBe(['kind' => 'unknown']);
    Http::assertNothingSent();
});

it('sends the exact documented controls while the pre-dispatch bound remains unknown', function (): void {
    Http::fake(['https://api.x.ai/v1/responses' => Http::response(xaiBoundResponse(['input_tokens' => 120, 'output_tokens' => 80, 'total_tokens' => 200]))]);

    $options = ['max_turns' => 2, 'max_output_tokens' => 2048];
    config()->set('librarium.profiles.grok.options', $options);

    grokSpendingBoundDriver()->run(grokSpendingBoundRequest(options: $options));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.x.ai/v1/responses'
            && $request->data() === [
                'model' => 'grok-4.7',
                'input' => [[
                    'role' => 'user',
                    'content' => 'Research current xAI information.',
                ]],
                'tools' => [['type' => 'web_search']],
                'max_turns' => 2,
                'max_output_tokens' => 2048,
            ];
    });
    Http::assertSentCount(1);

    $bound = Librarium::query('Research current xAI information.')
        ->using('grok')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertSentCount(1);
});

it('accepts a synthetic response billing more successful web search calls than turns', function (): void {
    // Adversarial assumption, not provider proof: if xAI ever returned twelve successful
    // web_search calls for a max_turns=2 request, the driver must still complete and report
    // the billed calls and settled cost. Any reservation derived from turns would under-
    // reserve here, which is why the bound below stays unknown.
    Http::fake(['https://api.x.ai/v1/responses' => Http::response(xaiBoundResponse([
        'input_tokens' => 96_000,
        'output_tokens' => 80,
        'total_tokens' => 96_080,
        'num_server_side_tools_used' => 12,
        'server_side_tool_usage_details' => ['web_search_calls' => 12],
        'cost_in_usd_ticks' => 600_000_000,
    ]))]);

    $options = ['max_turns' => 2, 'max_output_tokens' => 2048];
    config()->set('librarium.profiles.grok.options', $options);

    $result = grokSpendingBoundDriver()->run(grokSpendingBoundRequest(options: $options));

    expect($result->usage?->promptTokens)->toBe(96_000)
        ->and($result->usage?->actualCost)->toBe('0.06')
        ->and($result->providerMeta)->toEqual((object) ['total_tokens' => 96_080]);
    Http::assertSentCount(1);

    // Settlement evidence never becomes a pre-dispatch bound.
    $bound = Librarium::query('Research current xAI information.')
        ->using('grok')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertSentCount(1);
});

it('accepts a synthetic response whose cumulative billed input exceeds one context window', function (): void {
    // Adversarial assumption, not provider proof: agentic prompt_tokens accumulate across
    // inferences, so a response may legitimately bill 1.4M input tokens against grok-4.7's
    // 500k per-inference window. The driver must report it, and a one-window input
    // reservation would under-reserve — the bound below stays unknown.
    Http::fake(['https://api.x.ai/v1/responses' => Http::response(xaiBoundResponse([
        'input_tokens' => 1_400_000,
        'output_tokens' => 80,
        'total_tokens' => 1_400_080,
        'cost_in_usd_ticks' => 2_900_000_000,
    ]))]);

    $options = ['max_turns' => 3, 'max_output_tokens' => 2048];
    config()->set('librarium.profiles.grok.options', $options);

    $result = grokSpendingBoundDriver()->run(grokSpendingBoundRequest(options: $options));

    expect($result->usage?->promptTokens)->toBe(1_400_000)
        ->and($result->usage?->actualCost)->toBe('0.29');
    Http::assertSentCount(1);

    $bound = Librarium::query('Research current xAI information.')
        ->using('grok')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertSentCount(1);
});

function grokSpendingBoundDriver(): XaiGrokDriver
{
    return app(XaiGrokDriver::class);
}

function grokSpendingBoundRequest(array $options = []): DriverRequest
{
    $profile = new Profile(
        id: 'grok',
        driver: XaiGrokDriver::class,
        provider: 'xai',
        model: 'grok-4.7',
        resultKind: ResultKind::GroundedAnswer,
        grounding: GroundingPolicy::Required,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::ModelSearchTool]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: 'testing-xai-key',
    );

    return new DriverRequest(
        requestId: 'request-xai-bound-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: 'Research current xAI information.',
        deadline: CarbonImmutable::now()->addSeconds(30),
        progressCallback: static function (): void {},
    );
}

/**
 * @param  array<array-key, mixed>  $usage
 * @return array<string, mixed>
 */
function xaiBoundResponse(array $usage): array
{
    return [
        'id' => 'resp_grok_bound',
        'status' => 'completed',
        'completed_at' => CarbonImmutable::now()->getTimestamp(),
        'model' => 'grok-4.7',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => 'A grounded claim about xAI.',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url' => 'https://docs.x.ai/source',
                        'title' => 'Official xAI docs',
                        'start_index' => 0,
                        'end_index' => 10,
                    ],
                ],
            ]],
        ]],
        'usage' => $usage,
    ];
}
