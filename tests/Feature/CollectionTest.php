<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Events\ResearchCompleted;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeRuntimeDriver;

beforeEach(function (): void {
    FakeRuntimeDriver::reset();
    CarbonImmutable::setTestNow('2026-09-11T12:00:00Z');
    config(['librarium.runs.store' => 'memory', 'librarium.sources.collected' => [
        'key' => 'secret-token', 'quick' => runtimeProfileConfig('test-provider'),
    ], 'librarium.redaction' => ['paths' => ['private.nested'], 'patterns' => ['/account-[0-9]+/'], 'replacement' => '[REDACTED]']]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores automatically redacted evidence without exposing it in the public Run or event', function (): void {
    Event::fake([ResearchCompleted::class]);
    Log::spy();
    FakeRuntimeDriver::$outcomes['collected-quick'] = [function (DriverRequest $request) {
        $request->captureResponse(new Response(new PsrResponse(200, [
            'Content-Type' => 'application/json', 'X-Request-Id' => 'request-1',
            'Authorization' => 'Bearer secret-token', 'Set-Cookie' => 'session=secret-token',
        ], '{"answer":"Safe","credential":"secret-token","encoded":"secret-token%2Fnext","private":{"nested":"account-4815"},"source":"https://example.test/?api_key=secret-token","api_token":"different-secret","callback":"https://example.test/?redirect=https%253A%252F%252Fprivate.test%252F%253Faccess_token%253Dnested-secret"}')));

        return runtimeResult($request, 'Safe answer.');
    }];
    $run = Librarium::research('Research this.')->only(['collected'])->run();
    $evidence = $run->evidence('collected')[0];
    expect($run->result('collected')['answer'])->toBe('Safe answer.')
        ->and($evidence['status'])->toBe(200)
        ->and($evidence['headers'])->toBe(['content-type' => ['application/json'], 'x-request-id' => ['request-1']])
        ->and($evidence['body'])->toContain('[REDACTED]');
    foreach (['secret-token', 'account-4815', 'different-secret', 'nested-secret', 'access_token'] as $secret) {
        expect(json_encode($run->evidence('collected')))->not->toContain($secret);
    }
    expect(json_encode($run))->not->toContain('credential');
    Event::assertDispatched(ResearchCompleted::class, fn (ResearchCompleted $event): bool => ! str_contains(json_encode($event->run), 'credential'));
    Log::shouldNotHaveReceived('error');
    expect(Librarium::find($run->id())->evidence('collected'))->toBe($run->evidence('collected'));
});

it('omits unsafe evidence without losing a successful answer', function (string $body, array $headers, ?string $pattern, string $reason): void {
    if ($pattern !== null) {
        config(['librarium.redaction.patterns' => [$pattern]]);
    }
    FakeRuntimeDriver::$outcomes['collected-quick'] = [function (DriverRequest $request) use ($headers, $body) {
        $request->captureResponse(new Response(new PsrResponse(200, $headers, $body)));

        return runtimeResult($request, 'Safe answer.');
    }];
    $run = Librarium::research('Research this.')->only(['collected'])->run();
    expect($run->status())->toBe('done')
        ->and($run->evidence('collected')[0]['body'] ?? null)->toBeNull()
        ->and($run->evidence('collected')[0]['omitted_reason'])->toBe($reason);
})->with([
    ['{"secret":"secret-token"', ['Content-Type' => 'application/json'], null, 'invalid_json'],
    ['{"answer":"Safe"}', ['X-Request-Id' => str_repeat('a', 100).'X'], '~(*LIMIT_MATCH=1)(a+)+$~', 'redaction_failed'],
]);

it('validates redaction before any provider call', function (): void {
    config(['librarium.redaction.patterns' => ['not-a-regular-expression']]);
    expect(fn () => Librarium::research('Research')->only(['collected'])->run())->toThrow(ValidationException::class);
    expect(FakeRuntimeDriver::$requests)->toBeEmpty();
});

it('stores safe diagnostics for failed and ambiguous operations', function (?int $status, string $body, array $headers, string $code, string $category, string $state, ?string $providerCode, ?string $retryAt): void {
    FakeRuntimeDriver::$outcomes['collected-quick'] = [function (DriverRequest $request) use ($status, $body, $headers, $code): never {
        if ($status === null) {
            $request->captureNoResponse(Operation::Submit);
        } else {
            $request->captureResponse(new Response(new PsrResponse($status, $headers, $body)), Operation::Submit);
        }
        throw new DriverException($code, 'Safe provider failure.', false);
    }];
    $run = Librarium::research('Research')->only(['collected'])->run();
    $diagnostic = $run->diagnostics()[0];
    expect($run->status())->toBe('failed')
        ->and($diagnostic['category'])->toBe($category)
        ->and($diagnostic['operation'])->toBe('submit')
        ->and($diagnostic['submission_state'])->toBe($state)
        ->and($diagnostic['http_status'] ?? null)->toBe($status)
        ->and($diagnostic['provider_code'] ?? null)->toBe($providerCode)
        ->and($diagnostic['retry_at'] ?? null)->toBe($retryAt)
        ->and($diagnostic['reset_at'] ?? null)->toBeNull();
})->with([
    [429, '{"error":{"code":"quota_exceeded"}}', ['Retry-After' => '120'], 'test.rate_limited', 'rate_limit', 'possibly_accepted', 'quota_exceeded', '2026-09-11T12:02:00Z'],
    [429, '{"error":{"code":"secret-token"}}', ['Retry-After' => 'tomorrow', 'X-RateLimit-Reset' => 'next week'], 'test.rate_limited', 'rate_limit', 'possibly_accepted', null, null],
    [null, '', [], 'test.submit_connection', 'temporary', 'possibly_accepted', null, null],
    [200, '{"status":"unexpected"}', [], 'test.invalid_response', 'permanent', 'possibly_accepted', null, null],
]);

it('keeps capture isolated between Runs and supports uncaptured custom drivers', function (): void {
    FakeRuntimeDriver::$outcomes['collected-quick'] = [
        function (DriverRequest $request) {
            $request->captureResponse(new Response(new PsrResponse(200, [], '{"request":"first"}')));

            return runtimeResult($request, 'First.');
        },
        fn (DriverRequest $request) => runtimeResult($request, 'Second.'),
    ];
    $first = Librarium::research('First')->only(['collected'])->run();
    $second = Librarium::research('Second')->only(['collected'])->run();
    expect($first->evidence('collected'))->toHaveCount(1)
        ->and($second->evidence('collected'))->toBe([])
        ->and($second->diagnostics())->toBe([]);
});
