# Profile binding matrix

This began as the Wave 0 implementation authority for PlanMode #2814. It now
records the complete pre-1.0 provider-parity reconciliation under #3604. It is
not a promise that PHP and TypeScript will remain in permanent lockstep.

No row authorizes a provider request or a paid call.

## Authority and inventory

<!-- typescript-pin:start -->
- TypeScript catalog: [`jkudish/librarium` at `4c0d3d6383e37d37972fd3fe5f947d5a3972bbb3`](https://github.com/jkudish/librarium/tree/4c0d3d6383e37d37972fd3fe5f947d5a3972bbb3), ref `4c0d3d6383e37d37972fd3fe5f947d5a3972bbb3`, package version `2.0.0`, no release tag yet; the release check blocks until the pin names the accepted tag
- Catalog source: `src/core/provider-profiles.ts` and its exact profile bindings
- Vendored inventory: `tests/Support/Profiles/v2/catalog.tsv`, SHA-256
  `3422efce54284997b6c8c483cbaffa00c1589bfc53d9d467943d90c1028218c6`, 41 implemented Profiles
- Pin record: `tests/Support/typescript-pin.json`, written only by `composer typescript:repin`
<!-- typescript-pin:end -->
- Implemented TypeScript inventory: **34 providers and 41 Profiles**
- Planned or non-selectable catalog declarations: **0**
- Laravel AI SDK: [`laravel/ai` v0.10.3 at `c3848aae389f45c605eefb0dda5bd5fa7df76eaa`](https://github.com/laravel/ai/tree/c3848aae389f45c605eefb0dda5bd5fa7df76eaa)
- SDK evidence: exact package source plus the [Laravel 13 AI SDK documentation](https://laravel.com/docs/13.x/ai-sdk)

`composer typescript:repin` generates the vendored inventory from the pinned
TypeScript catalog (`BUILTIN_PROVIDER_CATALOG`, implemented Profiles only,
sorted by key). It pins the catalog and binding source hashes, every
implemented key, semantic fields, workflow membership, lifecycle, and target.
The committed Pest test checks the inventory against the pin record, reconciles
every matrix row, and compares PHP result, grounding, observation, corpora,
retrieval, and workflow semantics without a TypeScript runtime or network
dependency.

Target defaults are intentionally allowed to differ after explicit PHP review.
At the pinned TypeScript commit, `gemini-grounded/grounded` defaults to
`gemini-2.5-flash`, `openrouter/grounded` to `openai/gpt-4o-mini`,
`openai-chat/chat` to `gpt-5-mini`, and `gemini-chat/chat` to
`gemini-3.6-flash`, and the three xAI Profiles to `grok-4.6`. The PHP defaults
below follow the shared JS/PHP model decision of 2026-10-09 (#3807): GPT-6.1
Sol for OpenAI Research, GPT-6 Luna for OpenAI and OpenRouter chat, Gemini 3.8
Flash, Grok 4.7 and Claude Sonnet 5.5. The pinned TypeScript catalog shows the
older targets until the next repin.

The pin is a development candidate until it names the accepted JS `v2.0.0`
tag. JavaScript is retiring `searchapi-perplexity/surface` in parallel (#4769),
so the next repin is expected to carry 40 shared Profiles. None of these
catalog counts establishes live-provider acceptance or a published package
channel.

## Summary

| PHP disposition | Count | Meaning |
| --- | ---: | --- |
| Landed in core | 39 | Six Laravel AI SDK Profiles and thirty-three focused HTTP Profiles are configured and covered offline. |
| Landed in optional adapter | 1 | Firecrawl Search remains outside core under #2592 and is covered by the adapter package. |
| Holdback | 0 | No accepted TypeScript v2 Profile is held back. |
| Retired | 1 | `searchapi-perplexity` was retired after SearchAPI deprecated its `perplexity` engine. |
| **Total** | **41** | Every implemented TypeScript Profile appears exactly once below. |

The TypeScript inventory contains 14 grounded answers, 13 search-result
Profiles, 8 durable research reports, and 6 surface observations. Forty map to
PHP core or the optional Firecrawl adapter; one is retired. PHP additionally
ships the frozen Perplexity low Profile approved under #3653 and six
provider-local DataForSEO surface Profiles approved under #3481. These seven
are PHP-only extensions and are not rows in the TypeScript inventory.

`quick` has six curated members, `visibility` has eight, `deep` derives its
eight configured research Profiles, and `all` derives all 40 currently eligible
core Profiles from a 46-Profile configured catalog.

## Provider-local PHP extension

The enabled `perplexity-low-frozen` Profile preserves the reviewed
`frozen-low-2026-10-03` Agent configuration, targeting `openai/gpt-6-luna`
with pricing identity `perplexity:openai/gpt-6-luna`. The prior
`frozen-low-2026-09-11` identifier is rejected, not reinterpreted. All other
frozen payload controls are unchanged. It is a PHP-only reproducibility
variant of the accepted `perplexity-sonar-pro/grounded` semantics and has
offline coverage in `PerplexityAgentDriverTest`.

The following disabled-by-default Profiles implement DataForSEO collection
modes that are not rows in the TypeScript inventory above:

| PHP Profile | Surface and mode | Offline evidence |
| --- | --- | --- |
| `dataforseo-chatgpt` | ChatGPT LLM Scraper Standard | `DataForSeoDriverTest` |
| `dataforseo-chatgpt-live` | ChatGPT LLM Scraper Live | `DataForSeoDriverTest` |
| `dataforseo-gemini` | Gemini LLM Scraper Standard | `DataForSeoDriverTest` |
| `dataforseo-gemini-live` | Gemini LLM Scraper Live | `DataForSeoDriverTest` |
| `dataforseo-google-ai-mode` | Google AI Mode Standard | `DataForSeoDriverTest` |
| `dataforseo-google-ai-mode-live` | Google AI Mode Live | `DataForSeoDriverTest` |

All six are `surface_observation` / `surface_snapshot` Profiles collected by
`dataforseo`. They do not join `quick` or `visibility`; enabled Profiles join
the derived `all` group. See [`dataforseo-profiles.md`](dataforseo-profiles.md)
for the endpoint, configuration, polling, pricing, and worker-loss contracts.

## Landed PHP reconciliation

The table below is the row-by-row closeout. “Landed” means the named PHP Profile
exists in `config/librarium.php`, resolves through the package catalog, and has
offline provider-boundary coverage. “Separate task” means the Profile is
selectable through the named optional adapter rather than core. Live proof is
not claimed here; the separately gated canary inventory is in
[`live-provider-canaries.md`](live-provider-canaries.md).

| TypeScript Profile | PHP disposition | PHP Profile or owner | Offline evidence |
| --- | --- | --- | --- |
| `gemini-grounded/grounded` | Landed | `gemini-grounded` | `LaravelAiDriverTest` |
| `openrouter/grounded` | Landed | `openrouter-grounded` | `LaravelAiDriverTest` |
| `openrouter/chat` | Landed | `openrouter-chat` | `LaravelAiDriverTest` |
| `claude/chat` | Landed | `claude-chat` | `LaravelAiDriverTest` |
| `openai-chat/chat` | Landed | `openai-chat` | `LaravelAiDriverTest` |
| `gemini-chat/chat` | Landed | `gemini-chat` | `LaravelAiDriverTest` |
| `brave-answers/grounded` | Landed | `brave-answers` | `BraveAnswersDriverTest` |
| `exa/search` | Landed | `exa` | `ExaSearchDriverTest` |
| `kagi-fastgpt/grounded` | Landed | `kagi-fastgpt` | `KagiFastGptDriverTest` |
| `perplexity-sonar-pro/grounded` | Landed | `perplexity-sonar-pro` | `PerplexityAgentDriverTest` |
| `grok/web` | Landed | `grok` | `XaiGrokDriverTest` |
| `openai-research/research` | Landed | `openai-research` | `OpenAiResearchDriverTest` |
| `perplexity-sonar-deep/research` | Landed | `perplexity-sonar-deep` | `PerplexityAgentDriverTest` |
| `searchapi/search` | Landed | `searchapi` | `SearchApiDriverTest` |
| `searchapi-chatgpt/surface` | Landed | `searchapi-chatgpt` | `SearchApiDriverTest` |
| `searchapi-gemini/surface` | Landed | `searchapi-gemini` | `SearchApiDriverTest` |
| `searchapi-perplexity/surface` | Retired | none; the ID is reserved and rejected | `SearchApiDriverTest` |
| `searchapi-google-ai-mode/surface` | Landed | `searchapi-google-ai-mode` | `SearchApiDriverTest` |
| `searchapi-bing-copilot/surface` | Landed | `searchapi-bing-copilot` | `SearchApiDriverTest` |
| `searchapi-google-ai-overview/surface` | Landed | `searchapi-google-ai-overview` | `SearchApiDriverTest` |
| `firecrawl-search/search` | Separate task | #2592 adapter `firecrawl-search` | `FirecrawlSearchDriverTest` |
| `you-research/grounded` | Landed | `you-research-grounded` | `YouResearchGroundedDriverTest` |
| `you-research/research` | Landed | `you-research-research` | `YouResearchDriverTest` |
| `you-answer/grounded` | Landed | `you-answer` | `YouAnswerDriverTest` |
| `brave-search/search` | Landed | `brave-search` | `BraveSearchDriverTest` |
| `exa/research` | Landed | `exa-research` | `ExaResearchDriverTest` |
| `tavily/search` | Landed | `tavily-search` | `TavilySearchDriverTest` |
| `gemini-deep/research` | Landed | `gemini-deep-research` | `GeminiDeepDriverTest` |
| `perplexity-deep-research/research` | Landed | `perplexity-deep-research` | `PerplexityAgentDriverTest` |
| `perplexity-search/search` | Landed | `perplexity-search` | `PerplexitySearchDriverTest` |
| `grok-x-only/x` | Landed | `grok-x-only` | `XaiGrokDriverTest` |
| `grok-combined/combined` | Landed | `grok-combined` | `XaiGrokDriverTest` |
| `jina-search/search` | Landed | `jina-search` | `JinaSearchDriverTest` |
| `serpapi/search` | Landed | `serpapi` | `SerpApiDriverTest` |
| `parallel/search` | Landed | `parallel-search` | `ParallelSearchProfilesTest` |
| `parallel/turbo` | Landed | `parallel-turbo` | `ParallelSearchProfilesTest` |
| `parallel/research` | Landed | `parallel-research` | `ParallelResearchDriverTest` |
| `valyu/search` | Landed | `valyu-search` | `ValyuSearchDriverTest` |
| `valyu/research` | Landed | `valyu-research` | `ValyuResearchDriverTest` |
| `serpbase/news` | Landed | `serpbase-news` | `SerpBaseDriverTest` |
| `serpbase/search` | Landed | `serpbase-search` | `SerpBaseDriverTest` |

## How to read each row

Semantics use `result kind; grounding; observation; corpora; retrieval/access;
operator`. Target values preserve model selection, kind, ID, and any underlying
model slot. `all` is omitted from Workflow because it is derived.

Terminal mapping codes:

- **SDK answer:** final SDK text; citations from every SDK step's `Meta`;
  deduplicated sources; declared Profile plus effective SDK provider/model;
  provider must match the declared Profile provider by default, while an explicit
  top-level `'allow_provider_routing' => true` preserves a routed provider;
  accumulated SDK `Usage`; allowlisted request IDs only in `provider_meta`.
- **HTTP search:** normalized search text; result URLs become sources and
  citations; exact Profile provenance and reported usage; no raw response body.
- **HTTP answer:** answer text, URL citations, effective target, reported
  usage/cost, and compact safe identifiers.
- **HTTP report:** private submit/poll/retrieve state; terminal report, sources,
  citations, final provenance, usage/cost, and safe metadata only.
- **Surface snapshot:** HTTP answer mapping plus actual collector, measured
  surface, and disclosed or unknown surface context.

Every error becomes a safe `ResearchError`. Headers, credentials, raw provider
bodies, and private durable handles never enter terminal values. Disabling
optional search projects to truthful `model_answer / none / [] / model_only`
semantics instead of pretending grounded search occurred.

Test and proof codes:

- **SDK fake:** exact provider/model/native tool and terminal mapping through an
  SDK fake; one provider per attempt; no hidden SDK failover.
- **HTTP fake:** `Http::fake()`, exact request/mapping assertions, and
  `Http::preventStrayRequests()`.
- **Durable fake:** HTTP fake plus submit, webhook signal, poll, retrieve,
  duplicate delivery, and timeout tests.
- **Surface fixture:** HTTP fake plus collector/surface provenance and two-stage
  behavior where applicable.
- **D:** recheck official provider documentation before implementation.
- **L:** run one separately authorized paid live canary before release.
- **B:** pass the SearchAPI/Firecrawl fidelity bake-off under #2593.

Pricing statements identify usage semantics only. Landed PHP Profiles resolve
rates, source provenance, and freshness through `laravel-ai-pricing`; this
matrix does not duplicate a rate table. Provider-reported actual totals retain
resolver precedence after execution. SearchAPI and other configured-only
identities remain unavailable until the application supplies a rate. Missing
cost remains `null`, never zero.

## #2591 core SDK: landed 1.0 Profiles (6)

### `gemini-grounded/grounded`

- **Workflow:** quick, visibility
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/direct; google
- **Target:** TypeScript default `gemini-2.5-flash` at the pin; shared default `gemini-3.8-flash`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI Gemini, exact model, native `WebSearch`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** SDK fake; D + L; Wave 1 exemplar under #2833; PHP target refreshed to the installed Laravel AI SDK v0.10.3 default during #2834

### `openrouter/grounded`

- **Workflow:** quick
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/brokered; openrouter
- **Target:** TypeScript default `openai/gpt-4o-mini`; current PHP default `openai/gpt-6-luna`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenRouter, exact model, native `WebSearch`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** SDK fake; D + L

### `openrouter/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/brokered; openrouter
- **Target:** configurable model `openai/gpt-6-luna`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenRouter and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Options:** exact routing by default (`require_parameters: true`, `allow_fallbacks: false`); optional `provider_order`, `data_collection`, `zdr` (only with web search off) and `reasoning_effort` or `reasoning_max_tokens` plus `reasoning_exclude`; reasoning stays at the model default unless configured
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `claude/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; anthropic
- **Target:** configurable model `claude-sonnet-5-5`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI Anthropic and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Options:** shipped explicitly as `effort: high`, `thinking: adaptive` and `max_output_tokens: 64000`; `effort` accepts low through max, `thinking` accepts `adaptive` or `between_tools` (Sonnet 5.5 rejects `disabled`, and `between_tools` only runs at high effort or below)
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `openai-chat/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; openai
- **Target:** TypeScript default `gpt-5-mini`; current PHP default `gpt-6-luna`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenAI and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `gemini-chat/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; google
- **Target:** TypeScript default `gemini-3.6-flash` at the pin; shared default `gemini-3.8-flash`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI Gemini and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** SDK fake for search-on and search-off; D + L

## #2591 core HTTP: landed 1.0 Profiles (14)

### `brave-answers/grounded`

- **Workflow:** quick
- **Semantics:** grounded_answer; required; api_output; web; search_endpoint/direct; brave
- **Target:** fixed model/product target `brave`
- **PHP path:** #2591 core HTTP
- **Binding:** Brave chat-completions answer endpoint; no matching Laravel AI provider tool
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** request/token units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** HTTP fake; D + L

### `exa/search`

- **Workflow:** quick
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; exa
- **Target:** not applicable
- **PHP path:** #2591 core HTTP
- **Binding:** Exa Search API; no Laravel AI text-provider equivalent
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** conservative request units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** HTTP fake; D + L

### `kagi-fastgpt/grounded`

- **Workflow:** quick
- **Semantics:** grounded_answer; required; api_output; web; search_endpoint/direct; kagi
- **Target:** fixed preset `fastgpt`
- **PHP path:** #2591 core HTTP
- **Binding:** Kagi FastGPT endpoint; no Laravel AI provider equivalent
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** conservative request units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** HTTP fake; D + L

### `perplexity-sonar-pro/grounded`

- **Workflow:** visibility
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/direct; perplexity
- **Target:** fixed Agent API preset `low`
- **PHP path:** #2591 core HTTP
- **Binding:** Perplexity Agent API low effort; not an OpenAI-compatible SDK chat call
- **Execution:** inline Agent result
- **Terminal:** HTTP answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** HTTP fake; D + L

### `grok/web`

- **Workflow:** visibility
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/direct; xai
- **Target:** configurable model `grok-4.7` (the pinned TypeScript snapshot shows `grok-4.6`)
- **PHP path:** #2591 core HTTP
- **Binding:** xAI Responses API web-search tool; Laravel AI v0.10.3 text support does not expose this corpus-specific tool
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** HTTP fake with exact web-only tool; D + L; PHP default refreshed to the maintainer-approved Grok 4.6 during #2838

### `openai-research/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; openai
- **Target:** configurable model `gpt-6.1-sol`
- **PHP path:** #2591 core HTTP, focused background Driver under #2836
- **Options:** `reasoning_effort` (none, low, medium, high, xhigh or max; default high), `return_token_budget` (default or unlimited; default default) and an optional positive `max_tool_calls`
- **Binding:** OpenAI Responses API background mode plus web search; SDK queueing is not provider-durable execution
- **Execution:** background/durable; webhook wake hint plus bounded poll and retrieve
- **Terminal:** HTTP report
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** Durable fake with signed duplicate webhook; D + L

### `perplexity-sonar-deep/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; perplexity
- **Target:** fixed Agent API preset `high`
- **PHP path:** #2591 core HTTP; selected as the second 1.0 deep path
- **Binding:** Perplexity Agent API high effort
- **Execution:** background/durable; poll then retrieve; no remote cancellation claim
- **Terminal:** HTTP report
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** Durable fake; D + L

### `searchapi/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/brokered; google
- **Target:** not applicable
- **PHP path:** #2591 core HTTP under #2835
- **Binding:** SearchAPI Google engine with bearer auth; explicit `zero_retention` fails closed when unsupported
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** configured-only request unit; unavailable until the application supplies a rate
- **Test and proof:** HTTP fake for normal and zero-retention denial; D + L

### `searchapi-chatgpt/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; openai via searchapi to chatgpt
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `chatgpt` engine with web search enabled
- **Execution:** inline
- **Terminal:** Surface snapshot; preserve effective model only when reported
- **Pricing:** configured-only request unit; unavailable until the application supplies a rate
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-gemini/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; google via searchapi to gemini
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `gemini` engine
- **Execution:** inline
- **Terminal:** Surface snapshot
- **Pricing:** configured-only request unit; unavailable until the application supplies a rate
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-perplexity/surface`

- **Workflow:** visibility in TypeScript; none in PHP
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; perplexity via searchapi
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** retired; SearchAPI deprecated the `perplexity` engine (HTTP 503 "This API has been deprecated.", observed 2026-10-09) with no replacement
- **Binding:** none; `ProfileCatalog` reserves the ID and rejects it with its retirement reason, including a copy left in a published configuration
- **Execution:** never dispatched
- **Terminal:** validation error before any provider call
- **Pricing:** not applicable
- **Test and proof:** retirement tests in `SearchApiDriverTest`; the C17 live pass on 2026-09-12 predates the deprecation

### `searchapi-google-ai-mode/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; google via searchapi to google_ai_mode
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `google_ai_mode`; consume only AI answer fields, never organic-result fallback content
- **Execution:** inline
- **Terminal:** Surface snapshot
- **Pricing:** configured-only request unit; unavailable until the application supplies a rate
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-bing-copilot/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; microsoft via searchapi to bing_copilot
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `bing_copilot` engine
- **Execution:** inline
- **Terminal:** Surface snapshot
- **Pricing:** configured-only request unit; unavailable until the application supplies a rate
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-google-ai-overview/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; google via searchapi to google_ai_overview
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI Google search; an inline `ai_overview` is read directly, otherwise its page token loads `google_ai_overview`; no organic fallback; redirect citations resolve by URL parsing to the target or the favicon publisher origin
- **Execution:** inline logical operation with one or two bounded HTTP calls
- **Terminal:** Surface snapshot; `searchapi.no_ai_overview` and `searchapi.ai_overview_unparsed` stay distinct
- **Pricing:** two configured-only request units (the worst case); unavailable until the application supplies a rate
- **Test and proof:** Sanitized inline, page-token, no-overview and redirect-link fixtures; D + L + B

## Separate approved tasks (1)

### `firecrawl-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web by default, news only when configured; search_endpoint/direct; firecrawl
- **Target:** not applicable
- **PHP path:** #2592 optional Firecrawl adapter package
- **Binding:** official Firecrawl SDK/API search Driver; keep this dependency out of core
- **Execution:** inline search; Firecrawl research/browser async remains separately designed
- **Terminal:** HTTP search
- **Pricing:** owned by the separate adapter package and consuming account
- **Test and proof:** Adapter fake plus passed paid canary on 2026-09-11; D; B remains pending where used for collection

## #2694 You.com: landed 1.0 Profiles (3)

### `you-research/grounded`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; web; search_endpoint/direct; you-com
- **Target:** provider-managed
- **PHP path:** #2694
- **Binding:** You.com inline Research API
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** conservative request units quoted through `laravel-ai-pricing`
- **Test and proof:** HTTP fake; D + L

### `you-research/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; you-com
- **Target:** provider-managed agent
- **PHP path:** #2694
- **Binding:** You.com background Research API
- **Execution:** background/durable; poll then retrieve; no remote cancellation claim
- **Terminal:** HTTP report
- **Pricing:** conservative request units quoted through `laravel-ai-pricing`
- **Test and proof:** Durable fake; D + L

### `you-answer/grounded`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; web; search_endpoint/direct; you-com
- **Target:** provider-managed
- **PHP path:** #2694
- **Binding:** You.com Answer API; distinct from both Research Profiles
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** conservative request units quoted through `laravel-ai-pricing`
- **Test and proof:** HTTP fake; D + L

## #3604 parity additions: landed core Profiles (15)

These Profiles complete the accepted TypeScript v2 inventory in core. Their
provider-local implementations retain distinct endpoint, lifecycle, pricing,
and provenance semantics.

### `brave-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; brave
- **Target:** not applicable
- **PHP path:** #3604 core parity; provider implementation #2841
- **Binding:** Brave Web Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** fixed request units quoted through `laravel-ai-pricing`
- **Test and proof:** HTTP fake; D + L

### `exa/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; exa
- **Target:** provider-managed agent
- **PHP path:** #3604 core parity; provider implementation #2842
- **Binding:** Exa Agent runs API
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** actual cost is provider-reported; no preview quote is invented without preflight compute/search quantities
- **Test and proof:** Durable fake with ambiguous-submit no-retry; D + L

### `tavily/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; tavily
- **Target:** not applicable
- **PHP path:** #3604 core parity; provider implementation #3605
- **Binding:** Tavily Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** configured-only credit rate; advanced default supplies two preflight credit units
- **Test and proof:** HTTP fake; D + L

### `gemini-deep/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; google
- **Target:** configurable agent `deep-research-preview-04-2026`
- **PHP path:** #3604 core parity; provider implementation #3608
- **Binding:** Gemini Interactions background agent; Laravel AI v0.10.3 has no provider-durable research abstraction
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** configured-only; no request-level price is invented
- **Test and proof:** Durable fake with ambiguous-submit no-retry; D + L

### `perplexity-deep-research/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; perplexity
- **Target:** fixed preset `medium` with provider-managed underlying model
- **PHP path:** #3604 core parity; provider implementation #3609
- **Binding:** Perplexity Agent API medium effort
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** stable token/tool rates provide a partial estimate, never a hard ceiling
- **Test and proof:** Durable fake with ambiguous-submit no-retry; D + L

### `perplexity-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; perplexity
- **Target:** not applicable
- **PHP path:** #3604 core parity; provider implementation #3609
- **Binding:** Perplexity Search API, not Agent or chat completion
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** fixed successful-request units quoted through `laravel-ai-pricing`
- **Test and proof:** HTTP fake; D + L

### `grok-x-only/x`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; x; model_search_tool/direct; xai
- **Target:** configurable model `grok-4.7` (the pinned TypeScript snapshot shows `grok-4.6`)
- **PHP path:** #3604 core parity; provider implementation #2843
- **Binding:** xAI Responses API with X-only search tool
- **Execution:** inline
- **Terminal:** HTTP answer; `x_post` only when provider facts support it
- **Pricing:** unavailable since the September 21, 2026 X Search change (billed per fetched post/profile, unbounded by any request parameter); the Profile quotes unavailable and preflight fails closed
- **Test and proof:** exact X-only HTTP fake; D + L

### `grok-combined/combined`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; web+x; model_search_tool/direct; xai
- **Target:** configurable model `grok-4.7` (the pinned TypeScript snapshot shows `grok-4.6`)
- **PHP path:** #3604 core parity; provider implementation #2843
- **Binding:** one xAI Responses attempt with both web and X tools
- **Execution:** inline; one origin, never two independent confirmations
- **Terminal:** HTTP answer with per-citation source kinds when supported
- **Pricing:** unavailable since the September 21, 2026 X Search change (billed per fetched post/profile, unbounded by any request parameter); the Profile quotes unavailable and preflight fails closed
- **Test and proof:** combined-tool HTTP fake proving one request and one origin; D + L

### `jina-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; jina
- **Target:** not applicable
- **PHP path:** #3604 core parity; provider implementation #3606
- **Binding:** Jina Search Foundation; Laravel AI Jina support is embeddings/reranking, not this API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** unavailable; provider documents fixed token consumption but no response-level USD or token quantity
- **Test and proof:** HTTP fake; D + L

### `serpapi/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/brokered; google
- **Target:** not applicable
- **PHP path:** #3604 core parity; provider implementation #3607
- **Binding:** SerpAPI Google engine
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** configured-only successful-search rate
- **Test and proof:** HTTP fake; D + L

### `parallel/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; parallel
- **Target:** not applicable
- **PHP path:** #3604 core parity; provider implementation #2844
- **Binding:** Parallel Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** unavailable before execution because provider-managed mode determines the charge
- **Test and proof:** HTTP fake; D + L

### `parallel/turbo`

- **Workflow:** quick
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; parallel
- **Target:** not applicable
- **PHP path:** #3604 core parity; provider implementation #2844
- **Binding:** Parallel Search API with mode fixed to `turbo`
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** fixed request plus additional-result units quoted through `laravel-ai-pricing`
- **Test and proof:** exact Turbo HTTP fake; D + L

### `parallel/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; parallel
- **Target:** configurable preset `pro`
- **PHP path:** #3604 core parity; provider implementation #2844
- **Binding:** Parallel task-run submit, poll, and result APIs
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** fixed successful processor-request units quoted through `laravel-ai-pricing`
- **Test and proof:** Durable fake with ambiguous-submit no-retry; D + L

### `valyu/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web+specialized; search_endpoint/direct; valyu
- **Target:** not applicable
- **PHP path:** #3604 core parity; provider implementation #2845
- **Binding:** Valyu Search API
- **Execution:** inline
- **Terminal:** HTTP search; specialized categories are metadata, not independent evidence
- **Pricing:** unavailable before execution because charges depend on selected source types
- **Test and proof:** HTTP fake; D + L

### `valyu/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web+specialized; research_agent/direct; valyu
- **Target:** configurable preset `standard`
- **PHP path:** #3604 core parity; provider implementation #2845
- **Binding:** Valyu deep-research tasks API
- **Execution:** background/durable; only accepted Profile advertising exact-profile remote cancellation
- **Terminal:** HTTP report
- **Pricing:** standard base plus explicit optional units quoted through `laravel-ai-pricing`
- **Test and proof:** Durable fake with private best-effort cancellation and ambiguous-submit no-retry; D + L

## #3650 SerpBase: landed parity Profiles (2)

PHP shipped these first as provider-local extensions. The JavaScript
counterparts merged in [librarium #172](https://github.com/jkudish/librarium/pull/172),
so they are now part of the shared inventory. See
[`serpbase-profiles.md`](serpbase-profiles.md) for request, limit, and privacy
details.

### `serpbase/news`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; news; search_endpoint/direct; serpbase
- **Target:** not applicable
- **PHP path:** #3650 core parity
- **Binding:** one SerpBase Google News POST, one page, no retry or pagination
- **Execution:** inline
- **Terminal:** HTTP search; relative news dates stay unstructured text
- **Pricing:** account-configured credit rate; unavailable until the application supplies it
- **Test and proof:** HTTP fake; D + L

### `serpbase/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; serpbase
- **Target:** not applicable
- **PHP path:** #3650 core parity
- **Binding:** one SerpBase Google Search POST, one page, no retry or pagination; opt-in bounded rich-result text receives no inferred citations
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** account-configured credit rate; unavailable until the application supplies it
- **Test and proof:** HTTP fake; D + L

## Planned or non-selectable declarations

None exist at the accepted TypeScript commit. Future declarations stay outside
this inventory until they have a real selectable binding.

## Locked boundaries

- Firecrawl remains #2592.
- You.com remains #2694.
- The five shipped SearchAPI surface Profiles are owned by #2591, but cross-collector
  fallback stays disabled until exact measured-surface identity lands in #2830.
- Provider network calls and paid calls require the independent approval gate
  documented in `live-provider-canaries.md`; this reconciliation made none.
- Persistence, conversation storage, and TypeScript runtime internals remain
  outside the core package. Pricing catalogs are owned by
  `laravel-ai-pricing`, not duplicated here.
