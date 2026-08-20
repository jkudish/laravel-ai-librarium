<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Drivers\OpenAi\OpenAiResearchDriver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

it('runs one live OpenAI background research request', function (): void {
    $credential = getenv('OPENAI_API_KEY');
    if (! is_string($credential) || trim($credential) === '') {
        $this->markTestSkipped('Set OPENAI_API_KEY to run the live OpenAI research canary.');
    }
    $credential = trim($credential);

    $configuredModel = getenv('OPENAI_RESEARCH_MODEL');
    $model = is_string($configuredModel) && trim($configuredModel) !== ''
        ? trim($configuredModel)
        : 'gpt-5.6-sol';
    $profile = new Profile(
        id: 'openai-research',
        driver: OpenAiResearchDriver::class,
        provider: 'openai',
        model: $model,
        resultKind: ResultKind::ResearchReport,
        grounding: GroundingPolicy::Required,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::ResearchAgent]),
        prompt: '{{ query }}',
        enabled: true,
        options: [],
        credential: $credential,
    );
    $request = new DriverRequest(
        requestId: (string) Str::uuid(),
        requestedProfile: $profile,
        profile: $profile,
        prompt: 'In one short paragraph, identify the latest stable Laravel major version and cite the official Laravel documentation.',
        deadline: CarbonImmutable::now()->addMinutes(30),
        progressCallback: static function (string $message): void {},
    );

    $result = app(OpenAiResearchDriver::class)->run($request);

    expect($result->provider)->toBe('openai')
        ->and($result->profile)->toBe('openai-research')
        ->and($result->model)->toBeString()->not->toBeEmpty()
        ->and($result->content)->toBeString()->not->toBeEmpty()
        ->and($result->provenance->resultKind)->toBe(ResultKind::ResearchReport)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ResearchAgent])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->citations)->not->toBeEmpty();
});
