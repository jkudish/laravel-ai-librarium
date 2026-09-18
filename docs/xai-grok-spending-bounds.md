# xAI Grok spending bounds

This page records why the xAI Grok Profiles keep an `unknown` pre-dispatch
spending bound. It covers the exact shipped `grok` Profile: model `grok-4.6`,
the web-only corpus, `model_search_tool` retrieval, and the
`POST /v1/responses` route with the `web_search` server-side tool. See
`docs/spending-bounds.md` for the capability contract itself.

All sources were read on September 18, 2026; each lists its official URL and
the page's own last-updated date where published.

## Verdict

`unknown`, failing closed. For a complete `billable_quantities` bound the
driver would have to prove a maximum for every billed unit of this route:
aggregate input tokens (including repeated and retrieved context), output
plus reasoning tokens, and web-search invocations. Today **no documented
provable ceiling was found** for the aggregate-input and invocation
dimensions. That is a statement about absent documentation and guarantees,
not a proof of impossibility: if xAI documents the missing semantics, the
verdict can be revisited (see "What would change the verdict").

Per the package contract ("All other shipped Profiles return `unknown`
without driver boilerplate"), the driver therefore carries no
`DefinesSpendingBound` and `spendingBound()` resolves `unknown` without
constructing it.

## Dimension-by-dimension proof status

### Output + reasoning tokens — provably capped

`max_output_tokens` is documented as a per-response aggregate cap that
includes reasoning tokens, with a bounded default when unset (so even an
unset request is capped, though the driver would still require the explicit
control before claiming any bound):

> `max_output_tokens` (integer | null) — "Max number of tokens that can be
> generated in a response. This includes both output and reasoning tokens.
> Defaults to 128,000 when unset; set a larger value to allow longer
> generations."
> — [Responses REST reference](https://docs.x.ai/developers/rest-api-reference/inference/responses)
> (page last updated September 14, 2026); identical description in the
> official [OpenAPI `ModelRequest`](https://docs.x.ai/openapi.json).

### Aggregate input tokens — no documented provable ceiling found

Two documented facts and one undocumented multiplier:

- grok-4.6's context window is 500,000 tokens
  ([grok-4.6 page](https://docs.x.ai/developers/grok-4-6), last updated
  September 14, 2026). This caps **one** inference.
- Agentic billing is cumulative across inferences, each re-sending the whole
  conversation including retrieved web content:
  > "`prompt_tokens` Represents the **cumulative input tokens across all
  > inference requests** made during the agentic process. Each request
  > includes the full conversation history up to that point, which grows as
  > the agent progresses."
  > — [Tool Usage Details](https://docs.x.ai/developers/tools/tool-usage-details)
  > (last updated May 27, 2026).
- No xAI page documents how many billed inferences correspond to a turn or
  to a given `max_turns` value, and the global default cap applied when
  `max_turns` is unset is likewise not published.

Therefore `max_turns × context_window` is **not** a proven aggregate-input
ceiling: the window is real but the multiplier is not documented anywhere,
so the product is an assumption rather than a bound. This corrects an
earlier draft of this analysis that sketched that product as "arguable".

### Web-search invocations — no documented provable ceiling found

Invocation billing is explicit:

> "Requests which make use of xAI provided server-side tools are priced
> based on two components: **token usage** and **server-side tool
> invocations**." Web Search, `web_search`: "$5 / 1k calls"; "Only
> successful tool executions (`server_side_tool_usage`) are billed."
> — [Pricing, Tools Pricing](https://docs.x.ai/developers/pricing#tools-pricing)
> (last updated September 14, 2026). Image Search "is part of Web Search and
> is billed at the standard Web Search rate"; `view_image` understanding is
> token-based, not per call.

No request-side control bounds the count:

- `max_turns` is explicitly not a call cap:
  > "**`max_turns` does not directly limit the number of individual tool
  > calls.** Instead, it limits the number of _assistant turns_ in the
  > agentic loop. During a single turn, the model may invoke multiple tools
  > in parallel."
  > — [Tool Usage Details](https://docs.x.ai/developers/tools/tool-usage-details).
- The official request schema has no numeric call cap. An exhaustive walk of
  the [OpenAPI specification](https://docs.x.ai/openapi.json) shows
  `ModelRequest` exposes only `max_turns` (integer), `parallel_tool_calls`
  (boolean), and `tool_choice` for tool-count-relevant control.
  `max_tool_calls` exists **only** on `ModelResponse`, described as
  > "The maximum number of tool calls allowed for this response."
  with no request counterpart, no published default value, and no statement
  that a client can set it.

### The `parallel_tool_calls` investigation

Could `parallel_tool_calls: false` plus `max_turns` bound the invocation
count at one call per turn? Investigated explicitly; no official guarantee
was found, on either of the two assumptions that combination needs:

1. **Scope**: The only page documenting the flag's effect is the client-side
   [Function Calling](https://docs.x.ai/developers/tools/function-calling)
   guide: "By default, parallel function calling is enabled — the model can
   request multiple tool calls in a single response... Disable with
   `parallel_tool_calls: false` in your request." It speaks of *function*
   calling (developer-executed tools). The OpenAPI description is scope-free:
   > `parallel_tool_calls` (boolean | null) — "Whether to allow the model to
   > run parallel tool calls." (default `true`)
   > — [`ModelRequest`](https://docs.x.ai/openapi.json).
   No page states that the flag governs xAI-executed server-side tools such
   as `web_search`.
2. **Cardinality**: No page states that disabling parallel calls yields at
   most one tool call per turn. [Tool Usage
   Details](https://docs.x.ai/developers/tools/tool-usage-details) — the
   authoritative page for server-side tool limits and billing — never
   presents `parallel_tool_calls` as a call-count bound and reiterates that
   turns do not limit individual calls.

Deriving "calls ≤ max_turns" from `parallel_tool_calls: false` would
therefore require chaining two undocumented assumptions. Per this package's
rules that is a made-up ceiling, not a proven one, so the dimension stays
unproven. This is "no documented provable ceiling found": if xAI documents
that the flag applies to server-side tools and pins per-turn cardinality,
this becomes a candidate bound and the pricing needs below apply.

## Prices a future complete bound would resolve

grok-4.6 global-endpoint token prices: $2.00 input / $0.50 cached / $6.00
output per 1M tokens short-context; once a request's prompt reaches the 200k
long-context threshold, **all** its tokens bill at $4.00 / $1.00 / $12.00
([Pricing](https://docs.x.ai/developers/pricing), September 14, 2026), plus
`web_search` at $5 per 1k calls. The US regional endpoint (1.1x) and
priority tier (2x) multipliers never apply to this driver's requests: it
uses the global endpoint and default tier. For the X routes, xAI reprices
X Search on September 21, 2026 at 12:00 PM PT to $5 per 1k posts fetched and
$10 per 1k profiles fetched.

## Exact settlement serialization (evidence, never a bound)

The [Responses REST reference](https://docs.x.ai/developers/rest-api-reference/inference/responses)
documents the response `usage` object this driver parses:
`input_tokens` (cumulative), `input_tokens_details.cached_tokens`,
`output_tokens`, `output_tokens_details.reasoning_tokens`, `total_tokens`,
`num_server_side_tools_used`,
`server_side_tool_usage_details.web_search_calls`, and
`cost_in_usd_ticks` ("tick" = 1/10,000,000,000 of a dollar). The driver maps
input/output/total and converts ticks to an exact settled USD string.
Settlement usage is evidence after the paid request; it never promotes to a
pre-dispatch bound.

The tests in `tests/Feature/XaiGrokSpendingBoundTest.php` use fake HTTP and
synthetic adversarial fixtures (twelve billed calls against `max_turns=2`;
1.4M cumulative input tokens against the 500k window). They pin what the
driver accepts and reports at those boundary shapes and that the bound stays
`unknown` — they are assumptions, not observations of provider behavior.

## What would change the verdict

- xAI shipping a request-side tool-invocation cap with documented numeric
  semantics (for example a requestable `max_tool_calls`), or documenting that
  `parallel_tool_calls: false` applies to server-side tools and bounds
  per-turn cardinality, or
- xAI documenting the billed-inference count per turn / `max_turns` value,
  which would make `max_turns × context window` an arguable input bound, or
- xAI documenting a native monetary ceiling covering tool spend.

At that point the pricing registry would need identities covering the
grok-4.6 short-context rates, the 200k long-context tier, and a per-call
`web_search` unit before an application could resolve authoritative rates
for the proven quantities.
