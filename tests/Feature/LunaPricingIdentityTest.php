<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\Facades\AiPricing;
use Jkudish\LaravelAiPricing\Sources\PortkeyPricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

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
    $usage = new Usage(['input_tokens' => 100, 'output_tokens' => 200, 'web_searches' => 1]);
    $control = AiPricing::quote('openai', 'o3', $usage);
    expect($control->source)->toBe(PricingSource::Portkey);

    $generic = AiPricing::quote('openai', 'gpt-6-luna', $usage);
    expect($generic->completeness)->toBe(CostCompleteness::Unavailable);

    $tiered = AiPricing::quote('openai', 'gpt-6-luna-standard-short', $usage);
    expect($tiered->source)->toBe(PricingSource::ProviderNative)
        ->and($tiered->completeness)->toBe(CostCompleteness::Complete)
        ->and($tiered->missingUnits)->toBe([]);
});

it('ships OpenAI chat with the tiered Luna pricing identity and an unchanged model', function (): void {
    expect(config('librarium.profiles.openai-chat.model'))->toBe('gpt-6-luna')
        ->and(config('librarium.profiles.openai-chat.pricing.model'))->toBe('gpt-6-luna-standard-short');
});
