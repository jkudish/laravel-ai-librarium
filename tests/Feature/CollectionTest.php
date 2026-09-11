<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Collection\Enums\FailureCategory;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\Enums\SubmissionState;
use Jkudish\LaravelAiLibrarium\Collection\SensitiveCollection;
use Jkudish\LaravelAiLibrarium\Events\ResearchCompleted;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\ResearchStatusStore;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Tests\Support\FakeRuntimeDriver;

beforeEach(function (): void {
    FakeRuntimeDriver::reset();
    CarbonImmutable::setTestNow('2026-09-11T12:00:00Z');
    config()->set('librarium.profiles', [
        'collected' => collectionProfileConfig('secret-token'),
    ]);
    config()->set('librarium.redaction', [
        'paths' => ['private.nested'],
        'patterns' => ['/account-[0-9]+/'],
        'replacement' => '[REDACTED]',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns normalized research with automatically redacted serializable evidence', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): ResearchResult {
        $request->captureResponse(collectionHttpResponse(
            200,
            [
                'Content-Type' => 'application/json',
                'X-Request-Id' => 'provider-request-1',
                'Authorization' => 'Bearer secret-token',
                'Set-Cookie' => 'session=secret-token',
            ],
            <<<'JSON'
{"answer":"Safe answer","credential":"secret-token","encoded":"secret-token%2Fnext","private":{"nested":"account-4815"},"source":"https://example.test/path?api_key=secret-token"}
JSON,
        ));

        return collectionResult($request, 'Safe answer.');
    }];

    $collected = Librarium::query('Research this.')
        ->using('collected')
        ->collect();
    $encoded = json_encode($collected, JSON_THROW_ON_ERROR);

    expect($collected->coverage)->toBe(CaptureCoverage::Complete)
        ->and($collected->response->results->sole()->content)->toBe('Safe answer.')
        ->and($collected->evidence)->toHaveCount(1)
        ->and($collected->evidence->sole()->status)->toBe(200)
        ->and($collected->evidence->sole()->headers)->toBe([
            'content-type' => ['application/json'],
            'x-request-id' => ['provider-request-1'],
        ])
        ->and($collected->evidence->sole()->body)->toContain('[REDACTED]')
        ->and($collected->evidence->sole()->body)->not->toContain('secret-token')
        ->and($collected->evidence->sole()->body)->not->toContain('account-4815')
        ->and($encoded)->not->toContain('secret-token')
        ->and($encoded)->not->toContain('account-4815')
        ->and($collected->toArray()['schema_version'])->toBe(1)
        ->and($collected->toArray()['capture']['coverage'])->toBe('complete');
});

it('makes exact evidence an explicit non-serializable result', function (): void {
    $body = "{\"answer\":\"Safe answer\",\"credential\":\"secret-token\"}\n";
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request) use ($body): ResearchResult {
        $request->captureResponse(collectionHttpResponse(201, ['X-Request-Id' => 'request-2'], $body));

        return collectionResult($request, 'Safe answer.');
    }];

    $collected = Librarium::query('Research this.')
        ->using('collected')
        ->collectSensitive();

    expect($collected)->toBeInstanceOf(SensitiveCollection::class)
        ->and($collected->coverage())->toBe(CaptureCoverage::Complete)
        ->and($collected->responses())->toHaveCount(1)
        ->and($collected->responses()[0]->status())->toBe(201)
        ->and($collected->responses()[0]->body())->toBe($body)
        ->and($collected->responses()[0]->headers())->toBe(['X-Request-Id' => ['request-2']]);

    expect(fn (): string => serialize($collected))
        ->toThrow(LogicException::class, 'Sensitive collection results cannot be serialized.');
});

it('fails closed when a structured response cannot be safely redacted', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): ResearchResult {
        $request->captureResponse(collectionHttpResponse(
            200,
            ['Content-Type' => 'application/json'],
            '{"secret":"secret-token"',
        ));

        return collectionResult($request, 'Safe answer.');
    }];

    $evidence = Librarium::query('Research this.')
        ->using('collected')
        ->collect()
        ->evidence
        ->sole();

    expect($evidence->body)->toBeNull()
        ->and($evidence->omittedReason)->toBe('invalid_json');
});

it('omits evidence instead of losing a successful result when header redaction fails', function (): void {
    config()->set('librarium.redaction.patterns', ['~(*LIMIT_MATCH=1)(a+)+$~']);
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): ResearchResult {
        $request->captureResponse(collectionHttpResponse(
            200,
            ['X-Request-Id' => str_repeat('a', 100).'X'],
            '{"answer":"Safe"}',
        ));

        return collectionResult($request, 'Safe answer.');
    }];

    $collected = Librarium::query('Research this.')->using('collected')->collect();
    $evidence = $collected->evidence->sole();

    expect($collected->response->results->sole()->content)->toBe('Safe answer.')
        ->and($evidence->headers)->toBe([])
        ->and($evidence->body)->toBeNull()
        ->and($evidence->omittedReason)->toBe('redaction_failed');
});

it('redacts sensitive key variants and credential-bearing encoded URLs', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): ResearchResult {
        $request->captureResponse(collectionHttpResponse(
            200,
            ['Content-Type' => 'application/json'],
            <<<'JSON'
{"api_token":"different-provider-secret","token":"ephemeral-secret","callback":"https://example.test/callback?redirect=https%253A%252F%252Fprivate.test%252F%253Faccess_token%253Dnested-secret"}
JSON,
        ));

        return collectionResult($request, 'Safe answer.');
    }];

    $body = Librarium::query('Research this.')->using('collected')->collect()->evidence->sole()->body;

    expect($body)->toContain('[REDACTED]')
        ->not->toContain('different-provider-secret')
        ->not->toContain('ephemeral-secret')
        ->not->toContain('nested-secret')
        ->not->toContain('access_token');
});

it('validates automatic redaction before executing a provider request', function (): void {
    config()->set('librarium.redaction.patterns', ['not-a-regular-expression']);
    FakeRuntimeDriver::$outcomes['collected'] = [function (): never {
        throw new RuntimeException('The provider must not execute.');
    }];

    expect(fn () => Librarium::query('Research this.')->using('collected')->collect())
        ->toThrow(ValidationException::class);
    expect(FakeRuntimeDriver::$requests)->toBeEmpty();
});

it('keeps captured responses out of logs events and ordinary status storage', function (): void {
    Event::fake([ResearchCompleted::class]);
    Log::spy();
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): ResearchResult {
        $request->captureResponse(collectionHttpResponse(
            200,
            ['Authorization' => 'Bearer secret-token'],
            '{"answer":"Safe","secret":"provider-private-body"}',
        ));

        return collectionResult($request, 'Safe answer.');
    }];

    $collected = Librarium::query('Research this.')->using('collected')->collect();
    $status = app(ResearchStatusStore::class)->get($collected->response->requestId);

    Event::assertDispatched(ResearchCompleted::class, function (ResearchCompleted $event): bool {
        $wire = json_encode($event->response, JSON_THROW_ON_ERROR);

        return ! str_contains($wire, 'provider-private-body') && ! str_contains($wire, 'secret-token');
    });
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('notice');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
    expect(json_encode($status?->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('provider-private-body')
        ->not->toContain('secret-token');
});

it('reports unavailable capture for a successful third-party Driver that does not capture', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [
        fn (DriverRequest $request): ResearchResult => collectionResult($request, 'Uncaptured answer.'),
    ];

    $collected = Librarium::query('Research this.')
        ->using('collected')
        ->collect();

    expect($collected->coverage)->toBe(CaptureCoverage::Unavailable)
        ->and($collected->evidence)->toBeEmpty()
        ->and($collected->diagnostics)->toBeEmpty();
});

it('reports partial capture when only some selected Drivers expose their responses', function (): void {
    config()->set('librarium.profiles.uncaptured', collectionProfileConfig('other-secret-token'));
    FakeRuntimeDriver::$outcomes = [
        'collected' => [function (DriverRequest $request): ResearchResult {
            $request->captureResponse(collectionHttpResponse(200, [], '{"answer":"Captured"}'));

            return collectionResult($request, 'Captured.');
        }],
        'uncaptured' => [
            fn (DriverRequest $request): ResearchResult => collectionResult($request, 'Uncaptured.'),
        ],
    ];

    $collected = Librarium::query('Research this.')
        ->fanOut(['collected', 'uncaptured'])
        ->collect();

    expect($collected->coverage)->toBe(CaptureCoverage::Partial)
        ->and($collected->evidence)->toHaveCount(1)
        ->and($collected->response->results)->toHaveCount(2);
});

it('returns safe rate-limit diagnostics and an absolute Retry-After time', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): never {
        $request->captureResponse(
            collectionHttpResponse(429, ['Retry-After' => '120', 'X-Request-Id' => 'rate-request'], '{"error":{"code":"quota_exceeded"}}'),
            Operation::Submit,
        );

        throw new DriverException('test.rate_limited', 'The provider rate limited this request.', false);
    }];

    $collected = Librarium::query('Research this.')
        ->using('collected')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    expect($collected->coverage)->toBe(CaptureCoverage::Complete)
        ->and($diagnostic->category)->toBe(FailureCategory::RateLimit)
        ->and($diagnostic->operation)->toBe(Operation::Submit)
        ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
        ->and($diagnostic->httpStatus)->toBe(429)
        ->and($diagnostic->code)->toBe('test.rate_limited')
        ->and($diagnostic->providerCode)->toBe('quota_exceeded')
        ->and($diagnostic->retryAt?->toIso8601ZuluString())->toBe('2026-09-11T12:02:00Z');
});

it('omits credentials and ambiguous dates from operational diagnostics', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): never {
        $request->captureResponse(collectionHttpResponse(
            429,
            ['Retry-After' => 'tomorrow', 'X-RateLimit-Reset' => 'next week'],
            '{"error":{"code":"secret-token"}}',
        ));

        throw new DriverException('test.rate_limited', 'The provider rate limited this request.', false);
    }];

    $diagnostic = Librarium::query('Research this.')
        ->using('collected')
        ->collect()
        ->diagnostics
        ->sole();

    expect($diagnostic->providerCode)->toBeNull()
        ->and($diagnostic->retryAt)->toBeNull()
        ->and($diagnostic->resetAt)->toBeNull();
});

it('distinguishes an attempted request that returned no response', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): never {
        $request->captureNoResponse(Operation::Submit);

        throw new DriverException('test.submit_connection', 'The submission did not return a response.', false);
    }];

    $collected = Librarium::query('Research this.')
        ->using('collected')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    expect($collected->coverage)->toBe(CaptureCoverage::NoResponse)
        ->and($diagnostic->category)->toBe(FailureCategory::Temporary)
        ->and($diagnostic->operation)->toBe(Operation::Submit)
        ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
        ->and($diagnostic->httpStatus)->toBeNull();
});

it('marks a malformed successful submission receipt as possibly accepted', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [function (DriverRequest $request): never {
        $request->captureResponse(collectionHttpResponse(200, [], '{"status":"unexpected"}'), Operation::Submit);

        throw new DriverException('test.invalid_response', 'The submission receipt was invalid.', false);
    }];

    $diagnostic = Librarium::query('Research this.')
        ->using('collected')
        ->collect()
        ->diagnostics
        ->sole();

    expect($diagnostic->operation)->toBe(Operation::Submit)
        ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
        ->and($diagnostic->httpStatus)->toBe(200);
});

it('keeps response capture request-local across separate collections', function (): void {
    FakeRuntimeDriver::$outcomes['collected'] = [
        function (DriverRequest $request): ResearchResult {
            $request->captureResponse(collectionHttpResponse(200, [], '{"request":"first"}'));

            return collectionResult($request, 'First.');
        },
        function (DriverRequest $request): ResearchResult {
            $request->captureResponse(collectionHttpResponse(200, [], '{"request":"second"}'));

            return collectionResult($request, 'Second.');
        },
    ];

    $first = Librarium::query('First.')->using('collected')->collectSensitive();
    $second = Librarium::query('Second.')->using('collected')->collectSensitive();

    expect($first->responses())->toHaveCount(1)
        ->and($first->responses()[0]->body())->toContain('first')
        ->and($first->responses()[0]->body())->not->toContain('second')
        ->and($second->responses())->toHaveCount(1)
        ->and($second->responses()[0]->body())->toContain('second')
        ->and($second->responses()[0]->body())->not->toContain('first');
});

/** @return array<string, mixed> */
function collectionProfileConfig(string $credential): array
{
    return [
        'driver' => FakeRuntimeDriver::class,
        'provider' => 'test-provider',
        'model' => 'test-model',
        'result_kind' => ResultKind::GroundedAnswer->value,
        'grounding' => GroundingPolicy::Required->value,
        'observation' => ObservationMode::ApiOutput->value,
        'corpora' => [Corpus::Web->value],
        'retrieval_methods' => [RetrievalMethod::ModelSearchTool->value],
        'prompt' => '{{ query }}',
        'enabled' => true,
        'options' => [],
        'credential' => $credential,
    ];
}

/** @param array<string, string|list<string>> $headers */
function collectionHttpResponse(int $status, array $headers, string $body): Response
{
    return new Response(new PsrResponse($status, $headers, $body));
}

function collectionResult(DriverRequest $request, string $content): ResearchResult
{
    return new ResearchResult(
        id: Str::ulid()->toString(),
        contentFormat: ContentFormat::Markdown,
        content: $content,
        requestedProfile: 'driver-placeholder',
        provider: 'driver-placeholder',
        profile: 'driver-placeholder',
        provenance: new ResultProvenance(
            resultKind: $request->profile->resultKind,
            retrievalMethods: $request->profile->retrievalMethods,
            corpora: $request->profile->corpora,
            observedAt: CarbonImmutable::now(),
        ),
        citations: collect(),
        completedAt: CarbonImmutable::now(),
    );
}
