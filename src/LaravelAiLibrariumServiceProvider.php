<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Jkudish\LaravelAiLibrarium\Console\Commands\PruneCommand;
use Jkudish\LaravelAiLibrarium\Console\Commands\ResearchCommand;
use Jkudish\LaravelAiLibrarium\Console\Commands\ShowCommand;
use Jkudish\LaravelAiLibrarium\Console\Commands\SourcesCommand;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\Execution\ProfileCompatibility;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\LaravelAiPricingServiceProvider as PricingServiceProvider;
use Jkudish\LaravelAiPricing\PricingResolver;
use Jkudish\LaravelAiPricing\Sources\ConfiguredPricingSource;
use Jkudish\LaravelAiPricing\Sources\OpenRouterPricingSource;
use Jkudish\LaravelAiPricing\Sources\PackagePricingSource;
use Jkudish\LaravelAiPricing\Sources\PortkeyPricingSource;
use LogicException;

final class LaravelAiLibrariumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Discovery order is not guaranteed; source admission uses pricing.
        $this->app->register(PricingServiceProvider::class);

        $this->mergeConfigFrom(__DIR__.'/../config/librarium.php', 'librarium');

        $this->app->singleton(LibrariumManager::class);
        $this->app->singleton(SourceCatalog::class);
        $this->app->singleton(ProfileCatalog::class);
        $this->app->singleton(ProfileCompatibility::class);
        $this->app->when(LibrariumManager::class)
            ->needs(CostResolver::class)
            ->give(fn (): CostResolver => $this->offlineCostResolver());
        $this->app->singleton(
            WebhookSignalStore::class,
            function (): WebhookSignalStore {
                $ttl = config('librarium.webhook_signal_ttl', 10800);

                return new WebhookSignalStore(
                    cache: $this->app->make('cache.store'),
                    validator: $this->app->make('validator'),
                    ttl: is_int($ttl) ? $ttl : 10800,
                );
            },
        );
        $this->app->singleton(
            Librarium::class,
            fn (): Librarium => $this->app->make(LibrariumManager::class),
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ResearchCommand::class,
            ShowCommand::class,
            SourcesCommand::class,
            PruneCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/librarium.php' => config_path('librarium.php'),
        ], 'librarium-config');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_librarium_runs_table.php' => database_path('migrations/create_librarium_runs_table.php'),
        ], 'librarium-migrations');
    }

    private function offlineCostResolver(): CostResolver
    {
        $store = config('ai-pricing.cache_store');
        if ($store !== null && ! is_string($store)) {
            throw new LogicException('Configuration [ai-pricing.cache_store] must be null or a string.');
        }

        $cache = $this->app->make(CacheManager::class)->store($store);
        $http = $this->app->make(HttpFactory::class);
        $ttl = $this->pricingInteger('ai-pricing.cache_ttl');

        return new PricingResolver(
            configured: $this->app->make(ConfiguredPricingSource::class),
            native: new OpenRouterPricingSource(
                http: $http,
                cache: $cache,
                endpoint: $this->pricingString('ai-pricing.openrouter.endpoint'),
                ttlSeconds: $ttl,
                offline: true,
            ),
            fallback: new PortkeyPricingSource(
                http: $http,
                cache: $cache,
                endpoint: $this->pricingString('ai-pricing.portkey.endpoint'),
                ttlSeconds: $ttl,
                offline: true,
            ),
            currency: $this->pricingString('ai-pricing.currency'),
            snapshot: $this->app->make(PackagePricingSource::class),
        );
    }

    private function pricingString(string $key): string
    {
        $value = config($key);

        if (! is_string($value)) {
            throw new LogicException("Configuration [{$key}] must be a string.");
        }

        return $value;
    }

    private function pricingInteger(string $key): int
    {
        $value = config($key);

        if (! is_int($value)) {
            throw new LogicException("Configuration [{$key}] must be an integer.");
        }

        return $value;
    }
}
