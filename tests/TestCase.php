<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchAgent;
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
            ResearchAgent::fake()->preventStrayPrompts();
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

        foreach (['anthropic', 'openai', 'openrouter', 'xai'] as $provider) {
            if (blank($app['config']->get("ai.providers.{$provider}.key"))) {
                $app['config']->set("ai.providers.{$provider}.key", "testing-{$provider}-key");
            }
        }

        foreach (['brave', 'exa', 'kagi', 'perplexity', 'searchapi'] as $provider) {
            if (blank($app['config']->get("services.{$provider}.key"))) {
                $app['config']->set("services.{$provider}.key", "testing-{$provider}-key");
            }
        }
    }

    protected function preventsExternalRequests(): bool
    {
        return true;
    }
}
