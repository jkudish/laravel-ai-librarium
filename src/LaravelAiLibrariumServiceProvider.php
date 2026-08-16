<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiGroundedDriver;
use Jkudish\LaravelAiLibrarium\Execution\ProfileCompatibility;
use Jkudish\LaravelAiLibrarium\Execution\ResearchRuntime;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;

final class LaravelAiLibrariumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/librarium.php', 'librarium');
        $this->app->booting(function (): void {
            $config = $this->app->make(ConfigRepository::class);
            $profile = $config->get('librarium.profiles.gemini-grounded');

            if (! is_array($profile)
                || data_get($profile, 'driver') !== LaravelAiGroundedDriver::class
                || ! array_key_exists('credential', $profile)
                || filled(data_get($profile, 'credential'))) {
                return;
            }

            $config->set(
                'librarium.profiles.gemini-grounded.credential',
                $config->get('ai.providers.gemini.key'),
            );
        });

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
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/librarium.php' => config_path('librarium.php'),
        ], 'librarium-config');
    }
}
