<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveAnswersDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveSearchDriver;
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
use Jkudish\LaravelAiLibrarium\Drivers\SerpApi\SerpApiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\SerpBase\SerpBaseDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Tavily\TavilySearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Xai\XaiGrokDriver;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouAnswerDriver;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouResearchGroundedDriver;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LaravelAiLibrariumServiceProvider;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use Jkudish\LaravelAiPricing\LaravelAiPricingServiceProvider;

it('registers the manager behind the package contract', function (): void {
    $contract = app(LibrariumContract::class);

    expect($contract)
        ->toBeInstanceOf(LibrariumManager::class)
        ->toBe(app(LibrariumManager::class));
});

it('marks published OpenRouter Profiles without an explicit search engine unavailable', function (): void {
    config()->set('librarium.profiles.openrouter-grounded.options', []);

    $profile = app(ProfileCatalog::class)->find('openrouter-grounded');

    expect($profile)->not->toBeNull()
        ->and($profile?->reasons)->toContain('OpenRouter web search requires an explicit search_engine option.');
});

it('merges the package configuration', function (): void {
    expect(config('librarium.default_group'))->toBe('quick')
        ->and(config('librarium.timeout'))->toBe(6900)
        ->and(config('librarium.queue.timeout'))->toBe(7200)
        ->and(config('librarium.status_ttl'))->toBe(10800)
        ->and(config('librarium.webhook_signal_ttl'))->toBe(10800)
        ->and(config('librarium.queue.name'))->toBe('research')
        ->and(app(WebhookSignalStore::class))->toBe(app(WebhookSignalStore::class));
});

it('registers its pricing dependency before package discovery can resolve compiler bindings', function (): void {
    $originalContainer = Container::getInstance();

    try {
        $consumer = new Application(dirname(__DIR__, 2));
        $consumer->instance('config', new Repository);

        expect($consumer->providerIsLoaded(LaravelAiPricingServiceProvider::class))->toBeFalse();

        $librariumProvider = $consumer->register(LaravelAiLibrariumServiceProvider::class);
        $pricingProvider = $consumer->getProvider(LaravelAiPricingServiceProvider::class);

        expect($pricingProvider)->toBeInstanceOf(LaravelAiPricingServiceProvider::class)
            ->and($consumer->providerIsLoaded(LaravelAiPricingServiceProvider::class))->toBeTrue()
            ->and($consumer['config']->get('ai-pricing.cache_ttl'))->toBe(86_400)
            ->and($consumer->register(LaravelAiPricingServiceProvider::class))->toBe($pricingProvider)
            ->and($consumer->getProvider(LaravelAiLibrariumServiceProvider::class))->toBe($librariumProvider);
    } finally {
        Container::setInstance($originalContainer);
    }
});

it('resolves Laravel AI credentials after Laravel AI registers later', function (string $profileId, string $provider, string $model): void {
    $profile = config("librarium.profiles.{$profileId}");

    expect($profile)->toBeArray()
        ->and(data_get($profile, 'driver'))->toBe(LaravelAiDriver::class)
        ->and(data_get($profile, 'provider'))->toBe($provider)
        ->and(data_get($profile, 'model'))->toBe($model)
        ->and(config("ai.providers.{$provider}.key"))->toBeString()->not->toBeEmpty()
        ->and(data_get($profile, 'credential'))->toBe(config("ai.providers.{$provider}.key"))
        ->and(data_get($profile, 'credential'))->not->toBe("ai.providers.{$provider}.key");
})->with([
    'Gemini grounded' => ['gemini-grounded', 'gemini', 'gemini-3.6-flash'],
    'OpenRouter grounded' => ['openrouter-grounded', 'openrouter', 'openai/gpt-6-luna'],
    'OpenRouter chat' => ['openrouter-chat', 'openrouter', 'openai/gpt-5.6-terra'],
    'Claude chat' => ['claude-chat', 'anthropic', 'claude-sonnet-5'],
    'OpenAI chat' => ['openai-chat', 'openai', 'gpt-6-luna'],
    'Gemini chat' => ['gemini-chat', 'gemini', 'gemini-3.7-flash'],
]);

it('exposes matching GPT-6 Luna target and pricing bindings through the catalog', function (
    string $profileId,
    string $model,
    string $pricingIdentity,
    array $options,
): void {
    $availability = app(ProfileCatalog::class)->find($profileId);

    expect($availability)->not->toBeNull()
        ->and($availability?->reasons)->toBe([])
        ->and($availability?->profile->model)->toBe($model)
        ->and($availability?->profile->pricingIdentity?->key())->toBe($pricingIdentity)
        ->and($availability?->profile->options)->toBe($options);
})->with([
    'OpenRouter grounded' => ['openrouter-grounded', 'openai/gpt-6-luna', 'openrouter:openai/gpt-6-luna', ['search_engine' => 'exa']],
    'OpenAI chat' => ['openai-chat', 'gpt-6-luna', 'openai:gpt-6-luna-standard-short', ['web_search' => true]],
    'Perplexity frozen low' => ['perplexity-low-frozen', 'openai/gpt-6-luna', 'perplexity:openai/gpt-6-luna', ['configuration' => 'frozen-low-2026-10-03']],
]);

it('marks the retired frozen Perplexity configuration unavailable', function (): void {
    config()->set('librarium.profiles.perplexity-low-frozen.options.configuration', 'frozen-low-2026-09-11');

    $availability = app(ProfileCatalog::class)->find('perplexity-low-frozen');

    expect($availability)->not->toBeNull()
        ->and($availability?->reasons)->not->toBeEmpty();
});

it('keeps the Claude source model separate from its global-routing billing SKU', function (): void {
    $profile = config('librarium.profiles.claude-chat');

    expect(data_get($profile, 'provider'))->toBe('anthropic')
        ->and(data_get($profile, 'model'))->toBe('claude-sonnet-5')
        ->and(data_get($profile, 'pricing.provider'))->toBe('anthropic')
        ->and(data_get($profile, 'pricing.model'))->toBe('claude-sonnet-5-global');
});

it('registers the accepted core HTTP Profiles with resolved credentials', function (
    string $profileId,
    string $driver,
    string $provider,
    ?string $model,
    string $resultKind,
    string $grounding,
    string $retrievalMethod,
    string $credentialPath,
    string $pricingIdentity,
): void {
    $profile = config("librarium.profiles.{$profileId}");

    expect($profile)->toBeArray()
        ->and(data_get($profile, 'driver'))->toBe($driver)
        ->and(data_get($profile, 'provider'))->toBe($provider)
        ->and(data_get($profile, 'model'))->toBe($model)
        ->and(data_get($profile, 'result_kind'))->toBe($resultKind)
        ->and(data_get($profile, 'grounding'))->toBe($grounding)
        ->and(data_get($profile, 'observation'))->toBe('api_output')
        ->and(data_get($profile, 'corpora'))->toBe(['web'])
        ->and(data_get($profile, 'retrieval_methods'))->toBe([$retrievalMethod])
        ->and(data_get($profile, 'credential'))->toBe(config($credentialPath))
        ->and(data_get($profile, 'credential'))->not->toBe($credentialPath)
        ->and(data_get($profile, 'pricing.provider').':'.data_get($profile, 'pricing.model'))->toBe($pricingIdentity)
        ->and(data_get($profile, 'pricing.usage'))->toBeArray()->not->toBeEmpty()
        ->and(data_get($profile, 'pricing.conservative'))->toBeBool();
})->with([
    'Brave Answers' => ['brave-answers', BraveAnswersDriver::class, 'brave', 'brave', 'grounded_answer', 'required', 'search_endpoint', 'services.brave.key', 'brave:answers'],
    'Exa Search' => ['exa', ExaSearchDriver::class, 'exa', null, 'search_results', 'none', 'search_endpoint', 'services.exa.key', 'exa:search'],
    'Kagi FastGPT' => ['kagi-fastgpt', KagiFastGptDriver::class, 'kagi-fastgpt', null, 'grounded_answer', 'required', 'search_endpoint', 'services.kagi.key', 'kagi:fastgpt'],
    'Perplexity Sonar Pro' => ['perplexity-sonar-pro', PerplexityAgentDriver::class, 'perplexity', null, 'grounded_answer', 'required', 'model_search_tool', 'services.perplexity.key', 'perplexity:agent-low'],
    'Perplexity frozen low' => ['perplexity-low-frozen', PerplexityAgentDriver::class, 'perplexity', 'openai/gpt-6-luna', 'grounded_answer', 'required', 'model_search_tool', 'services.perplexity.key', 'perplexity:openai/gpt-6-luna'],
    'Grok web search' => ['grok', XaiGrokDriver::class, 'xai', 'grok-4.6', 'grounded_answer', 'required', 'model_search_tool', 'ai.providers.xai.key', 'xai:grok-4.6'],
    'OpenAI research' => ['openai-research', OpenAiResearchDriver::class, 'openai', 'gpt-5.6-sol', 'research_report', 'required', 'research_agent', 'ai.providers.openai.key', 'openai:gpt-5.6-sol'],
    'Perplexity Sonar Deep' => ['perplexity-sonar-deep', PerplexityAgentDriver::class, 'perplexity', null, 'research_report', 'required', 'research_agent', 'services.perplexity.key', 'perplexity:agent-high'],
]);

it('registers every accepted v2 parity Profile with exact shared semantics', function (
    string $profileId,
    string $driver,
    string $provider,
    ?string $model,
    string $resultKind,
    string $grounding,
    array $corpora,
    string $retrievalMethod,
    string $credentialPath,
    ?string $pricingIdentity,
): void {
    $profile = config("librarium.profiles.{$profileId}");

    expect($profile)->toBeArray()
        ->and(data_get($profile, 'driver'))->toBe($driver)
        ->and(app($driver))->toBeInstanceOf($driver)
        ->and(data_get($profile, 'provider'))->toBe($provider)
        ->and(data_get($profile, 'model'))->toBe($model)
        ->and(data_get($profile, 'result_kind'))->toBe($resultKind)
        ->and(data_get($profile, 'grounding'))->toBe($grounding)
        ->and(data_get($profile, 'observation'))->toBe('api_output')
        ->and(data_get($profile, 'corpora'))->toBe($corpora)
        ->and(data_get($profile, 'retrieval_methods'))->toBe([$retrievalMethod])
        ->and(data_get($profile, 'credential'))->toBe(config($credentialPath))
        ->and(data_get($profile, 'credential'))->not->toBe($credentialPath);

    $actualPricingIdentity = data_get($profile, 'pricing') === null
        ? null
        : data_get($profile, 'pricing.provider').':'.data_get($profile, 'pricing.model');

    expect($actualPricingIdentity)->toBe($pricingIdentity);
})->with([
    'Brave Search' => ['brave-search', BraveSearchDriver::class, 'brave-search', null, 'search_results', 'none', ['web'], 'search_endpoint', 'services.brave.key', 'brave:search'],
    'Tavily Search' => ['tavily-search', TavilySearchDriver::class, 'tavily', null, 'search_results', 'none', ['web'], 'search_endpoint', 'services.tavily.key', 'tavily:search'],
    'Perplexity Search' => ['perplexity-search', PerplexitySearchDriver::class, 'perplexity', null, 'search_results', 'none', ['web'], 'search_endpoint', 'services.perplexity.key', 'perplexity:search'],
    'Jina Search' => ['jina-search', JinaSearchDriver::class, 'jina-search', null, 'search_results', 'none', ['web'], 'search_endpoint', 'services.jina.key', null],
    'SerpAPI Search' => ['serpapi', SerpApiDriver::class, 'serpapi', null, 'search_results', 'none', ['web'], 'search_endpoint', 'services.serpapi.key', 'serpapi:search'],
    'SerpBase Search' => ['serpbase-search', SerpBaseDriver::class, 'serpbase', null, 'search_results', 'none', ['web'], 'search_endpoint', 'services.serpbase.key', 'serpbase:search'],
    'SerpBase News' => ['serpbase-news', SerpBaseDriver::class, 'serpbase', null, 'search_results', 'none', ['news'], 'search_endpoint', 'services.serpbase.key', 'serpbase:news'],
    'Exa Research' => ['exa-research', ExaResearchDriver::class, 'exa', null, 'research_report', 'required', ['web'], 'research_agent', 'services.exa.key', null],
    'Gemini Deep Research' => ['gemini-deep-research', GeminiDeepDriver::class, 'gemini-deep', 'deep-research-preview-04-2026', 'research_report', 'required', ['web'], 'research_agent', 'ai.providers.gemini.key', null],
    'Perplexity Deep Research' => ['perplexity-deep-research', PerplexityAgentDriver::class, 'perplexity', null, 'research_report', 'required', ['web'], 'research_agent', 'services.perplexity.key', 'perplexity:agent-medium'],
    'Grok X-only' => ['grok-x-only', XaiGrokDriver::class, 'xai', 'grok-4.6', 'grounded_answer', 'required', ['x'], 'model_search_tool', 'ai.providers.xai.key', null],
    'Grok combined' => ['grok-combined', XaiGrokDriver::class, 'xai', 'grok-4.6', 'grounded_answer', 'required', ['web', 'x'], 'model_search_tool', 'ai.providers.xai.key', null],
    'Parallel Search' => ['parallel-search', ParallelSearchDriver::class, 'parallel', null, 'search_results', 'none', ['web'], 'search_endpoint', 'services.parallel.key', null],
    'Parallel Turbo' => ['parallel-turbo', ParallelTurboDriver::class, 'parallel', null, 'search_results', 'none', ['web'], 'search_endpoint', 'services.parallel.key', 'parallel:turbo'],
    'Parallel Research' => ['parallel-research', ParallelResearchDriver::class, 'parallel', 'pro', 'research_report', 'required', ['web'], 'research_agent', 'services.parallel.key', 'parallel:research-pro'],
    'Valyu Search' => ['valyu-search', ValyuSearchDriver::class, 'valyu', null, 'search_results', 'none', ['web', 'specialized'], 'search_endpoint', 'services.valyu.key', null],
    'Valyu Research' => ['valyu-research', ValyuResearchDriver::class, 'valyu', null, 'research_report', 'required', ['web', 'specialized'], 'research_agent', 'services.valyu.key', 'valyu:research-standard'],
]);

it('registers three distinct You.com Profiles with one compatible credential value', function (): void {
    config()->set('librarium.profiles.you-answer.credential', 'testing-you-key');
    config()->set('librarium.profiles.you-research-grounded.credential', 'testing-you-key');
    config()->set('librarium.profiles.you-research-research.credential', 'testing-you-key');

    expect(config('librarium.profiles.you-answer.driver'))->toBe(YouAnswerDriver::class)
        ->and(config('librarium.profiles.you-answer.provider'))->toBe('you-answer')
        ->and(config('librarium.profiles.you-answer.pricing'))->toMatchArray(['provider' => 'you', 'model' => 'answer', 'usage' => ['requests' => 1], 'conservative' => true])
        ->and(config('librarium.profiles.you-research-grounded.driver'))->toBe(YouResearchGroundedDriver::class)
        ->and(config('librarium.profiles.you-research-grounded.provider'))->toBe('you-research')
        ->and(config('librarium.profiles.you-research-grounded.result_kind'))->toBe('grounded_answer')
        ->and(config('librarium.profiles.you-research-grounded.retrieval_methods'))->toBe(['search_endpoint'])
        ->and(config('librarium.profiles.you-research-grounded.options'))->toBe(['research_effort' => 'standard'])
        ->and(config('librarium.profiles.you-research-grounded.pricing'))->toMatchArray(['provider' => 'you', 'model' => 'research-standard', 'usage' => ['requests' => 1], 'conservative' => true])
        ->and(config('librarium.profiles.you-research-research.driver'))->toBe(YouResearchDriver::class)
        ->and(config('librarium.profiles.you-research-research.provider'))->toBe('you-research')
        ->and(config('librarium.profiles.you-research-research.result_kind'))->toBe('research_report')
        ->and(config('librarium.profiles.you-research-research.retrieval_methods'))->toBe(['research_agent'])
        ->and(config('librarium.profiles.you-research-research.options'))->toBe(['research_effort' => 'standard'])
        ->and(config('librarium.profiles.you-research-research.pricing'))->toMatchArray(['provider' => 'you', 'model' => 'research-standard', 'usage' => ['requests' => 1], 'conservative' => true])
        ->and(config('librarium.profiles.you-answer.credential'))->toBe(config('librarium.profiles.you-research-grounded.credential'))
        ->toBe(config('librarium.profiles.you-research-research.credential'));
});

it('resolves curated and derived groups from the complete built-in catalog', function (): void {
    $catalog = app(ProfileCatalog::class);

    expect($catalog->group('quick')['profiles']->pluck('id')->all())->toBe([
        'gemini-grounded',
        'openrouter-grounded',
        'brave-answers',
        'exa',
        'kagi-fastgpt',
        'parallel-turbo',
    ])->and($catalog->group('visibility')['profiles']->pluck('id')->all())->toBe([
        'searchapi-chatgpt',
        'searchapi-gemini',
        'searchapi-perplexity',
        'searchapi-google-ai-mode',
        'searchapi-bing-copilot',
        'searchapi-google-ai-overview',
        'perplexity-sonar-pro',
        'gemini-grounded',
        'grok',
    ])->and($catalog->group('deep')['profiles']->pluck('id')->all())->toBe([
        'you-research-research',
        'openai-research',
        'perplexity-sonar-deep',
        'exa-research',
        'gemini-deep-research',
        'perplexity-deep-research',
        'parallel-research',
        'valyu-research',
    ])->and($catalog->group('all')['profiles']->pluck('id')->all())->toBe([
        'gemini-grounded',
        'openrouter-grounded',
        'openrouter-chat',
        'claude-chat',
        'openai-chat',
        'gemini-chat',
        'brave-answers',
        'exa',
        'kagi-fastgpt',
        'you-answer',
        'you-research-grounded',
        'you-research-research',
        'perplexity-sonar-pro',
        'perplexity-low-frozen',
        'grok',
        'openai-research',
        'perplexity-sonar-deep',
        'searchapi',
        'searchapi-chatgpt',
        'searchapi-gemini',
        'searchapi-perplexity',
        'searchapi-google-ai-mode',
        'searchapi-bing-copilot',
        'searchapi-google-ai-overview',
        'brave-search',
        'tavily-search',
        'perplexity-search',
        'jina-search',
        'serpapi',
        'serpbase-search',
        'serpbase-news',
        'exa-research',
        'gemini-deep-research',
        'perplexity-deep-research',
        'grok-x-only',
        'grok-combined',
        'parallel-search',
        'parallel-turbo',
        'parallel-research',
        'valyu-search',
        'valyu-research',
    ]);

    expect($catalog->all()->keys()->all())->toHaveCount(47)
        ->and(array_keys($catalog->group('all')['unavailable']))->toBe([
            'dataforseo-chatgpt',
            'dataforseo-chatgpt-live',
            'dataforseo-gemini',
            'dataforseo-gemini-live',
            'dataforseo-google-ai-mode',
            'dataforseo-google-ai-mode-live',
        ]);
});

it('resolves Laravel AI credentials for Profile IDs containing dots', function (): void {
    $profiles = config('librarium.profiles');
    $profiles['custom.chat'] = [
        ...$profiles['gemini-chat'],
        'credential' => null,
    ];
    config()->set('librarium.profiles', $profiles);

    (new LaravelAiLibrariumServiceProvider(app()))->boot();

    $resolved = config('librarium.profiles');

    expect($resolved['custom.chat']['credential'])->toBe(config('ai.providers.gemini.key'))
        ->and($resolved)->not->toHaveKey('custom');
});

it('starts a research query through the facade', function (): void {
    $research = Librarium::query('Compare current agent memory architectures.');

    expect($research)
        ->toBeInstanceOf(PendingResearch::class)
        ->input->toBe('Compare current agent memory architectures.');
});

it('rejects empty input through the facade', function (string $input): void {
    expect(fn (): PendingResearch => Librarium::query($input))
        ->toThrow(ValidationException::class);
})->with(['', '   ', "\n\t"]);
