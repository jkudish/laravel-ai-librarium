# Collection and durable recovery

This reference is for applications that store provider evidence, own retry
policy, or recover paid background research after a process restart. Librarium
provides the capture and lifecycle facts; the application remains responsible
for storage, retention, queues, retry decisions, spending authorization, and
reporting.

## Collect a redacted provider response

`collect()` returns the normal `ResearchResponse` together with serializable,
automatically redacted provider responses and operational diagnostics:

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$collected = Librarium::query('Compare current agent memory architectures.')
    ->using('openrouter-grounded')
    ->collect();

$collected->response;    // normalized answer, citations, usage, and provenance
$collected->coverage;    // complete, partial, unavailable, or no_response
$collected->evidence;    // redacted provider response bodies and safe headers
$collected->diagnostics; // safe failure and submission facts
```

Redaction runs before the result is returned. It removes the selected Profiles'
configured credentials and their URL-encoded, form-encoded, and base64 forms;
redacts common secret-bearing JSON keys; applies application-configured paths
and regular expressions; and sanitizes URLs through `EvidenceUrl`. Only a small
header allowlist and rate-limit headers survive. If a structured body cannot be
parsed or safely redacted, its body is omitted with an `omitted_reason` instead
of returning the original bytes.

Configure application-specific rules in published `config/librarium.php`:

```php
'redaction' => [
    'paths' => ['customer.private_reference'],
    'patterns' => ['/(?:account|tenant)-[0-9]+/'],
    'replacement' => '[REDACTED]',
],
```

The configuration is validated before provider execution. Paths use exact
dot notation. Patterns are PHP regular expressions. Redaction is a safe storage
boundary, not a reason to log the result: provider response bodies remain
evidence and can contain private source material.

`collectSensitive()` is the explicit escape hatch for applications that need
the exact captured response before applying their own protection. Its
`SensitiveCollection` and nested response values reject PHP serialization and
hide headers and bodies from debug output. They exist only in process memory;
the caller must prevent logging and provide its own encryption and retention.
Ordinary `run()`, events, progress, status, errors, and result metadata never
contain captured response bodies.

Capture coverage has precise semantics:

| Value | Meaning |
| --- | --- |
| `complete` | Every response body exposed to the first-party Driver was captured. |
| `partial` | At least one response was captured, but a stream or SDK step was unavailable or truncated. |
| `unavailable` | No response was exposed for capture, including a third-party Driver that does not opt in. |
| `no_response` | A network attempt was recorded but no HTTP response was received. |

All built-in provider Drivers participate. Direct Laravel HTTP Drivers capture
their response bodies for inline, submit, poll, retrieve, and private cancel
operations. Laravel AI SDK Profiles capture the raw response and tool-step
responses that the SDK exposes; missing raw SDK steps produce `partial` or
`unavailable`, never an invented complete response.

## Use retry diagnostics without delegating policy

Collection diagnostics classify an observed failure as `temporary`,
`rate_limit`, `permanent`, or `unknown`. They also report the operation,
`http_status`, a bounded provider error code when safely extractable, and one of
these submission states:

| State | Meaning |
| --- | --- |
| `not_sent` | Validation failed before a network attempt. |
| `rejected` | The submit/inline endpoint returned a definite non-acceptance response. |
| `possibly_accepted` | A submit/inline attempt ended ambiguously, such as a connection loss, 408, 425, 429, or 5xx. |
| `accepted` | The provider accepted the request, or the operation addressed an already known handle. |

`retry_at` and `reset_at` are absolute UTC timestamps derived only from a
single bounded `Retry-After` or recognized rate-limit reset header. Malformed or
ambiguous values remain absent. Librarium does not retry based on these fields.
The category describes the observed failure; it is not retry authorization.
When `submission_state` is `possibly_accepted`, it takes precedence over a
`temporary` or `rate_limit` category because another submission may duplicate
paid work.

For `laravel_ai.model_revision_drift`, the optional `observed_model` diagnostic
reports the rejected model identifier, including when no raw exchange was
captured. It is limited to 255 ASCII bytes of model-name segments separated by
`/` or `:`; malformed text and values containing the configured credential are
omitted. It does not include the response ID, answer, or reasoning. Absence does
not change the original failure or authorize another submission.

## Persist a durable handle before retrieval

Durable submission is deliberately separate from retrieval:

```php
$submitted = Librarium::query('Prepare a research report.')
    ->using('exa-research')
    ->submit();

// Persist this before requesting the result.
$storedHandle = $submitted->handle->toArray();

// A later process reconstructs and verifies the handle.
$handle = \Jkudish\LaravelAiLibrarium\ResearchHandle::fromArray($storedHandle);
$outcome = Librarium::retrieve($handle);

if ($outcome->pending()) {
    // The application chooses when to schedule another observation.
}
```

`submit()` requires one exact `using()` Profile and no fallbacks. It performs
one submission and returns without polling. A successful response followed by
an application persistence failure is never retried by Librarium. An ambiguous
submission raises a non-fallback failure because repeating it could create a
second paid request.

The serializable handle contains a request ID, exact Profile/provider/model
identity, provider reference, submission time, HMAC fingerprint, and a signed
submission-usage snapshot when a provider reports cost only when creating its
task. It does not contain the prompt, Profile options, credential, response
body, URL, or a class name. Verification binds the handle to the current
retrieval semantics and credential identity using Laravel's `app.key`; changed
provider, model, result/provenance semantics, options, Driver, or credential fail
before HTTP. Prompt wording, enabled state, and pricing estimates are not
retrieval identity. Credential rotation therefore invalidates existing handles
unless the application retains the matching Profile binding in a controlled
recovery deployment. Librarium validates the complete signable binding before
the paid submission so a local signing-configuration failure cannot consume the
only copy of a successful provider handle.

Each `retrieve()` call validates the handle and performs one provider
observation without internal polling. Most providers need one known-handle GET;
Parallel makes its separate result GET only after status reports completion. A
retrieval never submits, falls back, sleeps, cancels provider work, emits package
lifecycle events, or writes Librarium's transient status store. A pending result
is explicit. A retrieval failure throws `RecoveryException` with one safe
`OperationalDiagnostic`; the application decides whether and when to call
again.

Public recovery currently supports:

| Profile | Submission | Retrieval observation |
| --- | --- | --- |
| `exa-research` | Exa Agent run | `GET /agent/runs/{id}` |
| `gemini-deep-research` | Gemini interaction | `GET /interactions/{id}` |
| `perplexity-deep-research` | Perplexity managed `medium` Agent run | `GET /v1/agent/{id}` |
| `perplexity-sonar-deep` | Perplexity managed `high` Agent run | `GET /v1/agent/{id}` |
| `parallel-research` | Parallel `pro` task run | `GET /v1/tasks/runs/{id}` and, only after completion, its result GET |
| `valyu-research` | Valyu `standard` research task | `GET /v1/deepresearch/tasks/{id}/status` |
| `dataforseo-chatgpt` | DataForSEO ChatGPT Standard task | One known-task GET |
| `dataforseo-gemini` | DataForSEO Gemini Standard task | One known-task GET |
| `dataforseo-google-ai-mode` | DataForSEO Google AI Mode Standard task | One known-task GET |

OpenAI Research and You.com Research still run their existing private
at-most-once background lifecycle through `run()` or `collect()`, but do not yet
expose a public recoverable handle. Inline Profiles have no provider request to
recover. Calling `submit()` for either category fails before a paid request.

## Provider credentials, capture, and controls

The table is the core provider audit. “Captured” means the built-in Driver
participates in both automatic redacted capture and explicit sensitive capture.
Profile options are defined in published `librarium.profiles.*`; unsupported or
unknown options fail preflight instead of being ignored.

| Provider / Profiles | Credential source | Capture | Public recovery | Explicit controls |
| --- | --- | --- | --- | --- |
| Gemini SDK (`gemini-grounded`, `gemini-chat`) | `ai.providers.gemini.key` / `GEMINI_API_KEY` | SDK raw responses/steps when exposed | No | exact model; web search on/off where optional |
| Gemini Deep Research | `ai.providers.gemini.key` / `GEMINI_API_KEY` | Captured | `gemini-deep-research` | exact Deep Research agent binding |
| OpenRouter (`openrouter-*`) | `ai.providers.openrouter.key` / `OPENROUTER_API_KEY` | SDK raw responses/steps when exposed | No | exact model; web search; required engine: `exa`, `firecrawl`, `parallel`, or `perplexity`; provider fallback disabled |
| Anthropic (`claude-chat`) | `ai.providers.anthropic.key` / `ANTHROPIC_API_KEY` | SDK raw responses/steps when exposed | No | exact model; web search on/off |
| OpenAI SDK and Research | `ai.providers.openai.key` / `OPENAI_API_KEY` | Captured | Deferred for Research | exact model and fixed profile tools; optional SDK web search |
| Brave Answers/Search | `services.brave.key` / suggested `BRAVE_API_KEY` | Captured, including the Answers stream; bounded partial capture is explicit | No | fixed endpoint/model semantics |
| Exa Search/Research | `services.exa.key` / suggested `EXA_API_KEY` | Captured | `exa-research` | Research effort, system prompt, output schema, and provider `max_cost_dollars` for supported effort modes |
| Kagi FastGPT | `services.kagi.key` / suggested `KAGI_API_KEY` | Captured | No | fixed FastGPT semantics |
| You.com Answer/Research | `YOU_COM_API_KEY`, then `YDC_API_KEY` | Captured | Deferred for background Research | Answer freshness, country, language, and domain policy; Research effort fixed to `standard` |
| Perplexity Agent/Search | `services.perplexity.key` / suggested `PERPLEXITY_API_KEY` | Captured | managed `medium` and `high` | Search result, country/language/domain/context limits; separate managed and frozen Agent Profiles |
| xAI Grok | `ai.providers.xai.key` / `XAI_API_KEY` | Captured | No | exact model/tool corpus, turns, output tokens, domains/X handles, dates, image/video controls |
| SearchAPI | `services.searchapi.key` / suggested `SEARCHAPI_KEY` | Captured | No | exact engine per Profile; zero-retention requirement |
| Tavily Search | `services.tavily.key` / suggested `TAVILY_API_KEY` | Captured | No | fixed advanced search binding |
| Jina Search | `services.jina.key` / suggested `JINA_API_KEY` | Captured | No | fixed search binding |
| SerpAPI | `services.serpapi.key` / suggested `SERPAPI_API_KEY` | Captured | No | fixed Google search binding |
| SerpBase Search/News | `services.serpbase.key` / `SERPBASE_API_KEY` | Captured | No | one page/request; `hl`, `gl`, and `page`; Search-only `device` and opt-in bounded rich text |
| Parallel Search/Research | `services.parallel.key` / suggested `PARALLEL_API_KEY` | Captured | `parallel-research` | managed or exact search mode, query/result/fetch limits, location/source policy; Research fixed to `pro` with location/domain policy |
| Valyu Search/Research | `services.valyu.key` / suggested `VALYU_API_KEY` | Captured | `valyu-research` | search type/result/price/relevance/date/country/source controls; Research fixed to `standard` with source/date/country policy |
| DataForSEO surface Profiles | `librarium.providers.dataforseo.login/password` / `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | Captured | three Standard task Profiles | required location/language plus supported device, OS, and web-search controls |

Applications with a previously published `librarium.php` must add an explicit
`search_engine` to each OpenRouter Profile that enables web search. Missing or
unsupported engines make the Profile unavailable before provider dispatch;
Librarium does not choose one implicitly.

Perplexity's low Agent forms are intentionally different Profiles:

- `perplexity-sonar-pro` sends the provider-managed `low` preset and does not
  claim an underlying model or inherited tool configuration.
- `perplexity-low-frozen` sends the explicit `frozen-low-2026-09-11`
  configuration: exact model, prompt, reasoning, step/output limits, and web
  search/fetch tool limits. Any drift from that binding fails before HTTP.

## Usage, provenance, and spending limits

Normalized results return only provider-reported facts: request/result IDs,
model identity, usage units, reported cost, evidence provenance, observation
time, and completion time when available. Collection adds request start and
per-response receive times. Missing facts remain `null` or absent. In
particular, an SDK-created zero is retained only when the captured provider
payload actually reports the corresponding usage field; absence is not changed
to reported zero.

`DurableOutcome::usage()` is the provider's latest task-level usage snapshot.
For providers such as DataForSEO that report task cost only at submission, the
signed handle carries that snapshot into later retrieval. Applications should
upsert or replace usage by their stable task identity; do not sum every
retrieval or add handle and result usage as separate charges.

`maxCost()` / `--max-cost` prevents dispatch only when every reachable pricing
quote is complete, current, conservative, and in one currency. Exa Research's
supported `max_cost_dollars` is sent as a provider-native run budget. Other
controls such as max output tokens, max tool tokens, turns, search results,
deadline, and timeout bound work or local waiting; they do **not** prove a total
dollar ceiling because input, tool, source, provider routing, already accepted
background work, or account pricing can still vary. Use provider/account caps
and application authorization when a hard spending bound is required.

`ResearchPreview::preflightCostCheckAvailable` and agent JSON
`preflight_cost_check_available` describe the pre-dispatch quote check.
`hardCostCeilingAvailable` and `hard_ceiling_available` are deprecated aliases
retained for compatibility; they do not indicate provider enforcement.
