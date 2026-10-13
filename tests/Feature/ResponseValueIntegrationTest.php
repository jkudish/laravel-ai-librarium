<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Validation\Factory;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\Usage;

it('keeps terminal response values usable by a Laravel package consumer', function (): void {
    /** @var array<string, mixed> $fixture */
    $fixture = json_decode(
        File::get(__DIR__.'/../Support/DriverValues/valid/partial-top-level-usage.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $response = ResearchResponse::fromArray($fixture);

    expect($response->usage)->toBeInstanceOf(Usage::class)
        ->and(app(Illuminate\Contracts\Validation\Factory::class))->toBeInstanceOf(Factory::class)
        ->and($response->results)->toHaveCount(1)
        ->and($response->errors)->toHaveCount(1)
        ->and($response->toArray())->toBe($fixture);
});

it('renders every terminal response value with its canonical wire keys through Laravel JSON responses', function (): void {
    /** @var array<string, mixed> $fixture */
    $fixture = json_decode(
        File::get(__DIR__.'/../Support/DriverValues/valid/partial-top-level-usage.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $response = ResearchResponse::fromArray($fixture);
    $result = $response->results->sole();
    $citation = $result->citations->sole();
    $error = $response->errors->sole();

    $values = [
        'response' => [$response, $fixture],
        'result' => [$result, $fixture['results'][0]],
        'provenance' => [$result->provenance, $fixture['results'][0]['provenance']],
        'citation' => [$citation, $fixture['results'][0]['citations'][0]],
        'source' => [$citation->source, $fixture['results'][0]['citations'][0]['source']],
        'usage' => [$response->usage, $fixture['usage']],
        'error' => [$error, $fixture['errors'][0]],
    ];

    foreach ($values as $value) {
        expect($value[0])->toBeInstanceOf(JsonSerializable::class)
            ->and(response()->json($value[0])->getContent())
            ->toBe(json_encode($value[1], JSON_THROW_ON_ERROR));
    }
});
