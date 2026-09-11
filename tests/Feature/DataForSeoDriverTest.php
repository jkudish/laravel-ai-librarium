<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoCredential;
use Jkudish\LaravelAiLibrarium\Drivers\DataForSeo\DataForSeoDriver;
use Jkudish\LaravelAiLibrarium\Exceptions\RecoveryException;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\LaravelAiLibrariumServiceProvider;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Personalization;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;

const DATAFORSEO_TEST_ID = '02241638-1535-4139-8000-f0c53c397c88';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-06T12:00:00Z');
    Sleep::fake(true, true);
    configureDataForSeoProfiles();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Sleep::fake(false);
});

it('registers six opt-in surface Profiles with exact pricing identities and no curated roster changes', function (): void {
    $profiles = app(ProfileCatalog::class)->all()
        ->filter(fn ($item): bool => $item->profile->driver === DataForSeoDriver::class);

    expect($profiles->keys()->all())->toBe([
        'dataforseo-chatgpt',
        'dataforseo-chatgpt-live',
        'dataforseo-gemini',
        'dataforseo-gemini-live',
        'dataforseo-google-ai-mode',
        'dataforseo-google-ai-mode-live',
    ])->and($profiles->map(fn ($item): string => $item->profile->pricingIdentity?->key() ?? '')->values()->all())->toBe([
        'dataforseo:chatgpt-llm-scraper-standard',
        'dataforseo:chatgpt-llm-scraper-live',
        'dataforseo:gemini-llm-scraper-standard',
        'dataforseo:gemini-llm-scraper-live',
        'dataforseo:google-ai-mode-standard',
        'dataforseo:google-ai-mode-live',
    ]);

    $profiles->each(function ($item): void {
        expect($item->profile->resultKind)->toBe(ResultKind::SurfaceObservation)
            ->and($item->profile->observation)->toBe(ObservationMode::SurfaceSnapshot)
            ->and($item->profile->retrievalMethods->map->value->all())->toBe(['surface_collector'])
            ->and($item->profile->pricingUsage?->toArray())->toBe(['requests' => '1'])
            ->and($item->profile->pricingUsageConservative)->toBeTrue();
    });

    expect(app(ProfileCatalog::class)->group('quick')['profiles']->pluck('id'))->not->toContain('dataforseo-chatgpt')
        ->and(app(ProfileCatalog::class)->group('visibility')['profiles']->pluck('id'))->not->toContain('dataforseo-chatgpt')
        ->and(app(ProfileCatalog::class)->group('all')['profiles']->pluck('id')->all())->toContain('dataforseo-chatgpt', 'dataforseo-google-ai-mode-live');
});

it('ships all DataForSEO Profiles disabled and unavailable until explicit controls and credentials are configured', function (): void {
    $fresh = require __DIR__.'/../../config/librarium.php';
    config()->set('librarium.profiles', $fresh['profiles']);

    $items = app(ProfileCatalog::class)->all()->filter(fn ($item): bool => $item->profile->driver === DataForSeoDriver::class);

    expect($items)->toHaveCount(6);
    $items->each(function ($item): void {
        expect($item->profile->enabled)->toBeFalse()
            ->and($item->isEligible())->toBeFalse()
            ->and($item->reasons)->toContain('Profile is disabled.', 'Credential is not configured.');
    });
});

it('resolves one provider login and password into every DataForSEO Profile without exposing them', function (): void {
    $fresh = require __DIR__.'/../../config/librarium.php';
    config()->set('librarium.profiles', $fresh['profiles']);
    config()->set('librarium.providers.dataforseo', ['login' => 'provider-login', 'password' => 'provider-password']);

    $provider = new LaravelAiLibrariumServiceProvider(app());
    $provider->register();
    $provider->boot();
    app()->forgetInstance(ProfileCatalog::class);

    $profiles = app(ProfileCatalog::class)->all()
        ->filter(fn ($item): bool => $item->profile->driver === DataForSeoDriver::class);
    $encoded = DataForSeoCredential::encode('provider-login', 'provider-password');

    $profiles->each(function ($item) use ($encoded): void {
        expect($item->profile->credential)->toBe($encoded)
            ->and(json_encode($item->profile, JSON_THROW_ON_ERROR))->not->toContain('provider-login', 'provider-password', (string) $encoded);
    });
});

it('runs each Standard and Live Profile through the real Librarium runtime on one fixed endpoint', function (string $profile, string $family, string $surface, bool $live): void {
    $query = 'literal + %20 % café';
    Http::fake(function (Request $request) use ($profile, $family, $surface, $live, $query) {
        $operation = $live ? 'live/advanced' : ($request->method() === 'POST' ? 'task_post' : 'task_get/advanced/'.DATAFORSEO_TEST_ID);

        return Http::response(dataForSeoEnvelope(
            profile: $profile,
            family: $family,
            operation: $operation,
            surface: $surface,
            query: $query,
            status: $request->method() === 'POST' && ! $live ? 20100 : 20000,
            completed: $request->method() === 'GET' || $live,
            cost: $request->method() === 'POST' ? ($live ? '0.004000' : '0.001200') : '0',
        ));
    });

    $response = Librarium::query($query)->using($profile)->run();
    $result = $response->results->sole();

    expect($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($result->provider)->toBe('dataforseo')
        ->and($result->profile)->toBe($profile)
        ->and($result->provenance->collector)->toBe('dataforseo')
        ->and($result->provenance->surface)->toBe($surface)
        ->and($result->provenance->context)->toBe([
            'authentication' => Authentication::Unknown,
            'personalization' => Personalization::Unknown,
        ])
        ->and($result->provenance->observedAt->format('Y-m-d H:i:s.u'))->toBe('2026-09-06 11:59:58.123400')
        ->and($result->content)->toContain('Collected answer')
        ->and($result->citations)->toHaveCount(1)
        ->and($result->citations->sole()->derivation)->toBe(CitationDerivation::CollectorExtracted)
        ->and($result->citations->sole()->source->url)->toBe('https://example.test/source')
        ->and($result->usage?->actualCost)->toBe($live ? '0.004000' : '0.001200')
        ->and($result->usage?->currency)->toBe('USD')
        ->and((array) $result->providerMeta)->toMatchArray([
            'task_id' => DATAFORSEO_TEST_ID,
            'endpoint_family' => $family,
            'mode' => $live ? 'live' : 'standard',
            'requested_controls' => (object) dataForSeoControls($profile),
        ]);

    $requests = Http::recorded();
    expect($requests)->toHaveCount($live ? 1 : 2)
        ->and($requests[0][0]->method())->toBe('POST')
        ->and($requests[0][0]->url())->toBe("https://api.dataforseo.com/v3/{$family}/".($live ? 'live/advanced' : 'task_post'))
        ->and($requests[0][0]->data())->toBe([[
            'keyword' => 'literal%20%2B%20%2520%20%25%20caf%C3%A9',
            ...dataForSeoControls($profile),
        ]])
        ->and($requests[0][0]->hasHeader('Authorization', 'Basic '.base64_encode('dataforseo-login:dataforseo-password')))->toBeTrue()
        ->and($requests[0][0]->url())->not->toContain('dataforseo-login', 'dataforseo-password');
})->with(dataForSeoProfileDataset());

it('maps supported scraper text and table blocks when full Markdown is absent without fabricating rich content', function (): void {
    Http::fake(fn (Request $request) => Http::response(dataForSeoEnvelope(
        profile: 'dataforseo-chatgpt-live',
        family: 'ai_optimization/chat_gpt/llm_scraper',
        operation: 'live/advanced',
        surface: 'chatgpt',
        query: 'fallback blocks',
        status: 20000,
        completed: true,
        cost: '0.004',
        resultOverride: [
            'keyword' => 'fallback blocks',
            'location_code' => 2840,
            'language_code' => 'en',
            'datetime' => '2026-09-06 11:59:58 +00:00',
            'markdown' => null,
            'sources' => null,
            'items' => [
                ['type' => 'chat_gpt_text', 'markdown' => 'Text answer.'],
                ['type' => 'chat_gpt_table', 'markdown' => "| A | B |\n| - | - |"],
                ['type' => 'chat_gpt_images', 'markdown' => 'image must not become answer'],
            ],
        ],
    )));

    $result = Librarium::query('fallback blocks')->using('dataforseo-chatgpt-live')->run()->results->sole();

    expect($result->content)->toBe("Text answer.\n\n| A | B |\n| - | - |")
        ->and($result->citations)->toBeEmpty()
        ->and((array) $result->providerMeta)->toMatchArray(['omitted_item_types' => ['chat_gpt_images']]);
});

it('reports rich item omissions only when projecting structured fallback content', function (bool $aiMode, bool $aggregate): void {
    $profile = $aiMode ? 'dataforseo-google-ai-mode-live' : 'dataforseo-chatgpt-live';
    $family = $aiMode ? 'serp/google/ai_mode' : 'ai_optimization/chat_gpt/llm_scraper';
    $surface = $aiMode ? 'google_ai_mode' : 'chatgpt';
    $richType = $aiMode ? 'ai_overview_shopping' : 'chat_gpt_images';
    $payload = dataForSeoEnvelope($profile, $family, 'live/advanced', $surface, 'rich answer', 20000, true, '0.004');
    $answerPath = 'tasks.0.result.0'.($aiMode ? '.items.0' : '');
    data_set($payload, $answerPath.'.markdown', $aggregate ? 'Answer with rich content' : null);
    data_set($payload, $answerPath.'.items', [
        ['type' => $aiMode ? 'ai_overview_element' : 'chat_gpt_text', 'markdown' => 'Projected text'],
        ['type' => $richType, 'markdown' => 'Rich content'],
    ]);
    Http::fake(fn () => Http::response($payload));

    $result = Librarium::query('rich answer')->using($profile)->run()->results->sole();

    expect($result->content)->toBe($aggregate ? 'Answer with rich content' : 'Projected text')
        ->and(data_get($result->providerMeta, 'omitted_item_types'))->toBe($aggregate ? null : [$richType]);
})->with([false, true])->with([false, true]);

it('never substitutes organic AI Mode results for a missing AI overview answer', function (): void {
    Http::fake(fn () => Http::response(dataForSeoEnvelope(
        profile: 'dataforseo-google-ai-mode-live',
        family: 'serp/google/ai_mode',
        operation: 'live/advanced',
        surface: 'google_ai_mode',
        query: 'missing answer',
        status: 20000,
        completed: true,
        cost: '0.004',
        resultOverride: [
            'keyword' => 'missing answer',
            'location_code' => 2840,
            'language_code' => 'en',
            'datetime' => '2026-09-06 11:59:58 +00:00',
            'items' => [['type' => 'organic', 'description' => 'must not become an answer']],
        ],
    )));

    $response = Librarium::query('missing answer')->using('dataforseo-google-ai-mode-live')->run();

    expect($response->status)->toBe(ResponseStatus::Failed)
        ->and($response->errors->sole()->code)->toBe('dataforseo.invalid_response')
        ->and($response->results)->toBeEmpty();
});

it('polls documented pending states and retries only safe GET failures while preserving the POST charge', function (): void {
    $calls = 0;
    $times = [];
    Http::fake(function (Request $request) use (&$calls, &$times) {
        $times[] = CarbonImmutable::now()->getTimestamp();
        $calls++;
        if ($calls === 1) {
            return Http::response(dataForSeoEnvelope('dataforseo-gemini', 'ai_optimization/gemini/llm_scraper', 'task_post', 'gemini', 'poll me', 20100, false, '0.001200'));
        }
        if ($calls === 2) {
            return Http::response(dataForSeoEnvelope('dataforseo-gemini', 'ai_optimization/gemini/llm_scraper', 'task_get/advanced/'.DATAFORSEO_TEST_ID, 'gemini', 'poll me', 40602, false, '0'));
        }
        if ($calls === 3) {
            return Http::response(['safe' => 'retrieval retry'], 503);
        }
        if ($calls === 4) {
            throw new ConnectionException('safe GET connection retry');
        }
        if ($calls === 5) {
            return Http::response(['status_code' => 50000, 'tasks_count' => 0, 'tasks_error' => 1, 'tasks' => []]);
        }

        return Http::response(dataForSeoEnvelope('dataforseo-gemini', 'ai_optimization/gemini/llm_scraper', 'task_get/advanced/'.DATAFORSEO_TEST_ID, 'gemini', 'poll me', 20000, true, '0'));
    });

    $result = Librarium::query('poll me')->using('dataforseo-gemini')->timeout(120)->run()->results->sole();

    expect($result->usage?->actualCost)->toBe('0.001200')
        ->and($calls)->toBe(6);
    expect($times[1] - $times[0])->toBe(0)
        ->and($times[2] - $times[1])->toBeBetween(10, 15);
    foreach ([3, 4, 5] as $index) {
        expect($times[$index] - $times[$index - 1])->toBeBetween(25, 30);
    }
    expect(Http::recorded())->toHaveCount(5)
        ->and(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() === 'POST'))->toHaveCount(1);
});

it('never retries an ambiguous paid POST or follows a credential-bearing redirect', function (mixed $failure): void {
    $calls = 0;
    Http::fake(function () use (&$calls, $failure) {
        $calls++;
        if ($failure === 'connection') {
            throw new ConnectionException('secret-bearing transport detail');
        }

        return Http::response('', 302, ['Location' => 'https://redirect.example.test/steal']);
    });

    $response = Librarium::query('one paid attempt')->using('dataforseo-chatgpt')->run();

    expect($response->status)->toBe(ResponseStatus::Failed)
        ->and($response->errors->sole()->code)->toBe('dataforseo.ambiguous_submission')
        ->and($response->errors->sole()->message)->not->toContain('secret-bearing')
        ->and($calls)->toBe(1);
    Sleep::assertNeverSlept();
})->with(['connection', 'redirect']);

it('fails closed on mismatched IDs, families, statuses, paths, echoes, and envelopes', function (Closure $mutate, string $expectedCode): void {
    $payload = dataForSeoEnvelope(
        'dataforseo-chatgpt-live',
        'ai_optimization/chat_gpt/llm_scraper',
        'live/advanced',
        'chatgpt',
        'identity check',
        20000,
        true,
        '0.004',
    );
    $mutate($payload);
    Http::fake(fn () => Http::response($payload));

    $response = Librarium::query('identity check')->using('dataforseo-chatgpt-live')->run();

    expect($response->status)->toBe(ResponseStatus::Failed)
        ->and($response->errors->sole()->code)->toBe($expectedCode);
})->with([
    'mismatched task ID' => [function (array &$payload): void {
        $payload['tasks'][0]['id'] = 'not-a-uuid';
    }, 'dataforseo.invalid_response'],
    'cross-family receipt' => [function (array &$payload): void {
        $payload['tasks'][0]['data']['se'] = 'gemini';
    }, 'dataforseo.invalid_response'],
    'wrong path' => [function (array &$payload): void {
        $payload['tasks'][0]['path'][2] = 'gemini';
    }, 'dataforseo.invalid_response'],
    'wrong function' => [function (array &$payload): void {
        $payload['tasks'][0]['data']['function'] = 'task_get';
    }, 'dataforseo.invalid_response'],
    'task error inside HTTP 200' => [function (array &$payload): void {
        $payload['tasks'][0]['status_code'] = 40501;
    }, 'dataforseo.provider'],
    'mismatched semantic echo' => [function (array &$payload): void {
        $payload['tasks'][0]['data']['keyword'] = 'different';
    }, 'dataforseo.invalid_response'],
    'missing tasks' => [function (array &$payload): void {
        unset($payload['tasks']);
    }, 'dataforseo.invalid_response'],
    'contradictory count' => [function (array &$payload): void {
        $payload['tasks_count'] = 2;
    }, 'dataforseo.invalid_response'],
]);

it('accepts the documented ChatGPT receipt alias while keeping the request route pinned', function (): void {
    $payload = dataForSeoEnvelope('dataforseo-chatgpt-live', 'ai_optimization/chat_gpt/llm_scraper', 'live/advanced', 'chatgpt', 'alias', 20000, true, '0.004');
    $payload['tasks'][0]['path'][2] = 'chatgpt';
    $payload['tasks'][0]['data']['se'] = 'chatgpt';
    Http::fake(fn () => Http::response($payload));

    expect(Librarium::query('alias')->using('dataforseo-chatgpt-live')->run()->status)->toBe(ResponseStatus::Succeeded);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.dataforseo.com/v3/ai_optimization/chat_gpt/llm_scraper/live/advanced');
});

it('accepts the observed ChatGPT Standard creation receipt identity', function (): void {
    $query = str_repeat('é', 2000);
    $payload = dataForSeoEnvelope('dataforseo-chatgpt', 'ai_optimization/chat_gpt/llm_scraper', 'task_post', 'chatgpt', $query, 20100, false, '0.0012');
    $payload['tasks'][0]['data']['function'] = 'llm_scraper';
    $payload['tasks'][0]['data']['keyword'] = rawurlencode($query);
    unset($payload['tasks'][0]['data']['se_type']);
    Http::fake(fn () => Http::response($payload));

    $submission = Librarium::query($query)->using('dataforseo-chatgpt')->submit();

    expect($submission->handle->reference)->toBe(DATAFORSEO_TEST_ID)
        ->and($submission->usage()?->actualCost)->toBe('0.0012');
    Http::assertSentCount(1);
});

it('keeps observed Standard receipt aliases exact', function (string $profile, string $family, string $surface, Closure $mutate): void {
    $query = 'literal + %20 % café';
    $payload = dataForSeoEnvelope($profile, $family, 'task_post', $surface, $query, 20100, false, '0.0012');
    $mutate($payload, $query);
    Http::fake(fn () => Http::response($payload));

    expect(fn () => Librarium::query($query)->using($profile)->submit())
        ->toThrow(RecoveryException::class, 'different task family or request');
    Http::assertSentCount(1);
})->with([
    'encoded echo of another prompt' => [
        'dataforseo-chatgpt',
        'ai_optimization/chat_gpt/llm_scraper',
        'chatgpt',
        function (array &$payload, string $query): void {
            $payload['tasks'][0]['data']['function'] = 'llm_scraper';
            $payload['tasks'][0]['data']['keyword'] = rawurlencode($query.' changed');
        },
    ],
    'AI Optimization function on a SERP receipt' => [
        'dataforseo-google-ai-mode',
        'serp/google/ai_mode',
        'google_ai_mode',
        function (array &$payload): void {
            $payload['tasks'][0]['data']['function'] = 'llm_scraper';
        },
    ],
]);

it('accepts omitted optional scraper echoes but rejects contradictory product or control echoes', function (array $echo, bool $accepted): void {
    $payload = dataForSeoEnvelope('dataforseo-chatgpt-live', 'ai_optimization/chat_gpt/llm_scraper', 'live/advanced', 'chatgpt', 'echo', 20000, true, '0.004');
    unset($payload['tasks'][0]['data']['force_web_search']);
    $payload['tasks'][0]['data'] = [...$payload['tasks'][0]['data'], ...$echo];
    Http::fake(fn () => Http::response($payload));

    $response = Librarium::query('echo')->using('dataforseo-chatgpt-live')->run();

    expect($response->status)->toBe($accepted ? ResponseStatus::Succeeded : ResponseStatus::Failed);
    if (! $accepted) {
        expect($response->errors->sole()->code)->toBe('dataforseo.invalid_response');
    }
})->with([
    'omitted controls are not measured facts' => [[], true],
    'matching optional product' => [['se_type' => 'llm_scraper'], true],
    'contradictory product' => [['se_type' => 'llm_responses'], false],
    'contradictory sent control' => [['force_web_search' => false], false],
]);

it('rejects a calendar-invalid provider observation time rather than normalizing it', function (): void {
    $payload = dataForSeoEnvelope('dataforseo-gemini-live', 'ai_optimization/gemini/llm_scraper', 'live/advanced', 'gemini', 'bad date', 20000, true, '0.004');
    $payload['tasks'][0]['result'][0]['datetime'] = '2026-02-30 11:59:58 +00:00';
    Http::fake(fn () => Http::response($payload));

    expect(Librarium::query('bad date')->using('dataforseo-gemini-live')->run()->errors->sole()->code)->toBe('dataforseo.invalid_response');
});

it('fails an expired Standard task and does not submit again', function (): void {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;

        return Http::response(dataForSeoEnvelope(
            'dataforseo-chatgpt',
            'ai_optimization/chat_gpt/llm_scraper',
            $request->method() === 'POST' ? 'task_post' : 'task_get/advanced/'.DATAFORSEO_TEST_ID,
            'chatgpt',
            'expired',
            $request->method() === 'POST' ? 20100 : 40401,
            false,
            $request->method() === 'POST' ? '0.0012' : '0',
        ));
    });

    $response = Librarium::query('expired')->using('dataforseo-chatgpt')->run();

    expect($response->errors->sole()->code)->toBe('dataforseo.task_not_found')
        ->and($calls)->toBe(2)
        ->and(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() === 'POST'))->toHaveCount(1);
});

it('shares the runtime deadline across polling and caps its clock-testable wait', function (): void {
    Http::fake(function (Request $request) {
        return Http::response(dataForSeoEnvelope(
            'dataforseo-gemini',
            'ai_optimization/gemini/llm_scraper',
            $request->method() === 'POST' ? 'task_post' : 'task_get/advanced/'.DATAFORSEO_TEST_ID,
            'gemini',
            'deadline',
            $request->method() === 'POST' ? 20100 : 40601,
            false,
            $request->method() === 'POST' ? '0.0012' : '0',
        ));
    });

    $response = Librarium::query('deadline')->using('dataforseo-gemini')->timeout(10)->run();

    expect($response->errors->sole()->code)->toBe('dataforseo.deadline_exceeded')
        ->and(Http::recorded())->toHaveCount(2);
    Sleep::assertSequence([Sleep::for(10)->seconds()]);
});

it('rejects query limits and unsupported controls before HTTP', function (string $profile, string $query, array $options, string $code): void {
    config()->set("librarium.profiles.{$profile}.options", $options);
    Http::fake();

    $response = Librarium::query($query)->using($profile)->run();

    expect($response->errors->sole()->code)->toBe($code);
    Http::assertNothingSent();
})->with([
    'scraper too long' => ['dataforseo-gemini-live', str_repeat('x', 2001), ['location_code' => 2840, 'language_code' => 'en'], 'dataforseo.invalid_query'],
    'AI Mode too long' => ['dataforseo-google-ai-mode-live', str_repeat('é', 701), ['location_code' => 2840, 'language_code' => 'en'], 'dataforseo.invalid_query'],
    'ChatGPT device rejected' => ['dataforseo-chatgpt-live', 'q', ['location_code' => 2840, 'language_code' => 'en', 'force_web_search' => true, 'device' => 'desktop'], 'dataforseo.invalid_options'],
    'Gemini web control rejected' => ['dataforseo-gemini-live', 'q', ['location_code' => 2840, 'language_code' => 'en', 'force_web_search' => true], 'dataforseo.invalid_options'],
    'AI Mode mismatch rejected' => ['dataforseo-google-ai-mode-live', 'q', ['location_code' => 2840, 'language_code' => 'en', 'device' => 'desktop', 'os' => 'ios'], 'dataforseo.invalid_options'],
]);

it('rejects unknown and secret option bags during preflight', function (array $options): void {
    config()->set('librarium.profiles.dataforseo-gemini.options', $options);

    expect(fn () => Librarium::query('preflight')->using('dataforseo-gemini')->preview())
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([
    [['location_code' => 2840, 'language_code' => 'en', 'priority' => 2]],
    [['location_code' => 2840, 'language_code' => 'en', 'password' => 'must-not-live-here']],
    [['location_code' => 2840, 'language_code' => 'en', 'endpoint_url' => 'https://evil.test']],
]);

it('keeps both raw and encoded credentials out of previews, terminal values, and provider errors', function (): void {
    Http::fake(fn () => Http::response([
        'version' => 'secret-login secret-password '.base64_encode('secret-login:secret-password'),
        'status_code' => 50000,
        'tasks_count' => 0,
        'tasks_error' => 1,
        'tasks' => [],
    ]));
    $credential = DataForSeoCredential::encode('secret-login', 'secret-password');
    config()->set('librarium.profiles.dataforseo-gemini-live.credential', $credential);

    $preview = Librarium::query('secret safety')->using('dataforseo-gemini-live')->preview();
    $response = Librarium::query('secret safety')->using('dataforseo-gemini-live')->run();
    $wire = json_encode([$preview, $response], JSON_THROW_ON_ERROR);

    expect($wire)->not->toContain('secret-login', 'secret-password', (string) $credential)
        ->and($response->errors->sole()->code)->toBe('dataforseo.invalid_response');
});

it('rejects successful receipts that expose configured credentials in retained fields', function (string $path, string $secretForm): void {
    $login = 'canary-login@example.test';
    $password = 'canary-password/é';
    $credential = DataForSeoCredential::encode($login, $password);
    config()->set('librarium.profiles.dataforseo-gemini-live.credential', $credential);
    $secret = match ($secretForm) {
        'login' => $login,
        'password' => $password,
        'basic' => base64_encode($login.':'.$password),
        default => $credential,
    };
    $payload = dataForSeoEnvelope('dataforseo-gemini-live', 'ai_optimization/gemini/llm_scraper', 'live/advanced', 'gemini', 'safe query', 20000, true, '0.004');
    data_set($payload, $path, $secret);
    Http::fake(fn () => Http::response($payload));

    $response = Librarium::query('safe query')->using('dataforseo-gemini-live')->run();

    expect($response->status)->toBe(ResponseStatus::Failed)
        ->and($response->errors->sole()->code)->toBe('dataforseo.invalid_response')
        ->and(json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->not->toContain($login, $password, (string) $credential, base64_encode($login.':'.$password));
})->with([
    ['version', 'login'],
    ['tasks.0.result.0.markdown', 'password'],
    ['tasks.0.result.0.model', 'basic'],
    ['tasks.0.result.0.sources.0.title', 'internal'],
]);

it('quotes all six exact pricing identities offline and enforces maxCost before HTTP', function (string $profile, string $cost): void {
    $preview = Librarium::query('pricing')->using($profile)->preview();

    expect($preview->pricingStatus)->toBe('complete')
        ->and($preview->maximumEstimatedCost)->toBe($cost)
        ->and($preview->preflightCostCheckAvailable)->toBeTrue();
    Http::assertNothingSent();

    expect(fn () => Librarium::query('pricing')->using($profile)->maxCost('0.001')->run())
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([
    ['dataforseo-chatgpt', '0.0012'],
    ['dataforseo-chatgpt-live', '0.004'],
    ['dataforseo-gemini', '0.0012'],
    ['dataforseo-gemini-live', '0.004'],
    ['dataforseo-google-ai-mode', '0.0012'],
    ['dataforseo-google-ai-mode-live', '0.004'],
]);

it('honors configured pricing overrides for an exact DataForSEO identity', function (): void {
    config()->set('ai-pricing.prices.dataforseo:chatgpt-llm-scraper-standard', [
        'requests' => ['amount' => '0.009', 'currency' => 'USD'],
    ]);

    expect(Librarium::query('pricing')->using('dataforseo-chatgpt')->preview()->maximumEstimatedCost)->toBe('0.009');
    Http::assertNothingSent();
});

it('executes the real encrypted queue job and callback boundary with the same result semantics', function (): void {
    Http::fake(fn () => Http::response(dataForSeoEnvelope(
        'dataforseo-gemini-live',
        'ai_optimization/gemini/llm_scraper',
        'live/advanced',
        'gemini',
        'queued',
        20000,
        true,
        '0.004',
    )));
    Queue::fake();
    $callback = null;

    Librarium::query('queued')->using('dataforseo-gemini-live')->queue()
        ->then(function (ResearchResponse $response) use (&$callback): void {
            $callback = $response;
        });
    $job = Queue::pushed(RunResearch::class)->sole();
    $job->handle(app(ExecutesResearch::class));

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(7200)
        ->and($callback)->toBeInstanceOf(ResearchResponse::class)
        ->and($callback?->results->sole()->provenance->surface)->toBe('gemini')
        ->and($callback?->results->sole()->usage?->actualCost)->toBe('0.004');
});

/** @return array<string, array{string, string, string, bool}> */
function dataForSeoProfileDataset(): array
{
    return [
        'ChatGPT Standard' => ['dataforseo-chatgpt', 'ai_optimization/chat_gpt/llm_scraper', 'chatgpt', false],
        'ChatGPT Live' => ['dataforseo-chatgpt-live', 'ai_optimization/chat_gpt/llm_scraper', 'chatgpt', true],
        'Gemini Standard' => ['dataforseo-gemini', 'ai_optimization/gemini/llm_scraper', 'gemini', false],
        'Gemini Live' => ['dataforseo-gemini-live', 'ai_optimization/gemini/llm_scraper', 'gemini', true],
        'Google AI Mode Standard' => ['dataforseo-google-ai-mode', 'serp/google/ai_mode', 'google_ai_mode', false],
        'Google AI Mode Live' => ['dataforseo-google-ai-mode-live', 'serp/google/ai_mode', 'google_ai_mode', true],
    ];
}

function configureDataForSeoProfiles(): void
{
    foreach (array_keys(dataForSeoProfileDataset()) as $label) {
        [$profile] = dataForSeoProfileDataset()[$label];
        config()->set("librarium.profiles.{$profile}.enabled", true);
        config()->set("librarium.profiles.{$profile}.options", dataForSeoControls($profile));
        config()->set("librarium.profiles.{$profile}.credential", DataForSeoCredential::encode('dataforseo-login', 'dataforseo-password'));
    }
}

/** @return array<string, int|string|bool> */
function dataForSeoControls(string $profile): array
{
    $controls = ['location_code' => 2840, 'language_code' => 'en'];
    if (str_contains($profile, 'chatgpt')) {
        $controls['force_web_search'] = true;
    }
    if (str_contains($profile, 'google-ai-mode')) {
        $controls['device'] = 'mobile';
        $controls['os'] = 'ios';
    }

    return $controls;
}

/**
 * @param  array<string, mixed>|null  $resultOverride
 * @return array<string, mixed>
 */
function dataForSeoEnvelope(
    string $profile,
    string $family,
    string $operation,
    string $surface,
    string $query,
    int $status,
    bool $completed,
    string $cost,
    ?array $resultOverride = null,
): array {
    $controls = dataForSeoControls($profile);
    // Literal identity fields from the nine public endpoint JSON examples,
    // checked 2026-09-06; references are in docs/dataforseo-profiles.md.
    $identities = [
        'chatgpt' => [
            'task_post' => ['api' => 'ai_optimization', 'function' => 'task_post', 'se' => 'chatgpt', 'se_type' => 'llm_scraper'],
            'task_get' => ['api' => 'ai_optimization', 'function' => 'llm_scraper', 'se' => 'chat_gpt'],
            'live' => ['api' => 'ai_optimization', 'function' => 'llm_scraper', 'se' => 'chat_gpt'],
        ],
        'gemini' => [
            'task_post' => ['api' => 'ai_optimization', 'function' => 'llm_scraper', 'se' => 'gemini'],
            'task_get' => ['api' => 'ai_optimization', 'function' => 'llm_scraper', 'se' => 'gemini'],
            'live' => ['api' => 'ai_optimization', 'function' => 'llm_scraper', 'se' => 'gemini'],
        ],
        'google_ai_mode' => [
            'task_post' => ['api' => 'serp', 'function' => 'task_post', 'se' => 'google', 'se_type' => 'ai_mode'],
            'task_get' => ['api' => 'serp', 'function' => 'task_get', 'se' => 'google', 'se_type' => 'ai_mode'],
            'live' => ['api' => 'serp', 'function' => 'live', 'se' => 'google', 'se_type' => 'ai_mode'],
        ],
    ];
    $data = [
        ...$identities[$surface][explode('/', $operation)[0]],
        'keyword' => $query,
        ...$controls,
    ];

    $result = $resultOverride ?? dataForSeoResult($surface, $query);

    return [
        'version' => '0.1.20260906',
        'status_code' => 20000,
        'status_message' => 'Ok.',
        'time' => '0.1 sec.',
        'cost' => $cost,
        'tasks_count' => 1,
        'tasks_error' => 0,
        'tasks' => [[
            'id' => DATAFORSEO_TEST_ID,
            'status_code' => $status,
            'status_message' => $status === 20000 ? 'Ok.' : ($status === 20100 ? 'Task Created.' : 'Task pending.'),
            'time' => '0.1 sec.',
            'cost' => $cost,
            'result_count' => $completed ? 1 : 0,
            'path' => ['v3', ...explode('/', $family), ...explode('/', $operation)],
            'data' => $data,
            'result' => $completed ? [$result] : null,
        ]],
    ];
}

/** @return array<string, mixed> */
function dataForSeoResult(string $surface, string $query): array
{
    $base = [
        'keyword' => $query,
        'location_code' => 2840,
        'language_code' => 'en',
        'datetime' => '2026-09-06 11:59:58.1234 +00:00',
    ];

    if ($surface === 'google_ai_mode') {
        return [...$base, 'items' => [[
            'type' => 'ai_overview',
            'markdown' => 'Collected answer from Google AI Mode.',
            'items' => [],
            'references' => [[
                'type' => 'ai_overview_reference',
                'url' => 'https://example.test/source',
                'title' => 'Captured source',
                'text' => 'Supporting excerpt.',
                'source' => 'Example',
            ]],
        ]]];
    }

    $sourceType = $surface === 'chatgpt' ? 'chat_gpt_source' : 'gemini_source';

    return [...$base,
        'model' => $surface === 'chatgpt' ? 'gpt-observed' : 'gemini-observed',
        'markdown' => 'Collected answer from the scraper.',
        'sources' => [[
            'type' => $sourceType,
            'url' => 'https://example.test/source',
            'title' => 'Captured source',
            'snippet' => 'Supporting excerpt.',
            'source_name' => 'Example',
        ]],
        'items' => [],
    ];
}
