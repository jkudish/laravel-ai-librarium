<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouAnswerDriver;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouCredential;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouResearchGroundedDriver;
use Jkudish\LaravelAiLibrarium\Events\ResearchCompleted;
use Jkudish\LaravelAiLibrarium\Events\ResearchStarted;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Tests\Support\ContractSnapshot;

beforeEach(function (): void {
    config()->set('librarium.profiles.you-answer.credential', 'testing-you-key');
    config()->set('librarium.profiles.you-research-grounded.credential', 'testing-you-key');
    config()->set('librarium.profiles.you-research-research.credential', 'testing-you-key');
});

it('registers all three independent You.com Profiles without aliases or default membership', function (): void {
    $catalog = app(ProfileCatalog::class);
    $answer = $catalog->find('you-answer')?->profile;
    $groundedResearch = $catalog->find('you-research-grounded')?->profile;
    $durableResearch = $catalog->find('you-research-research')?->profile;

    expect($answer)->not->toBeNull()
        ->and($answer?->driver)->toBe(YouAnswerDriver::class)
        ->and($answer?->provider)->toBe('you-answer')
        ->and($answer?->id)->toBe('you-answer')
        ->and($answer?->pricingIdentity?->key())->toBe('you:answer')
        ->and($answer?->pricingUsage?->toArray())->toBe(['requests' => '1'])
        ->and($answer?->pricingUsageConservative)->toBeTrue()
        ->and($groundedResearch)->not->toBeNull()
        ->and($groundedResearch?->driver)->toBe(YouResearchGroundedDriver::class)
        ->and($groundedResearch?->provider)->toBe('you-research')
        ->and($groundedResearch?->id)->toBe('you-research-grounded')
        ->and($groundedResearch?->pricingIdentity?->key())->toBe('you:research-standard')
        ->and($groundedResearch?->pricingUsage?->toArray())->toBe(['requests' => '1'])
        ->and($groundedResearch?->pricingUsageConservative)->toBeTrue()
        ->and($groundedResearch?->options)->toBe(['research_effort' => 'standard'])
        ->and($durableResearch)->not->toBeNull()
        ->and($durableResearch?->driver)->toBe(YouResearchDriver::class)
        ->and($durableResearch?->provider)->toBe('you-research')
        ->and($durableResearch?->id)->toBe('you-research-research')
        ->and($durableResearch?->pricingIdentity?->key())->toBe('you:research-standard')
        ->and($durableResearch?->pricingUsage?->toArray())->toBe(['requests' => '1'])
        ->and($durableResearch?->pricingUsageConservative)->toBeTrue()
        ->and($durableResearch?->options)->toBe(['research_effort' => 'standard']);

    foreach (['you-answer', 'you-research-grounded'] as $inlineProfile) {
        expect($catalog->group('quick')['profiles']->pluck('id'))->not->toContain($inlineProfile)
            ->and($catalog->group('visibility')['profiles']->pluck('id'))->not->toContain($inlineProfile)
            ->and($catalog->group('deep')['profiles']->pluck('id'))->not->toContain($inlineProfile);
    }
    expect($catalog->group('deep')['profiles']->pluck('id'))->toContain('you-research-research');

    expect(fn () => Librarium::query('Do deep research.')
        ->using('you-research-research')
        ->fallbacks(['you-answer'])
        ->preview())
        ->toThrow(ValidationException::class, 'compatible with at least one primary');
});

it('keeps YOU_COM_API_KEY canonical while accepting YDC_API_KEY as a nonbreaking fallback for all Profiles', function (): void {
    expect(YouCredential::resolve('canonical-key', 'docs-alias-key'))->toBe('canonical-key')
        ->and(YouCredential::resolve(null, 'docs-alias-key'))->toBe('docs-alias-key')
        ->and(YouCredential::resolve('  ', 'docs-alias-key'))->toBe('docs-alias-key')
        ->and(YouCredential::resolve(null, null))->toBeNull()
        ->and(config('librarium.profiles.you-answer.credential'))
        ->toBe(config('librarium.profiles.you-research-grounded.credential'))
        ->toBe(config('librarium.profiles.you-research-research.credential'));
});

it('keeps a top-level false routing policy out of strict HTTP Driver options', function (): void {
    config()->set('librarium.profiles.you-answer.allow_provider_routing', false);
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youProfilesAnswerSuccess())]);

    $profile = app(ProfileCatalog::class)->find('you-answer')?->profile;
    $result = Librarium::query('What changed?')->using('you-answer')->run()->results->sole();

    expect($profile)->not->toBeNull()
        ->and($profile?->allowProviderRouting)->toBeFalse()
        ->and($profile?->options)->toBe([])
        ->and($profile?->toArray()['allow_provider_routing'])->toBeFalse()
        ->and($result->provider)->toBe('you-answer');

    Http::assertSentCount(1);
});

it('quotes an exact 5000 micro-USD Answer estimate and refuses a lower maximum before HTTP', function (): void {
    Http::fake();

    $preview = Librarium::query('What changed?')
        ->using('you-answer')
        ->maxCost('0.005')
        ->preview();

    expect($preview->maximumEstimatedCost)->toBe('0.005')
        ->and($preview->currency)->toBe('USD')
        ->and($preview->costEstimateComplete)->toBeTrue()
        ->and($preview->prompts->get('you-answer'))->toBe('What changed?');

    expect(fn () => Librarium::query('What changed?')
        ->using('you-answer')
        ->maxCost('0.004999')
        ->run())
        ->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('quotes an exact 50000 micro-USD estimate for each Research Profile and refuses a lower maximum before HTTP', function (string $profile): void {
    Http::fake();

    $preview = Librarium::query('Research this.')
        ->using($profile)
        ->maxCost('0.05')
        ->preview();

    expect($preview->maximumEstimatedCost)->toBe('0.05')
        ->and($preview->currency)->toBe('USD')
        ->and($preview->costEstimateComplete)->toBeTrue()
        ->and($preview->prompts->get($profile))->toBe('Research this.');

    expect(fn () => Librarium::query('Research this.')
        ->using($profile)
        ->maxCost('0.049999')
        ->run())
        ->toThrow(ValidationException::class);

    Http::assertNothingSent();
})->with(['you-research-grounded', 'you-research-research']);

it('gives inline Answer run and queue identical terminal semantics and events through the shared runtime', function (): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youProfilesAnswerSuccess())]);
    Event::fake([ResearchStarted::class, ResearchCompleted::class]);

    $run = Librarium::query('What changed?')->using('you-answer')->run();

    Queue::fake();
    $queuedResult = null;
    Librarium::query('What changed?')->using('you-answer')->queue()
        ->then(function (ResearchResponse $response) use (&$queuedResult): void {
            $queuedResult = $response;
        });
    $job = Queue::pushed(RunResearch::class)->sole();
    $job->handle(app(ExecutesResearch::class));

    expect($run->status)->toBe(ResponseStatus::Succeeded)
        ->and($queuedResult)->toBeInstanceOf(ResearchResponse::class)
        ->and($queuedResult?->status)->toBe(ResponseStatus::Succeeded)
        ->and($queuedResult?->results->sole()->content)->toBe($run->results->sole()->content)
        ->and($queuedResult?->results->sole()->provider)->toBe($run->results->sole()->provider)
        ->and($queuedResult?->results->sole()->profile)->toBe($run->results->sole()->profile)
        ->and($queuedResult?->results->sole()->provenance->resultKind)->toBe($run->results->sole()->provenance->resultKind)
        ->and($queuedResult?->results->sole()->citations->pluck('source.url')->all())
        ->toBe($run->results->sole()->citations->pluck('source.url')->all());

    Http::assertSentCount(2);
    Event::assertDispatchedTimes(ResearchStarted::class, 2);
    Event::assertDispatchedTimes(ResearchCompleted::class, 2);
});

it('round-trips You.com terminal metadata through the accepted offline conformance contract', function (): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youProfilesAnswerSuccess())]);

    $result = Librarium::query('What changed?')->using('you-answer')->run()->results->sole();
    $roundTrip = ResearchResult::fromArray($result->toArray());

    expect(json_encode($roundTrip->toArray(), JSON_THROW_ON_ERROR))
        ->toBe(json_encode($result->toArray(), JSON_THROW_ON_ERROR))
        ->and(ContractSnapshot::vendored()->verify()['upstream_commit'])
        ->toBe(ContractSnapshot::pin()->commit);
});

/** @return array<string, mixed> */
function youProfilesAnswerSuccess(): array
{
    return [
        'answer' => 'A cited answer [[1]].',
        'citations' => [[
            'source' => 'https://example.com/source',
            'excerpts' => ['Supporting excerpt.'],
        ]],
        'results' => ['web' => [[
            'url' => 'https://example.com/source',
            'title' => 'Source',
            'snippets' => ['Search snippet.'],
        ]]],
    ];
}
