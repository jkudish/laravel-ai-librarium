<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\Gemini\GeminiDeepDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Gemini\GeminiDeepResultMapper;
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
    CarbonImmutable::setTestNow('2026-09-10T12:00:00Z');
    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('submits the exact Deep Research interaction and preserves provisional status ordering before canonical retrieval', function (): void {
    $transports = [];
    Http::globalMiddleware(function (callable $handler) use (&$transports): Closure {
        return function ($request, array $options) use ($handler, &$transports) {
            $transports[] = $options;

            return $handler($request, $options);
        };
    });
    Http::fake(['https://generativelanguage.googleapis.com/v1beta/interactions*' => Http::sequence()
        ->push(geminiDeepInteraction('in_progress'), 200)
        ->push(geminiDeepInteraction('queued'))
        ->push(geminiDeepInteraction('requires_action'))
        ->push(geminiDeepInteraction('future_provider_state'))
        ->push(geminiDeepInteraction('failed', error: ['message' => 'provider-secret']))
        ->push(geminiDeepInteraction('incomplete', error: ['message' => 'provider-secret']))
        ->push(geminiDeepInteraction('completed', completed: true))
        ->push(geminiDeepInteraction('completed', completed: true))]);
    $progress = [];

    $result = app(GeminiDeepDriver::class)->run(geminiDeepRequest(progress: $progress));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
            && $request->hasHeader('x-goog-api-key', 'testing-gemini-key')
            && $request->hasHeader('Api-Revision', '2026-05-20')
            && ! $request->hasHeader('Idempotency-Key')
            && $request->data() === [
                'input' => 'Research durable agent systems.',
                'agent' => 'deep-research-preview-04-2026',
                'background' => true,
                'agent_config' => [
                    'type' => 'deep-research',
                    'thinking_summaries' => 'none',
                    'visualization' => 'off',
                ],
                'tools' => [['type' => 'google_search']],
            ];
    });
    $gets = collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === 'GET');
    expect($gets)->toHaveCount(7);
    $gets->each(function (Request $request): void {
        expect($request->url())->toBe('https://generativelanguage.googleapis.com/v1beta/interactions/'.geminiDeepInteractionId())
            ->and($request->hasHeader('x-goog-api-key', 'testing-gemini-key'))->toBeTrue()
            ->and($request->hasHeader('Api-Revision', '2026-05-20'))->toBeTrue();
    });
    Http::assertSentCount(8);
    Sleep::assertSleptTimes(6);

    expect($transports)->toHaveCount(8)
        ->and(collect($transports)->every(fn (array $transport): bool => ($transport['allow_redirects'] ?? null) === false))->toBeTrue()
        ->and($progress)->toBe(['Gemini is researching in the background.'])
        ->and($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe('Canonical Deep Research report.')
        ->and($result->provider)->toBe('gemini-deep')
        ->and($result->profile)->toBe('gemini-deep-research')
        ->and($result->model)->toBe('deep-research-preview-04-2026')
        ->and($result->completedAt->toIso8601ZuluString())->toBe('2026-09-10T12:02:00Z')
        ->and($result->provenance->resultKind)->toBe(ResultKind::ResearchReport)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ResearchAgent])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->citations)->toHaveCount(3)
        ->and($result->citations->pluck('derivation')->unique()->sole())->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->pluck('source.url')->all())->toBe([
            'https://example.com/research',
            'https://maps.example.com/lab',
            'https://example.com/report.pdf',
        ])
        ->and($result->citations->first()->locator)->toBe('bytes:0-12')
        ->and($result->usage?->promptTokens)->toBe(90)
        ->and($result->usage?->completionTokens)->toBe(390)
        ->and($result->usage?->cacheReadInputTokens)->toBe(30)
        ->and($result->usage?->reasoningTokens)->toBe(50)
        ->and($result->usage?->toolUseTokens)->toBe(10)
        ->and((array) $result->providerMeta)->toBe([
            'invocation_id' => 'request-gemini-deep',
            'step_count' => 0,
        ]);

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain(geminiDeepInteractionId())
        ->not->toContain('testing-gemini-key')
        ->not->toContain('provider-secret')
        ->not->toContain('X-Goog-Signature')
        ->not->toContain('signed-secret');
});

it('retrieves canonical output when submission itself reports completion', function (): void {
    $submission = geminiDeepInteraction('completed', completed: true);
    $submission['output_text'] = 'Untrusted submission report.';
    Http::fakeSequence()
        ->push($submission)
        ->push(geminiDeepInteraction('completed', completed: true));

    $result = app(GeminiDeepDriver::class)->run(geminiDeepRequest());

    expect($result->content)->toBe('Canonical Deep Research report.');
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

it('preserves reported zero usage while leaving absent Gemini usage unknown', function (): void {
    $reported = geminiDeepInteraction('completed', completed: true);
    $reported['usage'] = [
        'total_input_tokens' => 0,
        'total_cached_tokens' => 0,
        'total_output_tokens' => 0,
        'total_thought_tokens' => 0,
    ];
    $unknown = geminiDeepInteraction('completed', completed: true);
    unset($unknown['usage']);
    $thoughtOnly = geminiDeepInteraction('completed', completed: true);
    $thoughtOnly['usage'] = ['total_thought_tokens' => 50];
    $mapper = app(GeminiDeepResultMapper::class);

    $reportedUsage = $mapper->map($reported, geminiDeepRequest())->usage;
    $unknownUsage = $mapper->map($unknown, geminiDeepRequest())->usage;
    $thoughtOnlyUsage = $mapper->map($thoughtOnly, geminiDeepRequest())->usage;

    expect($reportedUsage?->promptTokens)->toBe(0)
        ->and($reportedUsage?->completionTokens)->toBe(0)
        ->and($reportedUsage?->cacheReadInputTokens)->toBe(0)
        ->and($reportedUsage?->reasoningTokens)->toBe(0)
        ->and($unknownUsage?->promptTokens)->toBeNull()
        ->and($unknownUsage?->completionTokens)->toBeNull()
        ->and($unknownUsage?->cacheReadInputTokens)->toBeNull()
        ->and($unknownUsage?->reasoningTokens)->toBeNull()
        // Thought tokens alone cannot reconstruct the inclusive completion
        // total, so it stays unknown rather than understating output.
        ->and($thoughtOnlyUsage?->completionTokens)->toBeNull()
        ->and($thoughtOnlyUsage?->reasoningTokens)->toBe(50);
});

it('partitions the inclusive input total only while the remainder stays nonnegative', function (): void {
    $negative = geminiDeepInteraction('completed', completed: true);
    $negative['usage'] = [
        'total_input_tokens' => 10,
        'total_cached_tokens' => 30,
        'total_output_tokens' => 340,
        'total_thought_tokens' => 50,
        'total_tool_use_tokens' => 25,
    ];
    $zero = geminiDeepInteraction('completed', completed: true);
    $zero['usage'] = [
        'total_input_tokens' => 30,
        'total_cached_tokens' => 30,
        'total_output_tokens' => 340,
        'total_thought_tokens' => 50,
    ];
    $positive = geminiDeepInteraction('completed', completed: true);
    $positive['usage'] = [
        'total_input_tokens' => 120,
        'total_cached_tokens' => 30,
        'total_output_tokens' => 340,
        'total_thought_tokens' => 50,
    ];
    $mapper = app(GeminiDeepResultMapper::class);

    $negativeUsage = $mapper->map($negative, geminiDeepRequest())->usage;
    $zeroUsage = $mapper->map($zero, geminiDeepRequest())->usage;
    $positiveUsage = $mapper->map($positive, geminiDeepRequest())->usage;

    // A cached partition larger than the inclusive input total means the
    // provider's usage report is internally inconsistent, so the whole input
    // side fails closed to unknown instead of pricing unaccounted tokens.
    expect($negativeUsage?->promptTokens)->toBeNull()
        ->and($negativeUsage?->cacheReadInputTokens)->toBeNull()
        ->and($negativeUsage?->toArray())->toBe([
            'completion_tokens' => 390,
            'reasoning_tokens' => 50,
            'tool_use_tokens' => 25,
        ])
        ->and($zeroUsage?->promptTokens)->toBe(0)
        ->and($zeroUsage?->cacheReadInputTokens)->toBe(30)
        ->and($zeroUsage?->toolUseTokens)->toBeNull()
        ->and($positiveUsage?->promptTokens)->toBe(90)
        ->and($positiveUsage?->cacheReadInputTokens)->toBe(30);
});

it('labels multibyte citation offsets with the reported byte unit', function (): void {
    $completed = geminiDeepInteraction('completed', completed: true);
    data_set($completed, 'steps.0.content.0', [
        'type' => 'text',
        // 'É' is two bytes and the rocket emoji four, so the word "research"
        // spans bytes 22-30 while a character-based locator would say 18-26.
        'text' => 'Émile 🚀 published research.',
        'annotations' => [[
            'type' => 'url_citation',
            'url' => 'https://example.com/research',
            'title' => 'Research source',
            'start_index' => 22,
            'end_index' => 30,
        ]],
    ]);
    Http::fakeSequence()
        ->push(geminiDeepInteraction('in_progress'))
        ->push($completed)
        ->push($completed);

    $result = app(GeminiDeepDriver::class)->run(geminiDeepRequest());

    expect($result->citations->first()->locator)->toBe('bytes:22-30');
});

it('falls back to ordered model output text blocks when output_text is absent', function (): void {
    $completed = geminiDeepInteraction('completed', completed: true);
    unset($completed['output_text']);
    data_set($completed, 'steps.0.content.0.text', 'Part one.');
    $completed['steps'][0]['content'][] = ['type' => 'text', 'text' => 'Part two.'];
    Http::fakeSequence()
        ->push(geminiDeepInteraction('in_progress'))
        ->push($completed)
        ->push($completed);

    $result = app(GeminiDeepDriver::class)->run(geminiDeepRequest());

    expect($result->content)->toBe("Part one.\nPart two.");
});

it('supports only the two documented Deep Research agents', function (string $agent): void {
    Http::fakeSequence()
        ->push(geminiDeepInteraction('completed'))
        ->push(geminiDeepInteraction('completed', completed: true, agent: $agent));

    $result = app(GeminiDeepDriver::class)->run(geminiDeepRequest(model: $agent));

    expect($result->model)->toBe($agent);
    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST'
        || data_get($request->data(), 'agent') === $agent);
})->with([
    'standard' => 'deep-research-preview-04-2026',
    'max' => 'deep-research-max-preview-04-2026',
]);

it('rejects credentials, options, input, model, and deadline errors before HTTP', function (DriverRequest $request, string $code): void {
    Http::fake();

    expect(fn () => app(GeminiDeepDriver::class)->run($request))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse();
        });

    Http::assertNothingSent();
})->with([
    'missing credential' => fn () => [geminiDeepRequest(credential: null), 'gemini_deep.authentication'],
    'unknown option' => fn () => [geminiDeepRequest(options: ['collaborative_planning' => true]), 'gemini_deep.invalid_request'],
    'blank input' => fn () => [geminiDeepRequest(prompt: '  '), 'gemini_deep.invalid_request'],
    'chat model' => fn () => [geminiDeepRequest(model: 'gemini-3.7-flash'), 'gemini_deep.invalid_profile'],
    'expired deadline' => fn () => [geminiDeepRequest(deadline: CarbonImmutable::now()), 'gemini_deep.deadline_exceeded'],
]);

it('rejects profiles with grounded chat or otherwise incompatible provenance semantics', function (array $overrides): void {
    Http::fake();

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest(...$overrides)))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('gemini_deep.invalid_profile')
                ->and($exception->fallbackAllowed)->toBeFalse();
        });

    Http::assertNothingSent();
})->with([
    'provider' => [['provider' => 'gemini']],
    'result kind' => [['resultKind' => ResultKind::GroundedAnswer]],
    'grounding' => [['grounding' => GroundingPolicy::Optional]],
    'observation' => [['observation' => ObservationMode::SurfaceSnapshot]],
    'corpus' => [['corpora' => [Corpus::News]]],
    'retrieval method' => [['retrievalMethods' => [RetrievalMethod::ModelSearchTool]]],
]);

it('treats only stable cancellation and budget exhaustion observations as immediate failures', function (string $status, string $code): void {
    Http::fakeSequence()
        ->push(geminiDeepInteraction('in_progress'))
        ->push(geminiDeepInteraction($status, error: ['message' => 'provider-secret testing-gemini-key']));

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('testing-gemini-key');
        });

    Http::assertSentCount(2);
})->with([
    'cancelled' => ['cancelled', 'gemini_deep.cancelled'],
    'budget exceeded' => ['budget_exceeded', 'gemini_deep.budget_exceeded'],
]);

it('bounds repeated failed and incomplete observations by the request-wide deadline', function (string $status): void {
    Http::fakeSequence()
        ->push(geminiDeepInteraction($status))
        ->push(geminiDeepInteraction($status));
    Sleep::whenFakingSleep(static function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(15));
    });

    expect(fn () => app(GeminiDeepDriver::class)->run(
        geminiDeepRequest(deadline: CarbonImmutable::now()->addSeconds(30)),
    ))->toThrow(function (DriverException $exception): void {
        expect($exception->errorCode)->toBe('gemini_deep.deadline_exceeded')
            ->and($exception->fallbackAllowed)->toBeFalse();
    });

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(2);
})->with(['failed', 'incomplete']);

it('reconciles retryable known-handle polling and duplicate-retrieval failures without resubmitting', function (): void {
    Http::fakeSequence()
        ->push(geminiDeepInteraction('in_progress'))
        ->push(['error' => ['message' => 'provider-secret']], 503)
        ->push(geminiDeepInteraction('completed', completed: true))
        ->push(['error' => ['message' => 'provider-secret']], 429)
        ->push(geminiDeepInteraction('completed', completed: true))
        ->push(geminiDeepInteraction('completed', completed: true));

    $result = app(GeminiDeepDriver::class)->run(geminiDeepRequest());

    expect($result->content)->toBe('Canonical Deep Research report.');
    Http::assertSentCount(6);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST'))->toHaveCount(1);
});

it('never retries an ambiguous submission connection failure', function (): void {
    $attempts = 0;
    Http::fake(function (Request $request) use (&$attempts): never {
        $attempts++;
        expect($request->method())->toBe('POST');

        throw new ConnectionException('provider-secret testing-gemini-key');
    });

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('gemini_deep.submit_connection')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('testing-gemini-key');
        });

    expect($attempts)->toBe(1);
});

it('validates an accepted private handle before rejecting a late submission response', function (): void {
    $deadline = CarbonImmutable::now()->addSecond();
    Http::fake(function () use ($deadline) {
        CarbonImmutable::setTestNow($deadline);

        return Http::response(geminiDeepInteraction('in_progress'));
    });

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest(deadline: $deadline)))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('gemini_deep.deadline_exceeded')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain(geminiDeepInteractionId());
        });

    Http::assertSentCount(1);
});

it('maps submission HTTP failures safely and sends exactly one paid POST', function (int $status, string $code): void {
    Http::fakeSequence()->push([
        'error' => [
            'message' => 'provider-secret testing-gemini-key',
            'url' => 'https://signed.example/file?X-Goog-Signature=signed-secret',
        ],
    ], $status);

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('testing-gemini-key')
                ->not->toContain('signed-secret');
        });

    Http::assertSentCount(1);
})->with([
    'authentication' => [401, 'gemini_deep.authentication'],
    'authorization' => [403, 'gemini_deep.authorization'],
    'timeout' => [408, 'gemini_deep.ambiguous_submission'],
    'rate limit' => [429, 'gemini_deep.ambiguous_submission'],
    'unavailable' => [503, 'gemini_deep.ambiguous_submission'],
    'invalid request' => [422, 'gemini_deep.invalid_request'],
]);

it('treats a malformed successful submission receipt as ambiguous and never resubmits', function (): void {
    Http::fake(['https://generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
        'id' => geminiDeepInteractionId(),
        'raw' => 'provider-secret',
    ])]);

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('gemini_deep.ambiguous_submission')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain(geminiDeepInteractionId())
                ->not->toContain('provider-secret');
        });

    Http::assertSentCount(1);
});

it('maps non-retryable retrieval failures and malformed bodies without exposing raw data', function (mixed $body, int $status, string $code): void {
    Http::fakeSequence()
        ->push(geminiDeepInteraction('in_progress'))
        ->push($body, $status);

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret');
        });
})->with([
    'missing known handle' => [['error' => ['message' => 'provider-secret']], 404, 'gemini_deep.interaction_missing'],
    'non-object body' => ['provider-secret', 200, 'gemini_deep.invalid_response'],
    'mismatched handle' => [['id' => 'different-private-handle', 'status' => 'completed', 'raw' => 'provider-secret'], 200, 'gemini_deep.invalid_response'],
    'missing status' => [['id' => geminiDeepInteractionId(), 'raw' => 'provider-secret'], 200, 'gemini_deep.invalid_response'],
]);

it('rejects terminal reports without usable cited text and redacts signed-only citations', function (string $case): void {
    $completed = geminiDeepInteraction('completed', completed: true);
    if ($case === 'blank report') {
        $completed['output_text'] = ' ';
        data_set($completed, 'steps.0.content.0.text', ' ');
    } else {
        data_set($completed, 'steps.0.content.0.annotations', [[
            'type' => 'url_citation',
            'url' => 'https://signed.example/report?X-Goog-Signature=signed-secret',
        ]]);
    }
    Http::fakeSequence()
        ->push(geminiDeepInteraction('completed'))
        ->push($completed);

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('gemini_deep.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('signed-secret');
        });
})->with([
    'blank report',
    'signed citations only',
]);

it('rejects credentials and private handles reflected into retained output', function (string $private, string $field): void {
    $completed = geminiDeepInteraction('completed', completed: true);
    if ($field === 'output') {
        $completed['output_text'] = "Report containing {$private}";
    } else {
        data_set($completed, 'steps.0.content.0.annotations.0.title', "Source {$private}");
    }
    Http::fakeSequence()
        ->push(geminiDeepInteraction('completed'))
        ->push($completed);

    expect(fn () => app(GeminiDeepDriver::class)->run(geminiDeepRequest()))
        ->toThrow(function (DriverException $exception) use ($private): void {
            expect($exception->errorCode)->toBe('gemini_deep.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain($private);
        });
})->with([
    'credential in report' => ['testing-gemini-key', 'output'],
    'private handle in citation title' => [geminiDeepInteractionId(), 'citation'],
]);

/** @param list<string> $progress */
function geminiDeepRequest(
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-gemini-key',
    ?string $model = 'deep-research-preview-04-2026',
    array $options = [],
    string $prompt = 'Research durable agent systems.',
    array &$progress = [],
    string $provider = 'gemini-deep',
    ResultKind $resultKind = ResultKind::ResearchReport,
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ObservationMode $observation = ObservationMode::ApiOutput,
    array $corpora = [Corpus::Web],
    array $retrievalMethods = [RetrievalMethod::ResearchAgent],
): DriverRequest {
    $profile = new Profile(
        id: 'gemini-deep-research',
        driver: GeminiDeepDriver::class,
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
        requestId: 'request-gemini-deep',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addMinutes(5),
        progressCallback: static function (string $message) use (&$progress): void {
            $progress[] = $message;
        },
    );
}

/** @param array<string, mixed>|null $error
 * @return array<string, mixed>
 */
function geminiDeepInteraction(
    string $status,
    bool $completed = false,
    ?array $error = null,
    string $agent = 'deep-research-preview-04-2026',
): array {
    $interaction = [
        'id' => geminiDeepInteractionId(),
        'object' => 'interaction',
        'status' => $status,
        'agent' => $agent,
        'created' => '2026-09-10T12:00:00Z',
        'updated' => $completed ? '2026-09-10T12:02:00Z' : '2026-09-10T12:00:00Z',
    ];
    if ($error !== null) {
        $interaction['error'] = $error;
    }
    if (! $completed) {
        return $interaction;
    }

    return $interaction + [
        'output_text' => 'Canonical Deep Research report.',
        'steps' => [[
            'type' => 'model_output',
            'content' => [[
                'type' => 'text',
                'text' => 'Step report should not override output_text.',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/research',
                        'title' => 'Research source',
                        'start_index' => 0,
                        'end_index' => 12,
                    ],
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/research',
                        'title' => 'Duplicate source',
                    ],
                    [
                        'type' => 'place_citation',
                        'url' => 'https://maps.example.com/lab',
                        'name' => 'Research lab',
                    ],
                    [
                        'type' => 'file_citation',
                        'document_uri' => 'https://example.com/report.pdf',
                        'file_name' => 'Research report',
                    ],
                    [
                        'type' => 'file_citation',
                        'document_uri' => 'https://signed.example.com/report.pdf?X-Goog-Signature=signed-secret',
                        'file_name' => 'Private signed report',
                    ],
                ],
            ], [
                'type' => 'image',
                'uri' => 'https://signed.example.com/chart.png?X-Goog-Signature=signed-secret',
            ]],
        ]],
        'usage' => [
            'total_input_tokens' => 120,
            'total_cached_tokens' => 30,
            'total_output_tokens' => 340,
            'total_thought_tokens' => 50,
            'total_tokens' => 510,
            'total_tool_use_tokens' => 10,
        ],
        'raw' => [
            'body' => 'provider-secret',
            'download_url' => 'https://signed.example.com/raw?X-Goog-Signature=signed-secret',
        ],
    ];
}

function geminiDeepInteractionId(): string
{
    return 'v1_private-interaction-handle';
}
