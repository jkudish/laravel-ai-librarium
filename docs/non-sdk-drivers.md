# Non-SDK Driver authoring standard

Use this guide when a provider does not have a suitable Laravel AI SDK integration or an official PHP SDK.

The package already owns the common runtime. A Driver only translates one `DriverRequest` into one terminal `ResearchResult`, or throws a safe `DriverException`. Do not create a second execution system inside a provider.

## Default choice

Use Laravel's HTTP client for a provider with one or two endpoints and a small response shape. It keeps authentication, timeouts, retries, fakes, and exceptions visible in normal Laravel code.

Consider Saloon for one provider when its repeated request structure materially improves readability or testing. A durable provider with several submit, status, result, cancellation, or pagination requests may justify a Connector and request classes. A single search endpoint normally does not.

Do not:

- add Saloon as the default transport;
- add Saloon before a provider-specific spike proves the benefit;
- create a generic base Driver, transport framework, or provider lifecycle abstraction;
- make a Saloon decision globally. Reassess it for each provider.

| Provider shape | Laravel HTTP | Saloon |
| --- | --- | --- |
| Inline search or answer endpoint | Default. One explicit request and one normalizer are easy to follow. | Usually more structure than value. |
| Durable submit, poll, and retrieve lifecycle | Keep small lifecycles explicit when the three requests are straightforward. | Consider when auth, request objects, pagination, response mapping, or failure handling repeat across several endpoints. |

## Use the package seams

- Implement `Contracts\Driver`.
- Declare Profile option rules through `profileOptionRules()`.
- Read the effective Profile and its rendered prompt from `DriverRequest`.
- Respect `DriverRequest::$deadline` across every request, delay, poll, and retry.
- Report useful, non-sensitive progress through `DriverRequest::progress()`.
- Throw `DriverException` for expected provider failures.
- Return one normalized `ResearchResult` with provenance that matches the effective Profile.
- Use `WebhookSignalStore` only to correlate a verified terminal webhook with an active attempt.

Credentials are resolved by Laravel configuration. They are not option keys or configuration paths:

```php
'example-search' => [
    'driver' => ExampleSearchDriver::class,
    'credential' => config('services.example.key'),
    'options' => [
        'country' => 'ca',
        'max_results' => 10,
    ],
],
```

`Profile::toArray()` excludes the credential. A Driver must not add it to progress, exceptions, logs, `provider_meta`, or terminal content.

### Application-defined Drivers

A consuming application can add its own Profile without changing this package.
Point `driver` at an application class that implements `Driver`; Laravel's
container resolves that class when the Profile runs:

```php
// config/librarium.php in the consuming application
'profiles' => [
    'internal-search' => [
        'driver' => App\Research\InternalSearchDriver::class,
        'provider' => 'internal-search',
        'model' => null,
        'result_kind' => 'search_results',
        'grounding' => 'none',
        'observation' => 'api_output',
        'corpora' => ['specialized'],
        'retrieval_methods' => ['search_endpoint'],
        'prompt' => '{{ query }}',
        'enabled' => true,
        'options' => [],
    ],
],
```

The class may use constructor injection or an explicit container binding. It
receives the same compiled `DriverRequest`, deadline, progress callback, and
terminal validation as a built-in Driver. The package's conformance suite runs
an application-defined Driver through
`Librarium::query(...)->using(...)->run()` and proves that the public extension
seam needs no provider call or package-owned registration.

## Inline provider shape

Keep the request and normalization in a small concrete Driver. Use Laravel validation rules for public Profile options.

```php
final class ExampleSearchDriver implements Driver
{
    public static function profileOptionRules(): array
    {
        return [
            'country' => ['sometimes', 'string', 'size:2'],
            'max_results' => ['sometimes', 'integer', 'between:1,20'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $credential = $request->profile->credential;

        if ($credential === null) {
            throw new DriverException(
                'example.authentication',
                'The Example provider is not configured.',
                fallbackAllowed: true,
            );
        }

        $remainingSeconds = $request->deadline->getTimestamp() - now()->getTimestamp();

        if ($remainingSeconds < 1) {
            throw new DriverException(
                'example.deadline_exceeded',
                'The Example provider did not start before the research deadline.',
            );
        }

        $response = Http::baseUrl('https://api.example.test')
            ->withToken($credential)
            ->acceptJson()
            ->connectTimeout(min(3, $remainingSeconds))
            ->timeout(min(20, $remainingSeconds))
            ->get('/search', [
                'query' => $request->prompt,
                'country' => data_get($request->profile->options, 'country'),
                'limit' => data_get($request->profile->options, 'max_results', 10),
            ]);

        if ($response->failed()) {
            throw $this->providerFailure($response);
        }

        $answer = data_get($response->json(), 'answer');

        if (! is_string($answer) || trim($answer) === '') {
            throw new DriverException(
                'example.invalid_response',
                'The Example provider returned an invalid response.',
            );
        }

        return $this->result($request, $answer, $response->json());
    }
}
```

This is a shape, not a required base class. Keep provider-specific request and result code together until real duplication creates a clearer boundary.

## Durable provider shape

A durable provider still implements the same `run()` method. Provider handles, polling, and retrieval stay internal:

1. Validate the credential and Profile options before making a request.
2. Submit paid work at most once. Use a stable provider-supported idempotency
   key when available, but never treat a locally generated key as proof that an
   undocumented replay is safe.
3. Read the opaque provider reference with `data_get()` and bind it immediately through `WebhookSignalStore` when webhooks are supported.
4. Poll at a bounded interval, or consume a verified webhook signal between polls.
5. Stop before `DriverRequest::$deadline`. Never start a request or sleep that cannot finish inside the remaining time.
6. Retrieve the terminal result only after the provider reports completion.
7. Normalize the terminal response into `ResearchResult`. Do not expose the provider handle as public Librarium state.

Keep the lifecycle legible with private provider-specific methods when needed:

```php
public function run(DriverRequest $request): ResearchResult
{
    $providerReference = $this->submit($request);

    $this->webhooks->bind(
        $request->profile->provider,
        $request->requestId,
        $request->profile->id,
        $providerReference,
    );

    while ($this->hasTimeRemaining($request)) {
        $signal = $this->webhooks->get($request->requestId, $request->profile->id);
        $state = $signal?->state ?? $this->poll($request, $providerReference);

        if ($state === ResearchState::Completed) {
            return $this->retrieve($request, $providerReference);
        }

        if ($state === ResearchState::Failed) {
            throw new DriverException(
                'example.research_failed',
                'The Example provider could not complete this research.',
            );
        }

        $request->progress('Waiting for the Example provider.');
        $this->waitBeforePollingAgain($request);
    }

    throw new DriverException(
        'example.deadline_exceeded',
        'The Example provider did not complete before the research deadline.',
    );
}
```

The webhook endpoint must verify the provider signature against the raw request body before calling `WebhookSignalStore::record()`. Use the provider delivery ID for deduplication. Treat unknown provider references, signature failures, and conflicting bindings as rejected input. The webhook is a wake-up signal; retrieval remains the Driver's source of terminal content.

## Request rules

### Options and credentials

- Keep public, JSON-safe behavior controls in Profile `options`.
- Define every allowed option in `profileOptionRules()`; unknown keys already fail preflight.
- Keep credentials out of options. Resolve values with `config()` and place the resolved value in `credential`.
- Reject a missing credential before the first provider request.
- Use `data_get()` for optional nested provider and option values.

### Timeouts and deadlines

- Set explicit connect and request timeouts on every HTTP request.
- Treat the Driver deadline as the outer limit. Cap each timeout, backoff, and polling delay to the remaining time.
- Prefer a short connect timeout. A provider-specific request timeout may be longer, but it must not outlive the Driver deadline.
- Emit bounded progress for a meaningful phase change, not every poll.

### Retry and backoff

- Retry only failures that can be transient: connection failures, timeouts, `429`, and selected `5xx` responses.
- Use a short, bounded backoff sequence. Honor a sane provider `Retry-After` value when documented.
- Do not retry authentication, authorization, validation, malformed-response, or other deterministic failures.
- Treat an ambiguous mutating submission as terminal unless the provider
  explicitly guarantees replay for the same stable idempotency key. The
  accepted built-in paid Drivers do not automatically resubmit ambiguous work.
- When a provider does guarantee replay, reuse the same stable key for the same
  logical attempt; never generate a replacement key.
- Do not retry inside both Saloon and the Driver. One layer owns the retry policy.

### Error mapping

Map expected failures to a provider-namespaced `DriverException` code and a safe message:

| Provider failure | Example code | Fallback |
| --- | --- | --- |
| Missing or rejected credential | `example.authentication` | Usually allowed when another provider can answer. |
| Rate limit or transient outage | `example.rate_limited`, `example.unavailable` | Allowed. |
| Request rejected because of caller input | `example.invalid_request` | Not allowed unless another compatible provider can accept the same request. |
| Deadline exhausted | `example.deadline_exceeded` | Allowed if the runtime still has an eligible reserve. |
| Malformed or incomplete terminal payload | `example.invalid_response` | Allowed. |
| Provider terminal failure | `example.research_failed` | Follow the provider's retryability semantics. |

Never include credentials, headers, cookies, signed URLs, unrestricted request or response bodies, stack traces, or provider task secrets in an exception. Unexpected programming and infrastructure errors should remain unexpected exceptions so the runtime can report and rethrow them.

## Terminal normalization

Create the `ResearchResult` only after the provider response has passed provider-specific validation.

- Preserve the requested and effective Profile identities supplied by the runtime.
- Match the Profile's result kind, grounding, observation mode, corpora, and retrieval methods.
- Normalize citations and usage from provider facts. Do not invent citations, independent confirmations, token counts, costs, or tool provenance.
- Set the effective model only when the provider or SDK reports it.
- Build `provider_meta` from a small allowlist of useful, non-sensitive scalar or JSON-safe facts. Examples include provider request ID, finish reason, index name, or result count.
- Do not pass through a raw provider response or a generic metadata bag.

## Tests

Default tests must make no external request:

```php
it('normalizes an inline provider response', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/search' => Http::response([
            'answer' => 'A grounded answer.',
            'request_id' => 'provider-request-1',
            'sources' => [
                ['url' => 'https://example.test/source', 'title' => 'Example source'],
            ],
        ]),
    ]);

    $result = app(ExampleSearchDriver::class)->run(exampleDriverRequest());

    expect($result->content)->toBe('A grounded answer.')
        ->and($result->citations)->toHaveCount(1)
        ->and(data_get($result->providerMeta, 'request_id'))->toBe('provider-request-1');

    Http::assertSentCount(1);
});
```

Add only tests that protect a Driver-owned behavior:

- one representative success and terminal normalization;
- Profile option rejection at preflight when the Driver adds option rules;
- safe mapping for meaningful provider failure classes;
- exactly one submission for ambiguous paid work, plus stable-key reuse only
  when the provider explicitly guarantees replay;
- deadline behavior without wall-clock sleeps;
- webhook signature rejection, binding, delivery deduplication, and terminal retrieval for webhook providers;
- an allowlist test proving secrets and raw payloads do not enter `provider_meta` or exceptions.

Use `Http::fake()` and `Http::preventStrayRequests()`. Keep paid or live canaries explicit and outside the default suite.

Do not add a test that merely searches this document for required wording. The production mistake worth catching is incorrect Driver behavior, so each provider implementation owns its focused behavioral tests.

## Separate-package threshold

Keep a Driver in the core package by default. Use a separate adapter package when at least one real boundary makes independent installation valuable:

- a substantial optional SDK, browser runtime, native extension, or other heavy dependency;
- distinct infrastructure or deployment requirements;
- a genuinely independent release cadence;
- proven reuse by more than one application.

Firecrawl is an example of a browser/runtime dependency that belongs in an optional adapter. Simple HTTP providers do not need one package each.

## Review checklist

- The Driver uses existing Librarium seams and has no new provider framework.
- Laravel HTTP is the default unless a provider-specific Saloon spike proves a clearer result.
- Options use Laravel validation; credentials are resolved through `config()` and never serialized.
- Every request has explicit connect/request timeouts and obeys the outer deadline.
- Retrieval retries are bounded; paid submission is at most once unless a
  documented provider idempotency guarantee makes same-key replay safe.
- Webhooks are verified, correlated, and treated as terminal wake-up signals.
- Exceptions are safe and provider-namespaced.
- Results, citations, usage, provenance, and `provider_meta` contain only supported facts.
- Tests prevent stray requests and cover meaningful provider behavior.
- No dependency or separate package was added without meeting its threshold.
