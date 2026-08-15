<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\ResearchExecutor;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\ResearchStatus;
use Jkudish\LaravelAiLibrarium\ResearchStatusStore;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeProfileDriver;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeResearchExecutor;

beforeEach(function (): void {
    FakeProfileDriver::$constructorCalls = 0;
    FakeProfileDriver::$validationCalls = 0;
    config()->set('librarium.profiles', [
        'gemini-grounded' => profileConfig(
            provider: 'gemini',
            resultKind: ResultKind::GroundedAnswer,
            estimatedCost: '0.05',
        ),
        'openrouter-grounded' => profileConfig(
            provider: 'openrouter',
            resultKind: ResultKind::GroundedAnswer,
            estimatedCost: '0.10',
        ),
        'deep-report' => profileConfig(
            provider: 'openai',
            resultKind: ResultKind::ResearchReport,
            estimatedCost: '0.25',
        ),
        'disabled-report' => profileConfig(
            provider: 'future',
            resultKind: ResultKind::ResearchReport,
            enabled: false,
            estimatedCost: '0.30',
        ),
    ]);
    config()->set('librarium.groups.custom:focused', ['deep-report', 'disabled-report']);
    app()->singleton(ResearchExecutor::class, fn (): FakeResearchExecutor => new FakeResearchExecutor);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('uses quick by default and exposes unavailable curated members', function (): void {
    $preview = Librarium::query('Compare memory systems.')->preview();

    expect($preview->profiles->pluck('id')->all())->toBe(['gemini-grounded', 'openrouter-grounded'])
        ->and($preview->unavailable->keys()->all())->toBe(['brave-answers', 'exa', 'kagi-fastgpt'])
        ->and($preview->prompts->get('gemini-grounded'))->toBe('Research carefully: Compare memory systems.')
        ->and($preview->maximumEstimatedCost)->toBe('0.15')
        ->and($preview->currency)->toBe('USD')
        ->and($preview->costEstimateComplete)->toBeTrue()
        ->and(FakeProfileDriver::$constructorCalls)->toBe(0)
        ->and(FakeProfileDriver::$validationCalls)->toBeGreaterThan(0)
        ->and(app(ResearchExecutor::class)->plans)->toBe([]);
});

it('resolves curated derived and custom groups', function (string $group, array $expected): void {
    $preview = Librarium::query('Research this.')->group($group)->preview();

    expect($preview->profiles->pluck('id')->all())->toBe($expected);
})->with([
    'deep' => ['deep', ['deep-report']],
    'all' => ['all', ['gemini-grounded', 'openrouter-grounded', 'deep-report']],
    'custom' => ['custom:focused', ['deep-report']],
]);

it('selects exact fan out matching and fallback Profiles', function (): void {
    $preview = Librarium::query('Research this.')
        ->matching(resultKind: ResultKind::GroundedAnswer, corpora: [Corpus::Web])
        ->fallbacks(['deep-report'])
        ->timeout(600)
        ->maxCost('0.40')
        ->preview();

    expect($preview->profiles->pluck('id')->all())->toBe(['gemini-grounded', 'openrouter-grounded'])
        ->and($preview->fallbacks->pluck('id')->all())->toBe(['deep-report'])
        ->and($preview->maximumEstimatedCost)->toBe('0.4');
});

it('rejects conflicting selectors and repeated fallbacks', function (): void {
    expect(fn () => Librarium::query('Research this.')->using('gemini-grounded')->group('deep'))
        ->toThrow(ValidationException::class);

    expect(fn () => Librarium::query('Research this.')
        ->using('gemini-grounded')
        ->fallbacks(['gemini-grounded'])
        ->preview())
        ->toThrow(ValidationException::class);
});

it('rejects unavailable explicit Profiles before execution', function (): void {
    expect(fn () => Librarium::query('Research this.')->using('disabled-report')->preview())
        ->toThrow(ValidationException::class);

    expect(FakeProfileDriver::$constructorCalls)->toBe(0)
        ->and(app(ResearchExecutor::class)->plans)->toBe([]);
});

it('lets the Driver validate bounded options before execution', function (array $options): void {
    config()->set('librarium.profiles.gemini-grounded.options', $options);

    expect(fn () => Librarium::query('Research this.')->using('gemini-grounded')->preview())
        ->toThrow(ValidationException::class);

    expect(FakeProfileDriver::$constructorCalls)->toBe(0)
        ->and(app(ResearchExecutor::class)->plans)->toBe([]);
})->with([
    'unknown option' => [['unexpected' => true]],
    'credential option' => [['access_token' => 'do-not-store-this-here']],
    'provider-prefixed credential' => [['openai_api_key' => 'do-not-store-this-here']],
    'wrapped credential' => [['client_secret_value' => 'do-not-store-this-here']],
    'authorization header' => [['authorization_header' => 'do-not-store-this-here']],
    'nested credential' => [['provider' => ['access_token' => 'do-not-store-this-here']]],
    'invalid known option' => [['temperature' => 9]],
]);

it('allows legitimate token controls in Profile options', function (): void {
    config()->set('librarium.profiles.gemini-grounded.options', ['max_tokens' => 4000]);

    expect(Librarium::query('Research this.')->using('gemini-grounded')->preview()->profiles)
        ->toHaveCount(1);
});

it('rejects Drivers that do not publish Profile option rules', function (): void {
    config()->set('librarium.profiles.gemini-grounded.driver', stdClass::class);
    config()->set('librarium.profiles.gemini-grounded.options', []);

    expect(fn () => Librarium::query('Research this.')->using('gemini-grounded')->preview())
        ->toThrow(ValidationException::class);

    expect(app(ResearchExecutor::class)->plans)->toBe([]);
});

it('rejects malformed configured Profile IDs in derived groups', function (string $id): void {
    $profiles = config('librarium.profiles');
    $profiles[$id] = profileConfig('invalid', ResultKind::ResearchReport);
    config()->set('librarium.profiles', $profiles);

    expect(fn () => Librarium::query('Research this.')->group('all')->preview())
        ->toThrow(ValidationException::class);
})->with(['', '   ', 'not/a/profile']);

it('requires complete estimates for a hard cost ceiling', function (): void {
    config()->set('librarium.profiles.gemini-grounded.estimated_cost', null);

    expect(fn () => Librarium::query('Research this.')
        ->using('gemini-grounded')
        ->maxCost('1.00')
        ->preview())
        ->toThrow(ValidationException::class);
});

it('hands the same compiled plan shape to run and queue', function (): void {
    /** @var FakeResearchExecutor $executor */
    $executor = app(ResearchExecutor::class);

    $run = Librarium::query('Research this.')->using('gemini-grounded')->timeout(500);
    $runResponse = $run->run();
    $queued = Librarium::query('Research this.')->using('gemini-grounded')->timeout(500);
    $queuedResponse = $queued->queue()->onQueue('research')->onConnection('redis')->delay(5)->afterCommit();

    expect($runResponse->requestId)->toBe($run->requestId)
        ->and($queuedResponse)->toBeInstanceOf(QueuedResearchResponse::class)
        ->and($queuedResponse->requestId)->toBe($queued->requestId)
        ->and($executor->plans)->toHaveCount(2)
        ->and($executor->plans[0]->preview->prompts->all())->toBe($executor->plans[1]->preview->prompts->all())
        ->and($executor->plans[0]->timeout)->toBe(500)
        ->and($executor->plans[1]->timeout)->toBe(500);
});

it('stores nullable transient research status', function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');
    $store = new ResearchStatusStore(Cache::store(), 1);
    app()->instance(ResearchStatusStore::class, $store);
    $status = new ResearchStatus(ResearchState::Running, 'Waiting for the provider.', CarbonImmutable::now());

    $store->put('request-1', $status);

    expect(Librarium::status('request-1'))
        ->state->toBe(ResearchState::Running)
        ->progress->toBe('Waiting for the provider.')
        ->and(Librarium::status('missing'))->toBeNull();

    CarbonImmutable::setTestNow('2026-08-15T12:00:02Z');

    expect(Librarium::status('request-1'))->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function profileConfig(
    string $provider,
    ResultKind $resultKind,
    bool $enabled = true,
    ?string $estimatedCost = null,
): array {
    return [
        'driver' => FakeProfileDriver::class,
        'provider' => $provider,
        'model' => 'test-model',
        'result_kind' => $resultKind->value,
        'grounding' => GroundingPolicy::Required->value,
        'observation' => ObservationMode::ApiOutput->value,
        'corpora' => [Corpus::Web->value],
        'retrieval_methods' => [RetrievalMethod::ModelSearchTool->value],
        'prompt' => 'Research carefully: {{ query }}',
        'enabled' => $enabled,
        'options' => ['temperature' => 0.2],
        'estimated_cost' => $estimatedCost === null ? null : [
            'amount' => $estimatedCost,
            'currency' => 'USD',
        ],
    ];
}
