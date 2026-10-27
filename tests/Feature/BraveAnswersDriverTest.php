<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveAnswersDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Psr\Http\Message\StreamInterface;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-20T12:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('sends the exact deadline-bounded streaming request without allowing subscription token redirects', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.search.brave.com/res/v1/chat/completions')
            ->and($request->hasHeader('X-Subscription-Token', 'testing-brave-secret'))->toBeTrue()
            ->and($request->hasHeader('Accept', 'text/event-stream'))->toBeTrue()
            ->and($request->hasHeader('Content-Type', 'application/json'))->toBeTrue()
            ->and($request->data())->toBe([
                'model' => 'brave',
                'messages' => [['role' => 'user', 'content' => 'What changed?']],
                'stream' => true,
                'enable_citations' => true,
            ])
            ->and($options['connect_timeout'])->toBe(2)
            ->and($options['timeout'])->toBe(2)
            ->and($options['allow_redirects'] ?? null)->toBeFalse()
            ->and($options['stream'])->toBeTrue();

        return Http::response(braveSse(['Answer.', braveCitation()]));
    });

    $result = braveDriver()->run(braveRequest(deadline: CarbonImmutable::now()->addSeconds(2)));

    expect($result->content)->toBe('Answer.')
        ->and($result->model)->toBe('brave');
    Http::assertSentCount(1);
});

it('parses fragmented SSE and tags while filtering and deduplicating citations', function (): void {
    $quotedCloser = json_encode([
        'url' => 'https://example.com/quoted',
        'title' => 'A literal </citation> in a title',
        'description' => 'quoted excerpt',
    ], JSON_THROW_ON_ERROR);
    $deltas = [
        'Finding ',
        '<citation>{"url":"https://example.com/a","source":"First","snippet":"Excerpt"}</cit',
        'ation><enum_item>{"value":"hidden"}</enum_item>confirmed. ',
        '<citation>{"url":"https://example.com/a","title":"Duplicate"}</citation>',
        '<citation>{"url":"ftp://example.com/nope","title":"Unsafe"}</citation>',
        '<citation>{"url":"https://user:pass@example.com/nope"}</citation>',
        "<citation>{$quotedCloser}</citation>",
    ];
    $sse = str_repeat(':', 8180)."\r\nevent: message\r\n".implode("\r\n", [
        'data:'.json_encode(['model' => 'brave-live'], JSON_THROW_ON_ERROR),
    ])."\r\n\r\n";
    foreach ($deltas as $delta) {
        $sse .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $delta]]]], JSON_THROW_ON_ERROR)."\r\n\r\n";
    }
    $sse .= "data: {malformed}\r\n\r\ndata: [DONE]";

    Http::fake(['https://api.search.brave.com/*' => Http::response($sse)]);

    $result = braveDriver()->run(braveRequest());
    /** @var Citation $firstCitation */
    $firstCitation = $result->citations->first();

    expect($result->content)->toBe('Finding confirmed.')
        ->and($result->model)->toBe('brave-live')
        ->and($result->citations)->toHaveCount(2)
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/a',
            'https://example.com/quoted',
        ])
        ->and($firstCitation->source->title)->toBe('First')
        ->and($firstCitation->excerpt)->toBe('Excerpt')
        ->and($result->citations->pluck('derivation')->unique()->all())->toBe([CitationDerivation::ProviderReported]);
});

it('uses inline usage before final SSE usage and legacy headers', function (): void {
    $usage = '<usage>'.json_encode([
        'x-request-tokens-in' => 12,
        'X-REQUEST-TOKENS-OUT' => 4,
        'X-Request-Total-Cost' => 0.058585,
    ], JSON_THROW_ON_ERROR).'</usage>';
    $body = braveSse([
        'Answer.',
        braveCitation(),
        $usage,
    ], ['prompt_tokens' => 90, 'completion_tokens' => 80]);

    Http::fake(['https://api.search.brave.com/*' => Http::response($body, 200, [
        'X-Request-Tokens-In' => '70',
        'X-Request-Tokens-Out' => '60',
        'X-Request-Cost-Usd' => '0.9',
    ])]);

    $usage = braveDriver()->run(braveRequest())->usage;

    expect($usage?->promptTokens)->toBe(12)
        ->and($usage?->completionTokens)->toBe(4)
        ->and($usage?->actualCost)->toBe('0.058585')
        ->and($usage?->currency)->toBe('USD');
});

it('falls back from final SSE tokens to safe legacy usage headers and nested cost breakdown', function (): void {
    Http::fake(['https://api.search.brave.com/*' => Http::response(
        braveSse(['Answer.', braveCitation()], ['prompt_tokens' => 21]),
        200,
        [
            'X-Request-Tokens-In' => '91',
            'X-Request-Tokens-Out' => '8',
            'X-Request-Cost-Usd' => '0.29',
            'X-Request-Cost-Breakdown' => json_encode(['billing' => ['total_usd' => 0.0025]], JSON_THROW_ON_ERROR),
        ],
    )]);

    $usage = braveDriver()->run(braveRequest())->usage;

    expect($usage?->promptTokens)->toBe(21)
        ->and($usage?->completionTokens)->toBe(8)
        ->and($usage?->actualCost)->toBe('0.29');
});

it('rejects required-grounding answers without a valid citation safely', function (array $deltas): void {
    /** @var list<string> $deltas */
    $body = braveSse($deltas, terminate: false);
    Http::fake(['https://api.search.brave.com/*' => Http::response($body)]);

    try {
        braveDriver()->run(braveRequest());
        throw new RuntimeException('The ungrounded answer should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('brave.invalid_response')
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->and($exception->getMessage())->not->toContain('{not-json}')
            ->and($exception->getMessage())->not->toContain('<usage>');
    }
})->with([
    'no citation tags' => [['Safe answer without citation tags. provider-secret']],
    'all malformed or invalid citations' => [[
        'Safe answer.',
        '<citation>{not-json}</citation>',
        '<citation>{"url":"ftp://example.com/nope"}</citation>',
        '<citation>{"url":"https://user:pass@example.com/nope"}</citation>',
        '<usage>{"X-Request-Tokens-In":2',
        'provider-secret-after-incomplete-tag',
    ]],
]);

it('rejects malformed, oversized, and interrupted streams safely', function (mixed $fake, string $code): void {
    Http::fake(['https://api.search.brave.com/*' => $fake]);

    try {
        braveDriver()->run(braveRequest());
        throw new RuntimeException('The stream should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'malformed only' => [fn () => Http::response("data: {provider-secret}\n\n"), 'brave.invalid_response'],
    'oversized' => [fn () => Http::response(str_repeat('x', (10 * 1024 * 1024) + 1)), 'brave.response_too_large'],
    'interrupted' => [function () {
        $reads = 0;
        $stream = new PumpStream(function () use (&$reads): string {
            if ($reads++ === 0) {
                return 'data: '.json_encode(['choices' => [['delta' => ['content' => 'partial provider-secret']]]], JSON_THROW_ON_ERROR)."\n\n";
            }

            throw new RuntimeException('provider-secret stream failure');
        });

        return braveStreamResponse($stream);
    }, 'brave.invalid_response'],
]);

it('captures bytes received before an interrupted stream and marks them partial', function (): void {
    $reads = 0;
    $firstChunk = str_pad(
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'partial answer']]]], JSON_THROW_ON_ERROR)."\n\n",
        9000,
        'x',
    );
    Http::fake(fn () => braveStreamResponse(new PumpStream(function () use (&$reads, $firstChunk): string {
        if ($reads++ === 0) {
            return $firstChunk;
        }

        throw new RuntimeException('provider-secret stream failure');
    })));
    $capturedBody = null;
    $partial = false;

    $request = braveRequest(
        responseCallback: function (Response $response, Operation $operation, ?string $body) use (&$capturedBody): void {
            expect($response->status())->toBe(200)
                ->and($operation)->toBe(Operation::Inline);
            $capturedBody = $body;
        },
        partialCaptureCallback: function () use (&$partial): void {
            $partial = true;
        },
    );

    expect(fn () => braveDriver()->run($request))
        ->toThrow(DriverException::class, 'Brave Answers returned an invalid response.');
    expect($capturedBody)->toStartWith('data: ')
        ->and(strlen((string) $capturedBody))->toBe(8192)
        ->and($partial)->toBeTrue();
});

it('rejects missing credentials, incompatible profiles, and elapsed deadlines before dispatch', function (DriverRequest $request, string $code): void {
    Http::fake();

    try {
        braveDriver()->run($request);
        throw new RuntimeException('The request should be rejected before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'missing credential' => fn () => [braveRequest(profile: braveProfile(credential: null)), 'brave.authentication'],
    'wrong model' => fn () => [braveRequest(profile: braveProfile(model: 'not-brave')), 'brave.invalid_profile'],
    'elapsed deadline' => fn () => [braveRequest(deadline: CarbonImmutable::now()->subSecond()), 'brave.deadline_exceeded'],
]);

it('maps observed Brave and status failures without retaining provider payloads', function (int $status, array $body, string $code, bool $fallback): void {
    $body['raw'] = 'provider-secret';
    Http::fake(['https://api.search.brave.com/*' => Http::response($body, $status)]);

    try {
        braveDriver()->run(braveRequest());
        throw new RuntimeException('The provider request should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->fallbackAllowed)->toBe($fallback)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'observed invalid token' => [422, ['error' => ['code' => 'SUBSCRIPTION_TOKEN_INVALID']], 'brave.authentication', true],
    'observed plan denial' => [400, ['error' => ['code' => 'OPTION_NOT_IN_PLAN']], 'brave.plan_required', true],
    'plan code outranks forbidden status' => [403, ['error' => ['code' => 'OPTION_NOT_IN_PLAN']], 'brave.plan_required', true],
    'unauthorized' => [401, [], 'brave.authentication', true],
    'billing' => [402, [], 'brave.billing', true],
    'timeout' => [504, [], 'brave.deadline_exceeded', true],
    'rate limited' => [429, [], 'brave.rate_limited', true],
    'unavailable' => [503, [], 'brave.unavailable', true],
    'invalid request' => [400, [], 'brave.invalid_request', false],
]);

it('maps connection failures according to whether the deadline elapsed', function (bool $elapsed, string $code): void {
    Http::fake(function () use ($elapsed) {
        if ($elapsed) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));
        }

        throw new ConnectionException('provider-secret connection failure');
    });

    try {
        braveDriver()->run(braveRequest());
        throw new RuntimeException('The connection should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'before deadline' => [false, 'brave.connection'],
    'at deadline' => [true, 'brave.deadline_exceeded'],
]);

it('maps an interrupted error body through the safe status boundary', function (): void {
    Http::fake(function () {
        $stream = new PumpStream(function (): string {
            throw new RuntimeException('provider-secret error stream failure');
        });

        return braveStreamResponse($stream, 503);
    });

    try {
        braveDriver()->run(braveRequest());
        throw new RuntimeException('The error response should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('brave.unavailable')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
});

it('discards a response whose stream becomes available after the outer deadline', function (): void {
    Http::fake(function () {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));

        return Http::response(braveSse(['provider-secret partial answer']));
    });

    try {
        braveDriver()->run(braveRequest());
        throw new RuntimeException('The late stream should fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('brave.deadline_exceeded')
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
});

function braveDriver(): BraveAnswersDriver
{
    return app(BraveAnswersDriver::class);
}

function braveStreamResponse(StreamInterface $stream, int $status = 200): PromiseInterface
{
    return Create::promiseFor(new PsrResponse($status, [], $stream));
}

function braveRequest(
    ?Profile $profile = null,
    ?CarbonImmutable $deadline = null,
    string $prompt = 'What changed?',
    ?Closure $responseCallback = null,
    ?Closure $partialCaptureCallback = null,
): DriverRequest {
    $profile ??= braveProfile();

    return new DriverRequest(
        requestId: 'request-brave-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
        responseCallback: $responseCallback,
        partialCaptureCallback: $partialCaptureCallback,
    );
}

function braveProfile(?string $credential = 'testing-brave-secret', string $model = 'brave'): Profile
{
    /** @var Collection<int, Corpus> $corpora */
    $corpora = collect([Corpus::Web]);
    /** @var Collection<int, RetrievalMethod> $retrievalMethods */
    $retrievalMethods = collect([RetrievalMethod::SearchEndpoint]);

    return new Profile(
        id: 'brave-answers',
        driver: BraveAnswersDriver::class,
        provider: 'brave',
        model: $model,
        resultKind: ResultKind::GroundedAnswer,
        grounding: GroundingPolicy::Required,
        observation: ObservationMode::ApiOutput,
        corpora: $corpora,
        retrievalMethods: $retrievalMethods,
        prompt: '{{ query }}',
        enabled: true,
        options: [],
        credential: $credential,
    );
}

/**
 * @param  list<string>  $deltas
 * @param  array<array-key, mixed>|null  $usage
 */
function braveSse(array $deltas, ?array $usage = null, bool $terminate = true): string
{
    $events = [];
    foreach ($deltas as $delta) {
        $events[] = 'data: '.json_encode([
            'choices' => [['delta' => ['content' => $delta]]],
        ], JSON_THROW_ON_ERROR);
    }
    if ($usage !== null) {
        $events[] = 'data: '.json_encode(['choices' => [], 'usage' => $usage], JSON_THROW_ON_ERROR);
    }
    if ($terminate) {
        $events[] = 'data: [DONE]';
    }

    return implode("\n\n", $events);
}

function braveCitation(): string
{
    return '<citation>{"url":"https://example.com/source","title":"Source"}</citation>';
}
