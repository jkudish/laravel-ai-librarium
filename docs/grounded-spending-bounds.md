# Grounded Laravel AI spending bounds

This page documents the pre-dispatch `spendingBound()` results for the four
exact grounded Laravel AI Profiles — `claude-chat` (Anthropic
`claude-sonnet-5`), `openai-chat` (OpenAI `gpt-5.6-luna`),
`gemini-grounded` (Gemini `gemini-3.6-flash`), and `openrouter-chat`
(OpenRouter `openai/gpt-5.6-terra` with the Exa engine). The result contract,
ownership, and revalidation rules are defined in
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

## `openrouter-chat` — OpenRouter `openai/gpt-5.6-terra`, Exa engine: bounded

Required options: `web_search` is exactly `true`, `search_engine` is exactly
`exa`, `max_tool_calls` (1 through 30), `max_results` (1 through 10),
`max_steps` is exactly `1`, and `max_prompt_bytes` (positive, satisfied by
the rendered prompt). `max_output_tokens` is optional and does not affect the
bound. Configuring `max_searches` makes the bound `unknown` (see below).

### Why the loop length is provable here and nowhere else

OpenRouter documents a request-wide server-tool budget: "Every request that
uses server tools runs an agent loop with a step budget. **Each tool call the
model makes** (a web search, an image generation, etc.) **consumes one step;
when the budget is exhausted, the model is asked to produce its final
answer** with the context gathered so far." The top-level `max_tool_calls`
request field sets that budget, defaults to 30, and has 30 as the documented
maximum. The loop therefore performs at most `max_tool_calls` steps plus one
final completion. The driver sends `max_tool_calls` as a top-level field
(sibling of `messages` and `tools`, merged through the SDK's provider
options) and rejects budgets above 30 before execution.

A configured `max_searches` (forwarded as the tool's `max_uses`) is
deliberately **ambiguous** with the budget: once `max_uses` is reached,
"further search calls return an error result instead of executing," and the
documentation does not state whether those over-cap attempts still consume
budget steps. The bound therefore fails closed (`unknown`) whenever
`max_searches` is present rather than assuming an interpretation.

### The bound

| Unit | Maximum | Basis |
| --- | --- | --- |
| `exa_search_requests` | `max_tool_calls` | Every Exa-billed search is a tool call the model makes, so executing searches ≤ budget steps; Instant/Fast/Auto mode costs $0.007 per request. The unit is engine-specific so the catalog's model-level `web_search` rate (the $0.01 native passthrough fee) can never satisfy it. |
| `input_tokens` | `(max_tool_calls + 1) × 1,050,000` | At most budget + 1 model completions; each processes at most the pinned endpoint's 1,050,000-token context, including injected search content ("All pricing is in addition to standard LLM token costs for processing the search result content"). |
| `output_tokens` | `(max_tool_calls + 1) × 128,000` | Per-completion output uses the pinned endpoint's `max_completion_tokens` (128,000) because forwarding the request's `max_tokens` to every internal loop completion is not documented. |

Additional-result fees are structurally zero: each Exa request includes up
to 10 results and then bills $0.001 per additional result, and the bound
requires an enforced `max_results` of at most 10. `max_results` between 11
and 25 is a valid request but leaves the additional-result dimension
unproven (`unknown`); values above 25 are rejected by option validation.

### Endpoint routing and billing identity

`allow_fallbacks: false` alone still permits initial endpoint selection, so
the bounded request adds routing controls for the exact bounded model only:

```json
"provider": {
  "order": ["openai"],
  "ignore": ["openai/flex"],
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

The pin applies only to the exact bounded model; a budget control on any
other OpenRouter model keeps the pre-existing routing block and is never
rerouted to OpenAI. Requests without the budget control are never silently
altered.

Pricing identity: `openrouter:openai/gpt-5.6-terra-272k`. The identity names
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
`require_parameters`), and the public endpoint catalog for
`openai/gpt-5.6-terra` (standard `openai` endpoint: context 1,050,000,
`max_completion_tokens` 128,000, $2/$12 with the 272K override; `openai/flex`
variant at $1/$6 excluded by pinning).

## `claude-chat` — Anthropic `claude-sonnet-5`, web search enabled: `unknown`

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

## `openai-chat` — OpenAI `gpt-5.6-luna`, web search enabled: `unknown`

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
[Pricing](https://developers.openai.com/api/docs/pricing), [GPT-5.6
Luna](https://developers.openai.com/api/docs/models/gpt-5.6-luna).

## `gemini-grounded` — Gemini `gemini-3.6-flash`: remains `unknown`

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
[Tool reference](https://ai.google.dev/api/generate-content), [Gemini 3.6
Flash](https://ai.google.dev/gemini-api/docs/models/gemini-3.6-flash).

Smallest necessary external or product decision: either Google ships a
per-request search-query cap for the `google_search` tool (or documents a
hard per-request query maximum), or the product explicitly accepts unbounded
per-query grounding fees. Prompting the model to limit searches is not a
control.

## Shared integration needs for the pricing registry owner

- `openrouter:openai/gpt-5.6-terra-272k`: worst-case per-completion rates
  for the OpenAI default-tier endpoints serving this model — the >272K-prompt
  override (currently 2× the $2/M input and 1.5× the $12/M output for the
  full request) and the 1.25× cache-write basis — so resolving the bound's
  `input_tokens`/`output_tokens` quantities cannot silently use cheaper
  aggregate rates. The catalog's context length, output maximum, and rates
  are snapshot facts the pricing owner must reverify against the live
  endpoint catalog before enabling any price.
- An `exa_search_requests` unit rate ($0.007 per request in the default Exa
  `auto` mode) distinct from every model catalog's native `web_search` fee;
  the catalog's model-level rate ($0.01/call) must never satisfy it.
- Optional request-level price ceilings (such as `provider.max_price`) are
  deliberately not requested or priced here; whether to add them to the
  bounded configuration is the integration owner's decision.
- If Anthropic, OpenAI, or Google later ship loop-capping controls or
  documented iteration maxima, the withdrawn routes can be revisited with
  their then-current token rates (Anthropic $2/$10 global flat across 1M
  context; OpenAI Luna $0.20/$1.20 with the same 272K tier shape; Gemini
  3.6 Flash promotional $0.75 input through 2026-12-31 plus $14/1k grounded
  queries after 5,000 free monthly requests).
