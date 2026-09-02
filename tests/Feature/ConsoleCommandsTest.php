<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Console\Commands\LibrariumCommand;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\ResearchStatus;
use Jkudish\LaravelAiLibrarium\ResearchStatusStore;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeProfileDriver;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config()->set('librarium.default_group', 'quick');
    config()->set('librarium.profiles', [
        'eligible' => consoleProfileConfig('provider-a', '0.05'),
        'backup' => consoleProfileConfig('provider-b', '0.02'),
        'unavailable' => consoleProfileConfig('provider-c', '0.01', enabled: false),
    ]);
    config()->set('librarium.groups.custom:focused', ['eligible', 'unavailable']);
    config()->set('librarium.groups.custom:quick-test', ['eligible', 'unavailable']);
    config()->set('librarium.default_group', 'custom:quick-test');
});

it('shows an interactive compiled preview and allows exiting without dispatch', function (): void {
    $fake = Librarium::fake()->preventStrayQueries();

    $this->artisan('librarium:query', ['query' => 'Compare research tools.'])
        ->expectsQuestion('How should Librarium select Profiles?', 'profile:eligible')
        ->expectsQuestion('Add safeguards (optional)', [])
        ->expectsPromptsInfo('Compiled research preview')
        ->expectsOutputToContain('eligible')
        ->expectsOutputToContain('Estimated maximum cost: 0.05 USD (complete estimate).')
        ->expectsQuestion('What would you like to do?', 'exit')
        ->expectsPromptsOutro('No research was dispatched.')
        ->assertSuccessful();

    $fake->assertNeverQueried()->assertNeverQueued();
});

it('dispatches an explicit interactive run after preview without asking again', function (): void {
    $fake = Librarium::fake([consoleResponse('markdown-success.json')])->preventStrayQueries();

    $this->artisan('librarium:query', [
        'query' => 'Run after preview.',
        '--profile' => ['eligible'],
        '--run' => true,
    ])
        ->expectsQuestion('Add safeguards (optional)', [])
        ->expectsPromptsInfo('Compiled research preview')
        ->assertSuccessful();

    expect($fake->queries())->toHaveCount(1)
        ->and($fake->queuedQueries())->toBeEmpty();
});

it('runs by default when the interactive action menu is accepted', function (): void {
    $fake = Librarium::fake([consoleResponse('markdown-success.json')])->preventStrayQueries();

    $this->artisan('librarium:query', [
        'query' => 'Run from the action menu.',
        '--profile' => ['eligible'],
    ])
        ->expectsQuestion('Add safeguards (optional)', [])
        ->expectsPromptsInfo('Compiled research preview')
        ->expectsQuestion('What would you like to do?', 'run')
        ->assertSuccessful();

    expect($fake->queries())->toHaveCount(1)
        ->and($fake->queuedQueries())->toBeEmpty();
});

it('summarizes unavailable Profiles in one compact preview warning', function (): void {
    $fake = Librarium::fake()->preventStrayQueries();

    $this->artisan('librarium:query', ['query' => 'Summarize availability.'])
        ->expectsQuestion('How should Librarium select Profiles?', 'default')
        ->expectsQuestion('Add safeguards (optional)', [])
        ->expectsOutputToContain('Preview notes: Skipped 1 unavailable Profile: unavailable (Profile is disabled).')
        ->expectsQuestion('What would you like to do?', 'exit')
        ->assertSuccessful();

    $fake->assertNeverQueried()->assertNeverQueued();
});

it('returns a deterministic agent preview without dispatching', function (): void {
    $fake = Librarium::fake()->preventStrayQueries();

    $exit = Artisan::call('librarium:query', [
        'query' => 'Preview from automation.',
        '--agent' => true,
        '--profile' => ['eligible'],
        '--fallback' => ['backup'],
        '--timeout' => '120',
        '--max-cost' => '0.10',
        '--currency' => 'USD',
    ]);
    $output = Artisan::output();
    $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain("\e[")
        ->and($decoded)->toMatchArray([
            'schema_version' => 1,
            'type' => 'preview',
            'query' => 'Preview from automation.',
            'profiles' => ['eligible'],
            'fallbacks' => ['backup'],
            'unavailable' => [],
            'warnings' => [],
            'cost' => ['amount' => '0.07', 'currency' => 'USD', 'complete' => true],
        ]);

    $fake->assertNeverQueried()->assertNeverQueued();
});

it('runs in agent mode and prints only the public response representation', function (string $fixture, int $expectedExit): void {
    $response = consoleResponse($fixture);
    $fake = Librarium::fake([$response])->preventStrayQueries();

    $exit = Artisan::call('librarium:query', [
        'query' => 'Automated run.',
        '--agent' => true,
        '--profile' => ['eligible'],
        '--run' => true,
    ]);

    expect($exit)->toBe($expectedExit)
        ->and(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR))->toBe($response->toArray())
        ->and($fake->queries())->toHaveCount(1)
        ->and($fake->queuedQueries())->toBeEmpty();
})->with([
    'succeeded' => ['markdown-success.json', 0],
    'partial' => ['partial-top-level-usage.json', LibrariumCommand::EXIT_PARTIAL],
    'failed' => ['failed.json', LibrariumCommand::EXIT_RESEARCH_FAILED],
]);

it('queues in agent mode with a minimal envelope', function (): void {
    $fake = Librarium::fake()->preventStrayQueries();

    $exit = Artisan::call('librarium:query', [
        'query' => 'Automated queue.',
        '--agent' => true,
        '--group' => 'custom:focused',
        '--queue' => true,
    ]);
    $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output)->toMatchArray(['schema_version' => 1, 'type' => 'queued'])
        ->and($output['request_id'])->toBeString()->not->toBeEmpty()
        ->and($fake->queries())->toBeEmpty()
        ->and($fake->queuedQueries())->toHaveCount(1)
        ->and($fake->queuedQueries()->sole(fn (ResearchPlan $plan): bool => $plan->preview->profiles->pluck('id')->all() === ['eligible']))->toBeInstanceOf(ResearchPlan::class);
});

it('rejects conflicting and malformed query options before dispatch', function (array $arguments): void {
    $fake = Librarium::fake()->preventStrayQueries();

    $exit = Artisan::call('librarium:query', [
        'query' => 'Invalid automation request.',
        '--agent' => true,
        ...$arguments,
    ]);

    expect($exit)->toBe(LibrariumCommand::EXIT_INVALID)
        ->and(Artisan::output())->toBe('');

    $fake->assertNeverQueried()->assertNeverQueued();
})->with([
    'selector conflict' => [['--profile' => ['eligible'], '--group' => 'all']],
    'action conflict' => [['--run' => true, '--queue' => true]],
    'malformed timeout' => [['--profile' => ['eligible'], '--timeout' => '12seconds', '--run' => true]],
    'currency without cost' => [['--profile' => ['eligible'], '--currency' => 'CAD']],
    'duplicate profiles' => [['--profile' => ['eligible', 'eligible'], '--run' => true]],
    'unavailable profile' => [['--profile' => ['unavailable'], '--run' => true]],
]);

it('requires a query in no-interaction mode without implying JSON', function (): void {
    $fake = Librarium::fake()->preventStrayQueries();

    $missingExit = Artisan::call('librarium:query', ['--no-interaction' => true]);

    expect($missingExit)->toBe(LibrariumCommand::EXIT_INVALID)
        ->and(Artisan::output())->toContain('query argument is required');

    $fake->assertNeverQueried()->assertNeverQueued();
});

it('uses human preview output in strict no-interaction mode', function (): void {
    $fake = Librarium::fake()->preventStrayQueries();

    $this->artisan('librarium:query', [
        'query' => 'Plain automation preview.',
        '--no-interaction' => true,
        '--profile' => ['eligible'],
    ])
        ->expectsOutputToContain('Research preview')
        ->expectsOutputToContain('No research was dispatched.')
        ->assertSuccessful();

    $fake->assertNeverQueried()->assertNeverQueued();
});

it('shows a human preview before an explicit no-interaction run', function (): void {
    $fake = Librarium::fake([consoleResponse('markdown-success.json')])->preventStrayQueries();

    $this->artisan('librarium:query', [
        'query' => 'Plain automated run.',
        '--no-interaction' => true,
        '--profile' => ['eligible'],
        '--run' => true,
    ])
        ->expectsOutputToContain('Research preview')
        ->expectsOutputToContain('Research succeeded.')
        ->doesntExpectOutputToContain('No research was dispatched.')
        ->assertSuccessful();

    expect($fake->queries())->toHaveCount(1)
        ->and($fake->queuedQueries())->toBeEmpty();
});

it('fails a real non-tty interactive invocation rather than waiting for input', function (): void {
    $process = new Process([PHP_BINARY, 'vendor/bin/testbench', 'librarium:query'], dirname(__DIR__, 2));
    $process->setEnv(['APP_ENV' => 'local']);
    $process->setInput('');
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(LibrariumCommand::EXIT_INVALID)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('non-interactive query requires --agent or --no-interaction');
});

it('keeps real agent validation stdout empty and stderr bounded and secret-safe', function (): void {
    $secretQuery = 'do-not-echo-this-query-secret';
    $process = new Process([
        PHP_BINARY,
        'vendor/bin/testbench',
        'librarium:query',
        $secretQuery,
        '--agent',
        '--profile=gemini-grounded',
        '--group=all',
    ], dirname(__DIR__, 2));
    $process->setEnv(['APP_ENV' => 'local']);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(LibrariumCommand::EXIT_INVALID)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('--profile and --group cannot be combined.')
        ->and($process->getErrorOutput())->not->toContain($secretQuery)
        ->and($process->getErrorOutput())->not->toContain("\e[")
        ->and(mb_strlen($process->getErrorOutput()))->toBeLessThanOrEqual(501);
});

it('reports unexpected failures internally without exposing them to the operator', function (): void {
    $secret = 'unexpected-sensitive-detail';
    Exceptions::fake();
    app()->instance(LibrariumContract::class, new class($secret) implements LibrariumContract
    {
        public function __construct(private readonly string $message) {}

        public function query(string $input): PendingResearch
        {
            throw new RuntimeException($this->message);
        }

        public function status(string $requestId): ?ResearchStatus
        {
            return null;
        }
    });

    $this->artisan('librarium:query', [
        'query' => 'Fail safely.',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('The Librarium command could not be completed.')
        ->doesntExpectOutputToContain($secret)
        ->assertFailed();

    Exceptions::assertReported(
        fn (RuntimeException $exception): bool => $exception->getMessage() === $secret,
    );
});

it('reports status in human mode', function (): void {
    $status = new ResearchStatus(
        ResearchState::Running,
        'Waiting safely.',
        CarbonImmutable::parse('2026-08-28T12:00:00Z'),
    );
    app(ResearchStatusStore::class)->put('request-123', $status);

    $this->artisan('librarium:status')
        ->expectsQuestion('Request ID', 'request-123')
        ->expectsPromptsInfo('Librarium request request-123')
        ->expectsOutputToContain('Waiting safely.')
        ->assertSuccessful();
});

it('reports status in agent mode', function (): void {
    $status = new ResearchStatus(
        ResearchState::Running,
        'Waiting safely.',
        CarbonImmutable::parse('2026-08-28T12:00:00Z'),
    );
    app(ResearchStatusStore::class)->put('request-123', $status);

    $exit = Artisan::call('librarium:status', ['requestId' => 'request-123', '--agent' => true]);
    $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output)->toBe([
            'schema_version' => 1,
            'type' => 'status',
            'request_id' => 'request-123',
            'status' => $status->toArray(),
        ]);
});

it('uses a stable exit when agent status is missing', function (): void {
    $exit = Artisan::call('librarium:status', ['requestId' => 'missing', '--agent' => true]);

    expect($exit)->toBe(LibrariumCommand::EXIT_NOT_FOUND)
        ->and(Artisan::output())->toBe('');
});

it('does not echo a missing agent request ID to real stderr', function (): void {
    $secretRequestId = 'do-not-echo-this-request-secret';
    $process = new Process([
        PHP_BINARY,
        'vendor/bin/testbench',
        'librarium:status',
        $secretRequestId,
        '--agent',
    ], dirname(__DIR__, 2));
    $process->setEnv(['APP_ENV' => 'local']);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(LibrariumCommand::EXIT_NOT_FOUND)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toBe("No status was found for that request.\n")
        ->and($process->getErrorOutput())->not->toContain($secretRequestId);
});

it('lists eligible and unavailable profiles without exposing secret-bearing config', function (): void {
    $secret = 'super-secret-profile-credential';
    config()->set('librarium.profiles.eligible.credential', $secret);
    config()->set('librarium.profiles.unavailable.credential', null);

    $exit = Artisan::call('librarium:profiles', ['--agent' => true]);
    $json = Artisan::output();
    $output = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($json)->not->toContain($secret)
        ->and($output['type'])->toBe('profiles')
        ->and($output['profiles'][0])->toMatchArray([
            'id' => 'eligible',
            'provider' => 'provider-a',
            'eligible' => true,
            'reasons' => [],
        ])
        ->and($output['profiles'][2])->toMatchArray([
            'id' => 'unavailable',
            'eligible' => false,
        ]);
});

it('lists unavailable profiles safely in human mode', function (): void {
    $secret = 'super-secret-profile-credential';
    config()->set('librarium.profiles.eligible.credential', $secret);
    config()->set('librarium.profiles.unavailable.credential', null);

    $this->artisan('librarium:profiles')
        ->doesntExpectOutputToContain($secret)
        ->expectsOutputToContain('Credential is not configured.')
        ->assertSuccessful();
});

it('fails safely when no profiles are eligible and never reaches a provider', function (): void {
    config()->set('librarium.profiles.eligible.enabled', false);
    config()->set('librarium.profiles.backup.enabled', false);
    $fake = Librarium::fake()->preventStrayQueries();

    $this->artisan('librarium:query', ['query' => 'No available profile.'])
        ->expectsOutputToContain('No eligible Profiles are configured.')
        ->assertExitCode(LibrariumCommand::EXIT_INVALID);

    Http::assertNothingSent();
    $fake->assertNeverQueried()->assertNeverQueued();
});

function consoleResponse(string $fixture): ResearchResponse
{
    $value = json_decode(
        File::get(__DIR__.'/../Support/Contracts/v1/fixtures/valid/'.$fixture),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (! is_array($value)) {
        throw new RuntimeException('The response fixture must decode to an array.');
    }

    return ResearchResponse::fromArray($value);
}

/** @return array<string, mixed> */
function consoleProfileConfig(string $provider, string $cost, bool $enabled = true): array
{
    return [
        'driver' => FakeProfileDriver::class,
        'provider' => $provider,
        'model' => 'test-model',
        'result_kind' => ResultKind::GroundedAnswer->value,
        'grounding' => GroundingPolicy::Required->value,
        'observation' => ObservationMode::ApiOutput->value,
        'corpora' => [Corpus::Web->value],
        'retrieval_methods' => [RetrievalMethod::ModelSearchTool->value],
        'prompt' => 'Research carefully: {{ query }}',
        'enabled' => $enabled,
        'options' => ['temperature' => 0.2],
        'estimated_cost' => ['amount' => $cost, 'currency' => 'USD'],
    ];
}
