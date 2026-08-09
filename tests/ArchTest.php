<?php

declare(strict_types=1);

arch('it does not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('the core package has no persistence or presentation concerns')
    ->expect('Jkudish\\LaravelAiLibrarium')
    ->not->toUse([
        'Illuminate\\Database',
        'Illuminate\\View',
        'Symfony\\Component\\Process',
    ]);

arch('interchange values are immutable leaves')
    ->expect('Jkudish\\LaravelAiLibrarium\\Interchange\\Values')
    ->toBeFinal()
    ->toBeReadonly();

it('ships no package-owned database migrations', function (): void {
    expect(is_dir(__DIR__.'/../database'))->toBeFalse();
});
