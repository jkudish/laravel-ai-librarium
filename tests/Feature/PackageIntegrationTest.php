<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LaravelAiLibrariumServiceProvider;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\SourceCatalog;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use Jkudish\LaravelAiPricing\LaravelAiPricingServiceProvider;

it('registers one manager behind the facade contract', function (): void {
    expect(app(LibrariumContract::class))->toBeInstanceOf(LibrariumManager::class)
        ->toBe(app(LibrariumManager::class));
});

it('merges the simplified configuration and preserves queue and webhook lifetimes', function (): void {
    expect(config('librarium.groups'))->toBe([])
        ->and(config('librarium.runs.store'))->toBe('filesystem')
        ->and(config('librarium.runs.encrypt'))->toBeTrue()
        ->and(config('librarium.timeout'))->toBe(6900)
        ->and(config('librarium.queue.timeout'))->toBe(7200)
        ->and(config('librarium.webhook_signal_ttl'))->toBe(10800)
        ->and(config('librarium.queue.name'))->toBe('research')
        ->and(app(WebhookSignalStore::class))->toBe(app(WebhookSignalStore::class));
});

it('registers pricing before discovery can resolve the source planner', function (): void {
    $original = Container::getInstance();
    try {
        $consumer = new Application(dirname(__DIR__, 2));
        $consumer->instance('config', new Repository);
        expect($consumer->providerIsLoaded(LaravelAiPricingServiceProvider::class))->toBeFalse();
        $provider = $consumer->register(LaravelAiLibrariumServiceProvider::class);
        $pricing = $consumer->getProvider(LaravelAiPricingServiceProvider::class);
        expect($pricing)->toBeInstanceOf(LaravelAiPricingServiceProvider::class)
            ->and($consumer['config']->get('ai-pricing.cache_ttl'))->toBe(86_400)
            ->and($consumer->register(LaravelAiPricingServiceProvider::class))->toBe($pricing)
            ->and($consumer->getProvider(LaravelAiLibrariumServiceProvider::class))->toBe($provider);
    } finally {
        Container::setInstance($original);
    }
});

it('maps the default quick sources to retained driver variants with credentials resolved at use', function (string $source, string $variant, string $credential): void {
    config(['services.firecrawl.key' => 'placeholder-firecrawl']);
    $profile = app(SourceCatalog::class)->profile($source, 'quick');
    expect($profile->id)->toBe($variant)
        ->and($profile->credential)->toBe(config($credential))
        ->and(json_encode($profile))->not->toContain(config($credential));
})->with([
    ['brave', 'brave-answers', 'services.brave.key'],
    ['claude', 'claude-chat', 'ai.providers.anthropic.key'],
    ['exa', 'exa', 'services.exa.key'],
    ['firecrawl', 'firecrawl-search', 'services.firecrawl.key'],
    ['gemini', 'gemini-grounded', 'ai.providers.gemini.key'],
    ['google', 'searchapi', 'services.searchapi.key'],
    ['grok', 'grok', 'ai.providers.xai.key'],
    ['jina', 'jina-search', 'services.jina.key'],
    ['kagi', 'kagi-fastgpt', 'services.kagi.key'],
    ['openai', 'openai-chat', 'ai.providers.openai.key'],
    ['openrouter', 'openrouter-grounded', 'ai.providers.openrouter.key'],
    ['parallel', 'parallel-turbo', 'services.parallel.key'],
    ['perplexity', 'perplexity-sonar-pro', 'services.perplexity.key'],
    ['tavily', 'tavily-search', 'services.tavily.key'],
    ['valyu', 'valyu-search', 'services.valyu.key'],
    ['you', 'you-answer', 'services.you.key'],
]);

it('makes every deep source durable', function (string $source): void {
    $profile = app(SourceCatalog::class)->profile($source, 'deep');
    expect(is_a($profile->driver, DurableDriver::class, true))->toBeTrue();
})->with(['exa', 'gemini', 'openai', 'parallel', 'perplexity', 'valyu', 'you']);

it('keeps model-only overrides separate from the shipped billing identity', function (): void {
    $catalog = app(SourceCatalog::class);
    $default = $catalog->profile('openai', 'quick');
    $override = $catalog->profile('openai', 'quick', ['model' => 'different-model']);
    expect($default->model)->toBe('gpt-6-luna')
        ->and($default->pricingIdentity?->key())->toBe('openai:gpt-6-luna-standard-short')
        ->and($override->model)->toBe('different-model')
        ->and($override->pricingIdentity)->toBeNull();
});

it('starts research through the facade and rejects blank questions', function (): void {
    expect(Librarium::research('Compare agent memory.'))->toBeInstanceOf(PendingResearch::class);
    foreach (['', '   ', "\n\t"] as $question) {
        expect(fn () => Librarium::research($question))->toThrow(ValidationException::class);
    }
});
