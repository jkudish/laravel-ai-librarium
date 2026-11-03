<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Exceptions\SelectionException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\SourceCatalog;

beforeEach(function (): void {
    config(['librarium.runs.store' => 'memory', 'services.dataforseo.login' => 'dfs-test-login', 'services.dataforseo.password' => 'dfs-test-password']);
});

it('selects each opt-in Live collector and maps documented message sections, annotations, retrieval and total task cost', function (string $source, string $platform, string $provider): void {
    // Same response structure in all four Live reference examples, checked
    // 2026-11-03: docs.dataforseo.com/v3/ai_optimization/{platform}/llm_responses/live/.
    // Shortened text and URLs, retaining the documented nesting and cost fields.
    Http::fake(function (Request $request) use ($platform) {
        return Http::response(llmResponsesEnvelope($request, $platform));
    });
    $run = Librarium::research('Research café + %')->only([$source])->options([$source => ['collector' => 'dataforseo']])->run();
    $result = $run->result($source);
    expect($run->status())->toBe('done')
        ->and($result['answer'])->toBe('Answer cites one page.')
        ->and($result['citations'])->toBe([['url' => 'https://example.com/cited', 'title' => 'Cited']])
        ->and($result['search_results'])->toBe([
            ['url' => 'https://example.com/uncited', 'title' => 'Unused', 'snippet' => 'Retrieved only', 'published_at' => '2026-10-30T00:00:00Z'],
            ['url' => 'https://example.com/cited', 'title' => 'Cited', 'snippet' => null, 'published_at' => null],
        ])
        ->and($result['cost']['reported'])->toBe('0.029631')
        ->and($result['provenance']['provider'])->toBe($provider)
        ->and($result['provenance']['collector'])->toBe('dataforseo');
    expect(Librarium::find($run->id())->result($source)['search_results'])->toBe($result['search_results']);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === "https://api.dataforseo.com/v3/ai_optimization/{$platform}/llm_responses/live"
        && $request->data()[0]['user_prompt'] === 'Research café + %'
        && $request->data()[0]['web_search'] === true
        && $request->data()[0]['max_output_tokens'] === 2048
        && is_string($request->data()[0]['model_name']));

    $profile = app(SourceCatalog::class)->profile($source, 'quick', ['collector' => 'dataforseo']);
    $request = new DriverRequest('probe', $profile, $profile, 'Research café + %', now()->toImmutable()->addMinutes(3), static function (): void {});
    $mapped = app(DataForSeoDriver::class)->run($request);
    expect($mapped->citations->sole()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($mapped->providerMeta->fan_out_queries)->toBe(['related question'])
        ->and($mapped->usage->actualCost)->toBe('0.029631');
})->with([
    ['openai', 'chat_gpt', 'openai'], ['claude', 'claude', 'anthropic'],
    ['perplexity', 'perplexity', 'perplexity'], ['gemini', 'gemini', 'gemini'],
]);

it('rejects overlong Unicode prompts before HTTP but accepts exactly 500 characters', function (): void {
    Http::fake(fn (Request $request) => Http::response(llmResponsesEnvelope($request, 'chat_gpt')));
    $profile = app(SourceCatalog::class)->profile('openai', 'quick', ['collector' => 'dataforseo']);
    $request = new DriverRequest('probe', $profile, $profile, str_repeat('é', 501), now()->toImmutable()->addMinutes(3), static function (): void {});
    expect(fn () => app(DataForSeoDriver::class)->run($request))->toThrow(DriverException::class, '1 to 500');
    Http::assertNothingSent();
    $request = new DriverRequest('probe', $profile, $profile, str_repeat('é', 500), $request->deadline, static function (): void {});
    expect(app(DataForSeoDriver::class)->run($request)->content)->toBe('Answer cites one page.');
    Http::assertSentCount(1);
});

it('uses DataForSEO credentials rather than direct keys and stays unpriced under max_cost', function (): void {
    Http::fake();
    config(['ai.providers.openai.key' => null]);
    $run = Librarium::research('Research')->only(['openai'])->options(['openai' => ['collector' => 'dataforseo']])->dryRun();
    expect($run->result('openai')['provenance']['variant'])->toBe('dataforseo-llm-chatgpt');
    config(['librarium.sources.openai.collector' => 'dataforseo']);
    $listed = collect(Librarium::sources()['sources'])->firstWhere('id', 'openai');
    expect($listed['configured'])->toBeTrue()->and($listed['prices'])->toBe(['quick' => null]);
    $run = Librarium::research('Research')->only(['openai'])->options(['openai' => ['collector' => 'dataforseo']])->maxCost('1')->run();
    expect($run->toArray()['skipped'])->toContain(['source' => 'openai', 'reason' => 'unpriced']);
    config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null, 'ai.providers.openai.key' => 'direct-key']);
    expect(fn () => app(SourceCatalog::class)->profile('openai', 'quick', ['collector' => 'dataforseo']))->toThrow(ValidationException::class);
    expect(fn () => Librarium::research('Research')->only(['openai'])->options(['openai' => ['collector' => 'dataforseo']])->run())->toThrow(SelectionException::class);
    Http::assertNothingSent();
});

it('preserves unknown versus empty retrieval from LLM Responses without turning retrieval into grounding', function (?array $retrieval): void {
    Http::fake(function (Request $request) use ($retrieval) {
        $payload = llmResponsesEnvelope($request, 'chat_gpt');
        data_set($payload, 'tasks.0.result.0.search_results', $retrieval);
        data_set($payload, 'tasks.0.result.0.items.1.sections.0.annotations', []);

        return Http::response($payload);
    });
    $run = Librarium::research('Research')->only(['openai'])->options(['openai' => ['collector' => 'dataforseo']])->run();
    $result = $run->result('openai');
    expect($run->status())->toBe('done')->and($result['citations'])->toBe([]);
    if ($retrieval === null) {
        expect($result)->not->toHaveKey('search_results');
    } else {
        expect($result['search_results'])->toBe([]);
    }
})->with([[null], [[]]]);

it('preserves deep variants and enforces collector quick prerequisites', function (): void {
    $catalog = app(SourceCatalog::class);
    expect($catalog->profile('openai', 'deep', ['collector' => 'dataforseo'])->id)->toBe('openai-research')
        ->and($catalog->profile('gemini', 'deep', ['collector' => 'dataforseo'])->id)->toBe('gemini-deep-research');
    foreach (['gemini' => 'chat', 'perplexity' => 'search'] as $source => $quick) {
        expect(fn () => $catalog->profile($source, 'quick', ['quick' => $quick, 'collector' => 'dataforseo']))->toThrow(ValidationException::class);
    }
});

it('forwards model overrides and supported web search controls without a second client', function (): void {
    config(['librarium.sources.openai.options' => ['max_output_tokens' => 1024, 'force_web_search' => true, 'web_search_country_iso_code' => 'FR', 'web_search_city' => 'Paris']]);
    Http::fake(fn (Request $request) => Http::response(llmResponsesEnvelope($request, 'chat_gpt')));
    Librarium::research('Research')->only(['openai'])->options(['openai' => ['collector' => 'dataforseo', 'model' => 'gpt-4.1']])->run();
    Http::assertSent(fn (Request $request): bool => $request->data() === [[
        'user_prompt' => 'Research', 'model_name' => 'gpt-4.1', 'web_search' => true, 'max_output_tokens' => 1024,
        'force_web_search' => true, 'web_search_country_iso_code' => 'FR', 'web_search_city' => 'Paris',
    ]]);
});

it('rejects unsupported platform controls without HTTP', function (string $source, array $options): void {
    Http::fake();
    config(['librarium.sources.'.$source.'.options' => $options]);
    $profile = app(SourceCatalog::class)->profile($source, 'quick', ['collector' => 'dataforseo']);
    $request = new DriverRequest('probe', $profile, $profile, 'Research', now()->toImmutable()->addMinutes(3), static function (): void {});
    expect(fn () => app(DataForSeoDriver::class)->run($request))->toThrow(DriverException::class);
    Http::assertNothingSent();
})->with([['gemini', ['force_web_search' => true]], ['perplexity', ['web_search_city' => 'Paris']]]);

/** @return array<string, mixed> */
function llmResponsesEnvelope(Request $request, string $platform): array
{
    return [
        'version' => '0.1.20260722', 'status_code' => 20000, 'tasks_count' => 1, 'tasks_error' => 0,
        'tasks' => [[
            'id' => '07221754-1535-0612-0000-d708c6651bc3', 'status_code' => 20000, 'time' => '4.8305 sec.',
            // Task cost already includes money_spent; never add it again.
            'cost' => 0.029631, 'result_count' => 1,
            'path' => ['v3', 'ai_optimization', $platform, 'llm_responses', 'live'],
            'data' => ['api' => 'ai_optimization', 'function' => 'llm_responses', 'se' => $platform, ...$request->data()[0]],
            'result' => [[
                'model_name' => $request->data()[0]['model_name'], 'input_tokens' => 8174, 'output_tokens' => 476,
                'web_search' => true, 'money_spent' => 0.0290312, 'datetime' => '2026-07-22 17:54:30 +00:00',
                'items' => [
                    ['type' => 'reasoning', 'sections' => [['type' => 'summary_text', 'text' => 'Not answer content']]],
                    ['type' => 'message', 'sections' => [['type' => 'text', 'text' => 'Answer cites one page.', 'annotations' => [
                        ['url' => 'https://example.com/cited', 'title' => 'Cited', 'start_index' => 0, 'end_index' => 6, 'text' => 'Answer'],
                    ]]]],
                ],
                'fan_out_queries' => ['related question'],
                'search_results' => [
                    ['url' => 'https://example.com/uncited', 'title' => 'Unused', 'date' => '2026-10-30 00:00:00 +00:00', 'snippet' => 'Retrieved only'],
                    ['url' => 'https://example.com/cited', 'title' => 'Cited', 'date' => null, 'snippet' => null],
                ],
            ]],
        ]],
    ];
}
