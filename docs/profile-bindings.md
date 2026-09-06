# Profile binding matrix

This began as the Wave 0 implementation authority for PlanMode #2814. It now
records the Wave 4 closeout and subsequent landed Profile work. It targets
best-effort parity. It is not a promise that PHP and TypeScript will remain in
permanent lockstep.

No row authorizes a provider request or a paid call.

## Authority and inventory

- TypeScript catalog: [`jkudish/librarium` at `5ec53f2517ffab4db2e4446d458f168f0388879b`](https://github.com/jkudish/librarium/tree/5ec53f2517ffab4db2e4446d458f168f0388879b)
- Catalog source: `src/core/provider-profiles.ts` and its exact profile bindings
- Implemented TypeScript inventory: **33 providers and 40 Profiles**
- Planned or non-selectable catalog declarations: **0**
- Laravel AI SDK: [`laravel/ai` v0.10.3 at `c3848aae389f45c605eefb0dda5bd5fa7df76eaa`](https://github.com/laravel/ai/tree/c3848aae389f45c605eefb0dda5bd5fa7df76eaa)
- SDK evidence: exact package source plus the [Laravel 13 AI SDK documentation](https://laravel.com/docs/13.x/ai-sdk)

The one-off source comparison used the TypeScript catalog itself and returned
40 unique implemented keys with no missing or extra matrix row. The committed
Pest test locks that result without introducing a TypeScript runtime dependency.

## Summary

| PHP disposition | Count | Meaning |
| --- | ---: | --- |
| Landed in core | 23 | Six Laravel AI SDK Profiles and seventeen focused HTTP Profiles are configured and covered offline. |
| Separate approved task | 1 | Firecrawl remains outside core under #2592 and is not registered by this package. |
| Explicit post-1.0 holdback | 16 | Technically viable parity targets that are not registered by this package. |
| **Total** | **40** | Every implemented TypeScript Profile appears exactly once below. |

The TypeScript inventory contains 15 grounded answers, 10 search-result
Profiles, 9 durable research reports, and 6 surface observations. Its landed
PHP subset contains 12 grounded answers, 2 search-result Profiles, 3 durable
research reports, and 6 surface observations. PHP additionally ships six
provider-local DataForSEO surface Profiles approved under #3481; they extend
the PHP catalog without rewriting this frozen TypeScript reconciliation.
`quick` has five curated members, `visibility` has nine, `deep` derives its
three configured research Profiles, and `all` derives all 29 configured
Profiles.

## Provider-local PHP extension

The following disabled-by-default Profiles implement DataForSEO collection
modes that are not rows in the frozen TypeScript inventory above:

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
offline provider-boundary coverage. “Separate task” and “Holdback” mean the
TypeScript Profile remains intentionally absent from PHP configuration. Live
proof is not claimed here; the separately gated canary inventory is in
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
| `searchapi-perplexity/surface` | Landed | `searchapi-perplexity` | `SearchApiDriverTest` |
| `searchapi-google-ai-mode/surface` | Landed | `searchapi-google-ai-mode` | `SearchApiDriverTest` |
| `searchapi-bing-copilot/surface` | Landed | `searchapi-bing-copilot` | `SearchApiDriverTest` |
| `searchapi-google-ai-overview/surface` | Landed | `searchapi-google-ai-overview` | `SearchApiDriverTest` |
| `firecrawl-search/search` | Separate task | #2592 adapter package | Not registered in core |
| `you-research/grounded` | Landed | `you-research-grounded` | `YouResearchGroundedDriverTest` |
| `you-research/research` | Landed | `you-research-research` | `YouResearchDriverTest` |
| `you-answer/grounded` | Landed | `you-answer` | `YouAnswerDriverTest` |
| `brave-search/search` | Holdback | #2841 | Not registered in core |
| `exa/research` | Holdback | #2842 | Not registered in core |
| `tavily/search` | Holdback | #2841 | Not registered in core |
| `tavily/research` | Holdback | #2842 | Not registered in core |
| `gemini-deep/research` | Holdback | #2842 | Not registered in core |
| `perplexity-deep-research/research` | Holdback | #2842 | Not registered in core |
| `perplexity-search/search` | Holdback | #2841 | Not registered in core |
| `grok-x-only/x` | Holdback | #2843 | Not registered in core |
| `grok-combined/combined` | Holdback | #2843 | Not registered in core |
| `jina-search/search` | Holdback | #2841 | Not registered in core |
| `serpapi/search` | Holdback | #2841 | Not registered in core |
| `parallel/search` | Holdback | #2844 | Not registered in core |
| `parallel/chat` | Holdback | #2844 | Not registered in core |
| `parallel/research` | Holdback | #2844 | Not registered in core |
| `valyu/search` | Holdback | #2845 | Not registered in core |
| `valyu/research` | Holdback | #2845 | Not registered in core |

## How to read each row

Semantics use `result kind; grounding; observation; corpora; retrieval/access;
operator`. Target values preserve model selection, kind, ID, and any underlying
model slot. `all` is omitted from Workflow because it is derived.

Terminal mapping codes:

- **SDK answer:** final SDK text; citations from every SDK step's `Meta`;
  deduplicated sources; declared Profile plus effective SDK provider/model;
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
- **Holdback guard:** documented but not registered as available.
- **D:** recheck official provider documentation before implementation.
- **L:** run one separately authorized paid live canary before release.
- **B:** pass the SearchAPI/Firecrawl fidelity bake-off under #2593.

Pricing statements identify usage semantics only. Landed PHP Profiles resolve
rates, source provenance, and freshness through `laravel-ai-pricing`; this
matrix does not duplicate a rate table. Provider-reported actual totals retain
resolver precedence after execution. SearchAPI is configured-only and remains
unavailable until the application supplies its rate. Unregistered holdbacks do
not receive preview quotes. Missing cost remains `null`, never zero.

## #2591 core SDK: landed 1.0 Profiles (6)

### `gemini-grounded/grounded`

- **Workflow:** quick, visibility
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/direct; google
- **Target:** configurable model `gemini-3.6-flash`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI Gemini, exact model, native `WebSearch`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** SDK fake; D + L; Wave 1 exemplar under #2833; target refreshed to the installed Laravel AI SDK v0.10.3 default during #2834

### `openrouter/grounded`

- **Workflow:** quick
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/brokered; openrouter
- **Target:** configurable model `openai/gpt-5.6-luna`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenRouter, exact model, native `WebSearch`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** SDK fake; D + L

### `openrouter/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/brokered; openrouter
- **Target:** configurable model `openai/gpt-5.6-terra`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenRouter and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `claude/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; anthropic
- **Target:** configurable model `claude-sonnet-5`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI Anthropic and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `openai-chat/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; openai
- **Target:** configurable model `gpt-5.6-luna`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenAI and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; assumptions are not a hard bound
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `gemini-chat/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; google
- **Target:** configurable model `gemini-3.7-flash`
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
- **Target:** configurable model `grok-4.5` in the accepted TypeScript snapshot; approved PHP 1.0 default `grok-4.6`
- **PHP path:** #2591 core HTTP
- **Binding:** xAI Responses API web-search tool; Laravel AI v0.10.3 text support does not expose this corpus-specific tool
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** model token/tool units quoted through `laravel-ai-pricing`; reported actual cost wins
- **Test and proof:** HTTP fake with exact web-only tool; D + L; PHP default refreshed to the maintainer-approved Grok 4.6 during #2838

### `openai-research/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; openai
- **Target:** configurable model `gpt-5.6-sol`
- **PHP path:** #2591 core HTTP, focused background Driver under #2836
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

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; perplexity via searchapi
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `perplexity` engine
- **Execution:** inline
- **Terminal:** Surface snapshot
- **Pricing:** configured-only request unit; unavailable until the application supplies a rate
- **Test and proof:** Surface fixture; D + L + B

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
- **Binding:** SearchAPI two-stage Google page-token then `google_ai_overview`; no stage-one organic fallback
- **Execution:** inline logical operation with two bounded HTTP calls
- **Terminal:** Surface snapshot
- **Pricing:** two configured-only request units; unavailable until the application supplies a rate
- **Test and proof:** Two-stage surface fixture with missing-token failure; D + L + B

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
- **Test and proof:** Adapter fake plus shipped-adapter paid query; D + L; B where used for collection

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

## Explicit post-1.0 holdbacks (16)

These are legitimate parity targets. They are held back only to keep 1.0
focused on the complete quick workflow, SearchAPI visibility, primary SDK
paths, OpenAI background research, and one additional deep provider. A
maintainer checkpoint may move a row into #2591 later.

### `brave-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; brave
- **Target:** not applicable
- **PHP path:** explicit holdback; #2841
- **Binding:** Brave Web Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Exa already covers raw discovery in quick.

### `exa/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; exa
- **Target:** provider-managed agent
- **PHP path:** explicit holdback; #2842
- **Binding:** Exa Agent runs API
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** OpenAI and Perplexity provide the first two deep paths.

### `tavily/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; tavily
- **Target:** not applicable
- **PHP path:** explicit holdback; #2841
- **Binding:** Tavily Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Not required by quick and duplicates the initial search lane.

### `tavily/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; tavily
- **Target:** provider-managed agent
- **PHP path:** explicit holdback; #2842
- **Binding:** Tavily Research API
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** Defer another durable path until the first two prove the runtime.

### `gemini-deep/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; google
- **Target:** configurable agent `deep-research-preview-04-2026`
- **PHP path:** explicit holdback; #2842
- **Binding:** Gemini Interactions background agent; Laravel AI v0.10.3 has no provider-durable research abstraction
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** Preview agent and separate protocol need focused post-1.0 proof.

### `perplexity-deep-research/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; perplexity
- **Target:** fixed preset `medium` with provider-managed underlying model
- **PHP path:** explicit holdback; #2842
- **Binding:** Perplexity Agent API medium effort
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** High effort proves the Perplexity Agent family for 1.0.

### `perplexity-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; perplexity
- **Target:** not applicable
- **PHP path:** explicit holdback; #2841
- **Binding:** Perplexity Search API, not Agent or chat completion
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Not required by quick and adds another raw-search shape.

### `grok-x-only/x`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; x; model_search_tool/direct; xai
- **Target:** configurable model `grok-4.5`
- **PHP path:** explicit holdback; #2843
- **Binding:** xAI Responses API with X-only search tool
- **Execution:** inline
- **Terminal:** HTTP answer; `x_post` only when provider facts support it
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later exact X-only HTTP fake, D + L
- **Holdback reason:** `grok/web` supplies the 1.0 visibility baseline.

### `grok-combined/combined`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; web+x; model_search_tool/direct; xai
- **Target:** configurable model `grok-4.5`
- **PHP path:** explicit holdback; #2843
- **Binding:** one xAI Responses attempt with both web and X tools
- **Execution:** inline; one origin, never two independent confirmations
- **Terminal:** HTTP answer with per-citation source kinds when supported
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later combined-tool HTTP fake, D + L
- **Holdback reason:** Defer two-corpus provenance until each basic lane is stable.

### `jina-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; jina
- **Target:** not applicable
- **PHP path:** explicit holdback; #2841
- **Binding:** Jina Search Foundation; Laravel AI Jina support is embeddings/reranking, not this API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** No SDK reuse and no required 1.0 workflow membership.

### `serpapi/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/brokered; google
- **Target:** not applicable
- **PHP path:** explicit holdback; #2841
- **Binding:** SerpAPI Google engine
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** SearchAPI already supplies brokered Google search.

### `parallel/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; parallel
- **Target:** not applicable
- **PHP path:** explicit holdback; #2844
- **Binding:** Parallel Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Defer the three-profile family and shared options together.

### `parallel/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; parallel
- **Target:** configurable processor/model `base`
- **PHP path:** explicit holdback; #2844
- **Binding:** Parallel chat-completions with web retrieval and structured output
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Not required by quick and belongs with the deferred family.

### `parallel/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; parallel
- **Target:** configurable preset `pro`
- **PHP path:** explicit holdback; #2844
- **Binding:** Parallel task-run submit, poll, and result APIs
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** Defer another durable family until 1.0 dogfooding.

### `valyu/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web+specialized; search_endpoint/direct; valyu
- **Target:** not applicable
- **PHP path:** explicit holdback; #2845
- **Binding:** Valyu Search API
- **Execution:** inline
- **Terminal:** HTTP search; specialized categories are metadata, not independent evidence
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Specialized sources are valuable but not required for 1.0.

### `valyu/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web+specialized; research_agent/direct; valyu
- **Target:** configurable preset `standard`
- **PHP path:** explicit holdback; #2845
- **Binding:** Valyu deep-research tasks API
- **Execution:** background/durable; only accepted Profile advertising exact-profile remote cancellation
- **Terminal:** HTTP report
- **Pricing:** not resolved while the Profile remains unregistered
- **Test and proof:** Holdback guard; later Durable fake with cancellation, D + L
- **Holdback reason:** Cancellation and specialized provenance deserve focused work.

## Planned or non-selectable declarations

None exist at the accepted TypeScript commit. Future declarations stay outside
this inventory until they have a real selectable binding.

## Locked boundaries

- Firecrawl remains #2592.
- You.com remains #2694.
- The six SearchAPI surface Profiles are owned by #2591, but cross-collector
  fallback stays disabled until exact measured-surface identity lands in #2830.
- Provider network calls and paid calls require the independent approval gate
  documented in `live-provider-canaries.md`; this reconciliation made none.
- Persistence, conversation storage, and TypeScript runtime internals remain
  outside the core package. Pricing catalogs are owned by
  `laravel-ai-pricing`, not duplicated here.
