<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\PendingResearch;

it('preserves the exact research input', function (): void {
    $research = new PendingResearch("  Research this exactly.\n");

    expect($research->input)->toBe("  Research this exactly.\n");
});

it('rejects empty research input', function (string $input): void {
    expect(fn (): PendingResearch => new PendingResearch($input))
        ->toThrow(InvalidArgumentException::class, 'Research input must not be empty.');
})->with(['', '   ', "\n\t"]);
