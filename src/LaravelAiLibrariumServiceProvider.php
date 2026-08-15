<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Support\ServiceProvider;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\Execution\UnconfiguredResearchExecutor;

final class LaravelAiLibrariumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/librarium.php', 'librarium');

        $this->app->singleton(LibrariumManager::class);
        $this->app->singleton(ProfileCatalog::class);
        $this->app->singleton(ResearchCompiler::class);
        $this->app->singleton(ExecutesResearch::class, UnconfiguredResearchExecutor::class);
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
