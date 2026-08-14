<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\Usage;

it('keeps terminal response values usable by a Laravel package consumer', function (): void {
    /** @var array<string, mixed> $fixture */
    $fixture = json_decode(
        File::get(__DIR__.'/../Support/Contracts/v1/fixtures/valid/partial-top-level-usage.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $response = ResearchResponse::fromArray($fixture);

    expect($response->usage)->toBeInstanceOf(Usage::class)
        ->and($response->results)->toHaveCount(1)
        ->and($response->errors)->toHaveCount(1)
        ->and($response->toArray())->toBe($fixture);
});
