<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\You\YouAnswerDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23T12:00:00Z');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('sends one exact Answer request without allowing API key redirects and maps it losslessly', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($options['allow_redirects'] ?? null)->toBeFalse();

        return Http::response(youAnswerSuccess());
    });

    $result = app(YouAnswerDriver::class)->run(youAnswerRequest(options: [
        'freshness' => '2026-08-01to2026-08-23',
        'country' => 'CA',
        'language' => 'EN-GB',
        'exclude_domains' => ['spam.example'],
        'boost_domains' => [' PRIMARY.Example '],
    ]));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.you.com/v1/answer'
            && $request->hasHeader('X-API-Key', 'testing-you-key')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === [
                'query' => 'What changed?',
                'freshness' => '2026-08-01to2026-08-23',
                'country' => 'CA',
                'language' => 'EN-GB',
                'exclude_domains' => ['spam.example'],
                'boost_domains' => ['primary.example'],
            ]
            && ! str_contains($request->url(), 'testing-you-key');
    });
    Http::assertSentCount(1);

    expect($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe('A first claim [[1]] and both claims [[1, 2]].')
        ->and($result->provider)->toBe('you-answer')
        ->and($result->profile)->toBe('you-answer')
        ->and($result->provenance->resultKind)->toBe(ResultKind::GroundedAnswer)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://cited.example/one',
            'https://cited.example/two',
        ])
        ->and($result->citations->first()->excerpt)->toBe('Verbatim excerpt one.')
        ->and($result->citations->first()->locator)->toBe('[[1]]')
        ->and($result->citations->first()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and(data_get($result->providerMeta, 'you-com:answer.citation_entries'))->toBe([
            [
                'citation_number' => 1,
                'source_url' => 'https://cited.example/one',
                'excerpts' => ['Verbatim excerpt one.', 'A second supporting excerpt.'],
                'inline_reference_count' => 2,
            ],
            [
                'citation_number' => 2,
                'source_url' => 'https://cited.example/two',
                'excerpts' => [],
                'inline_reference_count' => 1,
            ],
        ])
        ->and(data_get($result->providerMeta, 'you-com:answer.considered_web_results'))->toBe([
            [
                'url' => 'https://cited.example/one',
                'title' => 'Cited result',
                'description' => 'Description',
                'thumbnail_url' => 'https://images.example/one.png',
                'page_age' => '2026-08-22T12:00:00',
                'snippets' => ['Search snippet'],
            ],
            [
                'url' => 'https://considered.example/only',
                'title' => 'Considered but uncited',
                'snippets' => ['Must remain distinct.'],
            ],
        ]);

    expect($result->citations->pluck('source.url')->all())
        ->not->toContain('https://considered.example/only');
    expect(json_encode($result->toArray(), JSON_THROW_ON_ERROR))->not->toContain('testing-you-key');
});

it('accepts the exact 400-unit rendered-query boundary', function (string $prompt): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youAnswerSuccess())]);

    app(YouAnswerDriver::class)->run(youAnswerRequest(prompt: $prompt));

    Http::assertSentCount(1);
})->with([
    'ASCII' => str_repeat('x', 400),
    'UTF-16 surrogate pairs' => str_repeat('😀', 200),
]);

it('rejects rendered queries and every invalid option class before HTTP dispatch', function (string $case, string $prompt, array $options): void {
    Http::fake();

    expect(fn () => app(YouAnswerDriver::class)->run(youAnswerRequest(prompt: $prompt, options: $options)))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'you_answer.invalid_request'
            && ! $exception->fallbackAllowed);

    Http::assertNothingSent();
})->with([
    'blank rendered query' => ['blank', '   ', []],
    'overlong rendered query' => ['overlong', str_repeat('x', 401), []],
    'overlong UTF-16 rendered query' => ['unicode overlong', str_repeat('😀', 201), []],
    'unknown option' => ['unknown', 'query', ['undocumented' => true]],
    'unsupported freshness' => ['freshness', 'query', ['freshness' => 'hour']],
    'impossible freshness date' => ['date', 'query', ['freshness' => '2026-02-30to2026-03-01']],
    'reversed freshness range' => ['range', 'query', ['freshness' => '2026-08-23to2026-08-01']],
    'unsupported country' => ['country', 'query', ['country' => 'ZZ']],
    'unsupported language' => ['language', 'query', ['language' => 'XX']],
    'invalid domain' => ['domain', 'query', ['include_domains' => ['https://example.com/path']]],
    'empty domain list' => ['empty domains', 'query', ['include_domains' => []]],
    'overlong domain list' => ['domain cap', 'query', ['include_domains' => array_fill(0, 501, 'example.com')]],
    'include and exclude conflict' => ['include/exclude', 'query', ['include_domains' => ['one.example'], 'exclude_domains' => ['two.example']]],
    'include and boost conflict' => ['include/boost', 'query', ['include_domains' => ['one.example'], 'boost_domains' => ['two.example']]],
]);

it('allows exclude and boost domains together', function (): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youAnswerSuccess())]);

    app(YouAnswerDriver::class)->run(youAnswerRequest(options: [
        'exclude_domains' => ['spam.example'],
        'boost_domains' => ['source.example'],
    ]));

    Http::assertSentCount(1);
});

it('accepts an Answer without citations or considered results', function (): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response([
        'answer' => 'A grounded answer without provider-reported evidence.',
        'citations' => [],
        'results' => ['web' => []],
    ])]);

    $result = app(YouAnswerDriver::class)->run(youAnswerRequest());

    expect($result->citations)->toBeEmpty()
        ->and(data_get($result->providerMeta, 'you-com:answer.citation_entries'))->toBe([])
        ->and(data_get($result->providerMeta, 'you-com:answer.considered_web_results'))->toBe([]);
    Http::assertSentCount(1);
});

it('rejects incompatible Answer Profile semantics before HTTP dispatch', function (array $overrides): void {
    Http::fake();

    expect(fn () => app(YouAnswerDriver::class)->run(youAnswerRequest(...$overrides)))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'you_answer.invalid_profile'
            && ! $exception->fallbackAllowed);

    Http::assertNothingSent();
})->with([
    'provider' => [['provider' => 'you-research']],
    'model' => [['model' => 'provider-selected']],
    'result kind' => [['resultKind' => ResultKind::ResearchReport]],
    'grounding' => [['grounding' => GroundingPolicy::Optional]],
    'observation' => [['observation' => ObservationMode::SurfaceSnapshot]],
    'corpus' => [['corpora' => [Corpus::News]]],
    'retrieval method' => [['retrievalMethods' => [RetrievalMethod::ResearchAgent]]],
]);

it('fails closed on malformed successful payloads without leaking them', function (mixed $payload): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response($payload)]);

    try {
        app(YouAnswerDriver::class)->run(youAnswerRequest());
        $this->fail('The malformed response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('you_answer.invalid_response')
            ->and($exception->getMessage())->toBe('You.com Answer returned an invalid grounded answer.')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'non-object' => ['provider-secret'],
    'empty answer' => [['answer' => ' ', 'citations' => [], 'results' => ['web' => []]]],
    'missing citations' => [['answer' => 'Answer', 'results' => ['web' => []]]],
    'malformed excerpt' => [['answer' => 'Answer', 'citations' => [['source' => 'https://example.com', 'excerpts' => 'bad']], 'results' => ['web' => []]]],
    'unsafe citation URL' => [['answer' => 'Answer', 'citations' => [['source' => 'https://user:pass@example.com', 'excerpts' => []]], 'results' => ['web' => []]]],
    'missing considered web results' => [['answer' => 'Answer', 'citations' => [], 'results' => []]],
    'malformed considered result' => [['answer' => 'Answer', 'citations' => [], 'results' => ['web' => [['url' => 'not-a-url']]]]],
    'orphan inline citation' => [['answer' => 'Answer [[2]].', 'citations' => [['source' => 'https://example.com', 'excerpts' => []]], 'results' => ['web' => []]]],
]);

it('maps HTTP failures to safe errors without leaking provider bodies or credentials', function (int $status, string $code, bool $fallbackAllowed): void {
    Http::fake(['https://api.you.com/v1/answer' => Http::response([
        'detail' => 'provider-secret testing-you-key',
        'X-API-Key' => 'testing-you-key',
    ], $status)]);

    try {
        app(YouAnswerDriver::class)->run(youAnswerRequest());
        $this->fail('The HTTP error should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallbackAllowed)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('testing-you-key');
    }

    Http::assertSentCount(1);
})->with([
    'bad request' => [400, 'you_answer.invalid_request', false],
    'authentication' => [401, 'you_answer.authentication', true],
    'credit' => [402, 'you_answer.insufficient_credit', true],
    'authorization' => [403, 'you_answer.authorization', true],
    'timeout' => [408, 'you_answer.timeout', false],
    'rate limit' => [429, 'you_answer.rate_limited', true],
    'server error' => [503, 'you_answer.unavailable', true],
]);

it('uses the exact remaining request-wide deadline and rejects elapsed requests and responses', function (): void {
    $options = null;
    Http::globalMiddleware(function (callable $handler) use (&$options): Closure {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    Http::fake(['https://api.you.com/v1/answer' => Http::response(youAnswerSuccess())]);

    app(YouAnswerDriver::class)->run(youAnswerRequest(deadline: CarbonImmutable::now()->addMilliseconds(2345)));

    expect($options['timeout'] ?? null)->toBe(2.345)
        ->and($options['connect_timeout'] ?? null)->toBe(2.345);

    Http::fake();
    expect(fn () => app(YouAnswerDriver::class)->run(youAnswerRequest(deadline: CarbonImmutable::now())))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'you_answer.deadline_exceeded');
    Http::assertNothingSent();

    $deadline = CarbonImmutable::now()->addSecond();
    Http::fake(function () use ($deadline): array {
        CarbonImmutable::setTestNow($deadline);

        return youAnswerSuccess();
    });
    expect(fn () => app(YouAnswerDriver::class)->run(youAnswerRequest(deadline: $deadline)))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === 'you_answer.deadline_exceeded');
});

it('maps connection failures safely according to the shared deadline', function (bool $expires, string $code): void {
    Http::fake(function () use ($expires): never {
        if ($expires) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        }

        throw new ConnectionException('provider-secret testing-you-key');
    });

    expect(fn () => app(YouAnswerDriver::class)->run(youAnswerRequest()))
        ->toThrow(fn (DriverException $exception): bool => $exception->errorCode === $code
            && ! str_contains($exception->getMessage(), 'provider-secret')
            && ! str_contains($exception->getMessage(), 'testing-you-key'));
})->with([
    'within deadline' => [false, 'you_answer.connection'],
    'deadline exhausted' => [true, 'you_answer.deadline_exceeded'],
]);

/** @return array<string, mixed> */
function youAnswerSuccess(): array
{
    return [
        'answer' => 'A first claim [[1]] and both claims [[1, 2]].',
        'citations' => [
            ['source' => 'https://cited.example/one', 'excerpts' => ['Verbatim excerpt one.', 'A second supporting excerpt.']],
            ['source' => 'https://cited.example/two', 'excerpts' => []],
        ],
        'results' => ['web' => [
            [
                'url' => 'https://cited.example/one',
                'title' => 'Cited result',
                'snippets' => ['Search snippet'],
                'description' => 'Description',
                'thumbnail_url' => 'https://images.example/one.png',
                'page_age' => '2026-08-22T12:00:00',
            ],
            [
                'url' => 'https://considered.example/only',
                'title' => 'Considered but uncited',
                'snippets' => ['Must remain distinct.'],
            ],
        ]],
    ];
}

/** @param array<string, mixed> $options */
function youAnswerRequest(
    string $prompt = 'What changed?',
    array $options = [],
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-you-key',
    string $provider = 'you-answer',
    ?string $model = null,
    ResultKind $resultKind = ResultKind::GroundedAnswer,
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ObservationMode $observation = ObservationMode::ApiOutput,
    array $corpora = [Corpus::Web],
    array $retrievalMethods = [RetrievalMethod::SearchEndpoint],
): DriverRequest {
    $profile = new Profile(
        id: 'you-answer',
        driver: YouAnswerDriver::class,
        provider: $provider,
        model: $model,
        resultKind: $resultKind,
        grounding: $grounding,
        observation: $observation,
        corpora: collect($corpora),
        retrievalMethods: collect($retrievalMethods),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-answer',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
