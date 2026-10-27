<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchResultMapper;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Facades\AiPricing;
use Jkudish\LaravelAiPricing\ValueObjects\Usage as PricingUsage;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;

beforeEach(function (): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);
    CarbonImmutable::setTestNow('2026-10-11T09:20:59Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * The usage object of the #4865 PHP canary's gemini-grounded run on
 * gemini-3.8-flash: 24 Google Search queries cost USD 0.336 of the
 * USD 0.3632925 rate-card total.
 *
 * @return array<string, mixed>
 */
function canaryGeminiUsage(): array
{
    return [
        'total_tokens' => 8525,
        'total_input_tokens' => 1363,
        'input_tokens_by_modality' => [['modality' => 'text', 'tokens' => 1363]],
        'total_cached_tokens' => 870,
        'cached_tokens_by_modality' => [['modality' => 'text', 'tokens' => 870]],
        'total_output_tokens' => 3226,
        'total_tool_use_tokens' => 0,
        'total_thought_tokens' => 3936,
        'grounding_tool_count' => [
            ['type' => 'google_search', 'count' => 24, 'search_query_count' => 24],
        ],
    ];
}

/** @param array<string, mixed> $usage */
function fakeGeminiInteraction(array $usage): void
{
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'text',
                    'text' => 'A grounded Gemini answer.',
                    'annotations' => [[
                        'type' => 'url_citation',
                        'url' => 'https://example.com/source',
                        'title' => 'Example source',
                    ]],
                ]],
            ]],
            'usage' => $usage,
        ]),
    ]);
}

it('reports the Gemini grounding search count in normalized usage and prices it', function (string $profile): void {
    fakeGeminiInteraction(canaryGeminiUsage());

    $usage = variantRun($profile, 'Which nonprofits use LuxGive?')->usage;

    expect($usage?->toArray())->toBe([
        'prompt_tokens' => 493,
        'completion_tokens' => 7162,
        'cache_read_input_tokens' => 870,
        'reasoning_tokens' => 3936,
        'server_tool_search_requests' => 24,
        'tool_use_tokens' => 0,
    ]);

    // Settled at the Profile's own pricing basis, the reported searches are
    // billed: 493 x 0.75/M + 870 x 0.075/M + 7,162 x 3.75/M + 24 x 0.014.
    $cost = AiPricing::quote('gemini', 'gemini-3.8-flash-standard', new PricingUsage([
        'input_tokens' => $usage->promptTokens,
        'cached_input_tokens' => $usage->cacheReadInputTokens,
        'output_tokens' => $usage->completionTokens,
        'web_searches' => $usage->serverToolSearchRequests,
    ]));
    expect($cost->completeness)->toBe(CostCompleteness::Complete)
        ->and($cost->cost?->amount->isEqualTo('0.3632925'))->toBeTrue();
    Http::assertSentCount(1);
})->with(['gemini-grounded', 'gemini-chat']);

it('includes the Google Search fee in every Gemini grounded quote', function (string $profile): void {
    $binding = app(ProfileCatalog::class)->find($profile)->profile;
    $quote = AiPricing::quote($binding->pricingIdentity->provider, $binding->pricingIdentity->model, $binding->pricingUsage);

    // 100,000 input x 0.75/M + 100,000 output x 3.75/M + 100 searches x 0.014:
    // the searches are 1.40 of the 1.85 quote.
    expect($binding->pricingUsage?->quantity('web_searches')->isEqualTo(100))->toBeTrue()
        ->and($quote->completeness)->toBe(CostCompleteness::Complete)
        ->and($quote->missingUnits)->toBe([])
        ->and($quote->cost?->amount->isEqualTo(BigDecimal::of('1.85')))->toBeTrue();
    Http::assertNothingSent();
})->with(['gemini-grounded', 'gemini-chat']);

it('counts only reported Google Search grounding queries and fails closed otherwise', function (mixed $counts, ?int $expected): void {
    expect(ResearchResultMapper::googleSearchQueries($counts))->toBe($expected);
})->with([
    'documented count only' => [[['type' => 'google_search', 'count' => 3]], 3],
    'query count only' => [[['type' => 'google_search', 'search_query_count' => 4]], 4],
    'several search entries sum' => [[['type' => 'google_search', 'count' => 2], ['type' => 'google_search', 'count' => 5]], 7],
    'other grounding tools are not web searches' => [[['type' => 'google_maps', 'count' => 9], ['type' => 'google_search', 'count' => 1], ['type' => 'retrieval', 'count' => 4]], 1],
    'no search entries is a reported zero' => [[['type' => 'google_maps', 'count' => 9]], 0],
    'empty list is a reported zero' => [[], 0],
    'counts that disagree' => [[['type' => 'google_search', 'count' => 3, 'search_query_count' => 5]], null],
    'present null count beside a query count' => [[['type' => 'google_search', 'count' => null, 'search_query_count' => 24]], null],
    'present null query count beside a count' => [[['type' => 'google_search', 'count' => 24, 'search_query_count' => null]], null],
    'search entry without a count' => [[['type' => 'google_search']], null],
    'string count' => [[['type' => 'google_search', 'count' => '24']], null],
    'negative count' => [[['type' => 'google_search', 'count' => -1]], null],
    'entry without a type' => [[['count' => 3]], null],
    'one malformed entry poisons the sum' => [[['type' => 'google_search', 'count' => 3], 'oops'], null],
    'map instead of list' => [['google_search' => 24], null],
    'not an array' => ['24', null],
    'Usage contract maximum' => [[['type' => 'google_search', 'count' => 9_007_199_254_740_991]], 9_007_199_254_740_991],
    'above the Usage contract maximum' => [[['type' => 'google_search', 'count' => 9_007_199_254_740_992]], null],
    'sum crossing the Usage contract maximum' => [[['type' => 'google_search', 'count' => 9_007_199_254_740_991], ['type' => 'google_search', 'count' => 1]], null],
]);

it('leaves the Gemini search count unknown when grounding_tool_count is absent or malformed', function (array $usage): void {
    fakeGeminiInteraction($usage);

    $result = variantRun('gemini-grounded', 'Which nonprofits use LuxGive?');

    expect($result->usage?->serverToolSearchRequests)->toBeNull()
        ->and($result->usage?->promptTokens)->toBe(493);
})->with([
    'absent' => [array_diff_key(canaryGeminiUsage(), ['grounding_tool_count' => true])],
    'malformed' => [[...canaryGeminiUsage(), 'grounding_tool_count' => [['type' => 'google_search', 'count' => 'many']]]],
]);
