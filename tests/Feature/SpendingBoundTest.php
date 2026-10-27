<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;

beforeEach(function (): void {
    foreach (array_keys(config('librarium.profiles')) as $profile) {
        config()->set("librarium.profiles.{$profile}.enabled", true);
        config()->set("librarium.profiles.{$profile}.credential", 'testing-bound-key');
        if (str_starts_with($profile, 'dataforseo-')) {
            config()->set("librarium.profiles.{$profile}.options.location_code", 2840);
            config()->set("librarium.profiles.{$profile}.options.language_code", 'en');
        }
    }
});

it('retains the drivers exact fixed billable quantities without provider calls', function (string $profile, string $identity, array $quantities): void {
    $bound = variantBound($profile, 'An exact request');

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($bound->pricingIdentity?->key())->toBe($identity)
        ->and($bound->quantities?->toArray())->toBe($quantities)
        ->and($bound->maximumCost)->toBeNull();
    Http::assertNothingSent();
})->with([
    ['dataforseo-chatgpt', 'dataforseo:chatgpt-llm-scraper-standard', ['requests' => '1']],
    ['dataforseo-chatgpt-live', 'dataforseo:chatgpt-llm-scraper-live', ['requests' => '1']],
    ['dataforseo-gemini', 'dataforseo:gemini-llm-scraper-standard', ['requests' => '1']],
    ['dataforseo-gemini-live', 'dataforseo:gemini-llm-scraper-live', ['requests' => '1']],
    ['dataforseo-google-ai-mode', 'dataforseo:google-ai-mode-standard', ['requests' => '1']],
    ['dataforseo-google-ai-mode-live', 'dataforseo:google-ai-mode-live', ['requests' => '1']],
    ['exa', 'exa:search', ['requests' => '1', 'additional_results' => '0', 'summary_pages' => '0']],
    ['kagi-fastgpt', 'kagi:fastgpt', ['uncached_queries' => '1']],
    ['brave-search', 'brave:search', ['requests' => '1']],
    ['tavily-search', 'tavily:search', ['credits' => '2']],
    ['perplexity-search', 'perplexity:search', ['requests' => '1']],
    ['serpapi', 'serpapi:search', ['successful_search_request' => '1']],
    ['serpbase-search', 'serpbase:search', ['credits' => '1']],
    ['serpbase-news', 'serpbase:news', ['credits' => '1']],
    ['searchapi', 'searchapi:search', ['successful_search_request' => '1']],
    ['searchapi-chatgpt', 'searchapi:search', ['successful_search_request' => '1']],
    ['searchapi-gemini', 'searchapi:search', ['successful_search_request' => '1']],
    ['searchapi-google-ai-mode', 'searchapi:search', ['successful_search_request' => '1']],
    ['searchapi-bing-copilot', 'searchapi:search', ['successful_search_request' => '1']],
    ['searchapi-google-ai-overview', 'searchapi:search', ['successful_search_request' => '2']],
    ['parallel-research', 'parallel:research-pro', ['processor_requests' => '1']],
]);

it('retains Exas explicit native monetary ceiling as a snapshot', function (string $effort, int|float $maximum, string $expected): void {
    config()->set('librarium.profiles.exa-research.options', ['effort' => $effort, 'max_cost_dollars' => $maximum]);
    $bound = variantBound('exa-research', 'Bound this run');
    config()->set('librarium.profiles.exa-research.options.max_cost_dollars', 8);

    expect($bound->kind)->toBe(SpendingBoundKind::NativeMonetaryCeiling)
        ->and((string) $bound->maximumCost?->amount)->toBe($expected)
        ->and($bound->maximumCost?->currency)->toBe('USD')
        ->and($bound->pricingIdentity)->toBeNull()
        ->and($bound->quantities)->toBeNull()
        ->and((string) variantBound('exa-research', 'Bound this run')->maximumCost?->amount)->toBe('8');
    Http::assertNothingSent();
})->with([['auto', 1, '1'], ['ultra', 17.25, '17.25'], ['auto', 100, '100']]);

it('does not claim every billed dimension is bounded without driver proof', function (string $profile): void {
    $bound = variantBound($profile, 'Do not estimate');

    expect($bound->toArray())->toBe(['kind' => 'unknown'])
        ->and($bound->maximumCost)->toBeNull()
        ->and($bound->pricingIdentity)->toBeNull()
        ->and($bound->quantities)->toBeNull();
    Http::assertNothingSent();
})->with(['exa-research', 'claude-chat', 'brave-answers', 'gemini-grounded', 'valyu-search', 'jina-search']);

it('bounds no-search Claude input and both documented output boundaries', function (int $output, string $cost): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => false, 'max_output_tokens' => $output, 'max_steps' => 1, 'max_prompt_bytes' => 4096,
    ]);
    $bound = variantBound('claude-chat', 'Bound this direct Claude answer.');

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($bound->pricingIdentity?->key())->toBe('anthropic:claude-sonnet-5-5-global-standard')
        ->and($bound->quantities?->toArray())->toBe(['cache_write_input_tokens_5m' => '1000000', 'output_tokens' => (string) $output]);
    $quote = app(CostResolver::class)->resolve(new PricingObservation(
        $bound->pricingIdentity ?? throw new RuntimeException('Missing Claude identity.'),
        $bound->quantities ?? throw new RuntimeException('Missing Claude quantities.'),
    ));
    // 1M x $2.50/M writes + output x $10/M, not $2/M plain input.
    expect($quote->cost?->toArray())->toBe(['amount' => $cost, 'currency' => 'USD']);
    Http::assertNothingSent();
})->with([[1, '2.50001'], [64_000, '3.14'], [128_000, '3.78']]);

it('rejects Claude output controls outside documented boundaries', function (int $output): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => false, 'max_output_tokens' => $output, 'max_steps' => 1, 'max_prompt_bytes' => 4096,
    ]);
    expect(fn () => variantBound('claude-chat', 'Do not dispatch invalid controls.'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([0, 128_001]);

it('keeps incomplete or unsupported Claude bound configurations unknown', function (Closure $configure): void {
    config()->set('librarium.profiles.claude-chat.options', [
        'web_search' => false, 'max_output_tokens' => 64_000, 'max_steps' => 1, 'max_prompt_bytes' => 4096,
    ]);
    $configure();
    expect(variantBound('claude-chat', 'A prompt longer than ten bytes.')->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertNothingSent();
})->with([
    'provider' => fn () => config()->set('librarium.profiles.claude-chat.provider', 'openrouter'),
    'model' => fn () => config()->set('librarium.profiles.claude-chat.model', 'claude-sonnet-4-6'),
    'search' => fn () => config()->set('librarium.profiles.claude-chat.options.web_search', true),
    'multiple steps' => fn () => config()->set('librarium.profiles.claude-chat.options.max_steps', 2),
    'prompt exceeded' => fn () => config()->set('librarium.profiles.claude-chat.options.max_prompt_bytes', 10),
    'missing step cap' => fn () => config()->set('librarium.profiles.claude-chat.options', array_diff_key(config('librarium.profiles.claude-chat.options'), ['max_steps' => true])),
    'missing output cap' => fn () => config()->set('librarium.profiles.claude-chat.options', array_diff_key(config('librarium.profiles.claude-chat.options'), ['max_output_tokens' => true])),
    'missing prompt contract' => fn () => config()->set('librarium.profiles.claude-chat.options', array_diff_key(config('librarium.profiles.claude-chat.options'), ['max_prompt_bytes' => true])),
]);

it('keeps SearchAPIs two-request bound when retrieval fails without fabricating spend', function (): void {
    $bound = variantBound('searchapi-google-ai-overview', 'overview');
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['engine'] ?? null) === 'google'
            ? Http::response(['ai_overview' => ['page_token' => 'next']])
            : Http::response(['error' => 'failed second request'], 500);
    });
    config(['librarium.runs.store' => 'memory', 'librarium.engines.collector' => 'searchapi']);
    $run = Librarium::research('overview')->only(['google-ai-overview'])->run();
    expect($bound->quantities?->toArray())->toBe(['successful_search_request' => '2'])
        ->and($run->result('google-ai-overview')['status'])->toBe('failed')
        ->and($run->result('google-ai-overview')['cost']['reported'])->toBeNull()
        ->and($run->evidence('google-ai-overview'))->toHaveCount(2);
    Http::assertSentCount(2);
});
