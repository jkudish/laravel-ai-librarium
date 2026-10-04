# Perplexity Agent spending bounds — evidence and limitations

Task #3820 evaluation of the two exact inline Agent routes against the
`SpendingBound` contract. Both routes resolve to `unknown`. **This records
that no bound could be proven from the documented control surface — not that
a bound is impossible**: any of the evidence gaps below, once closed by
provider documentation or an authorized configuration decision, could make a
bound provable. Checked against the official documentation on 2026-09-18; no
live API requests were made.

## Evaluated routes

| Profile | Request sent by `PerplexityAgentDriver` | Result |
| --- | --- | --- |
| `perplexity-sonar-pro` | `POST /v1/agent` inline with `{"input": <prompt>, "preset": "low"}` | `unknown` |
| `perplexity-low-frozen` | `POST /v1/agent` inline with the frozen low configuration below | `unknown` |

The frozen payload retains the official `low` preset controls
([presets → "low — current preset values"](https://docs.perplexity.ai/docs/agent-api/presets),
checked 2026-09-11), with its model upgraded to GPT-6 Luna under configuration
`frozen-low-2026-10-03`. This is a package-owned revision, not a claim that
the entire payload matches today's provider-managed preset:

```json
{
    "model": "openai/gpt-6-luna",
    "prompt_cache_key": "low",
    "max_steps": 5,
    "max_output_tokens": 32768,
    "reasoning": {"effort": "minimal"},
    "instructions": "Today is <date>. …",
    "tools": [
        {"type": "web_search", "max_results": 15, "max_tokens": 2000, "max_tokens_per_page": 2000},
        {"type": "fetch_url", "max_urls": 1}
    ]
}
```

## Billed dimensions and evidence

Perplexity's pricing model for the Agent API: "the Agent API charges per
token at direct provider rates with no markup, plus a per-invocation fee for
tools" ([FAQ](https://docs.perplexity.ai/docs/resources/faq),
[pricing](https://docs.perplexity.ai/docs/getting-started/pricing)).

| Dimension | Rate basis (source) | Enforced control in our requests |
| --- | --- | --- |
| Input tokens (incl. cache reads) | Per routed model; GPT-6 Luna's direct OpenAI input rate is 0.10 USD per 1M ([model](https://developers.openai.com/api/docs/models/gpt-6-luna)); Perplexity tier/cache rates are not reverified here | none bounding the aggregate |
| Output tokens (incl. reasoning) | GPT-6 Luna's direct OpenAI output rate is 0.50 USD per 1M ([model](https://developers.openai.com/api/docs/models/gpt-6-luna)); no Perplexity tier rates inferred | `max_output_tokens` (frozen route only) |
| `web_search` invocations | 2.50 USD per 1,000 invocations, "separate from model token usage" ([web search](https://docs.perplexity.ai/docs/agent-api/tools/web-search)) | none |
| `fetch_url` invocations | 0.50 USD per 1,000 invocations ([fetch URL](https://docs.perplexity.ai/docs/agent-api/tools/fetch-url-content)) | none |
| Search context tokens | "you are charged for the exact number of search context tokens consumed" ([web search](https://docs.perplexity.ai/docs/agent-api/tools/web-search)) | `max_tokens` 2000 / `max_tokens_per_page` 2000 per search call (frozen route only) |
| Fetched content tokens | Enters model context as snippets, "may be truncated", no published ceiling ([fetch URL → Limits](https://docs.perplexity.ai/docs/agent-api/tools/fetch-url-content)) | none |
| Token-rate multipliers | `service_tier` `flex` = 0.5×, `priority` = 2× listed prices ([models → Service tiers](https://docs.perplexity.ai/docs/agent-api/models)) | neither route sends it; managed presets may set it internally |

The response `usage` object reports aggregate `input_tokens`,
`output_tokens`, `tool_calls_details.<tool>.invocation`, and
`usage.cost.total_cost`
([API reference](https://docs.perplexity.ai/api-reference/agent-post));
those are settlement evidence after the fact, not pre-dispatch authority.

## Why the managed `perplexity-sonar-pro` route is unproven

1. **The billable model set is not client-bounded.** The request sends only
   `preset: "low"`. Dynamic presets are provider-managed: "call a preset by
   name … to opt in to the latest Perplexity-optimized configuration.
   Perplexity updates the underlying configuration as evals show
   improvements"
   ([presets → Overview](https://docs.perplexity.ai/docs/agent-api/presets)).
   The routed model (`openai/gpt-6-luna` as of the 2026-10-03 upgrade) can therefore change
   server-side without client action, and no bounded model set is published
   for the preset (preset updates optimize the "Quality" dimension). The
   package's own pricing SKU for this route (`perplexity:agent-low`) contains
   only the stable tool rates; routed-model token units are unpriced.
2. **No native monetary authority was found.** The `POST /v1/agent` request
   schema
   ([API reference → ResponsesRequest](https://docs.perplexity.ai/api-reference/agent-post))
   and the official SDK request type (`ResponsesRequestInput` in
   `@perplexity-ai/perplexity_ai` 0.38.5,
   [npm](https://www.npmjs.com/package/@perplexity-ai/perplexity_ai)) expose
   no budget, cost, or spend-cap parameter. Absence across every
   authoritative request surface is treated as "no documented control", not
   as proof the provider could never add one.

**Smallest decisions that would unblock:** Perplexity (a) publishes a
contractually bounded billable model set with step/tool/output ceilings for
the `low` preset, or (b) adds a request-level monetary ceiling. A third
option belongs to the integration owner, not this driver: serve this Profile
through the frozen model configuration instead (a product decision that must
not happen silently).

## Why the frozen `perplexity-low-frozen` route is unproven

The model is pinned (`openai/gpt-6-luna`), and
the payload enforces `max_steps` 5, `max_output_tokens` 32768, and per-call
search context caps. The dimensions that still cannot be maximized:

### 1. Tool invocation counts are not bounded

`web_search` and `fetch_url` are billed per invocation. `max_steps` caps
"research loop steps", and one step is "a single model turn that may call
tools"
([define-the-run](https://docs.perplexity.ai/docs/agent-api/building-agents/define-the-run))
— plural, and responses echo `"parallel_tool_calls": true`
([quickstart response examples](https://docs.perplexity.ai/docs/agent-api/quickstart)).
`max_results`/`max_tokens`/`max_tokens_per_page` bound results and context
**per call**, and `max_urls` bounds URLs **per fetch**, not the number of
calls.

**`parallel_tool_calls: false` was investigated explicitly** and is not a
documented Agent API request control:

- It is absent from the `ResponsesRequest` request schema in the
  [API reference](https://docs.perplexity.ai/api-reference/agent-post).
- It is absent from the SDK's Agent request type `ResponsesRequestInput`
  (`@perplexity-ai/perplexity_ai` 0.38.5). The SDK exposes
  `parallel_tool_calls` only on the **legacy Sonar Chat Completions** request
  (`ApiChatCompletionsRequestInput`), a different API, so the control exists
  in Perplexity's ecosystem but is not published for `/v1/agent`.
- Every mention in the Agent docs
  ([OpenAI compatibility](https://docs.perplexity.ai/docs/agent-api/openai-compatibility),
  [quickstart](https://docs.perplexity.ai/docs/agent-api/quickstart),
  [output control](https://docs.perplexity.ai/docs/agent-api/output-control))
  is a response-echo example value, not request-parameter documentation; the
  compatibility page's "Standard OpenAI Parameters" list for the Agent API
  does not include it.
- The response echoing `true` does not by itself rule out a controllable
  request-side `false` — the conclusion rests on the absence from every
  authoritative *request* surface above, and on the fact that even a
  silently-accepted undocumented parameter could not ground a defensible
  enforced limit: nothing documents that it applies to built-in tools
  (rather than function tools only) or that one call per step combines with
  `max_steps` to cap total invocations.

`max_tool_calls` is in the same position: echoed on responses (as `null`)
and referenced once as a disable-all workaround
([Sonar migration guide](https://docs.perplexity.ai/docs/agent-api/migrate-from-sonar/how-to)),
but absent from the request schema and SDK, with no published semantics for
non-zero values.

### 2. Fetched-content tokens are not capped

`fetch_url` content is "extracted into snippets for model context and may be
truncated" with no documented token ceiling, and the tool's only request
parameters are `type` and `max_urls`
([fetch URL → Parameters/Limits](https://docs.perplexity.ai/docs/agent-api/tools/fetch-url-content)).
Retrieved-context input tokens are therefore not completely bounded even
though `web_search` context is capped per call.

### 3. Aggregate input tokens are not bounded

Each of up to 5 steps re-processes the instructions and accumulated
conversation ("Every token here is re-processed on each step of the loop" —
[define-the-run](https://docs.perplexity.ai/docs/agent-api/building-agents/define-the-run)),
so billed input aggregates across steps, and per-step context grows with
tool results — including the uncapped fetched content of dimension 2.

**Why the provider's linked model documentation cannot substitute for a
context-window maximum.** The
[models page](https://docs.perplexity.ai/docs/agent-api/models) FAQ answer
defers: "Context window size varies by model. See the Agent API models page
and the linked provider documentation"
([FAQ](https://docs.perplexity.ai/docs/resources/faq)). An upstream OpenAI
context window is not itself proof of Perplexity's hosted serving limit.
The GPT-6 Luna upgrade does not establish such a contract. Even if a number
C were adopted, a `SpendingBound` maximum must be *enforced by the request
contract* — no Agent API request control caps per-step input, so a
Perplexity-side behavior change (or serving configuration that accepts more)
would silently exceed an externally assumed maximum, which is exactly the
failure a pre-dispatch bound exists to prevent. Finally, even granting a
per-step ceiling, the tool-invocation-count gap of dimension 1 independently
defeats completeness, so an external window alone could not complete this
bound. `max_output_tokens`
is documented only as "Maximum tokens to generate"
([API reference](https://docs.perplexity.ai/api-reference/agent-post)); its
whole-run coverage (all steps, including reasoning tokens) is likewise not
documented, and is not assumed here.

### What would make a bound provable

The invocation-count gap above **alone** is sufficient reason to keep this
route `unknown` today: `web_search` and `fetch_url` are billed per
invocation, and no documented control bounds the count. The remaining gaps
do not need to be satisfied together with it; a bound becomes provable when
**any one** of the following alternative sets holds (each set is sufficient
on its own, given the already-pinned model):

- **A native all-charge cap.** A request-level monetary ceiling over the
  whole run caps every billed dimension at once. Nothing else is needed —
  this is the same authority the `exa-research` Profile uses. None was
  found in the request schema or SDK.
- **A trusted per-step total input ceiling + an invocation cap + output
  semantics.** A documented, enforced per-step total input ceiling C (for
  example a provider-published, request-enforced context window for its own
  deployment) bounds aggregate input at 5 × C and **subsumes retrieved
  content** — fetched tokens are part of per-step input, so no separate
  fetched-content cap is required. With a documented total tool-invocation
  cap N (dimension 1) and documented whole-run `max_output_tokens`
  semantics including reasoning, every dimension is then bounded:
  input ≤ 5 × C, output ≤ 32,768, invocations ≤ N.
- **A computable context bound + an invocation cap + output semantics.**
  Without a trusted per-step ceiling, retrieved context must instead be
  bounded from tool controls: a documented invocation cap N, plus — because
  `web_search` context is capped per call but `fetch_url` content is not —
  either a documented fetched-content token ceiling or an authorized
  removal of `fetch_url` (removal alone does not address the invocation
  count; it only closes the uncapped-content part). Aggregate input is
  then the computable sum of instructions + prompt + per-call caps across
  the bounded invocations and steps.

A proven per-step total ceiling and a fetched-content cap are therefore
**alternatives, not independent requirements**: the first makes the second
unnecessary.

## Inline timeouts cannot be reconciled

The inline routes (`perplexity-sonar-pro`, `perplexity-low-frozen`) send one
synchronous `POST /v1/agent`. Each HTTP request times out after 30 seconds
unless the Profile sets `options.request_timeout_seconds`; the shared deadline
still caps it. A timeout ends as `perplexity.submit_connection` with an
unknown outcome. Perplexity may still finish and bill the run, and the client
cannot find it afterwards, checked against the API reference on 2026-10-04:

- `GET /v1/agent/{id}` retrieves a stored response only by its id, and a
  synchronous request returns that id only in the response that timed out.
- There is no list-responses endpoint and no idempotency or client
  reference parameter to look a run up by.
- The usage-analytics endpoints report organization-level time buckets (and
  need an organization analytics key), not per-request costs.

A timed-out inline request can therefore be reconciled only in aggregate,
through the account's billing totals. A reconcilable route would submit with
`background: true` (the id returns at once and the run can be polled, then
cancelled with `POST /v1/agent/{id}/cancel`), or stream and record the id from
the first event. Neither is implemented here.

## Tests

`tests/Feature/PerplexityAgentSpendingBoundTest.php` covers, fully offline:
the `unknown` result for both exact routes through the public
`spendingBound()` API (no HTTP requests; these regress against any future
claim of a bound without new evidence), and the exact request serialization
of both routes pinned to their enforced limits — including assertions that
the payloads carry no `parallel_tool_calls`, `max_tool_calls`, or
`service_tier` control the analysis relies on being absent.

## Shared integration needs

None. The driver does not implement `DefinesSpendingBound`; the shared
resolver fallback owns the `unknown` result, and
[docs/spending-bounds.md](spending-bounds.md) already lists Perplexity Agent
among the Profiles that "return `unknown` without driver boilerplate", which
remains accurate. No shared config, contract, pricing, or test changes are
required.
