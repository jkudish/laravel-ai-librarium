<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;

it('runs one explicitly approved provider-parity canary', function (
    string $profile,
    string $credentialEnvironment,
    string $credentialConfig,
    string $provider,
    ResultKind $resultKind,
    int $timeout,
    string $submissionMethod,
    string $submissionUrl,
): void {
    if (getenv('LIBRARIUM_LIVE_PROFILE') !== $profile) {
        $this->markTestSkipped("Set LIBRARIUM_LIVE_PROFILE={$profile} and run only this dataset.");
    }

    $credential = getenv($credentialEnvironment);
    if (! is_string($credential) || trim($credential) === '') {
        $this->markTestSkipped("Set {$credentialEnvironment} to run [{$profile}].");
    }

    $maximumUsd = getenv('LIBRARIUM_LIVE_MAX_USD');
    if (! is_string($maximumUsd) || preg_match('/^(?:0\.(?:0*[1-9]\d*)|[1-9]\d*(?:\.\d+)?)$/D', $maximumUsd) !== 1) {
        $this->markTestSkipped('Set LIBRARIUM_LIVE_MAX_USD to an explicit positive per-canary dollar cap.');
    }

    config()->set($credentialConfig, trim($credential));
    config()->set("librarium.profiles.{$profile}.credential", trim($credential));
    Http::record();

    $query = match ($profile) {
        'grok-x-only' => 'In one short paragraph, summarize a recent post from the official Laravel X account (@laravelphp) and cite that X post.',
        default => 'In one short paragraph, identify the latest stable Laravel major version and cite official documentation.',
    };
    $pending = Librarium::query($query)->using($profile)->timeout($timeout);
    $preview = $pending->preview();

    if ($preview->preflightCostCheckAvailable) {
        $pending->maxCost($maximumUsd);
    } elseif (getenv('LIBRARIUM_LIVE_UNPRICED_ACK') !== "external-cap:{$profile}:USD:{$maximumUsd}") {
        $this->markTestSkipped("This Profile has no conservative pre-dispatch estimate. Set LIBRARIUM_LIVE_UNPRICED_ACK=external-cap:{$profile}:USD:{$maximumUsd} only after applying that provider/account cap externally.");
    }

    $exactSubmissionUrl = match ($profile) {
        'brave-search' => $submissionUrl.'?'.http_build_query(['q' => $query], '', '&', PHP_QUERY_RFC3986),
        'serpapi' => $submissionUrl.'?'.http_build_query([
            'engine' => 'google',
            'q' => $query,
            'api_key' => trim($credential),
        ], '', '&', PHP_QUERY_RFC3986),
        default => $submissionUrl,
    };

    $response = $pending->run();
    $submissions = Http::recorded(fn (Request $request): bool => $request->method() === $submissionMethod
        && $request->url() === $exactSubmissionUrl);

    expect($submissions)->toHaveCount(1);

    if ($response->status !== ResponseStatus::Succeeded) {
        $errors = $response->errors
            ->map(fn ($error): string => "{$error->code}: {$error->message}")
            ->implode('; ');

        $this->fail("Profile [{$profile}] failed: {$errors}");
    }

    $result = $response->results->sole();

    expect($result->profile)->toBe($profile)
        ->and($result->provider)->toBe($provider)
        ->and($result->provenance->resultKind)->toBe($resultKind)
        ->and($result->content)->toBeString()->not->toBeEmpty()
        ->and($result->citations)->not->toBeEmpty();
})->with([
    'Brave Search' => ['brave-search', 'BRAVE_API_KEY', 'services.brave.key', 'brave-search', ResultKind::SearchResults, 120, 'GET', 'https://api.search.brave.com/res/v1/web/search'],
    'Tavily Search' => ['tavily-search', 'TAVILY_API_KEY', 'services.tavily.key', 'tavily', ResultKind::SearchResults, 120, 'POST', 'https://api.tavily.com/search'],
    'Perplexity Search' => ['perplexity-search', 'PERPLEXITY_API_KEY', 'services.perplexity.key', 'perplexity', ResultKind::SearchResults, 120, 'POST', 'https://api.perplexity.ai/search'],
    'Jina Search' => ['jina-search', 'JINA_API_KEY', 'services.jina.key', 'jina-search', ResultKind::SearchResults, 120, 'POST', 'https://s.jina.ai/'],
    'SerpAPI Search' => ['serpapi', 'SERPAPI_API_KEY', 'services.serpapi.key', 'serpapi', ResultKind::SearchResults, 120, 'GET', 'https://serpapi.com/search'],
    'SerpBase Search' => ['serpbase-search', 'SERPBASE_API_KEY', 'services.serpbase.key', 'serpbase', ResultKind::SearchResults, 120, 'POST', 'https://api.serpbase.dev/google/search'],
    'SerpBase News' => ['serpbase-news', 'SERPBASE_API_KEY', 'services.serpbase.key', 'serpbase', ResultKind::SearchResults, 120, 'POST', 'https://api.serpbase.dev/google/news'],
    'Exa Research' => ['exa-research', 'EXA_API_KEY', 'services.exa.key', 'exa', ResultKind::ResearchReport, 3600, 'POST', 'https://api.exa.ai/agent/runs'],
    'Gemini Deep Research' => ['gemini-deep-research', 'GEMINI_API_KEY', 'ai.providers.gemini.key', 'gemini-deep', ResultKind::ResearchReport, 3600, 'POST', 'https://generativelanguage.googleapis.com/v1beta/interactions'],
    'Perplexity Deep Research' => ['perplexity-deep-research', 'PERPLEXITY_API_KEY', 'services.perplexity.key', 'perplexity', ResultKind::ResearchReport, 3600, 'POST', 'https://api.perplexity.ai/v1/agent'],
    'Perplexity frozen low' => ['perplexity-low-frozen', 'PERPLEXITY_API_KEY', 'services.perplexity.key', 'perplexity', ResultKind::GroundedAnswer, 180, 'POST', 'https://api.perplexity.ai/v1/agent'],
    'Grok X-only' => ['grok-x-only', 'XAI_API_KEY', 'ai.providers.xai.key', 'xai', ResultKind::GroundedAnswer, 180, 'POST', 'https://api.x.ai/v1/responses'],
    'Grok combined' => ['grok-combined', 'XAI_API_KEY', 'ai.providers.xai.key', 'xai', ResultKind::GroundedAnswer, 180, 'POST', 'https://api.x.ai/v1/responses'],
    'Parallel Search' => ['parallel-search', 'PARALLEL_API_KEY', 'services.parallel.key', 'parallel', ResultKind::SearchResults, 120, 'POST', 'https://api.parallel.ai/v1/search'],
    'Parallel Turbo' => ['parallel-turbo', 'PARALLEL_API_KEY', 'services.parallel.key', 'parallel', ResultKind::SearchResults, 120, 'POST', 'https://api.parallel.ai/v1/search'],
    'Parallel Research' => ['parallel-research', 'PARALLEL_API_KEY', 'services.parallel.key', 'parallel', ResultKind::ResearchReport, 3600, 'POST', 'https://api.parallel.ai/v1/tasks/runs'],
    'Valyu Search' => ['valyu-search', 'VALYU_API_KEY', 'services.valyu.key', 'valyu', ResultKind::SearchResults, 120, 'POST', 'https://api.valyu.ai/v1/search'],
    'Valyu Research' => ['valyu-research', 'VALYU_API_KEY', 'services.valyu.key', 'valyu', ResultKind::ResearchReport, 3600, 'POST', 'https://api.valyu.ai/v1/deepresearch/tasks'],
]);
