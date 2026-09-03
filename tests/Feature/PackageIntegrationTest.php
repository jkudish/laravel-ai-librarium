<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveAnswersDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Exa\ExaSearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Kagi\KagiFastGptDriver;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\OpenAi\OpenAiResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Perplexity\PerplexityAgentDriver;
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

it('registers the manager behind the package contract', function (): void {
    $contract = app(LibrariumContract::class);

    expect($contract)
        ->toBeInstanceOf(LibrariumManager::class)
        ->toBe(app(LibrariumManager::class));
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
    'OpenRouter grounded' => ['openrouter-grounded', 'openrouter', 'openai/gpt-5.6-luna'],
    'OpenRouter chat' => ['openrouter-chat', 'openrouter', 'openai/gpt-5.6-terra'],
    'Claude chat' => ['claude-chat', 'anthropic', 'claude-sonnet-5'],
    'OpenAI chat' => ['openai-chat', 'openai', 'gpt-5.6-luna'],
    'Gemini chat' => ['gemini-chat', 'gemini', 'gemini-3.7-flash'],
]);

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
    'Grok web search' => ['grok', XaiGrokDriver::class, 'xai', 'grok-4.6', 'grounded_answer', 'required', 'model_search_tool', 'ai.providers.xai.key', 'xai:grok-4.6'],
    'OpenAI research' => ['openai-research', OpenAiResearchDriver::class, 'openai', 'gpt-5.6-sol', 'research_report', 'required', 'research_agent', 'ai.providers.openai.key', 'openai:gpt-5.6-sol'],
    'Perplexity Sonar Deep' => ['perplexity-sonar-deep', PerplexityAgentDriver::class, 'perplexity', null, 'research_report', 'required', 'research_agent', 'services.perplexity.key', 'perplexity:agent-high'],
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
