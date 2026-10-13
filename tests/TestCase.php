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
        $app['config']->set('ai.providers.gemini.key', 'testing-gemini-key');

        foreach (['anthropic', 'openai', 'openrouter', 'xai'] as $provider) {
            $app['config']->set("ai.providers.{$provider}.key", "testing-{$provider}-key");
        }

        foreach (['brave', 'exa', 'jina', 'kagi', 'parallel', 'perplexity', 'searchapi', 'serpapi', 'serpbase', 'tavily', 'valyu', 'you'] as $provider) {
            $app['config']->set("services.{$provider}.key", "testing-{$provider}-key");
        }

        // Direct driver tests exercise the private variant bindings, not the
        // application-facing source selection API.
        $bindings = require __DIR__.'/../src/Profiles/bindings.php';
        $app['config']->set('librarium.profiles', $bindings['profiles']);

        foreach (['you-answer', 'you-research-grounded', 'you-research-research'] as $profile) {
            $app['config']->set("librarium.profiles.{$profile}.credential", 'testing-you-key');
        }
    }

    protected function preventsExternalRequests(): bool
    {
        return true;
    }
}
