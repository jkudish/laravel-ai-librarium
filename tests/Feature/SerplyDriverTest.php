<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\Serply\SerplyDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\ProfileOptionValidator;

beforeEach(fn () => Http::preventStrayRequests());

it('always pins gl and sends the key only as a header', function (): void {
    Http::fake(['https://api.serply.io/v1/search/*' => Http::response(['results' => []])]);
    config(['librarium.profiles.serply-search.options' => ['num' => 5, 'hl' => 'de', 'device' => 'mobile']]);

    expect(variantRun('serply-search', 'café + 100%')->content)->toBe('No results found.');

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.serply.io/v1/search/?')
            && $query === ['q' => 'café + 100%', 'num' => '5', 'gl' => 'us', 'hl' => 'de']
            && $request->hasHeader('X-Api-Key', 'testing-serply-key')
            && $request->hasHeader('X-User-Agent', 'mobile')
            && ! str_contains($request->url(), 'testing-serply-key');
    });
});

it('deduplicates overlapping links and trims to num in provider order', function (): void {
    config(['librarium.profiles.serply-search.options' => ['gl' => 'de', 'num' => 3]]);
    Http::fake(['*' => Http::response(['results' => [
        ['title' => 'One', 'link' => 'https://one.example/', 'description' => 'First.'],
        ['title' => 'One again', 'link' => 'https://one.example/'],
        ['title' => 'Unsafe', 'link' => 'javascript:alert(1)'],
        ['title' => 'Two', 'link' => 'https://two.example/'],
        ['title' => 'Three', 'link' => 'https://three.example/'],
        ['title' => 'Four', 'link' => 'https://four.example/'],
    ], 'ai_overview' => ['metadata' => ['sources' => [['url' => 'https://overview.example/']]]]])]);

    $result = variantRun('serply-search', 'q');

    expect($result->citations->pluck('source.url')->all())->toBe(['https://one.example/', 'https://two.example/', 'https://three.example/'])
        ->and($result->content)->toContain('### 3. [Three]')->not->toContain('Four')->not->toContain('overview.example')
        ->and($result->providerMeta?->result_count)->toBe(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'gl=de') && str_contains($request->url(), 'num=3'));
});

it('retries a 502 exactly once and fails a second one', function (array $statuses, ?string $code): void {
    Http::fakeSequence()->pushStatus($statuses[0])->push(['results' => [['title' => 'T', 'link' => 'https://t.example/']]], $statuses[1] ?? 200)->pushStatus(200);

    try {
        $result = variantRun('serply-search', 'q');
        expect($code)->toBeNull()->and($result->citations)->toHaveCount(1);
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
    Http::assertSentCount(count($statuses));
})->with([
    'recovers' => [[502, 200], null],
    'fails twice' => [[502, 502], 'serply.unavailable'],
    'no retry on 500' => [[500], 'serply.unavailable'],
    'no retry on 429' => [[429], 'serply.rate_limited'],
    'bad key' => [[401], 'serply.authentication'],
]);

it('rejects invalid controls before any request', function (array $options): void {
    Http::fake();
    config(['librarium.profiles.serply-search.options' => $options]);
    expect(app(ProfileOptionValidator::class)->errors(SerplyDriver::class, $options))->not->toBe([]);
    Http::assertNothingSent();
})->with([[['gl' => 'US']], [['num' => 11]], [['num' => '5']], [['device' => 'tablet']], [['proxy' => 'US']]]);

it('quotes one request against the unconfigured serply:search identity', function (): void {
    $bound = variantBound('serply-search', 'q');

    expect($bound->pricingIdentity?->key())->toBe('serply:search')
        ->and($bound->quantities?->toArray())->toBe(['requests' => '1'])
        ->and(variantQuote('serply-search')->cost)->toBeNull();
});
