<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\Enums\SubmissionState;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiDriver;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchAgent;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchResultMapper;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage as AiUsage;
use Laravel\Ai\Responses\TextResponse;

beforeEach(function (): void {
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstance(AiManager::class);

    // The shipped openrouter-chat Profile default stays on the historical
    // Terra route; the Luna canary selects the bounded Luna route the way
    // Platform does, through an explicit model override.
    config()->set('librarium.profiles.openrouter-chat.model', 'openai/gpt-6-luna');
});

it('bounds exact grounded Luna quantities from the documented server-tool budget', function (int $budget, string $inputTokens, string $outputTokens, string $searchRequests): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions(maxToolCalls: $budget));

    $bound = Librarium::query('Bound this grounded Luna answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::BillableQuantities)
        ->and($bound->pricingIdentity?->key())->toBe('openrouter:openai/gpt-6-luna-272k')
        ->and($bound->quantities?->toArray())->toBe([
            'cache_write_input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'exa_search_requests' => $searchRequests,
        ]);
    Http::assertNothingSent();
})->with([
    'minimum budget' => [1, '2100000', '256000', '1'],
    'moderate budget' => [4, '5250000', '640000', '4'],
    'canary budget' => [5, '6300000', '768000', '5'],
    'maximum documented budget' => [30, '32550000', '3968000', '30'],
]);

it('returns an unknown Luna bound unless every reviewed bounded option is exact', function (array $options): void {
    config()->set('librarium.profiles.openrouter-chat.options', $options);

    $bound = Librarium::query('Bound this grounded Luna answer.')
        ->using('openrouter-chat')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertNothingSent();
})->with([
    'not the reviewed Exa auto mode' => [fn (): array => lunaBoundedOptions(searchMode: 'deep')],
    'a cap beside the budget' => [fn (): array => lunaBoundedOptions() + ['max_searches' => 2]],
    'no server-tool budget' => [fn (): array => array_diff_key(lunaBoundedOptions(), ['max_tool_calls' => true])],
    'web search disabled' => [fn (): array => lunaBoundedOptions(webSearch: false)],
]);

it('sends the exact grounded Luna budget, endpoint pin, max_price, reasoning, Exa mode, and revision controls through the real SDK', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions(maxToolCalls: 4));

    Http::fake(lunaCatalogFake() + [
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(lunaCompletionBody()),
    ]);

    $response = Librarium::query('Bound this grounded Luna answer.')
        ->using('openrouter-chat')
        ->run();

    expect($response->status->value)->toBe('succeeded')
        ->and($response->results->sole()->citations)->toHaveCount(1)
        ->and($response->results->sole()->model)->toBe('openai/gpt-6-luna-20260922')
        ->and($response->results->sole()->usage?->serverToolSearchRequests)->toBe(4);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $body['model'] === 'openai/gpt-6-luna'
            && $body['max_tool_calls'] === 4
            && ! array_key_exists('max_tokens', $body)
            && $body['tool_choice'] === 'auto'
            && $body['tools'] === [[
                'type' => 'openrouter:web_search',
                'parameters' => [
                    'engine' => 'exa',
                    'mode' => 'auto',
                    'max_results' => 5,
                ],
            ]]
            && $body['provider'] === [
                'order' => ['openai'],
                'ignore' => ['openai/flex', 'openai/fast'],
                'max_price' => ['prompt' => '0.1', 'completion' => '0.5'],
                'require_parameters' => true,
                'allow_fallbacks' => false,
            ]
            && $body['reasoning'] === ['effort' => 'max', 'exclude' => true]
            && str_contains((string) json_encode($body), 'Bound this grounded Luna answer.');
    });
    Http::assertSentCount(2);
});

it('checks the pinned Luna revision against the public catalog before dispatching the inference request', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions(maxToolCalls: 4));
    Http::fake(lunaCatalogFake() + [
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(lunaCompletionBody()),
    ]);

    $response = Librarium::query('Bound this grounded Luna answer.')
        ->using('openrouter-chat')
        ->run();

    expect($response->status->value)->toBe('succeeded');
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-6-luna'
            && ! $request->hasHeader('Authorization');
    });
    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->data()['model'] === 'openai/gpt-6-luna';
    });
    Http::assertSentCount(2);

    // The catalog check must precede the billable inference POST, so ordering
    // is part of the contract: rejection happens before any spend can occur.
    $recorded = Http::recorded();
    expect($recorded[0][0]->url())->toBe('https://openrouter.ai/api/v1/model/openai/gpt-6-luna')
        ->and($recorded[0][0]->method())->toBe('GET')
        ->and($recorded[1][0]->url())->toBe('https://openrouter.ai/api/v1/chat/completions');
});

it('fails closed before dispatch when the catalog resolves a different Luna revision', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-6-luna' => Http::response([
            'data' => ['canonical_slug' => 'openai/gpt-6-luna-20261101'],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(lunaCompletionBody(model: 'openai/gpt-6-luna-20261101')),
    ]);

    try {
        lunaDriver()->run(lunaBoundedRequest(options: lunaBoundedOptions()));
        $this->fail('A Luna catalog revision other than the reviewed exact revision should fail closed before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_drift_before_dispatch')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    Http::assertSentCount(1);
});

it('fails closed before dispatch when the Luna catalog cannot prove the revision', function (array|string $body, int $status): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-6-luna' => Http::response($body, $status),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(lunaCompletionBody()),
    ]);

    try {
        lunaDriver()->run(lunaBoundedRequest(options: lunaBoundedOptions()));
        $this->fail('An unverifiable Luna catalog answer should fail closed before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_unverified')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    Http::assertSentCount(1);
})->with([
    'server error' => [[], 500],
    'not found' => [[], 404],
    'non-json body' => ['upstream maintenance', 200],
    'missing data object' => [['error' => ['message' => 'boom']], 200],
    'missing canonical slug' => [['data' => ['id' => 'openai/gpt-6-luna']], 200],
    'non-string canonical slug' => [['data' => ['canonical_slug' => ['openai/gpt-6-luna-20260922']]], 200],
]);

it('fails closed before dispatch when the Luna catalog is unreachable', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake(function (): never {
        throw new ConnectionException('The model catalog could not be reached.');
    });

    try {
        lunaDriver()->run(lunaBoundedRequest(options: lunaBoundedOptions()));
        $this->fail('An unreachable Luna catalog should fail closed before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_unverified')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
});

it('never persists Luna catalog traffic as provider inference evidence', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-6-luna' => Http::response([
            'data' => ['canonical_slug' => 'openai/gpt-6-luna-20261101'],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(lunaCompletionBody(model: 'openai/gpt-6-luna-20261101')),
    ]);

    $captured = [];
    $request = lunaBoundedRequest(
        options: lunaBoundedOptions(),
        responseCallback: function (HttpResponse $response, Operation $operation) use (&$captured): void {
            $captured[] = [$response, $operation];
        },
    );

    try {
        lunaDriver()->run($request);
        $this->fail('The drifted Luna catalog answer should fail closed before dispatch.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('laravel_ai.model_revision_drift_before_dispatch')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    // Catalog traffic is never recorded as provider inference evidence: the
    // preflight rejection records zero attempts, so collection diagnostics
    // state NotSent by construction and the catalog exchange never persists
    // as a paid result.
    expect($captured)->toBeEmpty();
    Http::assertNotSent(fn (Request $sent): bool => $sent->url() === 'https://openrouter.ai/api/v1/chat/completions');
});

it('settles Luna preflight rejections as not sent with no inference evidence', function (array $body, int $status, string $expectedCode): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake([
        'https://openrouter.ai/api/v1/model/openai/gpt-6-luna' => Http::response($body, $status),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(lunaCompletionBody(model: 'openai/gpt-6-luna-20261101')),
    ]);

    $collected = Librarium::query('Bound this grounded Luna answer.')
        ->using('openrouter-chat')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    expect($collected->response->status->value)->toBe('failed')
        ->and($collected->response->errors->sole()->code)->toBe($expectedCode)
        ->and($diagnostic->code)->toBe($expectedCode)
        ->and($diagnostic->submissionState)->toBe(SubmissionState::NotSent)
        ->and($diagnostic->operation)->toBe(Operation::Inline)
        ->and($collected->coverage)->toBe(CaptureCoverage::Unavailable)
        ->and($collected->evidence)->toBeEmpty();
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
})->with([
    'catalog resolves a different revision' => [
        ['data' => ['canonical_slug' => 'openai/gpt-6-luna-20261101']], 200, 'laravel_ai.model_revision_drift_before_dispatch',
    ],
    'catalog server error' => [
        [], 500, 'laravel_ai.model_revision_unverified',
    ],
    'catalog not found' => [
        [], 404, 'laravel_ai.model_revision_unverified',
    ],
]);

it('settles an unreachable Luna catalog as not sent', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake(function (): never {
        throw new ConnectionException('The model catalog could not be reached.');
    });

    $collected = Librarium::query('Bound this grounded Luna answer.')
        ->using('openrouter-chat')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    expect($collected->response->errors->sole()->code)->toBe('laravel_ai.model_revision_unverified')
        ->and($diagnostic->code)->toBe('laravel_ai.model_revision_unverified')
        ->and($diagnostic->submissionState)->toBe(SubmissionState::NotSent)
        ->and($collected->coverage)->toBe(CaptureCoverage::Unavailable)
        ->and($collected->evidence)->toBeEmpty();
    Http::assertNothingSent();
});

it('settles Luna response-side drift without raw capture as possibly accepted, never not sent', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake(lunaCatalogFake());
    ResearchAgent::fake([
        new TextResponse(
            'A drifted revision answer.',
            new AiUsage(promptTokens: 900, completionTokens: 120),
            new Meta('openrouter', 'openai/gpt-6-luna-20261101'),
        ),
    ])->preventStrayPrompts();

    $collected = Librarium::query('Bound this grounded Luna answer.')
        ->using('openrouter-chat')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    // The preflight passed, so the inference prompt definitely ran before the
    // drift was rejected. With no raw SDK exchange to capture, the dispatch
    // is still recorded, so settlement stays PossiblyAccepted — a spent
    // reservation is never falsely released as NotSent.
    expect($collected->response->errors->sole()->code)->toBe('laravel_ai.model_revision_drift')
        ->and($diagnostic->code)->toBe('laravel_ai.model_revision_drift')
        ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
        ->and($diagnostic->operation)->toBe(Operation::Inline)
        ->and($collected->coverage)->toBe(CaptureCoverage::NoResponse)
        ->and($collected->evidence)->toBeEmpty();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-6-luna');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    // Exactly one catalog GET and no inference POST retry after rejection.
    Http::assertSentCount(1);
});

it('settles a runtime timeout after a conforming no-raw Luna response as possibly accepted, never not sent', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake(lunaCatalogFake());
    CarbonImmutable::setTestNow('2026-09-23T00:00:00Z');

    ResearchAgent::fake(function (): TextResponse {
        // The dispatch itself pushes the frozen clock past the shared 2s
        // deadline; the SDK then returns a fully conforming dated response
        // with no raw exchange to capture.
        CarbonImmutable::setTestNow('2026-09-23T00:00:03Z');

        return new TextResponse(
            'A grounded Luna answer.',
            new AiUsage(promptTokens: 900, completionTokens: 120),
            new Meta('openrouter', 'openai/gpt-6-luna-20260922'),
        );
    })->preventStrayPrompts();

    try {
        $collected = Librarium::query('Bound this grounded Luna answer.')
            ->using('openrouter-chat')
            ->timeout(2)
            ->collect();
        $diagnostic = $collected->diagnostics->sole();

        // The inference dispatch definitely happened before the runtime's
        // post-response deadline check rejected the result. With no raw SDK
        // exchange, the dispatch is still recorded as an unanswered attempt,
        // so the timeout settles PossiblyAccepted — a spent reservation is
        // never released as NotSent.
        expect($collected->response->errors->sole()->code)->toBe('librarium.timeout')
            ->and($diagnostic->code)->toBe('librarium.timeout')
            ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
            ->and($diagnostic->operation)->toBe(Operation::Inline)
            ->and($collected->coverage)->toBe(CaptureCoverage::NoResponse)
            ->and($collected->evidence)->toBeEmpty();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-6-luna');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
        Http::assertSentCount(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('settles an ungrounded required-grounding Luna response without raw capture as possibly accepted, never not sent', function (): void {
    $prompt = 'Answer without provider-reported evidence.';
    config()->set('librarium.profiles.openrouter-chat.options', array_merge(lunaBoundedOptions(), [
        'max_prompt_bytes' => strlen($prompt),
    ]));
    config()->set('librarium.profiles.openrouter-chat.grounding', 'required');
    Http::fake(lunaCatalogFake());
    ResearchAgent::fake([
        new TextResponse(
            'An answer without provider-reported evidence.',
            new AiUsage(promptTokens: 900, completionTokens: 120),
            new Meta('openrouter', 'openai/gpt-6-luna-20260922'),
        ),
    ])->preventStrayPrompts();

    $collected = Librarium::query($prompt)
        ->using('openrouter-chat')
        ->collect();
    $diagnostic = $collected->diagnostics->sole();

    // The inference dispatch ran before the grounding check rejected the
    // mapped result, and the SDK exposed no raw exchange: the recorded
    // unanswered attempt keeps the settlement PossiblyAccepted instead of
    // falsely releasing a spent reservation as NotSent.
    expect($collected->response->errors->sole()->code)->toBe('laravel_ai.ungrounded_response')
        ->and($diagnostic->code)->toBe('laravel_ai.ungrounded_response')
        ->and($diagnostic->submissionState)->toBe(SubmissionState::PossiblyAccepted)
        ->and($diagnostic->operation)->toBe(Operation::Inline)
        ->and($collected->coverage)->toBe(CaptureCoverage::NoResponse)
        ->and($collected->evidence)->toBeEmpty();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-6-luna');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    Http::assertSentCount(1);
});

it('fails the Luna preflight closed before the catalog lookup when the shared deadline is exhausted', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', lunaBoundedOptions());
    Http::fake(lunaCatalogFake());
    ResearchAgent::fake()->preventStrayPrompts();

    try {
        lunaDriver()->run(lunaBoundedRequest(
            deadline: CarbonImmutable::now()->subSecond(),
            options: lunaBoundedOptions(),
        ));
        $this->fail('An exhausted shared deadline should fail closed before the Luna catalog lookup.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('librarium.timeout')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }

    Http::assertNothingSent();
    ResearchAgent::assertNeverPrompted();
});

it('keeps unpinned Luna requests on default routing with no reasoning controls and no revision check', function (): void {
    config()->set('librarium.profiles.openrouter-chat.options', [
        'web_search' => true,
        'search_engine' => 'exa',
        'search_mode' => 'auto',
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => 4096,
    ]);
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(lunaCompletionBody(model: 'openai/gpt-6-luna-20261101')),
    ]);

    $bound = Librarium::query('Answer without the bounded pin.')
        ->using('openrouter-chat')
        ->spendingBound();
    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);

    $response = Librarium::query('Answer without the bounded pin.')
        ->using('openrouter-chat')
        ->run();

    // Without the server-tool budget the request is not the bounded route:
    // default routing only, no max_price, no reasoning controls, and no
    // revision check — even a different served revision is not rejected.
    expect($response->status->value)->toBe('succeeded')
        ->and($response->results->sole()->model)->toBe('openai/gpt-6-luna-20261101');
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $provider = $body['provider'];

        return $provider === ['require_parameters' => true, 'allow_fallbacks' => false]
            && ! array_key_exists('max_price', $provider)
            && ! array_key_exists('reasoning', $body)
            && ! array_key_exists('max_tool_calls', $body);
    });
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/model/openai/gpt-6-luna');
    Http::assertSentCount(1);
});

it('keeps unsupported OpenRouter models unbounded, unrouted, and free of the revision check', function (): void {
    $options = lunaBoundedOptions();
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(lunaCompletionBody(model: 'openai/gpt-6-luna-pro-20260922')),
    ]);

    $request = lunaBoundedRequest(model: 'openai/gpt-6-luna-pro', options: $options);
    $bound = lunaDriver()->spendingBound($request);
    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);

    $response = lunaDriver()->run($request);
    expect($response->model)->toBe('openai/gpt-6-luna-pro-20260922');
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $provider = $body['provider'];

        return $body['model'] === 'openai/gpt-6-luna-pro'
            && $provider === ['require_parameters' => true, 'allow_fallbacks' => false]
            && ! array_key_exists('reasoning', $body);
    });
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://openrouter.ai/api/v1/model/'));
    Http::assertSentCount(1);
});

/**
 * The reviewed bounded Luna option set. Every default matches the reviewed
 * canary controls; tests override individual keys through the named
 * parameters to prove the bound fails closed on any deviation.
 *
 * @return array<string, mixed>
 */
function lunaBoundedOptions(
    ?bool $webSearch = true,
    ?string $searchMode = 'auto',
    ?int $maxToolCalls = 5,
): array {
    return [
        'web_search' => $webSearch,
        'search_engine' => 'exa',
        'search_mode' => $searchMode,
        'max_tool_calls' => $maxToolCalls,
        'max_results' => 5,
        'max_steps' => 1,
        'max_prompt_bytes' => strlen('Bound this grounded Luna answer.'),
    ];
}

/**
 * Public-catalog fake entry proving the bounded Luna alias still resolves to
 * the reviewed exact revision, so the pre-dispatch check passes and the
 * pinned inference request is dispatched.
 *
 * @return array<string, mixed>
 */
function lunaCatalogFake(): array
{
    return ['https://openrouter.ai/api/v1/model/openai/gpt-6-luna' => Http::response([
        'data' => ['canonical_slug' => 'openai/gpt-6-luna-20260922'],
    ])];
}

/**
 * A conforming grounded chat-completions body served by the reviewed exact
 * Luna revision.
 *
 * @return array<string, mixed>
 */
function lunaCompletionBody(string $model = 'openai/gpt-6-luna-20260922'): array
{
    return [
        'id' => 'gen-luna-grounded',
        'model' => $model,
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => 'A grounded Luna answer.',
                'annotations' => [[
                    'type' => 'url_citation',
                    'url_citation' => ['url' => 'https://example.com/source', 'title' => 'Example source'],
                ]],
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 900,
            'completion_tokens' => 120,
            'cost' => 0.002,
            'server_tool_use' => ['web_search_requests' => 4],
        ],
    ];
}

function lunaDriver(): LaravelAiDriver
{
    return new LaravelAiDriver(new ResearchResultMapper);
}

/**
 * @param  array<string, mixed>  $options
 */
function lunaBoundedRequest(
    ?CarbonImmutable $deadline = null,
    string $model = 'openai/gpt-6-luna',
    string $provider = 'openrouter',
    array $options = [],
    string $prompt = 'Bound this grounded Luna answer.',
    ?Closure $responseCallback = null,
): DriverRequest {
    $profile = new Profile(
        id: 'luna-bounded-probe',
        driver: LaravelAiDriver::class,
        provider: $provider,
        model: $model,
        resultKind: ResultKind::GroundedAnswer,
        grounding: GroundingPolicy::Required,
        observation: ObservationMode::ApiOutput,
        corpora: collect([Corpus::Web]),
        retrievalMethods: collect([RetrievalMethod::ModelSearchTool]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
    );

    return new DriverRequest(
        requestId: 'request-123',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(42),
        progressCallback: static fn (): null => null,
        responseCallback: $responseCallback,
    );
}
