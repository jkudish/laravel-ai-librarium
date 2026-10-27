<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\Spec;

it('returns supplied Runs and records run and queue requests without providers', function (): void {
    Queue::fake();
    $fixture = Run::fromArray(Spec::read('fixtures/runs/valid/summary-run.json'));
    $fake = Librarium::fake([$fixture])->preventStrayQueries();
    expect(Librarium::research('First')->only(['brave'])->run())->toBe($fixture)
        ->and(Librarium::find($fixture->id()))->toBe($fixture);
    $queued = Librarium::research('Later')->deep()->only(['openai'])->queue()->onQueue('research')->delay(5);
    expect(Librarium::find($queued->id())->status())->toBe('running');
    $fake->assertQueried('First')->assertQueued(fn (array $request): bool => $request['depth'] === 'deep');
    expect(fn () => Librarium::research('Unexpected')->only(['brave'])->run())->toThrow(RuntimeException::class, 'without a fake Run');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('plans without consuming a fake Run', function (): void {
    $fake = Librarium::fake()->preventStrayQueries();
    expect(Librarium::research('q')->only(['brave'])->dryRun()->status())->toBe('planned');
    $fake->assertNeverQueried()->assertNeverQueued();
});
