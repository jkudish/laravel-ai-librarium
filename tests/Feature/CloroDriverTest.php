<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\SourceCatalog;

const CLORO_TASK_ID = 'b27a21e1-7c39-4aa2-a347-23e828c426f9';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-27T12:00:00Z');
    Sleep::fake(true, true);
    Http::preventStrayRequests();
    config(['librarium.runs.store' => 'memory', 'librarium.engines' => ['collector' => 'cloro', 'options' => ['country' => 'US']]]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Sleep::fake(false);
});

it('collects a live surface in one Bearer call and keeps credits and extras out of the Run', function (): void {
    Http::fake(['https://api.cloro.dev/v1/monitor/chatgpt' => Http::response(['success' => true, 'result' => cloroChatGpt()], 200, ['X-Credits-Charged' => '7'])]);

    $run = Librarium::research('best crm')->only(['chatgpt'])->options(['chatgpt' => ['priority' => 'live']])->run();
    $result = $run->result('chatgpt');

    expect($result['status'])->toBe('done')
        ->and($result['provenance'])->toBe(['provider' => 'cloro', 'variant' => 'cloro-chatgpt-live', 'model' => 'gpt-5-3-mini', 'collector' => 'cloro'])
        ->and($result['answer'])->toBe('**HubSpot** leads [HubSpot](https://hubspot.com/).')
        // Sources then pills, deduplicated by URL; the entity and its URL stay evidence-only.
        ->and(array_column($result['citations'], 'url'))->toBe(['https://hubspot.com/', 'https://pipedrive.com/'])
        ->and($result['cost'])->toBe(['estimated' => null, 'reported' => null])
        ->and(json_encode($run->toArray()))->not->toContain('entity.example')
        ->and(json_encode($run->evidence('chatgpt')))->toContain('entity.example')
        ->and($run->evidence('chatgpt')[0]['headers']['x-credits-charged'] ?? null)->toBe(['7']);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer testing-cloro-key')
        && $request->data() === ['prompt' => 'best crm', 'country' => 'US', 'include' => ['markdown' => true]]);
});

it('maps a live surface with anonymous provenance and the reported credits in provider metadata', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'result' => cloroChatGpt()], 200, ['X-Credits-Charged' => '7'])]);
    config(['librarium.profiles.cloro-chatgpt-live.enabled' => true, 'librarium.profiles.cloro-chatgpt-live.options' => ['country' => 'US']]);

    $result = variantRun('cloro-chatgpt-live', 'best crm');

    expect($result->provenance->collector)->toBe('cloro')
        ->and($result->provenance->surface)->toBe('chatgpt')
        ->and($result->provenance->context['authentication'] ?? null)->toBe(Authentication::Anonymous)
        ->and($result->usage)->toBeNull()
        ->and($result->providerMeta?->credits_charged)->toBe(7)
        ->and($result->providerMeta?->mode)->toBe('live');
});

it('submits a standard task under a Run idempotency key and finishes it on refresh', function (): void {
    Queue::fake();
    $gets = 0;
    Http::fake(function (Request $request) use (&$gets) {
        if ($request->method() === 'POST') {
            return Http::response(['success' => true, 'task' => ['id' => CLORO_TASK_ID, 'taskType' => 'GEMINI', 'status' => 'QUEUED', 'priority' => 1, 'createdAt' => '2026-11-27T12:00:00Z'], 'credits' => ['creditsToCharge' => 4, 'creditsCharged' => null]]);
        }
        $gets++;

        return Http::response(cloroTask('GEMINI', $gets === 1 ? 'PROCESSING' : 'COMPLETED', ['text' => 'Gemini says hi.', 'sources' => [['position' => 1, 'url' => 'https://a.example/', 'label' => 'A']]]));
    });

    $id = Librarium::research('hello')->only(['gemini-app'])->queue()->id();
    app(RunRuntime::class)->start($id);
    $pending = Librarium::find($id);
    expect($pending->status())->toBe('running')->and($pending->result('gemini-app')['provenance']['variant'])->toBe('cloro-gemini');

    expect($pending->refresh()->status())->toBe('running');
    $done = Librarium::find($id)->refresh();

    expect($done->status())->toBe('done')
        ->and($done->result('gemini-app')['answer'])->toBe('Gemini says hi.')
        ->and($done->result('gemini-app')['cost']['reported'])->toBeNull();
    $post = collect(Http::recorded())->first(fn (array $entry): bool => $entry[0]->method() === 'POST')[0];
    expect($post->url())->toBe('https://api.cloro.dev/v1/async/task')
        ->and($post->data())->toBe([
            'taskType' => 'GEMINI',
            'payload' => ['prompt' => 'hello', 'country' => 'US', 'include' => ['markdown' => true]],
            'idempotencyKey' => 'librarium-'.$id.'-cloro-gemini',
        ]);
});

it('recovers the existing task from an idempotency conflict after a lost acknowledgement', function (): void {
    $posts = 0;
    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'POST') {
            if (++$posts === 1) {
                throw new ConnectionException('reset');
            }

            return Http::response(['success' => false, 'error' => ['code' => 'RESOURCE_ALREADY_EXISTS', 'message' => 'Task already exists', 'details' => ['field' => 'idempotencyKey', 'taskId' => CLORO_TASK_ID]]], 409);
        }

        return Http::response(cloroTask('BING_AIMODE', 'COMPLETED', ['text' => 'Bing answer.', 'sources' => []]));
    });

    $run = Librarium::research('q')->only(['bing-ai-mode'])->run();

    expect($run->result('bing-ai-mode')['status'])->toBe('done')
        ->and($run->result('bing-ai-mode')['answer'])->toBe('Bing answer.')
        ->and($posts)->toBe(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloro.dev/v1/async/task/'.CLORO_TASK_ID);
});

it('retries one server error on submit under the same idempotency key', function (): void {
    $keys = [];
    Http::fake(function (Request $request) use (&$keys) {
        if ($request->method() === 'POST') {
            $keys[] = $request->data()['idempotencyKey'];

            return count($keys) === 1
                ? Http::response(['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Internal']], 500)
                : Http::response(['success' => true, 'task' => ['id' => CLORO_TASK_ID, 'taskType' => 'CHATGPT', 'status' => 'QUEUED']]);
        }

        return Http::response(cloroTask('CHATGPT', 'COMPLETED', ['text' => "Joe's CRM.", 'sources' => []]));
    });

    expect(Librarium::research('q')->only(['chatgpt'])->run()->result('chatgpt')['status'])->toBe('done')
        ->and($keys)->toHaveCount(2)->and(array_unique($keys))->toHaveCount(1);
});

it('never sends the configured Google key to a collector chosen per request', function (): void {
    config(['librarium.sources.google' => ['collector' => 'searchapi', 'key' => 'my-searchapi-key', 'options' => ['country' => 'US']], 'services.cloro.key' => 'testing-cloro-key']);

    expect(app(SourceCatalog::class)->profile('google', 'quick', ['collector' => 'cloro'])->credential)->toBe('testing-cloro-key')
        ->and(app(SourceCatalog::class)->profile('google', 'quick', ['collector' => 'searchapi'])->credential)->toBe('my-searchapi-key');
});

it('fails a task Cloro already deleted without retrying or resubmitting', function (): void {
    Http::fake(function (Request $request) {
        return $request->method() === 'POST'
            ? Http::response(['success' => true, 'task' => ['id' => CLORO_TASK_ID, 'taskType' => 'AIMODE', 'status' => 'QUEUED']])
            : Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found']], 404);
    });

    $result = Librarium::research('q')->only(['google-ai-mode'])->run()->result('google-ai-mode');

    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toMatchArray(['code' => 'cloro.task_expired', 'retryable' => false]);
    Http::assertSentCount(2);
});

it('maps provider failures to stable codes and never retries a paid live call', function (int $status, array $error, string $code): void {
    Http::fake(['*' => Http::response(['success' => false, 'error' => $error], $status)]);

    $result = Librarium::research('q')->only(['chatgpt'])->options(['chatgpt' => ['priority' => 'live']])->run()->result('chatgpt');

    expect($result['status'])->toBe('failed')->and($result['error']['code'])->toBe($code);
    Http::assertSentCount(1);
})->with([
    'bad key' => [401, ['code' => 'INVALID_OR_EXPIRED_API_KEY', 'message' => 'Invalid'], 'cloro.authentication'],
    'no credits' => [403, ['code' => 'INSUFFICIENT_CREDITS', 'message' => 'Insufficient credits'], 'cloro.insufficient_credits'],
    'suspended' => [403, ['code' => 'BILLING_SUSPENDED', 'message' => 'Suspended'], 'cloro.billing_suspended'],
    'concurrency' => [429, ['code' => 'CONCURRENCY_LIMIT_EXCEEDED', 'message' => 'Concurrency limit exceeded', 'details' => ['limit' => 1]], 'cloro.rate_limited'],
]);

it('fails a finished task by its error code', function (): void {
    Http::fake(function (Request $request) {
        return $request->method() === 'POST'
            ? Http::response(['success' => true, 'task' => ['id' => CLORO_TASK_ID, 'taskType' => 'CHATGPT', 'status' => 'QUEUED']])
            : Http::response(cloroTask('CHATGPT', 'FAILED', ['error' => ['code' => 'INSUFFICIENT_CREDITS', 'message' => 'Insufficient credits']]));
    });

    expect(Librarium::research('q')->only(['chatgpt'])->run()->result('chatgpt')['error']['code'])->toBe('cloro.insufficient_credits');
});

it('limits Bing AI Mode prompts by their URL-encoded length before any request', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['text' => 'ok', 'sources' => []]])]);
    $run = fn (string $prompt) => Librarium::research($prompt)->only(['bing-ai-mode'])->options(['bing-ai-mode' => ['priority' => 'live']])->run()->result('bing-ai-mode');

    // A CJK character encodes to 9 bytes: 388 fit in 3,500, 389 do not.
    expect($run(str_repeat('日', 389))['error']['code'])->toBe('cloro.invalid_query');
    Http::assertNothingSent();
    expect($run(str_repeat('日', 388))['status'])->toBe('done');
    Http::assertSentCount(1);
});

it('fails an AI Overview request when Google showed none, instead of answering from the SERP', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['organicResults' => [['position' => 1, 'title' => 'T', 'link' => 'https://t.example/']]]])]);

    $result = Librarium::research('q')->only(['google-ai-overview'])->options(['google-ai-overview' => ['priority' => 'live']])->run()->result('google-ai-overview');

    expect($result['error']['code'])->toBe('cloro.no_ai_overview');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloro.dev/v1/monitor/google'
        && $request->data() === ['query' => 'q', 'country' => 'US', 'include' => ['aioverview' => ['markdown' => true]]]);
});

it('maps google vertical news to Cloro News through by_collector and keeps SerpBase news for serpbase', function (): void {
    config(['librarium.sources.google' => ['collector' => 'cloro', 'options' => ['country' => 'GB']], 'services.cloro.key' => 'testing-cloro-key']);
    $catalog = app(SourceCatalog::class);
    expect($catalog->profile('google', 'quick')->id)->toBe('cloro-google-search')
        ->and($catalog->profile('google', 'quick', ['vertical' => 'news'])->id)->toBe('cloro-google-news')
        ->and($catalog->profile('google', 'quick', ['vertical' => 'news', 'collector' => 'serpbase'])->id)->toBe('serpbase-news');
    expect(fn () => $catalog->profile('google', 'quick', ['vertical' => 'news', 'collector' => 'serpapi']))->toThrow(ValidationException::class);

    Http::fake(['https://api.cloro.dev/v1/monitor/google/news' => Http::response(['success' => true, 'result' => ['newsResults' => [
        ['position' => 1, 'title' => 'Rates rise', 'link' => 'https://news.example/a', 'snippet' => 'Bank of England.', 'source' => 'Example News', 'date' => '2 hours ago'],
        ['position' => 2, 'title' => 'Duplicate', 'link' => 'https://news.example/a'],
    ]]], 200, ['X-Credits-Charged' => '5'])]);
    $result = Librarium::research('interest rates')->only(['google'])->options(['google' => ['vertical' => 'news']])->run()->result('google');

    expect($result['status'])->toBe('done')
        ->and($result['provenance']['collector'])->toBe('cloro')
        ->and($result['citations'])->toBe([['url' => 'https://news.example/a', 'title' => 'Rates rise']]);
    Http::assertSent(fn (Request $request): bool => $request->data() === ['query' => 'interest rates', 'country' => 'GB']);
});

it('rejects a Cloro collector without a country and an unknown priority before any request', function (): void {
    Http::fake();
    config(['librarium.engines.options' => []]);
    expect(fn () => Librarium::research('q')->only(['chatgpt'])->dryRun())->toThrow(ValidationException::class);
    config(['librarium.engines.options' => ['country' => 'us']]);
    expect(fn () => Librarium::research('q')->only(['chatgpt'])->dryRun())->toThrow(ValidationException::class);
    config(['librarium.engines.options' => ['country' => 'US']]);
    expect(fn () => Librarium::research('q')->only(['chatgpt'])->options(['chatgpt' => ['priority' => 'high']])->dryRun())->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('prices each priority in credits of one account-priced identity', function (string $profile, string $sku, int $credits): void {
    config(["librarium.profiles.{$profile}.enabled" => true, "librarium.profiles.{$profile}.options" => ['country' => 'US']]);
    $bound = variantBound($profile, 'q');
    $quote = variantRequest($profile, 'q')->profile;

    expect($bound->pricingIdentity?->key())->toBe("cloro:{$sku}")
        ->and($bound->quantities?->toArray())->toBe(['credits' => (string) $credits])
        ->and($quote->pricingIdentity?->key())->toBe("cloro:{$sku}")
        ->and($quote->pricingUsage?->toArray())->toBe(['credits' => (string) $credits]);
})->with([
    ['cloro-chatgpt', 'chatgpt', 5], ['cloro-chatgpt-live', 'chatgpt', 7],
    ['cloro-google-ai-overview', 'google-ai-overview', 5], ['cloro-bing-ai-mode-live', 'bing-ai-mode', 6],
    ['cloro-google-search', 'google-search', 5], ['cloro-google-news', 'google-news', 5],
]);

/** @return array<string, mixed> */
function cloroChatGpt(): array
{
    return [
        'model' => 'gpt-5-3-mini',
        'text' => 'HubSpot leads.',
        'markdown' => '**HubSpot** leads [HubSpot](https://hubspot.com/).',
        'sources' => [['position' => 1, 'url' => 'https://hubspot.com/', 'label' => 'HubSpot', 'description' => 'CRM']],
        'citationPills' => [
            ['citationPillId' => 0, 'url' => 'https://hubspot.com/', 'label' => 'HubSpot', 'domain' => 'hubspot.com', 'position' => 1],
            ['citationPillId' => 1, 'url' => 'https://pipedrive.com/', 'label' => 'Pipedrive', 'domain' => 'pipedrive.com', 'position' => 2],
        ],
        'entities' => [['name' => 'HubSpot', 'url' => 'https://entity.example/hubspot']],
    ];
}

/**
 * @param  array<string, mixed>  $response
 * @return array<string, mixed>
 */
function cloroTask(string $type, string $status, array $response): array
{
    $finished = in_array($status, ['COMPLETED', 'FAILED'], true);

    return array_filter([
        'task' => ['id' => CLORO_TASK_ID, 'taskType' => $type, 'status' => $status, 'priority' => 1, 'createdAt' => '2026-11-27T12:00:00Z'],
        'credits' => ['creditsToCharge' => 4, 'creditsCharged' => $finished ? ($status === 'FAILED' ? 0 : 4) : null],
        'response' => $finished ? $response : null,
    ], static fn (mixed $value): bool => $value !== null);
}
