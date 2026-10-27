<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuResearchDriver;
use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuSupport;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\ProfileOptionValidator;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10T12:00:00Z');
    Http::preventStrayRequests();
    Sleep::fake();
    config(['librarium.runs.store' => 'memory']);
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
                // Valyu's token-authenticated asset link names the task and states no expiry.
                ['kind' => 'pdf', 'url' => valyuPdfUrl('dr-private-1')],
                ['kind' => 'chart', 'provider_asset_id' => 'image-1', 'title' => 'Chart'],
                ['kind' => 'csv', 'provider_asset_id' => 'deliverable-1', 'status' => 'completed', 'title' => 'Data', 'row_count' => 4, 'column_count' => 2],
            ],
        ]);

    // The PDF link is the one place the private task handle may appear.
    expect(str_replace(valyuPdfUrl('dr-private-1'), '', json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))
        ->not->toContain('dr-private-1')
        ->not->toContain('testing-valyu-key')
        ->not->toContain('data.csv?token=ephemeral')
        ->not->toContain('chart.png?signature=ephemeral')
        ->not->toContain('secret/internal')
        ->not->toContain('cost_breakdown');
});

it('uses standard all-corpus defaults with the PDF link and invents no unavailable price', function (): void {
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-default', 'standard'), 202)
        ->push(valyuResearchTask('dr-default', 'completed', 'standard'))
        ->push(valyuResearchCompleted('dr-default', 'standard', cost: null))]);

    $result = app(ValyuResearchDriver::class)->run(valyuResearchRequest());

    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST' || $request->data() === [
        'query' => 'clinical question',
        'mode' => 'standard',
        'output_formats' => ['markdown', 'pdf'],
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
    'PDF without Markdown' => [['output_formats' => ['pdf']]],
    'reordered output formats' => [['output_formats' => ['pdf', 'markdown']]],
    'string timeout' => [['request_timeout_seconds' => '600']],
    'zero timeout' => [['request_timeout_seconds' => 0]],
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

it('rejects an escaped credential reflected in terminal output', function (): void {
    $credential = 'credential/"\\秘密';
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-retrieval-private', 'standard'), 202)
        ->push(valyuResearchTask('dr-retrieval-private', 'completed', 'standard'))
        ->push([
            ...valyuResearchCompleted('dr-retrieval-private', 'standard'),
            'output' => "Report containing {$credential}",
        ])]);

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest(credential: $credential)))
        ->toThrow(function (DriverException $exception) use ($credential): void {
            expect($exception->errorCode)->toBe('valyu_research.invalid_response')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain($credential);
        });
});

it('runs each mode against its own default deadline unless a timeout or the shared deadline is shorter', function (string $mode, array $options, int $sharedSeconds, int $expectedSeconds): void {
    Sleep::fake(syncWithCarbon: true);
    $startedAt = CarbonImmutable::now();
    $failedAt = null;
    Http::fake(function (Request $request) use ($mode) {
        return match (true) {
            $request->method() === 'POST' && str_ends_with($request->url(), '/cancel') => Http::response(['success' => true]),
            $request->method() === 'POST' => Http::response(valyuResearchSubmission('dr-deadline', $mode), 202),
            default => Http::response(valyuResearchTask('dr-deadline', 'running', $mode)),
        };
    });

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest(
        options: ['mode' => $mode, ...$options],
        deadline: $startedAt->addSeconds($sharedSeconds),
    )))->toThrow(function (DriverException $exception) use (&$failedAt): void {
        $failedAt = CarbonImmutable::now();
        expect($exception->errorCode)->toBe('valyu_research.deadline_exceeded');
    });

    expect((int) $startedAt->diffInSeconds($failedAt))->toBe($expectedSeconds);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.valyu.ai/v1/deepresearch/tasks'
        && $request['mode'] === $mode
        && ! array_key_exists('request_timeout_seconds', $request->data()));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/dr-deadline/cancel'));
})->with([
    'fast default' => ['fast', [], 86400, 600],
    'standard default' => ['standard', [], 86400, 1800],
    'heavy default' => ['heavy', [], 86400, 7200],
    'max default' => ['max', [], 86400, 10800],
    'explicit timeout wins' => ['max', ['request_timeout_seconds' => 45], 86400, 45],
    'explicit timeout may exceed the mode default' => ['fast', ['request_timeout_seconds' => 900], 86400, 900],
    'shared deadline caps the mode default' => ['heavy', [], 120, 120],
]);

it('maps a completed report for every mode and rejects a mode the provider did not run', function (string $mode): void {
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-mode', $mode), 202)
        ->push(valyuResearchTask('dr-mode', 'completed', $mode))
        ->push(valyuResearchCompleted('dr-mode', $mode))]);

    $result = app(ValyuResearchDriver::class)->run(valyuResearchRequest(options: ['mode' => $mode]));

    expect(data_get($result->providerMeta, 'valyu:research.mode'))->toBe($mode);
})->with(['fast', 'standard', 'heavy', 'max']);

it('rejects a report produced by a different mode than the Profile requested', function (): void {
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-mode', 'fast'), 202)
        ->push(valyuResearchTask('dr-mode', 'completed', 'fast'))
        ->push(valyuResearchCompleted('dr-mode', 'max'))]);

    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest(options: ['mode' => 'fast'])))
        ->toThrow(fn (DriverException $exception) => expect($exception->errorCode)->toBe('valyu_research.invalid_response'));
});

it('records the PDF bearer link and its expiry only when the URL is safe and states one', function (mixed $pdfUrl, array $artifact): void {
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-pdf-private', 'standard'), 202)
        ->push(valyuResearchTask('dr-pdf-private', 'completed', 'standard'))
        ->push([...valyuResearchCompleted('dr-pdf-private', 'standard'), 'pdf_url' => $pdfUrl])]);

    $result = app(ValyuResearchDriver::class)->run(valyuResearchRequest(options: ['output_formats' => ['markdown', 'pdf']]));

    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST' || $request['output_formats'] === ['markdown', 'pdf']);
    expect(data_get($result->providerMeta, 'valyu:research.artifacts.0'))->toBe($artifact);
})->with([
    'Valyu task asset link names the task and states no expiry' => [valyuPdfUrl('dr-pdf-private'), ['kind' => 'pdf', 'url' => valyuPdfUrl('dr-pdf-private')]],
    'Valyu task asset link without a token' => [
        'https://api.valyu.ai/v1/deepresearch/tasks/dr-pdf-private/assets/0b8e5d1c-7f4a-4c2e-9a61-3d5f8b2e7c90',
        ['kind' => 'pdf', 'url' => 'https://api.valyu.ai/v1/deepresearch/tasks/dr-pdf-private/assets/0b8e5d1c-7f4a-4c2e-9a61-3d5f8b2e7c90'],
    ],
    'SigV4 lifetime' => [
        'https://storage.valyu.ai/pdfs/report.pdf?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Date=20260910T121000Z&X-Amz-Expires=3600&X-Amz-Signature=pdf-bearer-signature',
        ['kind' => 'pdf', 'url' => 'https://storage.valyu.ai/pdfs/report.pdf?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Date=20260910T121000Z&X-Amz-Expires=3600&X-Amz-Signature=pdf-bearer-signature', 'expires_at' => '2026-09-10T13:10:00Z'],
    ],
    'Google V4 lifetime' => [
        'https://storage.googleapis.com/valyu/report.pdf?X-Goog-Date=20260910T120000Z&X-Goog-Expires=900&X-Goog-Signature=abc',
        ['kind' => 'pdf', 'url' => 'https://storage.googleapis.com/valyu/report.pdf?X-Goog-Date=20260910T120000Z&X-Goog-Expires=900&X-Goog-Signature=abc', 'expires_at' => '2026-09-10T12:15:00Z'],
    ],
    'epoch Expires' => [
        'https://cdn.valyu.ai/report.pdf?Expires=1789128000&Signature=abc',
        ['kind' => 'pdf', 'url' => 'https://cdn.valyu.ai/report.pdf?Expires=1789128000&Signature=abc', 'expires_at' => '2026-09-11T12:00:00Z'],
    ],
    'unsigned token has no stated expiry' => [
        'https://storage.valyu.ai/report.pdf?token=opaque',
        ['kind' => 'pdf', 'url' => 'https://storage.valyu.ai/report.pdf?token=opaque'],
    ],
    'no stated expiry' => ['https://storage.valyu.ai/pdfs/report.pdf', ['kind' => 'pdf', 'url' => 'https://storage.valyu.ai/pdfs/report.pdf']],
    'malformed signing date' => [
        'https://storage.valyu.ai/report.pdf?X-Amz-Date=20260231T120000Z&X-Amz-Expires=60',
        ['kind' => 'pdf', 'url' => 'https://storage.valyu.ai/report.pdf?X-Amz-Date=20260231T120000Z&X-Amz-Expires=60'],
    ],
    'plain HTTP withheld' => ['http://storage.valyu.ai/report.pdf', ['kind' => 'pdf']],
    'embedded credentials withheld' => ['https://user:pass@storage.valyu.ai/report.pdf', ['kind' => 'pdf']],
    'private task handle withheld' => ['https://storage.valyu.ai/dr-pdf-private/report.pdf', ['kind' => 'pdf']],
    'credential withheld' => ['https://storage.valyu.ai/report.pdf?for=testing-valyu-key', ['kind' => 'pdf']],
    'task asset link with the credential withheld' => [valyuPdfUrl('dr-pdf-private').'&key=testing-valyu-key', ['kind' => 'pdf']],
    'task handle on another host withheld' => [str_replace('api.valyu.ai', 'api.valyu.ai.example.com', valyuPdfUrl('dr-pdf-private')), ['kind' => 'pdf']],
    'task handle on a non-default port withheld' => [str_replace('api.valyu.ai', 'api.valyu.ai:8443', valyuPdfUrl('dr-pdf-private')), ['kind' => 'pdf']],
    'task handle outside the asset path withheld' => ['https://api.valyu.ai/v1/deepresearch/tasks/dr-pdf-private/status?token=opaque', ['kind' => 'pdf']],
    'another task asset reflecting the handle withheld' => [str_replace('/tasks/dr-pdf-private/', '/tasks/dr-other/', valyuPdfUrl('dr-pdf-private')), ['kind' => 'pdf']],
    'short asset id withheld' => ['https://api.valyu.ai/v1/deepresearch/tasks/dr-pdf-private/assets/report?token=opaque', ['kind' => 'pdf']],
    'plain HTTP task asset withheld' => [str_replace('https://', 'http://', valyuPdfUrl('dr-pdf-private')), ['kind' => 'pdf']],
    'task asset with user info withheld' => [str_replace('https://', 'https://user@', valyuPdfUrl('dr-pdf-private')), ['kind' => 'pdf']],
    'task asset longer than 8,192 bytes withheld' => [valyuPdfUrl('dr-pdf-private').str_repeat('a', 8192), ['kind' => 'pdf']],
]);

it('keeps the PDF bearer link out of captured evidence, diagnostics, and errors', function (bool $succeeds): void {
    $pdfUrl = valyuPdfUrl('dr-pdf-private');
    config()->set('services.valyu.key', 'testing-valyu-key');
    config()->set('librarium.profiles.valyu-research.options', ['mode' => 'standard', 'output_formats' => ['markdown', 'pdf']]);
    $completed = [...valyuResearchCompleted('dr-pdf-private', 'standard'), 'pdf_url' => $pdfUrl];
    if (! $succeeds) {
        $completed['sources'] = 'malformed';
    }
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-pdf-private', 'standard'), 202)
        ->push($completed)]);

    $run = Librarium::research('clinical question')->deep()->only(['valyu'])->run();
    $evidence = json_encode([$run->evidence('valyu'), $run->diagnostics(), $run->result('valyu')['error']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    // The task handle is private in the result, so evidence redacts it too:
    // in the submission, status and retrieval bodies, and inside the link.
    expect($run->evidence('valyu'))->toHaveCount(2)
        ->and($evidence)->not->toContain('dr-pdf-private')
        ->and($evidence)->not->toContain(valyuPdfAssetId())
        ->and($run->evidence('valyu')[0]['body'])->toContain('"deepresearch_id":"[REDACTED]"')
        ->and($run->evidence('valyu')[1]['body'])->toContain('"pdf_url":"[REDACTED]"');
    if ($succeeds) {
        expect($run->status())->toBe('done');
    } else {
        expect($run->status())->toBe('failed')
            ->and($run->result('valyu')['error']['code'])->toBe('valyu_research.invalid_response');
    }
})->with(['completed' => true, 'failed mapping' => false]);

it('rejects a result that reflects the PDF link anywhere but the artifact', function (string $output): void {
    $pdfUrl = valyuPdfUrl('dr-pdf-private');
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-pdf-private', 'standard'), 202)
        ->push(valyuResearchTask('dr-pdf-private', 'completed', 'standard'))
        ->push([...valyuResearchCompleted('dr-pdf-private', 'standard'), 'output' => str_replace('{pdf}', $pdfUrl, $output)])]);

    // Only the artifact's whole value may name the task; the same link in
    // the report, inside another URL, or extended is task material.
    expect(fn () => app(ValyuResearchDriver::class)->run(valyuResearchRequest()))
        ->toThrow(fn (DriverException $exception) => expect($exception->errorCode)->toBe('valyu_research.invalid_response'));
})->with([
    'linked in the report' => ['# Report\n\n[Download]({pdf})'],
    'nested in another URL' => ['[Download](https://attacker.example/?next={pdf})'],
    'extended link' => ['[Download]({pdf}&extra=value)'],
    'bare link as the report' => ['{pdf}'],
    'bare extended link as the report' => ['{pdf}&extra=value'],
]);

it('keeps the PDF artifact link in the durable driver result', function (): void {
    config()->set('services.valyu.key', 'testing-valyu-key');
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-durable-pdf', 'standard'), 202)
        ->push(valyuResearchCompleted('dr-durable-pdf', 'standard'))]);

    $driver = app(ValyuResearchDriver::class);
    $request = valyuResearchRequest();
    $submitted = $driver->submitDurable($request);
    $recovery = new RecoveryRequest(
        $request->requestId, $request->requestedProfile, $request->profile, $request->deadline,
        static function (): void {}, static function (): void {}, $submitted->usage,
    );
    $retrieved = $driver->retrieveDurable($recovery, $submitted->reference);

    expect(data_get($retrieved?->providerMeta, 'valyu:research.artifacts.0'))
        ->toBe(['kind' => 'pdf', 'url' => valyuPdfUrl('dr-durable-pdf')]);
});

it('redacts the task handle and PDF link from stored bodies under valid JSON escaping', function (): void {
    config()->set('services.valyu.key', 'testing-valyu-key');
    config()->set('librarium.profiles.valyu-research.options', ['mode' => 'standard', 'output_formats' => ['markdown', 'pdf']]);
    $completed = json_encode([...valyuResearchCompleted('dr-pdf-private', 'standard'), 'deepresearch_id' => '@@ID@@', 'pdf_url' => '@@PDF@@'], JSON_THROW_ON_ERROR);
    // \u0064 is "d", and the link escapes only its first slashes.
    $raw = str_replace(
        ['"@@ID@@"', '"@@PDF@@"'],
        ['"\\u0064r-pdf-private"', '"https:\\/\\/'.substr(valyuPdfUrl('dr-pdf-private'), 8).'"'],
        $completed,
    );
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(str_replace('"dr-pdf-private"', '"\\u0064r-pdf-private"', (string) json_encode(valyuResearchSubmission('dr-pdf-private', 'standard'))), 202, ['Content-Type' => 'application/json'])
        ->push($raw, 200, ['Content-Type' => 'application/json'])]);

    $run = Librarium::research('clinical question')->deep()->only(['valyu'])->run();
    $bodies = collect($run->evidence('valyu'))->pluck('body');
    $decoded = $bodies->map(fn (string $body): string => json_encode(json_decode($body, true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->implode("\n");

    expect($bodies)->toHaveCount(2)
        ->and($bodies->implode("\n"))->not->toContain('pdf-private')
        ->and($decoded)->not->toContain('dr-pdf-private')
        ->and($decoded)->not->toContain(valyuPdfAssetId())
        ->and($decoded)->not->toContain('c3ludGhldGljLXNpZ25hdHVyZQ');
});

it('never keeps a task handle that only a lossy JSON decoding would hide', function (string $body, string $taskId): void {
    $captured = ValyuSupport::capturedBody($body, $taskId);

    expect($captured)->not->toContain($taskId)
        ->and($captured)->not->toContain('\\u0064');
})->with([
    'escaped handle in an overwritten duplicate member' => ['{"deepresearch_id":"dr-pdf-private","echo":"\\u0064r-pdf-private","echo":"ok"}', 'dr-pdf-private'],
    'numeric handle as an object member name' => ['{"deepresearch_id":"123","123":"echo"}', '123'],
]);

it('rejects a durable result that reflects the handle outside the PDF artifact', function (): void {
    config()->set('services.valyu.key', 'testing-valyu-key');
    Http::fake(['https://api.valyu.ai/v1/deepresearch/tasks*' => Http::sequence()
        ->push(valyuResearchSubmission('dr-durable-pdf', 'standard'), 202)
        ->push([...valyuResearchCompleted('dr-durable-pdf', 'standard'), 'output' => valyuPdfUrl('dr-durable-pdf').'&extra=value'])]);

    $run = Librarium::research('clinical question')->deep()->only(['valyu'])->run();
    expect($run->status())->toBe('failed')
        ->and($run->result('valyu')['error']['code'])->toBe('valyu_research.invalid_response');
});

it('selects each Valyu mode through source options and saved groups', function (string $mode): void {
    config()->set('services.valyu.key', 'testing-valyu-key');
    config(['librarium.sources.valyu.mode' => $mode, 'librarium.groups.costly' => ['valyu']]);
    $run = Librarium::research('Research')->deep()->only(['costly'])->dryRun();
    expect(array_column($run->toArray()['results'], 'source'))->toBe(['valyu']);
    Http::assertNothingSent();
})->with(['standard', 'heavy', 'max']);

it('requires the pricing identity of the configured mode', function (array $options, string $pricingModel, ?string $reason): void {
    config()->set('services.valyu.key', 'testing-valyu-key');
    config()->set('librarium.profiles.valyu-research.options', $options);
    config()->set('librarium.profiles.valyu-research.pricing.model', $pricingModel);

    expect(app(ProfileCatalog::class)->find('valyu-research')?->reasons)->toBe($reason === null ? [] : [$reason]);
})->with([
    'shipped standard' => [['mode' => 'standard'], 'research-standard', null],
    'implicit standard' => [[], 'research-standard', null],
    'matching max' => [['mode' => 'max'], 'research-max', null],
    'heavy quoted as standard' => [['mode' => 'heavy'], 'research-standard', 'The pricing identity must be [valyu:research-heavy] to quote the configured research target.'],
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
        'pdf_url' => valyuPdfUrl($id),
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

/**
 * Valyu's live pdf_url shape with a synthetic asset ID and token: its
 * token-authenticated asset endpoint, naming the task in the path and the token.
 */
function valyuPdfUrl(string $taskId): string
{
    return 'https://api.valyu.ai/v1/deepresearch/tasks/'.$taskId.'/assets/'.valyuPdfAssetId().'?token=vdr.'.$taskId.'.c3ludGhldGljLXNpZ25hdHVyZQ';
}

function valyuPdfAssetId(): string
{
    return '4f1d2a9e-8c3b-4e7a-b5d6-9a0c1e2f3b4d';
}
