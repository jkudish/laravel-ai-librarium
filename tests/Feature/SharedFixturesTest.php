<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Exceptions\SelectionException;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\SourceSelection;
use Jkudish\LaravelAiLibrarium\Spec;

it('conforms to the shared selection fixture', function (string $file): void {
    $case = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $data = Spec::read('sources.json');
    $catalog = [];
    foreach ([...$data['sources'], ...$data['engines']] as $entry) {
        $engine = $entry['kind'] === 'engine';
        $catalog[$entry['id']] = ['configured' => $engine ? $case['setup']['engines']['configured'] : in_array($entry['id'], $case['setup']['sources'], true), 'deep' => isset($entry['variants']['deep']), 'collector' => ! $engine || isset($entry['collectors'][$case['setup']['engines']['collector']]), 'engine' => $engine];
    }
    $request = $case['request'];
    try {
        $actual = SourceSelection::select($catalog, $case['setup']['groups'], $request['depth'] ?? 'quick', $request['only'] ?? null, $request['skip'] ?? [], $request['max_cost'] ?? null, $case['estimates']);
        expect($case['expected'])->not->toHaveKey('error');
        foreach ($case['expected'] as $key => $value) {
            expect($actual[$key])->toBe($value);
        }
    } catch (SelectionException $exception) {
        expect($exception->errorCode)->toBe($case['expected']['error'])
            ->and($exception->name)->toBe($case['expected']['name'] ?? null);
    }
})->with(fn (): array => array_map(fn (string $file): array => [$file], glob(Spec::path('fixtures/selection/*.json'))));

it('accepts valid shared Runs and rejects invalid shared Runs', function (): void {
    $valid = glob(Spec::path('fixtures/runs/valid/*.json'));
    $invalid = glob(Spec::path('fixtures/runs/invalid/*.json'));
    expect($valid)->not->toBeEmpty()->and($invalid)->not->toBeEmpty();
    foreach ($valid as $file) {
        $doc = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        expect(Run::fromArray($doc)->toArray())->toBe($doc);
    }
    foreach ($invalid as $file) {
        $doc = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        expect(fn () => Run::fromArray($doc))->toThrow(InvalidArgumentException::class);
    }
});
