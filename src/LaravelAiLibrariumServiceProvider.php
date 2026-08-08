<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Support\ServiceProvider;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;

final class LaravelAiLibrariumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/librarium.php', 'librarium');

        $this->app->singleton(LibrariumManager::class);
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
