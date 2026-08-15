# Profile binding matrix

This is the Wave 0 implementation authority for PlanMode #2814 and the review
checkpoint before provider code begins under #2591. It targets best-effort
parity. It is not a promise that PHP and TypeScript will remain in permanent
lockstep.

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

| Classification | Count | Meaning |
| --- | ---: | --- |
| #2591 core SDK, proposed for 1.0 | 6 | Reuse the Laravel AI SDK text providers, native `WebSearch`, response metadata, citations, usage, and fakes. |
| #2591 core HTTP, proposed for 1.0 | 14 | Small provider-specific HTTP Drivers with no substantial third-party SDK. |
| #2592 Firecrawl adapter | 1 | Keep the optional Firecrawl package outside core. |
| #2694 You.com | 3 | Keep this provider family in its existing task. |
| Explicit post-1.0 holdback | 16 | Technically viable, but not required for the approved first useful release. |
| **Total** | **40** | Every implemented TypeScript Profile appears exactly once below. |

The inventory contains 15 grounded answers, 10 search-result Profiles, 9
durable research reports, and 6 surface observations. Runtime inventory is 31
inline Profiles and 9 provider-durable background Profiles. `quick` has five
curated members, `visibility` has nine, `deep` derives nine research Profiles,
and `all` derives all 40 eligible Profiles.

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

Pricing is descriptive. `native tokens` and `API units` do not imply known USD
cost. Missing provider-reported cost remains `null`, never zero.

## #2591 core SDK: proposed 1.0 Profiles (6)

### `gemini-grounded/grounded`

- **Workflow:** quick, visibility
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/direct; google
- **Target:** configurable model `gemini-2.5-flash`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI Gemini, exact model, native `WebSearch`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** native tokens; USD unknown unless authoritative pricing is supplied
- **Test and proof:** SDK fake; D + L; Wave 1 exemplar under #2833

### `openrouter/grounded`

- **Workflow:** quick
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/brokered; openrouter
- **Target:** configurable model `openai/gpt-4o-mini`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenRouter, exact model, native `WebSearch`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** provider-native cost when reported; otherwise null
- **Test and proof:** SDK fake; D + L

### `openrouter/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/brokered; openrouter
- **Target:** configurable model `openai/gpt-5.6-terra`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenRouter and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** provider-native cost when reported; otherwise null
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `claude/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; anthropic
- **Target:** configurable model `claude-sonnet-5`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI Anthropic and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** native tokens; USD unknown unless authoritative pricing is supplied
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `openai-chat/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; openai
- **Target:** configurable model `gpt-5-mini`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI OpenAI and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** native tokens; USD unknown unless authoritative pricing is supplied
- **Test and proof:** SDK fake for search-on and search-off; D + L

### `gemini-chat/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; google
- **Target:** configurable model `gemini-3.6-flash`
- **PHP path:** #2591 core SDK
- **Binding:** Laravel AI Gemini and native `WebSearch`, on by default; search-off becomes `model_answer`
- **Execution:** inline; no provider-durable handle
- **Terminal:** SDK answer
- **Pricing:** native tokens; USD unknown unless authoritative pricing is supplied
- **Test and proof:** SDK fake for search-on and search-off; D + L

## #2591 core HTTP: proposed 1.0 Profiles (14)

### `brave-answers/grounded`

- **Workflow:** quick
- **Semantics:** grounded_answer; required; api_output; web; search_endpoint/direct; brave
- **Target:** fixed model/product target `brave`
- **PHP path:** #2591 core HTTP
- **Binding:** Brave chat-completions answer endpoint; no matching Laravel AI provider tool
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** API-unit priced by search plus tokens; exact USD unknown
- **Test and proof:** HTTP fake; D + L

### `exa/search`

- **Workflow:** quick
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; exa
- **Target:** not applicable
- **PHP path:** #2591 core HTTP
- **Binding:** Exa Search API; no Laravel AI text-provider equivalent
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** provider-native cost when reported; otherwise null
- **Test and proof:** HTTP fake; D + L

### `kagi-fastgpt/grounded`

- **Workflow:** quick
- **Semantics:** grounded_answer; required; api_output; web; search_endpoint/direct; kagi
- **Target:** fixed preset `fastgpt`
- **PHP path:** #2591 core HTTP
- **Binding:** Kagi FastGPT endpoint; no Laravel AI provider equivalent
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** estimated $0.015 per request; retain reported cost when available
- **Test and proof:** HTTP fake; D + L

### `perplexity-sonar-pro/grounded`

- **Workflow:** visibility
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/direct; perplexity
- **Target:** fixed Agent API preset `low`
- **PHP path:** #2591 core HTTP
- **Binding:** Perplexity Agent API low effort; not an OpenAI-compatible SDK chat call
- **Execution:** inline Agent result
- **Terminal:** HTTP answer
- **Pricing:** provider-native cost when reported; otherwise null
- **Test and proof:** HTTP fake; D + L

### `grok/web`

- **Workflow:** visibility
- **Semantics:** grounded_answer; required; api_output; web; model_search_tool/direct; xai
- **Target:** configurable model `grok-4.5`
- **PHP path:** #2591 core HTTP
- **Binding:** xAI Responses API web-search tool; Laravel AI v0.10.3 text support does not expose this corpus-specific tool
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** native tokens plus estimated $0.015 tool charge; reported cost wins
- **Test and proof:** HTTP fake with exact web-only tool; D + L

### `openai-research/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; openai
- **Target:** configurable model `gpt-5.6-sol`
- **PHP path:** #2591 core HTTP, focused background Driver under #2836
- **Binding:** OpenAI Responses API background mode plus web search; SDK queueing is not provider-durable execution
- **Execution:** background/durable; webhook wake hint plus bounded poll and retrieve
- **Terminal:** HTTP report
- **Pricing:** native tokens; USD unknown unless authoritative pricing is supplied
- **Test and proof:** Durable fake with signed duplicate webhook; D + L

### `perplexity-sonar-deep/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; perplexity
- **Target:** fixed Agent API preset `high`
- **PHP path:** #2591 core HTTP; selected as the second 1.0 deep path
- **Binding:** Perplexity Agent API high effort
- **Execution:** background/durable; poll then retrieve; no remote cancellation claim
- **Terminal:** HTTP report
- **Pricing:** provider-native cost when reported; otherwise null
- **Test and proof:** Durable fake; D + L

### `searchapi/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/brokered; google
- **Target:** not applicable
- **PHP path:** #2591 core HTTP under #2835
- **Binding:** SearchAPI Google engine with bearer auth; explicit `zero_retention` fails closed when unsupported
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** estimated $0.004 per request; retain reported cost when available
- **Test and proof:** HTTP fake for normal and zero-retention denial; D + L

### `searchapi-chatgpt/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; openai via searchapi to chatgpt
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `chatgpt` engine with web search enabled
- **Execution:** inline
- **Terminal:** Surface snapshot; preserve effective model only when reported
- **Pricing:** estimated $0.004 per request
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-gemini/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; google via searchapi to gemini
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `gemini` engine
- **Execution:** inline
- **Terminal:** Surface snapshot
- **Pricing:** estimated $0.004 per request
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-perplexity/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; perplexity via searchapi
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `perplexity` engine
- **Execution:** inline
- **Terminal:** Surface snapshot
- **Pricing:** estimated $0.004 per request
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-google-ai-mode/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; google via searchapi to google_ai_mode
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `google_ai_mode`; consume only AI answer fields, never organic-result fallback content
- **Execution:** inline
- **Terminal:** Surface snapshot
- **Pricing:** estimated $0.004 per request
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-bing-copilot/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; microsoft via searchapi to bing_copilot
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI `bing_copilot` engine
- **Execution:** inline
- **Terminal:** Surface snapshot
- **Pricing:** estimated $0.004 per request
- **Test and proof:** Surface fixture; D + L + B

### `searchapi-google-ai-overview/surface`

- **Workflow:** visibility
- **Semantics:** surface_observation; optional; surface_snapshot; web; surface_collector/collected; google via searchapi to google_ai_overview
- **Target:** provider-managed and otherwise unaudited
- **PHP path:** #2591 core HTTP under #2835; exact fallback identity remains #2830
- **Binding:** SearchAPI two-stage Google page-token then `google_ai_overview`; no stage-one organic fallback
- **Execution:** inline logical operation with two bounded HTTP calls
- **Terminal:** Surface snapshot
- **Pricing:** two estimated $0.004 request units
- **Test and proof:** Two-stage surface fixture with missing-token failure; D + L + B

## Separate approved tasks (4)

### `firecrawl-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web by default, news only when configured; search_endpoint/direct; firecrawl
- **Target:** not applicable
- **PHP path:** #2592 optional Firecrawl adapter package
- **Binding:** official Firecrawl SDK/API search Driver; keep this dependency out of core
- **Execution:** inline search; Firecrawl research/browser async remains separately designed
- **Terminal:** HTTP search
- **Pricing:** one credit by default; USD is account-specific
- **Test and proof:** Adapter fake plus shipped-adapter paid query; D + L; B where used for collection

### `you-research/grounded`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; web; search_endpoint/direct; you-com
- **Target:** provider-managed
- **PHP path:** #2694
- **Binding:** You.com inline Research API
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** one query credit; USD is account-specific
- **Test and proof:** HTTP fake; D + L

### `you-research/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; you-com
- **Target:** provider-managed agent
- **PHP path:** #2694
- **Binding:** You.com background Research API
- **Execution:** background/durable; poll then retrieve; no remote cancellation claim
- **Terminal:** HTTP report
- **Pricing:** manual/unmetered in the accepted catalog; unknown stays null
- **Test and proof:** Durable fake; D + L

### `you-answer/grounded`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; web; search_endpoint/direct; you-com
- **Target:** provider-managed
- **PHP path:** #2694
- **Binding:** You.com Answer API; distinct from both Research Profiles
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** estimated $0.005 per request
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
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Brave Web Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** estimated $0.005 per request
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Exa already covers raw discovery in quick.

### `exa/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; exa
- **Target:** provider-managed agent
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Exa Agent runs API
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** provider-native cost when reported
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** OpenAI and Perplexity provide the first two deep paths.

### `tavily/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; tavily
- **Target:** not applicable
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Tavily Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** two credits by default; USD is account-specific
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Not required by quick and duplicates the initial search lane.

### `tavily/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; tavily
- **Target:** provider-managed agent
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Tavily Research API
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** credits reported when available; USD is account-specific
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** Defer another durable path until the first two prove the runtime.

### `gemini-deep/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; google
- **Target:** configurable agent `deep-research-preview-04-2026`
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Gemini Interactions background agent; Laravel AI v0.10.3 has no provider-durable research abstraction
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** native tokens plus descriptive $3 task estimate; reported cost wins
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** Preview agent and separate protocol need focused post-1.0 proof.

### `perplexity-deep-research/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; perplexity
- **Target:** fixed preset `medium` with provider-managed underlying model
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Perplexity Agent API medium effort
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** provider-native cost when reported
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** High effort proves the Perplexity Agent family for 1.0.

### `perplexity-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; perplexity
- **Target:** not applicable
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Perplexity Search API, not Agent or chat completion
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** one request unit; exact USD unknown
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Not required by quick and adds another raw-search shape.

### `grok-x-only/x`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; x; model_search_tool/direct; xai
- **Target:** configurable model `grok-4.5`
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** xAI Responses API with X-only search tool
- **Execution:** inline
- **Terminal:** HTTP answer; `x_post` only when provider facts support it
- **Pricing:** native tokens plus estimated $0.015 tool charge
- **Test and proof:** Holdback guard; later exact X-only HTTP fake, D + L
- **Holdback reason:** `grok/web` supplies the 1.0 visibility baseline.

### `grok-combined/combined`

- **Workflow:** none
- **Semantics:** grounded_answer; required; api_output; web+x; model_search_tool/direct; xai
- **Target:** configurable model `grok-4.5`
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** one xAI Responses attempt with both web and X tools
- **Execution:** inline; one origin, never two independent confirmations
- **Terminal:** HTTP answer with per-citation source kinds when supported
- **Pricing:** native tokens plus estimated $0.025 tool charge
- **Test and proof:** Holdback guard; later combined-tool HTTP fake, D + L
- **Holdback reason:** Defer two-corpus provenance until each basic lane is stable.

### `jina-search/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; jina
- **Target:** not applicable
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Jina Search Foundation; Laravel AI Jina support is embeddings/reranking, not this API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** token/API-unit priced; exact USD unknown
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** No SDK reuse and no required 1.0 workflow membership.

### `serpapi/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/brokered; google
- **Target:** not applicable
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** SerpAPI Google engine
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** estimated $0.015 per request
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** SearchAPI already supplies brokered Google search.

### `parallel/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web; search_endpoint/direct; parallel
- **Target:** not applicable
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Parallel Search API
- **Execution:** inline
- **Terminal:** HTTP search
- **Pricing:** API-unit priced; exact USD unknown
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Defer the three-profile family and shared options together.

### `parallel/chat`

- **Workflow:** none
- **Semantics:** grounded_answer; optional; api_output; web; model_search_tool/direct; parallel
- **Target:** configurable processor/model `base`
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Parallel chat-completions with web retrieval and structured output
- **Execution:** inline
- **Terminal:** HTTP answer
- **Pricing:** API-unit priced; exact USD unknown
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Not required by quick and belongs with the deferred family.

### `parallel/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web; research_agent/direct; parallel
- **Target:** configurable preset `pro`
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Parallel task-run submit, poll, and result APIs
- **Execution:** background/durable; poll then retrieve
- **Terminal:** HTTP report
- **Pricing:** processor-request units; exact USD unknown
- **Test and proof:** Holdback guard; later Durable fake, D + L
- **Holdback reason:** Defer another durable family until 1.0 dogfooding.

### `valyu/search`

- **Workflow:** none
- **Semantics:** search_results; none; api_output; web+specialized; search_endpoint/direct; valyu
- **Target:** not applicable
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Valyu Search API
- **Execution:** inline
- **Terminal:** HTTP search; specialized categories are metadata, not independent evidence
- **Pricing:** provider-native cost when reported
- **Test and proof:** Holdback guard; later HTTP fake, D + L
- **Holdback reason:** Specialized sources are valuable but not required for 1.0.

### `valyu/research`

- **Workflow:** deep
- **Semantics:** research_report; required; api_output; web+specialized; research_agent/direct; valyu
- **Target:** configurable preset `standard`
- **PHP path:** explicit holdback; future #2591 core HTTP
- **Binding:** Valyu deep-research tasks API
- **Execution:** background/durable; only accepted Profile advertising exact-profile remote cancellation
- **Terminal:** HTTP report
- **Pricing:** provider-native cost when reported
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
- Provider implementation, provider network calls, paid calls, persistence,
  conversation storage, pricing catalogs, and TypeScript runtime internals are
  outside Wave 0.
- Joey's matrix checkpoint is required before #2833 or any later provider wave.
