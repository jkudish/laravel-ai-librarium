<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\GroundedResearchAgent;
use Jkudish\LaravelAiLibrarium\LaravelAiLibrariumServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->preventsExternalRequests()) {
            Http::preventStrayRequests();
            GroundedResearchAgent::fake()->preventStrayPrompts();
        }
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAiLibrariumServiceProvider::class,
            AiServiceProvider::class,
        ];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        if (blank($app['config']->get('ai.providers.gemini.key'))) {
            $app['config']->set('ai.providers.gemini.key', 'testing-gemini-key');
        }
    }

    protected function preventsExternalRequests(): bool
    {
        return true;
    }
}
