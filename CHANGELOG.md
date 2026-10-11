# Changelog

All notable changes to this project will be documented in this file.

## 1.0.0 - 2026-10-09

First stable release.

### Requirements

- PHP 8.4 or later, Laravel 13.30 or later, and Laravel AI SDK `^1.0.1`.
  Laravel Prompts `^0.3.15`, CommonMark `^2.10.2`, and Standard Webhooks
  `^1.0` are the lowest versions that pass the lowest-dependency lane. Laravel
  starts at 13.30 because Composer blocks earlier 13.x releases for a security
  advisory. Development dependencies are ranges, so the lowest lane runs Pest
  4 and PHPUnit 12 while the highest lane runs the latest Pest 5.
  Laravel AI SDK 1.0.0 is excluded because of
  [GHSA-6qhr-3g93-pxhw](https://github.com/laravel/ai/security/advisories/GHSA-6qhr-3g93-pxhw)
  (SSRF via client-supplied file URLs in the SDK's Vercel AI and AG-UI
  adapters).
- `jkudish/laravel-ai-pricing` `^0.3` for offline, provenance-aware pricing
  with dated rate windows.

### Research API

- Laravel-native query, preview, synchronous, queued, event, and
  terminal-response APIs with no package-owned persistence.
- Provider-safe option validation, credential handling, deadlines, fallbacks,
  webhook wake hints, provenance, citations, usage, and errors.
- Recoverable durable research with application-owned handles.
- A package-owned queued research test fake.
- Application-defined Drivers run through the public query runtime.

### Profiles

- Six Laravel AI SDK Profiles and 40 focused HTTP Profiles, including You.com
  Answer and Research, SearchAPI and DataForSEO surface observations, SerpBase
  Search and News, and the TypeScript v2 parity set.
- Model defaults follow the shared JS/PHP decision: `openai-research` targets
  GPT-6.1 Sol; `openai-chat`, `openrouter-grounded`, `openrouter-chat` and
  `perplexity-low-frozen` target GPT-6 Luna; `gemini-grounded` and
  `gemini-chat` target Gemini 3.8 Flash; `grok`, `grok-x-only` and
  `grok-combined` target Grok 4.7; and `claude-chat` targets Claude Sonnet 5.5.
- `claude-chat` sends `effort: high`, adaptive thinking and `max_tokens`
  64,000 explicitly, all configurable through the `effort`, `thinking` and
  `max_output_tokens` options. `thinking` accepts `adaptive` or
  `between_tools`, because Sonnet 5.5 rejects `disabled`, and `between_tools`
  is rejected above high effort. A Laravel AI response that finishes as a
  refusal or content filter now fails as `laravel_ai.refused` instead of
  returning its text as an answer.
- `openai-research` defaults to reasoning effort `high` and the web search
  tool's default return-token budget, replacing the hard-coded `xhigh` and
  `unlimited`. The `reasoning_effort`, `return_token_budget` and
  `max_tool_calls` options are validated strictly.
- OpenRouter Profiles accept `provider_order`, `allow_fallbacks`,
  `require_parameters`, `data_collection`, `zdr`, and `reasoning_effort` or
  `reasoning_max_tokens` with `reasoning_exclude`, passed through the Laravel
  AI provider-options seam. The defaults keep exact routing
  (`require_parameters: true`, `allow_fallbacks: false`) and the model's own
  reasoning. `zdr` is rejected while web search is on, and the bounded
  OpenRouter routes reject overrides of their pinned routing and reasoning.
- `perplexity-low-frozen` accepts only the `frozen-low-2026-10-03`
  configuration. `openai-chat` quotes against the `gpt-6-luna-standard-short`
  billing basis, because the pricing package deliberately leaves the generic
  `openai:gpt-6-luna` identity unpriced.
- OpenRouter Profiles that enable web search must declare a supported
  `search_engine`; no engine is selected implicitly.
- SearchAPI's Google AI Overview Profile reads an overview returned inline by
  the first `google` request and follows `page_token` only when no inline
  overview is present. A SERP without an overview fails as
  `searchapi.no_ai_overview`; an overview the driver cannot read fails as
  `searchapi.ai_overview_unparsed`. Citations resolve Google redirect links by
  URL parsing only: a `url`/`q` publisher URL becomes the citation, otherwise
  the publisher origin from the link's `favicon` URL. The raw redirect is kept
  as `provider_reference` when it fits 255 characters.
- SearchAPI and Perplexity Agent Profiles accept
  `options.request_timeout_seconds` (strict integer, 1 to 600). Defaults are 20
  and 30 seconds, and the shared research deadline still caps each request. The
  Perplexity option is a transport control and never reaches the request
  payload.
- The `searchapi-perplexity` ID is reserved and rejected with a retirement
  reason, because SearchAPI withdrew its `perplexity` engine without a
  replacement. A copy left in a published configuration is reported
  unavailable and never sent.
- `parallel-research` accepts every processor Parallel lists, from `lite`
  through `ultra8x`. The default stays `pro`, and the delisted `-fast`
  processors are rejected. Each processor has its own default polling
  deadline, from 30 minutes to 180 minutes. Research also accepts
  `after_date`, `output_schema` (a text description, or a JSON Schema for a
  JSON result), `metadata`, and `memory_scope_key`, using the TypeScript
  option names. See `docs/parallel-valyu-research.md` for prices and the
  Task Run parameters left out, with reasons.
- `valyu-research` accepts the `fast`, `standard` (default), `heavy`, and
  `max` modes, each with a default deadline sized from Valyu's documented
  runtimes. It requests Markdown plus PDF output by default; `output_formats:
  ['markdown']` opts out of the PDF. A Profile configured for `max` is never a
  member of the built-in `deep` or `all` groups.
- Parallel and Valyu research Profiles accept `request_timeout_seconds`, which
  replaces the processor or mode default; the shared research deadline still
  caps it. When a Profile's deadline exceeds the shared timeout, the compiled
  plan and the `librarium:query --agent` preview warn and name each setting to
  raise and the value it needs. A Profile whose pricing identity does not
  match its processor or mode is reported unavailable instead of being quoted
  at another price.
- The Valyu PDF artifact keeps the `pdf_url` bearer link, plus `expires_at`
  when the URL states an expiry. Valyu's link is its token-authenticated asset
  endpoint (`https://api.valyu.ai/v1/deepresearch/tasks/{task}/assets/{asset}?token=…`),
  which names the private task. That exact host and path shape is therefore
  allowed to contain the task ID. Every other guard still withholds the link:
  HTTPS only, no user information, no credential, at most 8,192 bytes. The
  link states no expiry, so `expires_at` is absent. The driver strips the link
  and the task ID from captured provider responses, so redacted evidence,
  sensitive collections, diagnostics, and errors contain neither.
- Exa Research preserves API-output observation provenance across synchronous
  and durable results and validates explicit native run budgets from $1
  through $100.

### Evidence and identity

- Provider identity fails closed: a Driver or SDK response must report the
  Profile's exact provider unless a custom Profile sets
  `allow_provider_routing`.
- Terminal values reject opaque identifiers (request, result, and citation IDs
  and source `provider_reference`) that are empty, longer than 255
  characters, padded with whitespace, or contain control characters, matching
  the TypeScript contract's whitespace rules.
- Citation locators name the unit the provider reports: Gemini Interactions
  offsets are labeled `bytes:`, while OpenAI-compatible surfaces (OpenAI,
  OpenRouter, xAI) keep `characters:`.

### Usage and pricing

- Profile cost comes from offline, provenance-aware `laravel-ai-pricing`
  quotes, with fail-closed maximum-cost preflight checks. Unknown cost stays
  unknown, never zero.
- An optional exact-request spending-bound API reports provider-native
  ceilings, complete fixed-operation billable quantities, or an explicit
  unknown result. A quantity bound carries its admission time and deadline,
  and `pricingObservation()` turns it into a maximum-cost pricing observation
  over that interval.
- Preview quotes are priced from compile time until the run deadline
  (`pricedAt` and `pricedUntil`, also in the agent JSON pricing envelope). A
  price with dated rate windows, such as Gemini 3.8 Flash's
  `gemini-3.8-flash-standard` basis, is quoted at the highest rate in effect
  before the deadline, and quote provenance lists the windows used.
- `claude-chat` and `openai-research` quote against the
  `claude-sonnet-5-5-global-standard` and `gpt-6.1-sol-standard-short` billing
  bases. The no-search Claude spending bound uses the Sonnet 5.5 basis.
- Result mappers derive the interchange contract's exclusive input partitions
  from the SDK's inclusive token totals. Gemini Interactions usage is read from
  the raw response body (`usage.total_input_tokens`, `total_output_tokens`,
  `total_cached_tokens`, `total_thought_tokens`), so a reported zero stays `0`,
  and Gemini Deep Research thought tokens are folded into the completion
  total.
- Usage fails closed when a provider's report cannot be reconciled. If the
  inclusive input total is smaller than its own cache partitions, every
  input-side partition is unknown rather than clamped. The Laravel AI mapper
  also checks each step before trusting the SDK's folded aggregate. OpenAI
  background research treats absent usage as unknown and rejects malformed or
  inconsistent usage records.
- Gemini Interactions `usage.total_tool_use_tokens` is carried as an unpriced
  `tool_use_tokens` audit count, alongside `server_tool_search_requests`. It is
  never added to the priced partitions, because no verified charge covers the
  whole field.
- Gemini grounded results (`gemini-grounded`, `gemini-chat` and Gemini Deep
  Research) report Google Search grounding queries as
  `server_tool_search_requests`, read from the `google_search` entries of
  Interactions `usage.grounding_tool_count`. Google bills Gemini 3 grounding
  per query, and those queries can dominate a run's cost: the #4865 canary
  reported 24 queries, USD 0.336 of a USD 0.363 run. When an entry carries
  both `count` and `search_query_count`, they must agree. A missing or
  malformed report leaves the count unknown instead of partial. Gemini quotes
  already include 100 searches at USD 14 per 1,000 (USD 1.40 of the USD 1.85
  quote), and the Gemini spending bound stays `unknown` because no request
  control caps the number of queries.
- `grok-x-only` and `grok-combined` carry no pricing usage. xAI bills X Search
  per fetched post and profile, which no request parameter bounds, so their
  quotes are unavailable and a maximum-cost preflight that includes them fails
  closed. The web-only `grok` Profile keeps its per-call search count.

### Conformance and release tooling

- The TypeScript terminal-interchange snapshot and the 41-row TypeScript v2
  Profile inventory are vendored and verified offline. `composer
  typescript:repin` regenerates both, and every digest, from a
  `jkudish/librarium` checkout at a named ref.
- The binding matrix, the seven PHP-only Profiles, and separately
  approval-gated live provider canaries are documented and checked.
- Executable compatibility, exact-SHA pull-request, and release-readiness
  checks.
