<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\Perplexity\PerplexityAgentDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-20T12:00:00Z');
    Sleep::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('runs the low preset inline with one exact POST and strictly normalizes reported facts', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($options['allow_redirects'] ?? null)->toBeFalse();

        return Http::response(perplexityCompletedResponse());
    });

    $result = perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));

    expect($result->content)->toBe('A current answer [web:1] with another fact [news:story-2].')
        ->and($result->provider)->toBe('perplexity')
        ->and($result->profile)->toBe('perplexity-sonar-pro')
        ->and($result->model)->toBe('openai/gpt-5.6-luna')
        ->and($result->provenance->resultKind)->toBe(ResultKind::GroundedAnswer)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ModelSearchTool])
        ->and($result->citations)->toHaveCount(2)
        ->and($result->citations->first()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->first()->source->kind)->toBe(SourceKind::WebPage)
        ->and($result->citations->first()->source->providerReference)->toBe('1')
        ->and($result->citations->first()->excerpt)->toBe('First snippet')
        ->and($result->citations->last()->source->kind)->toBe(SourceKind::NewsArticle)
        ->and($result->usage?->promptTokens)->toBe(80)
        ->and($result->usage?->completionTokens)->toBe(40)
        ->and($result->usage?->cacheReadInputTokens)->toBe(20)
        ->and($result->usage?->cacheWriteInputTokens)->toBe(5)
        ->and($result->usage?->reasoningTokens)->toBe(10)
        ->and($result->usage?->actualCost)->toBe('0.0125')
        ->and($result->usage?->currency)->toBe('USD')
        ->and($result->providerMeta)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.perplexity.ai/v1/agent'
            && $request->hasHeader('Authorization', 'Bearer testing-perplexity-key')
            && ! $request->hasHeader('Idempotency-Key')
            && $request->data() === [
                'input' => 'Research current agent APIs.',
                'preset' => 'low',
            ];
    });
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();

    $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('resp_private_1')
        ->not->toContain('testing-perplexity-key')
        ->not->toContain('raw-provider-secret')
        ->not->toContain('uncited.example.com');
});

it('submits each durable preset once and uses only canonical GET retrieval until completion', function (string $profile, string $preset): void {
    Http::fakeSequence()
        ->push(['id' => 'resp_private_1', 'status' => 'queued'])
        ->push(['id' => 'resp_private_1', 'status' => 'in_progress'])
        ->push(perplexityCompletedResponse());
    $progress = [];

    $result = perplexityAgentDriver()->run(perplexityAgentRequest($profile, progress: $progress));

    expect($result->profile)->toBe($profile)
        ->and($result->provenance->resultKind)->toBe(ResultKind::ResearchReport)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ResearchAgent])
        ->and($progress)->toBe(['Perplexity is researching in the background.']);

    $requests = Http::recorded()->map(fn (array $record): Request => $record[0]);
    expect($requests->map->method()->all())->toBe(['POST', 'GET', 'GET'])
        ->and($requests->map->url()->all())->toBe([
            'https://api.perplexity.ai/v1/agent',
            'https://api.perplexity.ai/v1/agent/resp_private_1',
            'https://api.perplexity.ai/v1/agent/resp_private_1',
        ])
        ->and($requests->first()->data())->toBe([
            'input' => 'Research current agent APIs.',
            'preset' => $preset,
            'background' => true,
        ]);
    Sleep::assertSleptTimes(2);
})->with([
    'medium' => ['perplexity-deep-research', 'medium'],
    'high' => ['perplexity-sonar-deep', 'high'],
]);

it('always retrieves canonical background output even when submission reports completion', function (string $profile): void {
    $submission = perplexityCompletedResponse();
    data_set($submission, 'output.1.content.0.text', 'Untrusted submission [web:1].');
    Http::fakeSequence()
        ->push($submission)
        ->push(perplexityCompletedResponse());

    $result = perplexityAgentDriver()->run(perplexityAgentRequest($profile));

    expect($result->content)->toBe('A current answer [web:1] with another fact [news:story-2].');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.perplexity.ai/v1/agent/resp_private_1');
    Sleep::assertNeverSlept();
})->with(['medium' => ['perplexity-deep-research'], 'high' => ['perplexity-sonar-deep']]);

it('uses canonical retrieval when a background submission reports another terminal state', function (string $status): void {
    Http::fakeSequence()
        ->push([
            'id' => 'resp_private_1',
            'status' => $status,
            'error' => ['code' => 'invalid_api_key', 'message' => 'untrusted submission error'],
        ])
        ->push(perplexityCompletedResponse());

    $result = perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-deep-research'));

    expect($result->content)->toContain('current answer');
    $requests = Http::recorded()->map(fn (array $record): Request => $record[0]);
    expect($requests->map->method()->all())->toBe(['POST', 'GET'])
        ->and($requests->last()->url())->toBe('https://api.perplexity.ai/v1/agent/resp_private_1');
    Sleep::assertNeverSlept();
})->with(['failed', 'incomplete', 'cancelled']);

it('reconciles transient GET connection and HTTP failures without resubmitting', function (): void {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;

        return match ($calls) {
            1 => Http::response(['id' => 'resp_private_1', 'status' => 'queued']),
            2 => throw new ConnectionException('provider-secret connection failed'),
            3 => Http::response(['error' => ['message' => 'provider-secret']], 503),
            default => Http::response(perplexityCompletedResponse()),
        };
    });

    $result = perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-deep-research'));

    expect($result->content)->toContain('current answer');
    expect($calls)->toBe(4);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    expect(Http::recorded()->filter(fn (array $record): bool => $record[0]->method() === 'POST'))->toHaveCount(1);
    Sleep::assertSleptTimes(3);
});

it('does not retry an ambiguous submission for any Agent profile', function (string $profile): void {
    $calls = 0;
    Http::fake(function () use (&$calls): never {
        $calls++;

        throw new ConnectionException('Bearer provider-secret');
    });

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest($profile));
        $this->fail('An ambiguous submission must fail without retry.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.submit_connection')
            ->and($exception->fallbackAllowed)->toBeFalse()
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }

    expect($calls)->toBe(1);
    Http::assertNothingSent();
})->with([
    'inline low' => ['perplexity-sonar-pro'],
    'background medium' => ['perplexity-deep-research'],
    'background high' => ['perplexity-sonar-deep'],
]);

it('makes uncertain background HTTP and malformed acknowledgements ambiguous and non-fallback', function (mixed $payload, int $status): void {
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($payload, $status)]);

    expect(fn () => perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-deep-research')))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('perplexity.ambiguous_submission')
                ->and($exception->fallbackAllowed)->toBeFalse();
        });

    Http::assertSentCount(1);
})->with([
    'timeout' => [['error' => 'provider-secret'], 408],
    'rate limit' => [['error' => 'provider-secret'], 429],
    'unavailable' => [['error' => 'provider-secret'], 503],
    'missing handle' => [['status' => 'queued'], 200],
    'unknown status' => [['id' => 'resp_private_1', 'status' => 'paused'], 200],
]);

it('makes definitive background submission rejections non-fallback', function (int $status, string $code): void {
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response(['error' => 'provider-secret'], $status)]);

    expect(fn () => perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-deep-research')))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse();
        });
})->with([
    'authentication' => [401, 'perplexity.authentication'],
    'billing' => [402, 'perplexity.billing'],
    'invalid request' => [422, 'perplexity.invalid_request'],
]);

it('maps all terminal Agent states to safe errors', function (string $status, string $code): void {
    Http::fake([
        'https://api.perplexity.ai/v1/agent' => Http::response([
            'id' => 'resp_private_1',
            'status' => $status,
            'error' => ['code' => 'unknown', 'message' => 'provider-secret'],
        ]),
    ]);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));
        $this->fail('A non-success terminal state must fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
})->with([
    'failed' => ['failed', 'perplexity.agent_failed'],
    'incomplete' => ['incomplete', 'perplexity.incomplete'],
    'cancelled' => ['cancelled', 'perplexity.cancelled'],
]);

it('maps allowlisted provider terminal failure codes without exposing raw errors', function (string $providerCode, string $code): void {
    Http::fake([
        'https://api.perplexity.ai/v1/agent' => Http::response([
            'id' => 'resp_private_1',
            'status' => 'failed',
            'error' => ['code' => $providerCode, 'message' => 'secret at https://private.example.com'],
        ]),
    ]);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));
        $this->fail('A failed response must be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('private.example.com');
    }
})->with([
    'authentication' => ['invalid_api_key', 'perplexity.authentication'],
    'plan' => ['plan_required', 'perplexity.plan_required'],
    'billing' => ['insufficient_credits', 'perplexity.billing'],
    'rate limit' => ['rate_limit_exceeded', 'perplexity.rate_limited'],
    'invalid request' => ['validation_error', 'perplexity.invalid_request'],
]);

it('maps a retrieved medium Agent failure without exposing its handle or body', function (): void {
    Http::fakeSequence()
        ->push(['id' => 'resp_private_1', 'status' => 'queued'])
        ->push([
            'id' => 'resp_private_1',
            'status' => 'failed',
            'error' => [
                'code' => 'rate_limit_exceeded',
                'message' => 'resp_private_1 Bearer provider-secret https://private.example/signed?token=secret',
            ],
        ]);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-deep-research'));
        $this->fail('A retrieved medium failure must fail safely.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.rate_limited')
            ->and($exception->fallbackAllowed)->toBeFalse()
            ->and($exception->getMessage())->not->toContain('resp_private_1')
            ->not->toContain('provider-secret')
            ->not->toContain('private.example');
    }

    expect(Http::recorded()->map(fn (array $record): string => $record[0]->method())->all())->toBe(['POST', 'GET']);
});

it('maps submit HTTP failures safely and sends only once', function (int $status, string $code): void {
    Http::fake([
        'https://api.perplexity.ai/v1/agent' => Http::response([
            'error' => ['message' => 'provider-secret', 'token' => 'raw-secret'],
        ], $status),
    ]);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));
        $this->fail('A failed submission must be mapped.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->not->toContain('provider-secret')
            ->not->toContain('raw-secret');
    }

    Http::assertSentCount(1);
})->with([
    'authentication' => [401, 'perplexity.authentication'],
    'billing' => [402, 'perplexity.billing'],
    'plan' => [403, 'perplexity.plan_required'],
    'timeout' => [408, 'perplexity.timeout'],
    'rate limit' => [429, 'perplexity.rate_limited'],
    'unavailable' => [503, 'perplexity.unavailable'],
    'invalid request' => [422, 'perplexity.invalid_request'],
]);

it('maps a missing canonical background response without exposing its private ID', function (): void {
    Http::fakeSequence()
        ->push(['id' => 'resp_private_1', 'status' => 'completed'])
        ->push(['error' => ['message' => 'resp_private_1 provider-secret']], 404);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-deep-research'));
        $this->fail('A missing canonical response must fail safely.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.response_missing')
            ->and($exception->fallbackAllowed)->toBeFalse()
            ->and($exception->getMessage())->not->toContain('resp_private_1')
            ->not->toContain('provider-secret');
    }

    Http::assertSentCount(2);
});

it('rejects unknown states and mismatched canonical response IDs', function (string $case): void {
    $retrieved = $case === 'state'
        ? ['id' => 'resp_private_1', 'status' => 'paused']
        : array_replace(perplexityCompletedResponse(), ['id' => 'resp_different']);
    Http::fakeSequence()
        ->push(['id' => 'resp_private_1', 'status' => 'queued'])
        ->push($retrieved);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-deep-research'));
        $this->fail('Invalid canonical state must fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.invalid_response')
            ->and($exception->fallbackAllowed)->toBeFalse();
    }
})->with(['unknown state' => ['state'], 'mismatched id' => ['id']]);

it('fails at one shared deadline without another retrieval or wall-clock sleep', function (): void {
    Http::fakeSequence()->push(['id' => 'resp_private_1', 'status' => 'queued']);
    Sleep::whenFakingSleep(static function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));
    });

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest(
            'perplexity-deep-research',
            deadline: CarbonImmutable::now()->addSeconds(2),
        ));
        $this->fail('The Driver must stop at the shared deadline.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.deadline_exceeded');
    }

    Http::assertSentCount(1);
    Sleep::assertSleptTimes(1);
});

it('rejects responses arriving after the shared deadline', function (string $profile): void {
    Http::fake(function (Request $request) use ($profile) {
        if ($profile !== 'perplexity-sonar-pro' && $request->method() === 'POST') {
            return Http::response(['id' => 'resp_private_1', 'status' => 'completed']);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3));

        return Http::response(perplexityCompletedResponse());
    });

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest(
            $profile,
            deadline: CarbonImmutable::now()->addSeconds(2),
        ));
        $this->fail('A late provider response must fail.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.deadline_exceeded');
    }
})->with([
    'late inline low POST' => ['perplexity-sonar-pro'],
    'late background medium GET' => ['perplexity-deep-research'],
    'late background high GET' => ['perplexity-sonar-deep'],
]);

it('rejects malformed or unknown completed output', function (string $path, mixed $value): void {
    $response = perplexityCompletedResponse();
    data_set($response, $path, $value);
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($response)]);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));
        $this->fail('Malformed completed output must fail closed.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.invalid_response');
    }
})->with([
    'unknown output item' => ['output.0.type', 'future_search_results'],
    'unknown message content' => ['output.1.content.0.type', 'refusal'],
    'empty content' => ['output.1.content.0.text', ''],
    'malformed output list' => ['output', ['not' => 'a list']],
    'missing effective model' => ['model', null],
    'invalid effective model' => ['model', 'sonar'],
    'invalid timestamp' => ['completed_at', 'today'],
]);

it('rejects credentials and private handles reflected into retained Agent output', function (string $private, string $path): void {
    $response = perplexityCompletedResponse();
    data_set($response, $path, "Retained {$private} [web:1].");
    Http::fakeSequence()
        ->push(['id' => 'resp_private_1', 'status' => 'completed'])
        ->push($response);

    expect(fn () => perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-deep-research')))
        ->toThrow(function (DriverException $exception) use ($private): void {
            expect($exception->errorCode)->toBe('perplexity.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain($private);
        });
})->with([
    'credential in report' => ['testing-perplexity-key', 'output.1.content.0.text'],
    'private handle in citation title' => ['resp_private_1', 'output.0.results.0.title'],
]);

it('strictly validates referenced search evidence and keeps only cited results', function (string $path, mixed $value): void {
    $response = perplexityCompletedResponse();
    data_set($response, $path, $value);
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($response)]);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));
        $this->fail('Invalid provider evidence must fail closed.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.invalid_response');
    }
})->with([
    'unknown citation marker' => ['output.1.content.0.text', 'Unknown [web:999].'],
    'unknown citation source' => ['output.1.content.0.text', 'Unknown [private:1] with valid [web:1].'],
    'source mismatch' => ['output.1.content.0.text', 'Wrong [news:1].'],
    'invalid result URL scheme' => ['output.0.results.0.url', 'file:///etc/passwd'],
    'duplicate stringified ID' => ['output.0.results.1.id', '1'],
    'unknown source' => ['output.0.results.0.source', 'private'],
    'no cited evidence' => ['output.1.content.0.text', 'An answer without markers.'],
]);

it('normalizes empty optional search result text to absent citation fields', function (): void {
    $response = perplexityCompletedResponse();
    data_set($response, 'output.0.results.0.title', '');
    data_set($response, 'output.0.results.0.snippet', '');
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($response)]);

    $result = perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));

    expect($result->citations->first()->source->title)->toBeNull()
        ->and($result->citations->first()->excerpt)->toBeNull();
});

it('strictly validates usage, cache, reasoning, and reported USD cost', function (string $path, mixed $value): void {
    $response = perplexityCompletedResponse();
    data_set($response, $path, $value);
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($response)]);

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));
        $this->fail('Invalid metering must fail closed.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe('perplexity.invalid_response');
    }
})->with([
    'negative input tokens' => ['usage.input_tokens', -1],
    'fractional output tokens' => ['usage.output_tokens', 1.5],
    'inconsistent total tokens' => ['usage.total_tokens', 999],
    'negative reasoning tokens' => ['usage.output_tokens_details.reasoning_tokens', -1],
    'conflicting cache write counts' => ['usage.input_tokens_details.cache_write_input_tokens', 6],
    'negative total cost' => ['usage.cost.total_cost', -0.01],
    'non-USD cost' => ['usage.cost.currency', 'EUR'],
]);

it('floors uncached prompt usage when reported cache reads exceed input tokens', function (): void {
    $response = perplexityCompletedResponse();
    data_set($response, 'usage.input_tokens', 10);
    data_set($response, 'usage.total_tokens', 50);
    data_set($response, 'usage.input_tokens_details.cache_read_input_tokens', 20);
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($response)]);

    $result = perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));

    expect($result->usage?->promptTokens)->toBe(0)
        ->and($result->usage?->cacheReadInputTokens)->toBe(20);
});

it('preserves reported USD cost precision supported by the interchange contract', function (): void {
    $response = perplexityCompletedResponse();
    data_set($response, 'usage.cost.total_cost', 0.0000000000000009);
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($response)]);

    $result = perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro'));

    expect($result->usage?->actualCost)->toBe('0.0000000000000009');
});

it('rejects missing credentials, expired deadlines, and incompatible profile semantics before requests', function (array $overrides, string $code): void {
    Http::fake();

    try {
        perplexityAgentDriver()->run(perplexityAgentRequest('perplexity-sonar-pro', ...$overrides));
        $this->fail('Invalid local input must fail before a request.');
    } catch (DriverException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'missing credential' => [['credential' => null], 'perplexity.authentication'],
    'expired deadline' => [['deadline' => CarbonImmutable::parse('2026-08-20T11:59:59Z')], 'perplexity.deadline_exceeded'],
    'wrong provider' => [['provider' => 'other'], 'perplexity.invalid_profile'],
    'provider-selected model forbidden' => [['model' => 'openai/gpt-5.6-luna'], 'perplexity.invalid_profile'],
    'wrong result kind' => [['resultKind' => ResultKind::ResearchReport], 'perplexity.invalid_profile'],
    'wrong grounding' => [['grounding' => GroundingPolicy::Optional], 'perplexity.invalid_profile'],
    'wrong corpus' => [['corpora' => [Corpus::News]], 'perplexity.invalid_profile'],
    'wrong retrieval' => [['retrievalMethods' => [RetrievalMethod::ResearchAgent]], 'perplexity.invalid_profile'],
]);

function perplexityAgentDriver(): PerplexityAgentDriver
{
    return app(PerplexityAgentDriver::class);
}

/** @param list<string> $progress */
function perplexityAgentRequest(
    string $profileId,
    ?CarbonImmutable $deadline = null,
    ?string $credential = 'testing-perplexity-key',
    array &$progress = [],
    string $provider = 'perplexity',
    ?string $model = null,
    ?ResultKind $resultKind = null,
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ObservationMode $observation = ObservationMode::ApiOutput,
    array $corpora = [Corpus::Web],
    ?array $retrievalMethods = null,
): DriverRequest {
    $deep = in_array($profileId, ['perplexity-deep-research', 'perplexity-sonar-deep'], true);
    $profile = new Profile(
        id: $profileId,
        driver: PerplexityAgentDriver::class,
        provider: $provider,
        model: $model,
        resultKind: $resultKind ?? ($deep ? ResultKind::ResearchReport : ResultKind::GroundedAnswer),
        grounding: $grounding,
        observation: $observation,
        corpora: collect($corpora),
        retrievalMethods: collect($retrievalMethods ?? [$deep ? RetrievalMethod::ResearchAgent : RetrievalMethod::ModelSearchTool]),
        prompt: '{{ query }}',
        enabled: true,
        options: [],
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: 'Research current agent APIs.',
        deadline: $deadline ?? CarbonImmutable::now()->addSeconds(30),
        progressCallback: static function (string $message) use (&$progress): void {
            $progress[] = $message;
        },
    );
}

/** @return array<string, mixed> */
function perplexityCompletedResponse(): array
{
    return [
        'id' => 'resp_private_1',
        'status' => 'completed',
        'completed_at' => CarbonImmutable::now()->getTimestamp(),
        'model' => 'openai/gpt-5.6-luna',
        'output' => [
            [
                'type' => 'search_results',
                'results' => [
                    [
                        'id' => 1,
                        'url' => 'https://example.com/first',
                        'title' => 'First source',
                        'snippet' => 'First snippet',
                        'source' => 'web',
                    ],
                    [
                        'id' => 'story-2',
                        'url' => 'https://news.example.com/story',
                        'title' => 'News source',
                        'snippet' => 'News snippet',
                        'source' => 'news',
                    ],
                    [
                        'id' => 3,
                        'url' => 'https://uncited.example.com/private',
                        'title' => 'Uncited source',
                        'source' => 'web',
                    ],
                ],
            ],
            [
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'A current answer [web:1] with another fact [news:story-2].',
                ]],
            ],
            [
                'type' => 'fetch_url_results',
                'content' => 'raw-provider-secret',
            ],
        ],
        'usage' => [
            'input_tokens' => 100,
            'output_tokens' => 40,
            'total_tokens' => 140,
            'input_tokens_details' => [
                'cached_tokens' => 19,
                'cache_read_input_tokens' => 20,
                'cache_creation_input_tokens' => 5,
                'cache_write_input_tokens' => 5,
            ],
            'output_tokens_details' => ['reasoning_tokens' => 10],
            'cost' => ['currency' => 'USD', 'total_cost' => 0.0125],
        ],
        'raw' => [
            'secret' => 'raw-provider-secret',
            'authorization' => 'Bearer testing-perplexity-key',
        ],
    ];
}
