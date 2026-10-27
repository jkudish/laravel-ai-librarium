<?php

declare(strict_types=1);
use Jkudish\LaravelAiLibrarium\Tests\TestCase;

uses(TestCase::class);

arch('it does not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('the core package has no Eloquent or presentation concerns')
    ->expect('Jkudish\\LaravelAiLibrarium')
    ->not->toUse([
        'Illuminate\\Database\\Eloquent',
        'Illuminate\\View',
        'Symfony\\Component\\Process',
    ]);

it('ships one opt-in migration without loading it automatically', function (): void {
    expect(glob(__DIR__.'/../database/migrations/*.php'))->toHaveCount(1)
        ->and(app('db')->connection()->getSchemaBuilder()->hasTable('librarium_runs'))->toBeFalse();
});
