<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Support\ServiceProvider;
use Jkudish\LaravelAiLibrarium\Console\Commands\ProfilesCommand;
use Jkudish\LaravelAiLibrarium\Console\Commands\QueryCommand;
use Jkudish\LaravelAiLibrarium\Console\Commands\StatusCommand;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveAnswersDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Exa\ExaSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Kagi\KagiFastGptDriver;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\OpenAi\OpenAiResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Perplexity\PerplexityAgentDriver;
use Jkudish\LaravelAiLibrarium\Drivers\SearchApi\SearchApiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Xai\XaiGrokDriver;
use Jkudish\LaravelAiLibrarium\Execution\ProfileCompatibility;
use Jkudish\LaravelAiLibrarium\Execution\ResearchRuntime;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;

final class LaravelAiLibrariumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/librarium.php', 'librarium');

        $this->app->singleton(LibrariumManager::class);
        $this->app->singleton(ProfileCatalog::class);
        $this->app->singleton(ProfileCompatibility::class);
        $this->app->singleton(ResearchCompiler::class);
        $this->app->singleton(ExecutesResearch::class, ResearchRuntime::class);
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
                BraveAnswersDriver::class => config('services.brave.key'),
                ExaSearchDriver::class => config('services.exa.key'),
                KagiFastGptDriver::class => config('services.kagi.key'),
                PerplexityAgentDriver::class => config('services.perplexity.key'),
                SearchApiDriver::class => config('services.searchapi.key'),
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
}
