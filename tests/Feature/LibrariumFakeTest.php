<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Testing\LibrariumFake;

it('returns supplied responses in order without provider access', function (): void {
    $first = fakeResearchResponse('response-1');
    $second = fakeResearchResponse('response-2');
    $fake = Librarium::fake([$first, $second]);

    expect(Librarium::query('First query')->run())->toBe($first)
        ->and(Librarium::query('Second query')->run())->toBe($second)
        ->and($fake->queries())->toHaveCount(2);
});

it('returns a generated response when no response was supplied', function (): void {
    $fake = Librarium::fake();
    $query = Librarium::query('Explain fake research responses.');
    $response = $query->run();

    expect($response->requestId)->toBe($query->requestId)
        ->and($response->results->sole()->content)->toBe('Fake response for query: Explain fake research responses.')
        ->and($fake->queries()->sole())->toBeInstanceOf(ResearchPlan::class);
});

it('prevents stray synchronous queries after supplied responses are exhausted', function (): void {
    $fake = Librarium::fake([fakeResearchResponse('response-1')])->preventStrayQueries();

    Librarium::query('Expected query')->run();

    expect(fn (): ResearchResponse => Librarium::query('Unexpected query')->run())
        ->toThrow(RuntimeException::class, 'Attempted research query [Unexpected query] without a fake response.');

    $fake->assertQueried('Unexpected query');
});

it('records compiled run and queue plans separately with Laravel-style assertions', function (): void {
    $response = fakeResearchResponse('response-1');
    $fake = Librarium::fake([$response]);

    $queuedQuery = Librarium::query('Queued query')->timeout(321);
    $queued = $queuedQuery->queue()->onQueue('research')->onConnection('redis')->delay(5)->afterCommit();
    $run = Librarium::query('Run query')->timeout(123)->run();

    expect($queued)->toBeInstanceOf(QueuedResearchResponse::class)
        ->and($queued->requestId)->toBe($queuedQuery->requestId)
        ->and($run)->toBe($response)
        ->and($fake->queries()->sole()->timeout)->toBe(123)
        ->and($fake->queuedQueries()->sole()->timeout)->toBe(321);

    $fake->assertQueried('Run query')
        ->assertQueried(fn (ResearchPlan $plan): bool => $plan->timeout === 123)
        ->assertNotQueried('Missing query')
        ->assertQueued('Queued query')
        ->assertQueued(fn (ResearchPlan $plan): bool => $plan->timeout === 321)
        ->assertNotQueued('Missing query');
});

it('can assert that no synchronous or queued queries were executed', function (): void {
    Librarium::fake()
        ->assertNeverQueried()
        ->assertNeverQueued();
});

it('replaces already resolved container and facade state', function (): void {
    $resolvedManager = Librarium::getFacadeRoot();
    $resolvedContract = app(LibrariumContract::class);
    $pendingBeforeFake = Librarium::query('Created before fake');

    $firstFake = Librarium::fake([fakeResearchResponse('first-fake')]);

    expect($firstFake)->toBeInstanceOf(LibrariumFake::class)
        ->and(app(ExecutesResearch::class))->toBe($firstFake)
        ->and(Librarium::getFacadeRoot())->toBe(app(LibrariumContract::class))
        ->and(Librarium::getFacadeRoot())->not->toBe($resolvedManager)
        ->and(app(LibrariumContract::class))->not->toBe($resolvedContract)
        ->and($pendingBeforeFake->queue())->toBeInstanceOf(QueuedResearchResponse::class)
        ->and($pendingBeforeFake->run()->requestId)->toBe('first-fake');

    $firstFake->assertQueued('Created before fake')
        ->assertQueried('Created before fake');

    $secondFake = Librarium::fake([fakeResearchResponse('second-fake')]);

    expect(app(ExecutesResearch::class))->toBe($secondFake)
        ->and(Librarium::getFacadeRoot())->toBe(app(LibrariumContract::class))
        ->and(Librarium::query('Second fake query')->run()->requestId)->toBe('second-fake')
        ->and($firstFake->queries())->toHaveCount(1)
        ->and($secondFake->queries())->toHaveCount(1);
});

function fakeResearchResponse(string $requestId): ResearchResponse
{
    $value = json_decode(
        File::get(__DIR__.'/../Support/Contracts/v1/fixtures/valid/markdown-success.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (! is_array($value)) {
        throw new RuntimeException('The fake response fixture must decode to an array.');
    }

    $value['request_id'] = $requestId;

    return ResearchResponse::fromArray($value);
}
