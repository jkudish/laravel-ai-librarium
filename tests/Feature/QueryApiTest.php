<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Exceptions\SelectionException;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\PendingResearch;

it('plans the new surface without calls or storage and preserves input', function (): void {
    config(['services.you.key' => 'placeholder', 'librarium.groups.focused' => ['you', 'openai', 'brave']]);
    $run = Librarium::research("  Exact question.\n")->deep()->only(['focused'])->skip(['openai'])->maxCost('0.05')->dryRun();
    expect($run->status())->toBe('planned')
        ->and($run->toArray()['question'])->toBe("  Exact question.\n")
        ->and(array_column($run->toArray()['results'], 'source'))->toBe(['you'])
        ->and($run->toArray()['cost']['estimated'])->toBe('0.05')
        ->and($run->toArray()['skipped'])->toBe([
            ['source' => 'brave', 'reason' => 'no_deep_variant'],
            ['source' => 'openai', 'reason' => 'excluded'],
        ])
        ->and(Librarium::find($run->id()))->toBeNull();
    Http::assertNothingSent();
});

it('rejects empty questions', function (string $question): void {
    expect(fn () => Librarium::research($question))->toThrow(ValidationException::class);
})->with(['', ' ', "\n\t"]);

it('rejects selection errors before provider calls', function (): void {
    expect(fn () => Librarium::research('q')->only(['unknown'])->dryRun())->toThrow(SelectionException::class, 'unknown_name');
    config(['librarium.sources.brave.enabled' => false]);
    expect(fn () => Librarium::research('q')->only(['brave'])->dryRun())->toThrow(SelectionException::class, 'not_configured');
    config(['librarium.groups.brave' => ['exa']]);
    expect(fn () => Librarium::research('q')->dryRun())->toThrow(SelectionException::class, 'group_name_conflict');
    expect(fn () => Librarium::sources())->toThrow(SelectionException::class, 'group_name_conflict');
    Http::assertNothingSent();
});

it('selects catalog order and excludes engines by default', function (): void {
    config(['librarium.engines.collector' => 'searchapi']);
    $plan = Librarium::research('q')->only(['openai', 'brave', 'chatgpt'])->dryRun();
    expect(array_column($plan->toArray()['results'], 'source'))->toBe(['brave', 'openai', 'chatgpt'])
        ->and($plan->result('chatgpt')['observation'])->toBe('surface')
        ->and(array_column(Librarium::research('q')->dryRun()->toArray()['results'], 'source'))->not->toContain('chatgpt');
});

it('validates options without constructing drivers or exposing credentials', function (): void {
    $plan = Librarium::research('q')->only(['grok'])->options(['grok' => ['search' => 'x']])->dryRun();
    expect($plan->result('grok')['provenance']['variant'])->toBe('grok-x-only')
        ->and(json_encode($plan))->not->toContain('testing-xai-key');
    expect(fn () => Librarium::research('q')->only(['grok'])->options(['grok' => ['search' => 'bad']])->dryRun())->toThrow(ValidationException::class);
    expect(fn () => Librarium::research('q')->only(['grok'])->options(['grok' => ['api_key' => 'secret']])->dryRun())->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('reports all shared sources and preserves driver validation', function (): void {
    expect(Librarium::sources()['sources'])->toHaveCount(16)->and(Librarium::sources()['engines'])->toHaveCount(5);
    config(['librarium.sources.firecrawl.key' => "unsafe\nkey"]);
    expect(fn () => Librarium::research('q')->only(['firecrawl'])->dryRun())->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('has no removed fluent methods', function (): void {
    foreach (['using', 'fanOut', 'group', 'matching', 'fallbacks', 'preview', 'spendingBound', 'collect', 'collectSensitive', 'submit', 'retrieve'] as $name) {
        expect(method_exists(PendingResearch::class, $name))->toBeFalse();
    }
});

it('rejects invalid options even for unselected unavailable or unknown sources before storing', function (array $options): void {
    Bus::fake();
    config(['librarium.runs.store' => 'memory', 'librarium.sources.openai.enabled' => false, 'ai.providers.openai.key' => null]);
    expect(fn () => Librarium::research('q')->only(['exa'])->options($options)->queue())->toThrow(ValidationException::class);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
    expect(app(LibrariumManager::class)->store()->list())->toBeEmpty();
})->with([
    [['openai' => ['api_key' => 'sentinel-auth-secret']]],
    [['unknown' => ['api_key' => 'sentinel-auth-secret']]],
    [['openai' => ['model' => []]]],
    [['grok' => ['search' => 'invalid']]],
    [['chatgpt' => ['key' => 'sentinel-auth-secret']]],
]);
