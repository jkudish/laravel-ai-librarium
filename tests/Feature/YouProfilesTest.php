<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouAnswerDriver;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouCredential;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouResearchGroundedDriver;
use Jkudish\LaravelAiLibrarium\Events\ResearchCompleted;
use Jkudish\LaravelAiLibrarium\Events\ResearchStarted;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\SourceCatalog;

beforeEach(function (): void {
    config(['services.you.key' => 'testing-you-key', 'librarium.runs.store' => 'memory']);
    CarbonImmutable::setTestNow('2026-10-11T12:00:00Z');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('maps You options and depth to distinct inline and durable drivers', function (): void {
    $catalog = app(SourceCatalog::class);
    expect($catalog->profile('you', 'quick')->driver)->toBe(YouAnswerDriver::class)
        ->and($catalog->profile('you', 'quick', ['quick' => 'research'])->driver)->toBe(YouResearchGroundedDriver::class)
        ->and($catalog->profile('you', 'deep')->driver)->toBe(YouResearchDriver::class);
});

it('keeps YOU_COM_API_KEY canonical while accepting the documented alias', function (): void {
    expect(YouCredential::resolve('canonical-key', 'docs-alias-key'))->toBe('canonical-key')
        ->and(YouCredential::resolve(null, 'docs-alias-key'))->toBe('docs-alias-key')
        ->and(YouCredential::resolve('  ', 'docs-alias-key'))->toBe('docs-alias-key')
        ->and(YouCredential::resolve(null, null))->toBeNull();
});

it('keeps a top-level false routing policy out of strict HTTP driver options', function (): void {
    config()->set('librarium.profiles.you-answer.allow_provider_routing', false);
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youProfilesAnswerSuccess())]);
    $profile = variantRequest('you-answer', 'What changed?')->profile;
    $result = variantRun('you-answer', 'What changed?');
    expect($profile->allowProviderRouting)->toBeFalse()
        ->and($profile->options)->toBe([])
        ->and($result->provider)->toBe('you-answer');
    Http::assertSentCount(1);
});

it('admits You at the exact bound and skips it below the bound before HTTP', function (bool $deep, string $amount, array $options): void {
    $research = fn () => $deep
        ? Librarium::research('Research this.')->deep()->only(['you'])
        : Librarium::research('Research this.')->only(['you'])->options($options);
    $plan = $research()->maxCost($amount)->dryRun();
    expect($plan->toArray()['cost']['estimated'])->toBe($amount)
        ->and(array_column($plan->toArray()['results'], 'source'))->toBe(['you']);
    $lower = (string) BigDecimal::of($amount)->minus('0.000001');
    $excluded = $research()->maxCost($lower)->dryRun();
    expect($excluded->toArray()['results'])->toBe([])
        ->and($excluded->toArray()['skipped'])->toBe([['source' => 'you', 'reason' => 'over_budget']]);
    Http::assertNothingSent();
})->with([
    'answer' => [false, '0.005', []],
    'quick research' => [false, '0.05', ['you' => ['quick' => 'research']]],
    'deep research' => [true, '0.05', []],
]);

it('gives inline Answer run and queue identical answers and terminal events', function (): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youProfilesAnswerSuccess())]);
    Event::fake([ResearchStarted::class, ResearchCompleted::class]);
    $run = Librarium::research('What changed?')->only(['you'])->run();
    Queue::fake();
    $queuedResult = null;
    Librarium::research('What changed?')->only(['you'])->queue()->then(function (Run $run) use (&$queuedResult): void {
        $queuedResult = $run;
    });
    Queue::pushed(RunResearch::class)->sole()->handle(app(RunRuntime::class));
    expect($run->status())->toBe('done')
        ->and($queuedResult?->status())->toBe('done')
        ->and($queuedResult?->result('you')['answer'])->toBe($run->result('you')['answer'])
        ->and($queuedResult?->citations())->toBe($run->citations());
    Http::assertSentCount(2);
    Event::assertDispatchedTimes(ResearchStarted::class, 2);
    Event::assertDispatchedTimes(ResearchCompleted::class, 2);
});

it('round-trips the You Run through the shared public contract', function (): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youProfilesAnswerSuccess())]);
    $run = Librarium::research('What changed?')->only(['you'])->run();
    expect(Run::fromArray($run->toArray())->toArray())->toBe($run->toArray());
});

/** @return array<string, mixed> */
function youProfilesAnswerSuccess(): array
{
    return [
        'answer' => 'A cited answer [[1]].',
        'citations' => [['source' => 'https://example.com/source', 'excerpts' => ['Supporting excerpt.']]],
        'results' => ['web' => [['url' => 'https://example.com/source', 'title' => 'Source', 'snippets' => ['Search snippet.']]]],
    ];
}
