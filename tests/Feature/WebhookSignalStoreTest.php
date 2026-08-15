<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('records one terminal wake-up signal for an exact bound attempt', function (): void {
    $store = webhookSignalStore();
    $store->bind('openai', 'request-1', 'openai-research', 'provider-job-1');

    expect($store->record('openai', 'provider-job-1', 'delivery-1', ResearchState::Completed))->toBeTrue();

    $signal = $store->get('request-1', 'openai-research');

    expect($signal)
        ->requestId->toBe('request-1')
        ->profile->toBe('openai-research')
        ->provider->toBe('openai')
        ->providerReference->toBe('provider-job-1')
        ->state->toBe(ResearchState::Completed)
        ->receivedAt->toEqual(CarbonImmutable::now())
        ->and($store->get('request-1', 'another-profile'))->toBeNull();
});

it('deduplicates deliveries and never replaces the first terminal signal', function (): void {
    $store = webhookSignalStore();
    $store->bind('openai', 'request-1', 'openai-research', 'provider-job-1');

    expect($store->record('openai', 'provider-job-1', 'delivery-1', ResearchState::Completed))->toBeTrue()
        ->and($store->record('openai', 'provider-job-1', 'delivery-1', ResearchState::Completed))->toBeFalse()
        ->and($store->record('openai', 'provider-job-1', 'delivery-2', ResearchState::Failed))->toBeFalse()
        ->and($store->get('request-1', 'openai-research')->state)->toBe(ResearchState::Completed);
});

it('scopes opaque provider references to the provider', function (): void {
    $store = webhookSignalStore();
    $store->bind('openai', 'request-1', 'openai-research', 'shared-reference');
    $store->bind('firecrawl', 'request-2', 'firecrawl-research', 'shared-reference');

    expect($store->record('openai', 'shared-reference', 'delivery-1', ResearchState::Completed))->toBeTrue()
        ->and($store->record('firecrawl', 'shared-reference', 'delivery-1', ResearchState::Failed))->toBeTrue()
        ->and($store->get('request-1', 'openai-research')?->provider)->toBe('openai')
        ->and($store->get('request-2', 'firecrawl-research')?->provider)->toBe('firecrawl');
});

it('rejects unknown or conflicting provider bindings', function (): void {
    $store = webhookSignalStore();

    expect(fn (): bool => $store->record('openai', 'unknown-job', 'delivery-1', ResearchState::Completed))
        ->toThrow(ValidationException::class);

    $store->bind('openai', 'request-1', 'openai-research', 'provider-job-1');

    expect(fn () => $store->bind('openai', 'request-2', 'another-profile', 'provider-job-1'))
        ->toThrow(LogicException::class);
});

it('accepts only terminal wake-up states', function (ResearchState $state): void {
    $store = webhookSignalStore();
    $store->bind('openai', 'request-1', 'openai-research', 'provider-job-1');

    expect(fn (): bool => $store->record('openai', 'provider-job-1', 'delivery-1', $state))
        ->toThrow(ValidationException::class);
})->with([ResearchState::Queued, ResearchState::Running]);

function webhookSignalStore(): WebhookSignalStore
{
    return new WebhookSignalStore(
        cache: Cache::store(),
        validator: app(ValidationFactory::class),
        ttl: 10800,
    );
}
