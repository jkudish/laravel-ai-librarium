<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\Sources\PortkeyPricingSource;

/**
 * Seed the fallback catalog exactly as a host's ai-pricing sync would, from a
 * faked Portkey response that prices both the generic Luna identity and an
 * unblocked control identity.
 */
function syncPortkeyOpenAiCatalogWithGenericLuna(): void
{
    $rate = ['request_token' => ['price' => '0.00001'], 'response_token' => ['price' => '0.00005']];
    Http::fake(['configs.portkey.ai/*' => Http::response([
        'gpt-6-luna' => ['pricing_config' => ['pay_as_you_go' => $rate]],
        'o3' => ['pricing_config' => ['pay_as_you_go' => $rate]],
    ])]);

    $synced = (new PortkeyPricingSource(
        http: app(HttpFactory::class),
        cache: app(CacheManager::class)->store(config('ai-pricing.cache_store')),
        endpoint: config('ai-pricing.portkey.endpoint'),
        ttlSeconds: config('ai-pricing.cache_ttl'),
        offline: false,
        providers: ['openai'],
    ))->sync();

    expect($synced)->toBe(2);
}

it('quotes OpenAI chat against the tiered Luna billing basis because the generic identity is fallback-blocked', function (): void {
    syncPortkeyOpenAiCatalogWithGenericLuna();
    config()->set('librarium.profiles.openai-chat.credential', 'test-key');
    config()->set('librarium.profiles.openai-research.credential', 'test-key');

    // The control proves the synced fallback catalog is consulted for an
    // unblocked identity, so the Luna refusal below is the block itself.
    // pricing snapshot v12 fallback-blocks openai:gpt-5.6-sol, so the control
    // uses an identity the snapshot leaves to the fallback catalog.
    config()->set('librarium.profiles.openai-research.pricing.model', 'o3');
    $control = Librarium::query('Research this.')->using('openai-research')->preview()->pricingQuotes->sole();
    expect($control['identity']?->key())->toBe('openai:o3')
        ->and($control['quote']->source)->toBe(PricingSource::Portkey);

    config()->set('librarium.profiles.openai-chat.pricing.model', 'gpt-6-luna');
    $generic = Librarium::query('Research this.')->using('openai-chat')->preview()->pricingQuotes->sole();
    expect($generic['identity']?->key())->toBe('openai:gpt-6-luna')
        ->and($generic['quote']->completeness)->toBe(CostCompleteness::Unavailable);

    config()->set('librarium.profiles.openai-chat.pricing.model', 'gpt-6-luna-standard-short');
    $tiered = Librarium::query('Research this.')->using('openai-chat')->preview()->pricingQuotes->sole();
    expect($tiered['identity']?->key())->toBe('openai:gpt-6-luna-standard-short')
        ->and($tiered['quote']->completeness)->not->toBe(CostCompleteness::Unavailable)
        ->and($tiered['quote']->source)->toBe(PricingSource::ProviderNative)
        ->and($tiered['quote']->completeness)->toBe(CostCompleteness::Complete)
        ->and($tiered['quote']->missingUnits)->toBe([]);
});

it('ships OpenAI chat with the tiered Luna pricing identity and an unchanged model', function (): void {
    expect(config('librarium.profiles.openai-chat.model'))->toBe('gpt-6-luna')
        ->and(config('librarium.profiles.openai-chat.pricing.model'))->toBe('gpt-6-luna-standard-short');
});
