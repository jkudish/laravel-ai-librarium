<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuResearchDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileOptionValidator;
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

it('submits, polls a known handle, retrieves twice, and maps a durable specialized report', function (): void {
    $calls = 0;
    $transports = [];
    Http::globalMiddleware(function (callable $handler) use (&$transports): Closure {
        return function ($request, array $options) use ($handler, &$transports) {
            $transports[] = $options;

            return $handler($request, $options);
        };
    });
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;
        if ($request->method() === 'POST') {
            return Http::response(valyuResearchSubmission('dr-private-1', 'standard'), 202);
        }

        return match ($calls) {
            2 => Http::response(valyuResearchTask('dr-private-1', 'running', 'standard')),
            3 => Http::response(valyuResearchTask('dr-private-1', 'completed', 'standard')),
            default => Http::response(valyuResearchCompleted('dr-private-1', 'standard')),
        };
    });
    $options = [
        'mode' => 'standard',
        'research_strategy' => 'Use peer-reviewed evidence.',
        'report_format' => 'Write Markdown.',
        'search' => ['search_type' => 'proprietary', 'included_sources' => ['medical']],
        'urls' => ['https://example.com/context'],
        'output_formats' => ['markdown'],
    ];

    $result = app(ValyuResearchDriver::class)->run(valyuResearchRequest(options: $options, corpora: [Corpus::Specialized]));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.valyu.ai/v1/deepresearch/tasks'
        && $request->hasHeader('X-API-Key', 'testing-valyu-key')
        && $request->data() === [
            'query' => 'clinical question',
            'mode' => 'standard',
            'output_formats' => ['markdown'],
            'research_strategy' => 'Use peer-reviewed evidence.',
            'report_format' => 'Write Markdown.',
            'urls' => ['https://example.com/context'],
            'search' => ['search_type' => 'proprietary', 'included_sources' => ['medical']],
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.valyu.ai/v1/deepresearch/tasks/dr-private-1/status'
        && $request->hasHeader('X-API-Key', 'testing-valyu-key'));
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(2);

    expect($transports)->toHaveCount(4)
        ->and(collect($transports)->every(fn (array $transport): bool => ($transport['allow_redirects'] ?? null) === false))->toBeTrue()
        ->and($result->contentFormat)->toBe(ContentFormat::Markdown)
        ->and($result->content)->toBe('# Durable report')
        ->and($result->provenance->resultKind)->toBe(ResultKind::ResearchReport)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::ResearchAgent])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Specialized])
        ->and($result->citations)->toHaveCount(1)
        ->and($result->citations->sole()->derivation)->toBe(CitationDerivation::ProviderReported)
        ->and($result->citations->sole()->source->providerReference)->toBe('valyu/valyu-pubmed')
        ->and($result->citations->sole()->locator)->toBe('#:~:text=Finding')
        ->and($result->usage?->actualCost)->toBe('2.5')
        ->and(data_get($result->providerMeta, 'valyu:research'))->toBe([
            'mode' => 'standard',
            'execution' => 'background',
            'resumability' => 'durable_provider_task',
            'sources' => [[
                'publisher' => 'valyu/valyu-pubmed',
                'doi' => '10.1/example',
                'word_count' => 321,
            ]],
            'artifacts' => [
                ['kind' => 'pdf'],
                ['kind' => 'chart', 'provider_asset_id' => 'image-1', 'title' => 'Chart'],
                ['kind' => 'csv', 'provider_asset_id' => 'deliverable-1', 'status' => 'completed', 'title' => 'Data', 'row_count' => 4, 'column_count' => 2],
            ],
        ]);

    expect(json_encode($result->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('dr-private-1')
        ->not->toContain('testing-valyu-key')
        ->not->toContain('token=ephemeral')
        ->not->toContain('secret/internal')
        ->not->toContain('cost_breakdown');
});

it('uses standard all-corpus defaults and invents no unavailable price', function (): void {
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-default', 'standard'), 202)
        ->push(valyuResearchTask('dr-default', 'completed', 'standard'))
        ->push(valyuResearchCompleted('dr-default', 'standard', cost: null))]);

    $result = app(ValyuResearchDriver::class)->run(valyuResearchRequest());

    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST' || $request->data() === [
        'query' => 'clinical question',
        'mode' => 'standard',
        'output_formats' => ['markdown'],
    ]);
    expect($result->provenance->corpora->all())->toBe([Corpus::Web, Corpus::Specialized])
        ->and($result->usage)->toBeNull();
});

it('retries known-handle connection and transient HTTP failures without resubmitting', function (): void {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;

        return match ($calls) {
            1 => Http::response(valyuResearchSubmission('dr-retry', 'standard'), 202),
            2 => throw new ConnectionException('provider-secret testing-valyu-key'),
            3 => Http::response(['error' => 'provider-secret'], 408),
            4 => Http::response(['error' => 'provider-secret'], 429),
            5 => Http::response(['error' => 'provider-secret'], 503),
            default => Http::response(valyuResearchCompleted('dr-retry', 'standard')),
        };
    });

    $result = app(ValyuResearchDriver::class)->run(valyuResearchRequest());

    expect($result->content)->toBe('# Durable report')
        ->and($calls)->toBe(7)
        ->and(Http::recorded()->filter(fn (array $record): bool => $record[0]->method() === 'POST'))->toHaveCount(1);
    Sleep::assertSleptTimes(5);
});

it('validates Research options through the shared Profile validator', function (array $options): void {
    expect(app(ProfileOptionValidator::class)->errors(ValyuResearchDriver::class, $options))->not->toBeEmpty();
})->with([
    'unknown option' => [['unknown' => true]],
    'bad mode' => [['mode' => 'extreme']],
    'unpriced mode' => [['mode' => 'heavy']],
    'unpriced PDF output' => [['output_formats' => ['markdown', 'pdf']]],
    'bad source filter' => [['search' => ['included_sources' => []]]],
    'both source filters' => [['search' => ['included_sources' => ['a'], 'excluded_sources' => ['b']]]],
    'bad date' => [['search' => ['end_date' => '2026-02-30']]],
    'too many URLs' => [['urls' => array_fill(0, 11, 'https://example.com')]],
    'embedded credentials' => [['urls' => ['https://user:pass@example.com']]],
    'camel-case API key' => [['urls' => ['https://example.com/context?apiKey=secret']]],
    'AWS access key' => [['urls' => ['https://example.com/context?AWSAccessKeyId=secret']]],
    'nested token key' => [['urls' => ['https://example.com/context?redirect%5Btoken%5D=secret']]],
]);

it('rejects incompatible Research requests before paid submission', function (DriverRequest $request, string $code): void {
    Http::fake();

    expect(fn () => app(ValyuResearchDriver::class)->run($request))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse();
        });
    Http::assertNothingSent();
})->with([
    'blank query' => fn () => [valyuResearchRequest(prompt: ' '), 'valyu_research.invalid_request'],
    'overlong query' => fn () => [valyuResearchRequest(prompt: str_repeat('x', 25_001)), 'valyu_research.invalid_request'],
    'missing credential' => fn () => [valyuResearchRequest(credential: null), 'valyu_research.authentication'],
    'specialized projection drift' => fn () => [valyuResearchRequest(options: ['search' => ['search_type' => 'proprietary']]), 'valyu_research.invalid_profile'],
    'grounding drift' => fn () => [valyuResearchRequest(grounding: GroundingPolicy::None), 'valyu_research.invalid_profile'],
]);

it('never automatically retries an ambiguous paid submission', function (int|string $failure, string $code): void {
    $attempts = 0;
    Http::fake(function () use ($failure, &$attempts) {
        $attempts++;
        if ($failure === 'connection') {
            throw new ConnectionException('provider-secret testing-valyu-key');
        }

        return Http::response(['error' => 'provider-secret testing-valyu-key'], $failure);
    });

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret');
        });

    expect($attempts)->toBe(1);
})->with([
    'connection' => ['connection', 'valyu_research.submit_connection'],
    'rejected' => [422, 'valyu_research.invalid_request'],
    'timeout uncertainty' => [408, 'valyu_research.ambiguous_submission'],
    'rate-limit uncertainty' => [429, 'valyu_research.ambiguous_submission'],
    'server uncertainty' => [503, 'valyu_research.ambiguous_submission'],
]);

it('cancels a known handle from a malformed or late accepted submission', function (string $case): void {
    $deadline = CarbonImmutable::now()->addSecond();
    Http::fake(function (Request $request) use ($case, $deadline) {
        if (str_ends_with($request->url(), '/cancel')) {
            return Http::response(['success' => true]);
        }

        if ($case === 'late') {
            CarbonImmutable::setTestNow($deadline);
        }

        return Http::response([
            ...valyuResearchSubmission('dr-accepted-private', 'standard'),
            ...($case === 'malformed' ? ['mode' => 'unsupported'] : []),
        ], 202);
    });

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest(deadline: $deadline)))
        ->toThrow(function (DriverException $exception) use ($case): void {
            expect($exception->errorCode)->toBe($case === 'late'
                ? 'valyu_research.deadline_exceeded'
                : 'valyu_research.ambiguous_submission')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('dr-accepted-private');
        });

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.valyu.ai/v1/deepresearch/tasks/dr-accepted-private/cancel');
})->with(['malformed', 'late']);

it('maps known terminal provider states without cancellation or provider error leakage', function (string $status, string $code): void {
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-terminal', 'standard'), 202)
        ->push([...valyuResearchTask('dr-terminal', $status, 'standard'), 'error' => 'provider-secret testing-valyu-key'])]);

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret');
        });

    Http::assertSentCount(2);
})->with([
    'failed' => ['failed', 'valyu_research.failed'],
    'cancelled' => ['cancelled', 'valyu_research.cancelled'],
]);

it('best-effort cancels a known running task at the local deadline without masking timeout', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $deadline = CarbonImmutable::now()->addSecond();
    $cancelAttempts = 0;
    Http::fake(function (Request $request) use (&$cancelAttempts) {
        if ($request->url() === 'https://api.valyu.ai/v1/deepresearch/tasks') {
            return Http::response(valyuResearchSubmission('dr-timeout', 'standard'), 202);
        }
        if (str_ends_with($request->url(), '/cancel')) {
            $cancelAttempts++;
            throw new ConnectionException('private cancellation failure');
        }

        return Http::response(valyuResearchTask('dr-timeout', 'running', 'standard'));
    });

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest(deadline: $deadline)))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('valyu_research.deadline_exceeded')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('private cancellation failure');
        });

    expect($cancelAttempts)->toBe(1);
    Http::assertSentCount(1);
});

it('cancels a known handle after malformed polling and keeps handles and raw bodies private', function (): void {
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-malformed-private', 'standard'), 202)
        ->push(['deepresearch_id' => 'different-private-handle', 'status' => 'running'])
        ->push(['success' => true])]);

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('valyu_research.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('dr-malformed-private')
                ->not->toContain('different-private-handle');
        });

    Http::assertSentCount(3);
});

it('fails closed on malformed completed retrieval without leaking the response', function (array $terminal): void {
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-retrieval-private', 'standard'), 202)
        ->push(valyuResearchTask('dr-retrieval-private', 'completed', 'standard'))
        ->push($terminal)]);

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('valyu_research.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain('provider-secret')
                ->not->toContain('dr-retrieval-private');
        });

    Http::assertSentCount(3);
})->with([
    'wrong identity' => [[
        ...valyuResearchCompleted('different-private-task', 'standard'),
        'output' => 'provider-secret',
    ]],
    'unsupported output' => [[
        ...valyuResearchCompleted('dr-retrieval-private', 'standard'),
        'output_type' => 'json',
        'output' => ['secret' => 'provider-secret'],
    ]],
    'blank output' => [[
        ...valyuResearchCompleted('dr-retrieval-private', 'standard'),
        'output' => ' ',
    ]],
    'malformed sources' => [[
        ...valyuResearchCompleted('dr-retrieval-private', 'standard'),
        'sources' => 'provider-secret',
    ]],
    'credential reflected in output' => [[
        ...valyuResearchCompleted('dr-retrieval-private', 'standard'),
        'output' => 'testing-valyu-key',
    ]],
    'private handle reflected in output' => [[
        ...valyuResearchCompleted('dr-retrieval-private', 'standard'),
        'output' => 'dr-retrieval-private',
    ]],
]);

/** @return array<string, mixed> */
function valyuResearchSubmission(string $id, string $mode): array
{
    return [
        'deepresearch_id' => $id,
        'status' => 'queued',
        'mode' => $mode,
        'created_at' => '2026-09-10T12:00:00Z',
    ];
}

/** @return array<string, mixed> */
function valyuResearchTask(string $id, string $status, string $mode): array
{
    return [
        'deepresearch_id' => $id,
        'status' => $status,
        'mode' => $mode,
        'created_at' => '2026-09-10T12:00:00Z',
    ];
}

/** @return array<string, mixed> */
function valyuResearchCompleted(string $id, string $mode, mixed $cost = 2.5): array
{
    return [
        ...valyuResearchTask($id, 'completed', $mode),
        'completed_at' => '2026-09-10T12:10:00Z',
        'output_type' => 'markdown',
        'output' => '# Durable report',
        'cost' => $cost,
        'cost_breakdown' => ['task' => 2.5],
        'sources' => [[
            'title' => 'Study',
            'url' => 'https://example.com/study',
            'snippet' => 'Finding',
            'source' => 'valyu/valyu-pubmed',
            'fragment' => '#:~:text=Finding',
            'doi' => '10.1/example',
            'word_count' => 321,
        ], [
            'title' => 'Private signed source',
            'url' => 'https://storage.valyu.ai/source?AWSAccessKeyId=ephemeral',
        ], [
            'title' => 'Private nested source',
            'url' => 'https://storage.valyu.ai/source?redirect%5Btoken%5D=ephemeral',
        ]],
        'pdf_url' => 'https://storage.valyu.ai/report.pdf?token=ephemeral',
        'images' => [[
            'image_id' => 'image-1',
            'image_type' => 'chart',
            'title' => 'Chart',
            'image_url' => 'https://storage.valyu.ai/chart.png?signature=ephemeral',
        ]],
        'deliverables' => [[
            'id' => 'deliverable-1',
            'type' => 'csv',
            'status' => 'completed',
            'title' => 'Data',
            'url' => 'https://storage.valyu.ai/data.csv?token=ephemeral',
            's3_key' => 'secret/internal/data.csv',
            'row_count' => 4,
            'column_count' => 2,
        ]],
    ];
}

/** @param array<string, mixed> $options
 * @param  list<Corpus>  $corpora
 */
function valyuResearchRequest(
    array $options = [],
    array $corpora = [Corpus::Web, Corpus::Specialized],
    string $prompt = 'clinical question',
    ?string $credential = 'testing-valyu-key',
    GroundingPolicy $grounding = GroundingPolicy::Required,
    ?CarbonImmutable $deadline = null,
): DriverRequest {
    $profile = new Profile(
        id: 'valyu-research',
        driver: ValyuResearchDriver::class,
        provider: 'valyu',
        model: null,
        resultKind: ResultKind::ResearchReport,
        grounding: $grounding,
        observation: ObservationMode::ApiOutput,
        corpora: collect($corpora),
        retrievalMethods: collect([RetrievalMethod::ResearchAgent]),
        prompt: '{{ query }}',
        enabled: true,
        options: $options,
        credential: $credential,
    );

    return new DriverRequest(
        requestId: 'request-research',
        requestedProfile: $profile,
        profile: $profile,
        prompt: $prompt,
        deadline: $deadline ?? CarbonImmutable::now()->addMinutes(20),
        progressCallback: static fn (): null => null,
    );
}
