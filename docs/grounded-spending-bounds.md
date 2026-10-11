# Grounded Laravel AI spending bounds

This page documents the pre-dispatch `spendingBound()` results for the four
exact grounded Laravel AI Profiles — `claude-chat` (Anthropic
`claude-sonnet-5-5`), `openai-chat` (OpenAI `gpt-6-luna`),
`gemini-grounded` (Gemini `gemini-3.8-flash`), and `openrouter-chat`
(OpenRouter with the Exa engine — the shipped Profile default is
`openai/gpt-6-luna`, the reviewed Luna route below; the historical
`openai/gpt-5.6-terra` route stays available through an explicit model
override). The result
contract, ownership, and revalidation rules are
defined in
[Exact-request spending bounds](spending-bounds.md).

Grounded requests bill more dimensions than model-only requests: the model's
own input and output tokens, retrieval content injected by the provider's
search tool, repeated processing of the conversation across the provider's
internal search iterations, and per-search or per-tool-call fees. A complete
bound needs an official guarantee for **every** dimension, including the
length of the provider's internal agent loop. After an oracle review, only
the OpenRouter route has such a guarantee; the Anthropic and OpenAI grounded
routes return `unknown` and keep their controls as supported run controls
that are never advertised as spending authority. The shipped default Profile
options remain `unknown` everywhere, and the existing no-search Claude bound
is unchanged.

## Search-count correction (2026-09-24)

A retained consumer observation reported 12 executed searches with
`max_tool_calls: 5`. That disproves the previous equation of search count
with loop steps: one tool-calling turn can request several searches.
Both reviewed OpenRouter models now require an independent positive
`max_searches`, emitted by the Laravel AI SDK as
`tools[].parameters.max_uses`. Without it, `spendingBound()` returns
`unknown`, including for the old five-step request.

The corrected bound combines the documented outer-loop turn budget with
the documented per-request executed-search cap. It does not restrict how
many products, citations or claims an answer may contain, reject retained
answers by count, or change usage mapping and settlement. A provider-reported
charge remains charge evidence; a small charge does not prove a ceiling.

This is a documentation-derived correction verified offline for quantity
calculation and real-SDK serialization, **not a live validation of provider
enforcement**. Consumers must review and authorize the changed request before
activation. Do not retrofit the new option into persisted request envelopes
or reuse historical dispatch authority: a consumer that recomputes an old
bound should now refuse it. Stored evidence and settled charges remain valid
records of what happened.

## `openrouter-chat` — OpenRouter `openai/gpt-6-luna` (shipped default), Exa engine: bounded

The current reviewed route is **regular** `openai/gpt-6-luna` (not Luna
Pro), the shipped `openrouter-chat` default since October 11, 2026 — with every
safeguard of the historical route preserved: the same option gate,
server-tool budget authority, fail-closed catalog revision check, response
model compatibility check, no-catalog-evidence settlement semantics,
preflight deadline capping, and no-raw `possibly_accepted` guarantees. The
route is a per-model entry in the driver's bounded-model facts — adding a
bounded route requires a reviewed entry with official-source facts; no
generic framework infers one.

Required options: `web_search` is exactly `true`, `search_engine` is exactly
`exa`, `search_mode` is exactly `auto` (sent explicitly as the Exa `mode`
tool parameter; any other documented mode leaves the bound `unknown` because
the deeper modes price differently), `max_tool_calls` (1 through 30),
`max_searches` (positive integer, independent of the turn budget),
`max_results` (1 through 10), `max_steps` is exactly `1`, and
`max_prompt_bytes` (positive, satisfied by the rendered prompt).
`max_output_tokens` is optional and does not affect the bound. Omitting
`max_searches` makes the bound `unknown`. The loop-length authority
(OpenRouter's request-wide server-tool
budget) is also identical: at most `max_tool_calls` steps plus one final
completion, with `max_tool_calls` sent as a top-level field and budgets
above 30 rejected before execution.

### The bound

| Unit | Maximum | Basis |
| --- | --- | --- |
| `exa_search_requests` | `max_searches` | The explicit `max_uses` tool parameter bounds executed searches independently of parallel calls per turn. The request pins Exa `mode: "auto"` ($0.007 per request; deeper modes fail the bound closed). The unit is engine-specific so the catalog's model-level `web_search` passthrough rate can never satisfy it. |
| `cache_write_input_tokens` | `(max_tool_calls + 1) × 1,050,000` | At most budget + 1 model completions; each processes at most the pinned endpoint's 1,050,000-token context, including injected search content. Input tokens bill as exclusive partitions (uncached input, cache-read, cache-write), and OpenAI automatic caching can bill cache writes without opt-in, so the request cannot be proven cache-write-free; the bound therefore reserves the entire worst-case input quantity in the most expensive partition — cache-write under the ≥272K long-context override rate ($0.25/M for Luna) — which dominates every possible actual partition mix without counting any token twice. |
| `output_tokens` | `(max_tool_calls + 1) × 128,000` | Per-completion output uses the pinned endpoint's `max_completion_tokens` (128,000) because forwarding the request's `max_tokens` to every internal loop completion is not documented. |

Additional-result fees are structurally zero for the same reason as Terra:
each Exa request includes up to 10 results and then bills $0.001 per
additional result, and the bound requires an enforced `max_results` of at
most 10.

For the corrected candidate (`max_tool_calls` 5 and `max_searches` 5), quantities are
exactly 6,300,000 `cache_write_input_tokens`, 768,000 `output_tokens`, and 5
`exa_search_requests`, which the pricing snapshot v7 (assembly timestamp
`2026-09-23T00:10:40Z`) quotes at $2.186 ($1.575 cache-write + $0.576
output + $0.035 Exa). Snapshot v7 changed the snapshot fingerprint
globally; historically persisted snapshots are never rewritten. The Terra
B5 quote ($45.359) is unchanged under the same snapshot.

### Endpoint routing and billing identity

`allow_fallbacks: false` alone still permits initial endpoint selection, so
the bounded request adds routing controls for the exact bounded model only:

```json
"provider": {
  "order": ["openai"],
  "ignore": ["openai/flex", "openai/fast"],
  "max_price": {"prompt": "0.1", "completion": "0.5"},
  "require_parameters": true,
  "allow_fallbacks": false
}
```

Base slugs match all default-tier endpoints of that provider, and service
tier endpoints (`openai/fast`, `google-vertex/flex`-style) are **not**
matched by base slugs, so `order: ["openai"]` restricts the request to
OpenAI's default-tier endpoints serving `openai/gpt-6-luna`; the ignore list
naming `openai/flex` and `openai/fast` is explicit redundant defense, not
the pin itself. This is a tier restriction, not an immutable single-endpoint
pin — today's catalog lists exactly one such default OpenAI endpoint, and
that one-endpoint state and its rates are pricing snapshot facts the pricing
owner must reverify.

`max_price` (`"prompt": "0.1"`, `"completion": "0.5"` — the endpoint's base
per-M rates from the catalog's dated Luna endpoints, USD per million tokens,
typed as strings per OpenRouter's OpenAPI schema) is emitted as a **rate
routing filter with candidate base thresholds**: the docs demonstrate
comparisons against base per-M prompt/completion rates, and it "will prevent
your request from running if the price is not available." It is **not an
all-in spend cap**: it does not cap total tokens, requests, or total spend,
and it does not shrink the bound's worst-case quantities. Existing
source-snapshot pricing does not cap future costs — endpoint prices can
change after the snapshot is taken, and a changed price either fails the
filter closed (price above the ceiling) or continues to bill at the new
lower price within the same worst-case quantities. max_price coverage of
cache/tool/tier price overrides is undocumented — the docs describe base
prompt/completion filtering only, so overrides such as the ≥272K
long-context rates, the cache-write premium, and per-tool fees are not
proven to be filtered by it, which is exactly why the bound still reserves
those worst-case bases.

Pricing identity: `openrouter:openai/gpt-6-luna-272k`. The identity names
the worst-case billing basis rather than the base model rates: every
completion is conservatively billed at the ≥272K long-context override rates
($0.2 input / $0.75 output per M, cache-read $0.02, cache-write $0.25),
because the input quantity necessarily exceeds 272K tokens per completion in
the worst case. No aggregate model identity can safely satisfy this, so rate
resolution fails closed until the pricing registry serves this exact
identity.

### Reasoning controls (route-fixed, not a Profile option)

The Luna bounded route sends reviewed reasoning controls at the top level of
the chat-completions body:

```json
"reasoning": {"effort": "max", "exclude": true}
```

- **Route-fixed.** `max` is one of this model's catalog-listed
  `supported_efforts` (max/xhigh/high/medium/low/none) and is a property of
  the bounded route itself, emitted by the driver whenever the Luna pin
  applies (provider `openrouter` + model `openai/gpt-6-luna` + the
  `max_tool_calls` budget). There is deliberately no `reasoning_effort`
  Profile option: the frozen Profile option surface is exactly the Terra
  route's, and per-user reasoning choice would be a separate review.
- **Forwarded unmodified.** The Laravel AI SDK merges `providerOptions`
  into the outbound body verbatim — it has no reasoning-effort enum, so no
  silent downgrade to a lower effort is possible (the package's separate
  OpenAI Responses research driver sends its own fixed effort, but it never
  touches this route); the outbound payload test asserts
  `reasoning.effort === "max"` reaches the wire.
- **`exclude: true` is request-only, not disabling.** Per the official
  reasoning guide, `exclude` suppresses the reasoning text returned in the
  response but reasoning is still generated and still billed as completion
  tokens. The bound's output quantities already cover reasoning tokens;
  nothing about `exclude` shrinks them.
- **No retention guarantee.** `exclude: true` alone cannot prove that
  retained raw evidence omits chain-of-thought — a provider endpoint that
  ignores the flag could still return reasoning text in the captured raw
  exchange. Consumers persisting collected evidence must redact before
  persistence; the package's collection redaction
  (`librarium.redaction.paths`/`patterns`, see
  [Collection and recovery](collection-and-recovery.md)) already supports
  exact dotted paths such as `reasoning_details` or
  `choices.0.message.reasoning` — it does not treat reasoning fields as
  sensitive by default, so persistence owners must configure those paths.

Terra emits no `reasoning` key at all, and unpinned or unsupported
OpenRouter models emit none either.

### Catalog revision and response identity

`openai/gpt-6-luna` is a moving alias whose public catalog entry names the
canonical dated revision `openai/gpt-6-luna-20260922`. The dated slug is not
sent as the request model, for the same evidence reasons as Terra. The
catalog preflight remains the revision check; the response check is bounded
identity compatibility, not independent immutable revision proof:

1. **Pre-dispatch catalog check.** One public, unauthenticated,
   timeout-bounded GET of `/api/v1/model/openai/gpt-6-luna` (no credentials,
   no retry, no caching) must return `canonical_slug`
   `openai/gpt-6-luna-20260922` before any inference request can spend
   money. The same positive shared-deadline check precedes the GET and the
   lookup timeout is capped to the remaining request budget (integer
   granularity is the `remainingSeconds()` helper's policy, not a framework
   limitation; no hard upstream cancellation at the exact deadline is
   claimed). A missing, malformed, errored, or unreachable answer fails
   closed before dispatch (`laravel_ai.model_revision_unverified`); a
   different `canonical_slug` fails closed before dispatch
   (`laravel_ai.model_revision_drift_before_dispatch`). Catalog traffic is
   never recorded as provider inference evidence, so every pre-dispatch
   rejection settles `not_sent` by construction.
2. **Response model compatibility.** OpenRouter may report either the exact
   requested alias (`openai/gpt-6-luna`) or the reviewed dated revision
   (`openai/gpt-6-luna-20260922`). The driver accepts either exact value and
   preserves the reported value on the result. Missing identities, sibling
   or unknown models, and unexpected dated revisions fail closed with
   `laravel_ai.model_revision_drift`. The inference POST has definitely
   happened by then, so this rejection never settles `not_sent`: with no raw
   capture the dispatch is explicitly recorded as an unanswered attempt and
   settles `possibly_accepted`. The same post-prompt guarantee covers a
   runtime deadline reached after a conforming no-raw response
   (`librarium.timeout`) and a required-grounding rejection
   (`laravel_ai.ungrounded_response`).

The check-to-use race is unavoidable and the same as Terra's: the alias can
move between the catalog check and the inference POST. A dated response can
expose a mismatch, but an alias response does not identify the served
revision and therefore does not guarantee that the race is closed. The
catalog `canonical_slug` check remains the revision assertion, but it is not
a provider-side immutable pin. Re-enabling after catalog drift requires
updating the reviewed revision. These checks apply only to the pinned bounded
route — unpinned Luna requests and unsupported OpenRouter models perform no
catalog check, keep default routing, and are never rejected for their served
model.

The Luna behavioral suite (`tests/Feature/LunaBoundedCanaryTest.php`) covers
the payload, quantities, the pre-dispatch drift rejection with zero
inference POSTs, the response-side drift rejection that can only fire after
the inference POST has been made and spent, and the no-raw
`possibly_accepted` settlements on Luna itself; the Terra suite keeps the
historical route's coverage on the shipped Terra default.

## Historical bounded route — OpenRouter `openai/gpt-5.6-terra`

The Terra route was the reviewed canary before the Luna switch. Its driver
facts, bound, routing, catalog preflight, and settlement semantics remain
preserved. Its response model check has the same bounded compatibility as
Luna: exact requested alias or reviewed dated revision. The route remains
fully covered by
`tests/Feature/GroundedLaravelAiSpendingBoundTest.php` (which selects Terra
through an explicit model override, including the assertion that Terra emits
no `reasoning` key). Since October 11, 2026 the shipped `openrouter-chat`
model is Luna; Terra is selected only through an explicit model override.
The section below is the reviewed Terra authority, and the Luna section
above inherits every mechanism it describes, with Luna's facts.

Required options: `web_search` is exactly `true`, `search_engine` is exactly
`exa`, `search_mode` is exactly `auto` (sent explicitly as the Exa `mode`
tool parameter; any other documented mode — or omitting it — leaves the bound
`unknown` because the deeper modes price differently), `max_tool_calls`
(1 through 30), `max_searches` (positive integer), `max_results` (1 through 10), `max_steps` is exactly `1`,
and `max_prompt_bytes` (positive, satisfied by the rendered prompt).
`max_output_tokens` is optional and does not affect the bound. Omitting
`max_searches` makes the bound `unknown` (see below).

### Independent loop and search limits

OpenRouter's [server-tool guide](https://openrouter.ai/docs/guides/features/server-tools#tool-call-limits)
defines a request-wide outer-loop step budget, default and maximum 30,
followed by a final answer. The [Chat Completions API reference](https://openrouter.ai/docs/api/api-reference/chat/create-a-chat-completion)
describes tool-calling steps and allows multiple parallel calls in one
response. We use `max_tool_calls + 1` for the maximum inference turns,
never for executed searches. The guide's loose "each tool call" wording
must not be interpreted as a search-count guarantee. The driver sends
`max_tool_calls` as a top-level field and rejects budgets above 30.

Separately, [web-search `max_uses`](https://openrouter.ai/docs/guides/features/server-tools/web-search#limiting-the-number-of-searches)
limits searches per request: further calls return errors instead of
executing. Librarium's existing `max_searches` option emits this parameter.
Those errors remain inside the request-wide outer loop; they do not grant
extra loop turns. The bound relies on both published provider contracts,
not on success-only step counting or a prompt asking for fewer searches.
If provider behavior contradicts either control, stop new affected dispatch
and retain the answer and reported usage for reconciliation.

No `stop_server_tools_when` is emitted: it overrides, rather than supplements,
`max_tool_calls`. No model-specific `parallel_tool_calls` behavior is assumed.
The old interpretation that adding a search cap necessarily loses the outer
loop bound is withdrawn; a per-tool error is not evidence that a documented
request-wide loop budget ceases to apply.

### The bound

| Unit | Maximum | Basis |
| --- | --- | --- |
| `exa_search_requests` | `max_searches` | The explicit per-request `max_uses` cap bounds executed searches. The request pins Exa `mode: "auto"` at $0.007 per request; deeper modes fail the bound closed. The engine-specific unit cannot be satisfied by a model-level native search rate. |
| `cache_write_input_tokens` | `(max_tool_calls + 1) × 1,050,000` | At most budget + 1 model completions; each processes at most the pinned endpoint's 1,050,000-token context, including injected search content ("All pricing is in addition to standard LLM token costs for processing the search result content"). Input tokens bill as exclusive partitions (uncached input, cache-read, cache-write), and OpenAI automatic caching can bill cache writes without opt-in, so the request cannot be proven cache-write-free; the bound therefore reserves the entire worst-case input quantity in the most expensive partition — cache-write at the compounded 1.25x long-context premium ($5/M) — which dominates every possible actual partition mix without counting any token twice. |
| `output_tokens` | `(max_tool_calls + 1) × 128,000` | Per-completion output uses the pinned endpoint's `max_completion_tokens` (128,000) because forwarding the request's `max_tokens` to every internal loop completion is not documented. |

Additional-result fees are structurally zero: each Exa request includes up
to 10 results and then bills $0.001 per additional result, and the bound
requires an enforced `max_results` of at most 10. `max_results` between 11
and 25 is a valid request but leaves the additional-result dimension
unproven (`unknown`); values above 25 are rejected by option validation.

### Actual usage and settlement

Reported usage mirrors the billed partitions: the Laravel AI gateway
parsers convert the provider's inclusive prompt total into exclusive
uncached / cache-read / cache-write partitions. The result mapper also
carries the engine's search count: it prefers the current
`usage.server_tool_use_details.web_search_requests` field and falls back to
the legacy `usage.server_tool_use.web_search_requests` field only when the
current field is absent, then sums the selected value across steps. It never
double counts when both are present, and a malformed present current value
is not masked by legacy data. When deriving a cost estimate, consumers must price each partition
at its own rate — never re-adding an inclusive prompt total to the cache
partitions — and must fail closed when the engine search count is missing or
malformed rather than treating it as zero.

For OpenRouter, the mapper also carries `usage.cost` as `actual_cost` in USD
using the pricing package's provider-cost adapter. This is OpenRouter's
[reported account charge](https://openrouter.ai/docs/guides/guides/usage-accounting),
not a token-rate estimate or the BYOK `upstream_inference_cost`. Every step
must report a valid nonnegative cost; steps are summed with decimal arithmetic
without counting the aggregate response again. Zero is a reported charge,
not missing data. Missing/malformed costs or precision beyond the interchange
contract's 18 fractional digits leave actual cost unavailable; consumers may
retain a separately labeled snapshot estimate, never relabel it as actual.

### Endpoint routing and billing identity

`allow_fallbacks: false` alone still permits initial endpoint selection, so
the bounded request adds routing controls for the exact bounded model only:

```json
"provider": {
  "order": ["openai"],
  "ignore": ["openai/flex"],
  "max_price": {"prompt": "2", "completion": "12"},
  "require_parameters": true,
  "allow_fallbacks": false
}
```

What this guarantees, per the provider routing docs: base slugs match **all
default-tier endpoints** of that provider, and "service tier endpoints
(e.g. `openai/fast`, `google-vertex/flex`) are **not** matched by base slugs
— they require explicit opt-in via the `service_tier` parameter or a
tier-suffixed slug," so no tier routing can occur here. `order: ["openai"]`
therefore restricts the request to OpenAI's default-tier endpoints serving
`openai/gpt-5.6-terra`, and the `ignore: ["openai/flex"]` entry is explicit
redundant defense, not the pin itself. This is a tier restriction, not an
immutable single-endpoint pin: today the catalog lists exactly one such
endpoint ($2/$12 per M tokens with the 272K override), and that one-endpoint
state and its rates are pricing snapshot facts the pricing owner must
reverify — OpenRouter may serve additional default-tier endpoints later.

`max_price` (`"prompt": "2"`, `"completion": "12"`, USD per million tokens,
typed as strings per OpenRouter's OpenAPI schema) is emitted as a **rate
routing filter with candidate base thresholds**: the docs demonstrate
comparisons against base per-M prompt/completion rates, and it "will prevent
your request from running if the price is not available." It is **not an
all-in spend cap**: it does not cap total tokens, requests, or total spend,
and it does not shrink the bound's worst-case quantities. Existing
source-snapshot pricing does not cap future costs — endpoint prices can
change after the snapshot is taken, and a changed price either fails the
filter closed (price above the ceiling) or continues to bill at the new
lower price within the same worst-case quantities. max_price coverage of
cache/tool/tier price overrides is undocumented — the docs describe base
prompt/completion filtering only, so overrides such as the 272K
long-context rates, the cache-write premium, and per-tool fees are not
proven to be filtered by it, which is exactly why the bound still reserves
those worst-case bases.

### Catalog revision and response identity

`openai/gpt-5.6-terra` is a moving alias whose public catalog entry names the
canonical dated revision `openai/gpt-5.6-terra-20260709`. The dated slug is
**not sent as the request model**: the public catalog's model-resolution
routes return no entry for it (a 404 is documented to mean "treat as an
unavailable model"), and the chat schema documents no revision semantics for
the `model` field, so requesting it would be an invented control. Absence
from the catalog is not proof the chat router would reject the dated slug,
but it is equally not proof it would serve it, so the request keeps the
alias. The catalog preflight remains the revision check; the response check
is bounded identity compatibility, not independent immutable revision proof:

1. **Pre-dispatch catalog check.** Before any inference request can spend
   money, the driver performs one public, unauthenticated, timeout-bounded
   GET of OpenRouter's alias-resolving model-lookup route
   (`/api/v1/model/openai/gpt-5.6-terra`, no credentials, no retry, no
   caching) and requires the returned row's `canonical_slug` to equal
   `openai/gpt-5.6-terra-20260709`. A positive shared-deadline check precedes
   the GET, and the lookup timeout is capped to the minimum of its 5 s bound
   and the integer remaining request budget; the integer granularity comes
   from the driver's own `remainingSeconds()` helper (`max(1, ceil(remaining))`)
   — it is current helper policy, not a framework limitation, since
   `PendingRequest::timeout()` accepts fractional seconds. The cap bounds only
   the configured timeout and no hard upstream cancellation at the exact
   deadline is claimed, but a shorter remaining duration is never ignored. A
   missing, malformed, errored, or unreachable
   answer fails closed before dispatch
   (`laravel_ai.model_revision_unverified`, fallback not allowed); a
   different `canonical_slug` fails closed before dispatch
   (`laravel_ai.model_revision_drift_before_dispatch`, fallback not
   allowed). Catalog traffic is never recorded as provider inference
   evidence: the check captures no attempt, so every pre-dispatch rejection
   settles its collection diagnostic `not_sent` by construction — zero
   inference attempts exist, so no catalog exchange can replay as an
   accepted (paid) result, and no evidence counting is needed.
2. **Response model compatibility.** OpenRouter may report either the exact
   requested alias (`openai/gpt-5.6-terra`) or the reviewed dated revision
   (`openai/gpt-5.6-terra-20260709`). The driver accepts either exact value,
   preserves it on the result, and rejects missing identities, sibling or
   unknown models, and unexpected dated revisions with
   `laravel_ai.model_revision_drift` (fallback not allowed). The inference
   POST has definitely happened by then, so this rejection never settles
   `not_sent`: with a raw captured exchange it settles `accepted` (the
   billable call ran and is preserved for reconciliation), and with no raw
   capture the dispatch is explicitly recorded as an unanswered attempt and
   settles `possibly_accepted` — a spent reservation is never falsely
   released. The same post-prompt guarantee covers every later failure with
   no raw capture: whenever the SDK returns a conforming response whose raw
   exchange is unavailable, the driver records the unanswered dispatch
   before mapping, so a runtime deadline reached after the response
   (`librarium.timeout`) and a required-grounding rejection
   (`laravel_ai.ungrounded_response`) also settle `possibly_accepted`, never
   `not_sent`.

The **check-to-use race is unavoidable**: the alias can move between the
pre-dispatch catalog check and the inference POST. A dated response can
expose a mismatch, but an alias response does not identify the served
revision and therefore cannot guarantee that the race is closed. The catalog
`canonical_slug` check remains the revision assertion, but it is not a
provider-side immutable pin. Catalog movement fails closed before dispatch;
re-enabling requires updating the reviewed revision. These checks apply only
to the pinned bounded route — unpinned and non-bounded OpenRouter requests
perform no catalog check and are never rejected for their served model.

The pin applies only to the exact bounded model; a budget control on any
other OpenRouter model keeps the pre-existing routing block and is never
rerouted to OpenAI. Requests without the budget control are never silently
altered.

Pricing identity: `openrouter:openai/gpt-5.6-terra-272k` — this is Terra's
identity and this paragraph is Terra-specific; the Luna route carries its
own `openrouter:openai/gpt-6-luna-272k` identity under the same
worst-case-reservation logic, documented in the Luna section above. Terra's
identity names
the worst-case billing basis rather than the base model rates: every
completion is conservatively billed at the endpoint's >272K-prompt override
rates (2× input, 1.5× output for the full request) with the 1.25×
cache-write premium, because the input quantity necessarily exceeds 272K
tokens per completion in the worst case. No aggregate model identity can
safely satisfy this, so rate resolution fails closed until the pricing
registry serves this exact identity.

Sources: [Server tools / Tool Call
limits](https://openrouter.ai/docs/guides/features/server-tools) (budget
quote above; `max_tool_calls` default 30 / max 30; usage tracking),
[Web Search server
tool](https://openrouter.ai/docs/guides/features/server-tools/web-search)
(`max_uses` semantics and error results; `max_results` 1–25; Exa mode pricing
$0.007 with 10 included results then $0.001; "All pricing is in addition to
standard LLM token costs for processing the search result content"),
[Provider
routing](https://openrouter.ai/docs/guides/routing/provider-selection)
(`order` with exact endpoint slugs, `ignore`, `allow_fallbacks`,
`require_parameters`, and `max_price` — rate routing with candidate base
thresholds that "will prevent your request from running if the price is not
available"; the OpenAPI schema types `max_price.prompt`/`max_price.completion` as
strings), [Get a model by its
slug](https://openrouter.ai/docs/api/api-reference/models/get-a-model-by-its-slug)
(the public unauthenticated alias-resolving lookup used by the pre-dispatch
revision check; `canonical_slug` is the revision authority), and the public
endpoint catalog for
`openai/gpt-5.6-terra` (standard `openai` endpoint: context 1,050,000,
`max_completion_tokens` 128,000, $2/$12 with the 272K override; `openai/flex`
variant at $1/$6 excluded by pinning; alias `canonical_slug`
`openai/gpt-5.6-terra-20260709`).

## `claude-chat` — Anthropic `claude-sonnet-5-5`, web search enabled: `unknown`

The grounded Anthropic route stays `unknown` under every configuration. The
`max_searches` option remains a supported run control and is enforced on the
outbound request as the `web_search_20250305` tool's `max_uses`, but it is
not spending authority:

- `max_uses` caps *billed searches* ($10 per 1,000 searches; "Each web search
  counts as one use"; errored searches are not billed), yet "If Claude
  attempts more searches than allowed, the `web_search_tool_result` is an
  error with the `max_uses_exceeded` error code" — the error result returns
  to the internal model, which can keep iterating. Each such iteration
  re-processes input ("Web search results retrieved throughout a
  conversation are counted as input tokens, in search iterations executed
  during a single turn"), so iterations can exceed `max_searches + 1`.
- The server-side loop's per-turn iteration limit exists ("the API might
  pause that loop and return a `pause_turn` stop reason"; batch requests use
  "a higher per-turn iteration limit") but no numeric sync limit is
  documented, so no multiple of the 1M context window bounds aggregate
  billed input.
- No official derivation ties output tokens to `max_tokens` across the
  internal iterations, and inventing one would be speculation.

Smallest necessary external/product decision: Anthropic documents the
synchronous per-turn iteration limit (or ships a control that caps loop
iterations rather than billed searches). Until then, grounded Claude is
`unknown` even with every control set, while the no-search Claude bound
(one request, no server tools, context-window input cap, `max_tokens` output
cap) remains exact.

Sources: [Web search
tool](https://platform.claude.com/docs/en/agents-and-tools/tool-use/web-search-tool),
[Server
tools](https://platform.claude.com/docs/en/agents-and-tools/tool-use/server-tools),
[Pricing](https://platform.claude.com/docs/en/about-claude/pricing).

## `openai-chat` — OpenAI `gpt-6-luna`, web search enabled: `unknown`

The grounded OpenAI route stays `unknown` under every configuration.
`max_tool_calls` remains a supported run control, sent as the top-level
Responses field ("The maximum number of total calls to built-in tools that
can be processed in a response… **Any further attempts to call a tool by the
model will be ignored**"), and `max_output_tokens` remains a run control
(response-wide, "including visible output tokens and reasoning tokens",
minimum 16). But ignored attempts are not documented to stop inference, and
nothing official bounds how many internal steps the model performs while its
tool calls are being ignored, so no multiple of the 1,050,000-token context
window bounds aggregate billed input. Web search also bills $10.00/1k calls
plus search content at model input rates, with search context capped at
128k independently of the model context.

Smallest necessary external/product decision: OpenAI documents that
`max_tool_calls` (or another control) terminates the server-side loop, or
publishes a per-response step maximum. Until then, grounded OpenAI is
`unknown` even with every control set.

Sources: [Create a model
response](https://developers.openai.com/api/reference/resources/responses/methods/create/),
[Web search
guide](https://developers.openai.com/api/docs/guides/tools-web-search),
[Pricing](https://developers.openai.com/api/docs/pricing), [GPT-6
Luna](https://developers.openai.com/api/docs/models/gpt-6-luna).

## `gemini-grounded` — Gemini `gemini-3.8-flash`: remains `unknown`

Gemini grounding cannot be completely bounded, so this Profile returns
`unknown` under every configuration, and Gemini has no `max_searches`,
`max_tool_calls`, or `max_results` request controls (setting them is
rejected as unsupported before execution).

Google bills Gemini 3 grounding **per generated search query**: "A
customer-submitted request to Gemini may result in one or more queries to
Google Search. You will be charged for each individual search query
performed" — for example, two distinct queries in one API call count as two
billable uses — at 5,000 free search requests per month shared across Gemini
3.x models, then **$14 per 1,000 requests**. The `google_search` tool object
in the Gemini API exposes no parameter that caps the number of queries per
request (only time-range/domain filters), so the search dimension has no
enforceable maximum and the bound fails closed.

The other dimensions would be provable: retrieved grounding context "is not
charged as input tokens", submitted input is capped by the model's
1,048,576-token input limit, `maxOutputTokens` is enforced on the real
request, and output — including thinking tokens — is capped at 65,536.

Sources: [Gemini Developer API
pricing](https://ai.google.dev/gemini-api/docs/pricing), [Grounding with
Google Search](https://ai.google.dev/gemini-api/docs/generate-content/google-search),
[Tool reference](https://ai.google.dev/api/generate-content), [Gemini 3.8
Flash](https://ai.google.dev/gemini-api/docs/models/gemini-3.8-flash) (same
1,048,576-token input and 65,536-token output limits as Gemini 3.6 Flash,
read 2026-10-11).

Smallest necessary external or product decision: either Google ships a
per-request search-query cap for the `google_search` tool (or documents a
hard per-request query maximum), or the product explicitly accepts unbounded
per-query grounding fees. Prompting the model to limit searches is not a
control.

## Shared integration needs for the pricing registry owner

These needs are shared by both bounded routes; each carries its own
per-model facts rather than one merged entry.

- Two bounded identities, each with worst-case per-completion rates for
  the OpenAI default-tier endpoints serving its model:
  `openrouter:openai/gpt-5.6-terra-272k` (the >272K-prompt override and
  the 1.25× cache-write basis) and `openrouter:openai/gpt-6-luna-272k`
  (the ≥272K long-context partition rates — input $0.2/M, output
  $0.75/M, cache-read $0.02/M, cache-write $0.25/M — with the same
  reserve-everything-in-cache-write logic), so resolving the bound's
  `cache_write_input_tokens`/`output_tokens` quantities cannot silently
  use cheaper aggregate rates. Each catalog's context length, output
  maximum, endpoint set, and rates are snapshot facts the pricing owner
  must reverify against the live endpoint catalog before enabling or
  refreshing any price.
- An `exa_search_requests` unit rate ($0.007 per request in the pinned Exa
  `auto` mode, shared by both routes) distinct from every model catalog's
  native `web_search` fee; the catalog's model-level rate ($0.01/call)
  must never satisfy it.
- Each bounded request carries `provider.max_price` as a rate routing
  filter with candidate base thresholds — Terra `prompt: "2"` /
  `completion: "12"`, Luna `prompt: "0.1"` / `completion: "0.5"`, USD
  per million tokens — and the exact request contract includes it:
  the docs demonstrate base prompt/completion comparisons and the request
  fails when no endpoint qualifies. Record its limits with the frozen
  configuration: existing source-snapshot pricing does not cap future
  costs, and max_price coverage of cache/tool/tier price overrides is
  undocumented, so the worst-case quantity bases above are unchanged by it.
- The reviewed catalog revision is per-model:
  `openai/gpt-5.6-terra-20260709` for Terra and
  `openai/gpt-6-luna-20260922` for Luna (each the alias's current
  `canonical_slug`). The request keeps the alias. The pre-dispatch catalog
  check fails closed unless that reviewed revision still matches; the
  response check accepts only the exact requested alias or reviewed dated
  revision and preserves whichever was reported. An alias response is
  compatibility evidence, not immutable revision proof, and does not close
  the catalog-to-inference race. An alias rollover therefore requires
  re-review here before the bounded route runs again. Settlement is
  phase-distinct: the pre-dispatch codes
  (`laravel_ai.model_revision_unverified`,
  `laravel_ai.model_revision_drift_before_dispatch`) always settle
  `not_sent` because the check records no inference attempt, while the
  post-inference `laravel_ai.model_revision_drift` settles `accepted`
  with a raw captured exchange and `possibly_accepted` without one —
  never `not_sent`. The same rule holds for any post-prompt failure
  without a raw capture (`librarium.timeout` reached after the response,
  `laravel_ai.ungrounded_response`): the unanswered dispatch is recorded,
  so they settle `possibly_accepted`. Catalog traffic is never persisted
  as inference evidence.
- If Anthropic, OpenAI, or Google later ship loop-capping controls or
  documented iteration maxima, the withdrawn routes can be revisited with
  their then-current token rates (Anthropic $2/$10 global flat across 1M
  context; OpenAI Luna $0.20/$1.20 with the same 272K tier shape; Gemini
  3.6 Flash promotional $0.75 input through 2026-12-31 plus $14/1k grounded
  queries after 5,000 free monthly requests).
