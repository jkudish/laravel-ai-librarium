<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\PendingResearch;

it('registers the manager behind the package contract', function (): void {
    $contract = app(LibrariumContract::class);

    expect($contract)
        ->toBeInstanceOf(LibrariumManager::class)
        ->toBe(app(LibrariumManager::class));
});

it('merges the package configuration', function (): void {
    expect(config('librarium.default_group'))->toBe('quick')
        ->and(config('librarium.timeout'))->toBe(7200)
        ->and(config('librarium.status_ttl'))->toBe(10800);
});

it('starts a research query through the facade', function (): void {
    $research = Librarium::query('Compare current agent memory architectures.');

    expect($research)
        ->toBeInstanceOf(PendingResearch::class)
        ->input->toBe('Compare current agent memory architectures.');
});

it('rejects empty input through the facade', function (string $input): void {
    expect(fn (): PendingResearch => Librarium::query($input))
        ->toThrow(ValidationException::class);
})->with(['', '   ', "\n\t"]);
