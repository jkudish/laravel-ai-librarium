# Writing a source driver

Prefer Laravel AI SDK primitives where they truthfully implement the provider
operation. Otherwise use Laravel HTTP and a small provider-specific mapper.
Substantial optional SDKs or browser runtimes belong in adapters.

Implement `Contracts\Driver`: `profileOptionRules()` defines allowed JSON-safe
controls, and `run(DriverRequest $request)` returns a validated `ResearchResult`.
The internal Profile is the selected source binding, not a public selector.
Respect the shared deadline and explicit HTTP timeouts; capture responses through
the request's capture callback. Return citations, cost and provenance only from
provider facts, never raw bodies in result metadata. Throw a safe, namespaced
`DriverException` for expected failures. Never put secrets or handles in messages.

```php
// config/librarium.php
'sources' => ['internal' => [
    'key' => config('services.internal.key'),
    'quick' => [
        'driver' => App\Research\InternalSearchDriver::class,
        'provider' => 'internal', 'model' => null,
        'result_kind' => 'search_results', 'grounding' => 'none',
        'observation' => 'api_output', 'corpora' => ['specialized'],
        'retrieval_methods' => ['search_endpoint'], 'prompt' => '{{ query }}',
        'options' => [],
    ],
]],
```

For any durable variant or collector implement `Contracts\DurableDriver` too.
`submitDurable()` performs at most one submission and returns a
`Recovery\DurableSubmission` with opaque reference and reported submission usage.
`retrieveDurable()` makes one known-handle observation, returning null when
pending or a terminal result. It never submits, sleeps, retries paid work or
cancels. The host stores/signs the handle and owns waiting; depth only selects
the variant. Every deep binding must be durable. Quick durable bindings work too.

Webhook receivers verify the raw body's signature before recording a signal in
`WebhookSignalStore`; bind it to the Run/source attempt. A signal only wakes
authoritative retrieval, never supplies terminal content or authorization.

Keep provider-focused offline tests for request mapping, identity/usage/citations,
ambiguous submit (one POST), pending/terminal recovery and deadline failures.
Use SDK/HTTP fakes and `Http::preventStrayRequests()`. See existing drivers and
`ApplicationDefinedDriverTest` for the supported extension path. Paid live checks
require separate approval; there is no public fallback policy.
