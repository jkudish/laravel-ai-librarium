<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Jkudish\LaravelAiLibrarium\Console\Commands\ProfilesCommand;
use Jkudish\LaravelAiLibrarium\Console\Commands\QueryCommand;
use Jkudish\LaravelAiLibrarium\Console\Commands\StatusCommand;
use Jkudish\LaravelAiLibrarium\Contracts\CollectsResearch;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\Contracts\RecoversResearch;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveAnswersDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoCredential;
use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Exa\ExaResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Exa\ExaSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Gemini\GeminiDeepDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Jina\JinaSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Kagi\KagiFastGptDriver;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\OpenAi\OpenAiResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Parallel\ParallelResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Parallel\ParallelSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Parallel\ParallelTurboDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Perplexity\PerplexityAgentDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Perplexity\PerplexitySearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\SearchApi\SearchApiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\SerpApi\SerpApiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\SerpBase\SerpBaseDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Tavily\TavilySearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Xai\XaiGrokDriver;
use Jkudish\LaravelAiLibrarium\Execution\ProfileCompatibility;
use Jkudish\LaravelAiLibrarium\Execution\ResearchRuntime;
use Jkudish\LaravelAiLibrarium\Execution\SpendingBoundResolver;
use Jkudish\LaravelAiLibrarium\Recovery\DurableResearchRuntime;
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
        // Discovery order is not guaranteed, but Librarium's compiler requires
        // pricing configuration while its contextual resolver is constructed.
        $this->app->register(PricingServiceProvider::class);

        $this->mergeConfigFrom(__DIR__.'/../config/librarium.php', 'librarium');

        $this->app->singleton(LibrariumManager::class);
        $this->app->singleton(ProfileCatalog::class);
        $this->app->singleton(ProfileCompatibility::class);
        $this->app->singleton(ResearchCompiler::class);
        $this->app->singleton(SpendingBoundResolver::class);
        $this->app->when(ResearchCompiler::class)
            ->needs(CostResolver::class)
            ->give(fn (): CostResolver => $this->offlineCostResolver());
        $this->app->singleton(ExecutesResearch::class, ResearchRuntime::class);
        $this->app->singleton(CollectsResearch::class, ResearchRuntime::class);
        $this->app->singleton(RecoversResearch::class, DurableResearchRuntime::class);
        $this->app->singleton(
            ResearchStatusStore::class,
            function (): ResearchStatusStore {
                $ttl = config('librarium.status_ttl', 10800);

                return new ResearchStatusStore(
                    cache: $this->app->make('cache.store'),
                    ttl: is_int($ttl) ? $ttl : 10800,
                );
            },
        );
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
        $this->resolveProfileCredentials();
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            QueryCommand::class,
            StatusCommand::class,
            ProfilesCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/librarium.php' => config_path('librarium.php'),
        ], 'librarium-config');
    }

    private function resolveProfileCredentials(): void
    {
        $profiles = config('librarium.profiles', []);
        if (! is_array($profiles)) {
            return;
        }

        $changed = false;

        foreach ($profiles as $id => $profile) {
            if (! is_string($id)
                || ! is_array($profile)
                || ! array_key_exists('credential', $profile)
                || filled(data_get($profile, 'credential'))) {
                continue;
            }

            $driver = data_get($profile, 'driver');
            $provider = data_get($profile, 'provider');
            if (! is_string($driver) || ! is_string($provider) || $provider === '') {
                continue;
            }

            $credential = match ($driver) {
                LaravelAiDriver::class, OpenAiResearchDriver::class, XaiGrokDriver::class => config("ai.providers.{$provider}.key"),
                BraveAnswersDriver::class, BraveSearchDriver::class => config('services.brave.key'),
                DataForSeoDriver::class => DataForSeoCredential::encode(
                    config('librarium.providers.dataforseo.login'),
                    config('librarium.providers.dataforseo.password'),
                ),
                ExaResearchDriver::class, ExaSearchDriver::class => config('services.exa.key'),
                GeminiDeepDriver::class => config('ai.providers.gemini.key'),
                JinaSearchDriver::class => config('services.jina.key'),
                KagiFastGptDriver::class => config('services.kagi.key'),
                ParallelResearchDriver::class, ParallelSearchDriver::class, ParallelTurboDriver::class => config('services.parallel.key'),
                PerplexityAgentDriver::class, PerplexitySearchDriver::class => config('services.perplexity.key'),
                SearchApiDriver::class => config('services.searchapi.key'),
                SerpApiDriver::class => config('services.serpapi.key'),
                SerpBaseDriver::class => config('services.serpbase.key'),
                TavilySearchDriver::class => config('services.tavily.key'),
                ValyuResearchDriver::class, ValyuSearchDriver::class => config('services.valyu.key'),
                default => null,
            };

            $profile['credential'] = $credential;
            $profiles[$id] = $profile;
            $changed = true;
        }

        if ($changed) {
            config()->set('librarium.profiles', $profiles);
        }
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
