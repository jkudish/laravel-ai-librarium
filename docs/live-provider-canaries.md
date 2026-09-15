# Live provider canary approval gates

This is the inventory and operating policy for materially unique live checks
for 47 core Profiles plus the optional Firecrawl Search adapter Profile. The
ordinary test suite remains network-free; every live execution still requires
an explicit approval bound to its exact candidate, scope, and spend ceiling.

## Approval contract

Each canary ID below is an independent approval gate unless an approval names a
defined tier below. A tier approval covers only its listed IDs, exact repository
heads, fixed queries and models, per-canary reservations, aggregate ceiling,
and one sequential attempt per canary. It never authorizes a retry or a changed
model, query, timeout, or maximum-cost threshold. Before dispatch, record:

1. the exact commit or release candidate under test;
2. the canary ID, Profile, query, model or provider-managed target, and timeout;
3. the credential-owning account and a hard per-call and aggregate spend cap;
4. for an individual non-tier canary, whether one retry is authorized and what
   failure permits it; tier approvals always record one attempt and no retry; and
5. the terminal receipt location with secrets and raw provider payloads removed.

Unknown cost is not zero. A canary with unknown or variable pricing must not run
until the approver supplies a dollar ceiling that the operator can enforce.
Librarium enables its `--max-cost` preflight check only when
`laravel-ai-pricing` returns a complete, current, conservative, single-currency
quote for the full reachable plan. That check does not cap provider charges;
every canary still needs an independently enforced provider/account ceiling.
The ordinary suite remains network-free. Existing live tests additionally
require `LIBRARIUM_LIVE_TESTS=1`; the environment flag alone is not approval.

## Provider-parity cadence and reservation ledger

Run the cheap Search tier manually once per calendar month. Run the full parity
tier before a release and after a material provider API, authentication,
transport, lifecycle, or result-mapping change. Cadence is not standing spend
authorization: each execution needs a new approval for the exact tested heads
and aggregate ceiling.

| Tier | Canary IDs | Per-canary USD reservations | Aggregate ceiling |
| --- | --- | --- | --- |
| Monthly Search | C30–C34, C40–C41, C43, C45 | 0.02, 0.10, 0.02, 0.10, 0.10, 0.10, 0.01, 0.15, 0.10 | 0.70 |
| Full provider parity | C30–C45 | 0.02, 0.10, 0.02, 0.10, 0.10, 0.60, 2.00, 0.50, 0.20, 0.25, 0.10, 0.01, 0.15, 0.15, 0.60, 0.10 | 5.00 |

The reservations are safety ceilings, not predicted charges. Revalidate them
against current provider pricing before approval; in particular, refresh both
xAI reservations before any run on or after 2026-09-21. Execute canaries
sequentially, expose only the relevant provider credential to each child
process, and retain a sanitized receipt containing the tested heads, reservation
ledger, submission counts, outcomes, and assertion counts. Never retain
credentials, raw provider bodies, private durable handles, or auth-bearing URLs.

## Unique canaries

The list deliberately excludes `quick`, `visibility`, `deep`, and `all`: those
groups repeat the Profile calls below and add cost without proving a new
provider boundary. Optional-search Profiles are retained because their selected
provider/model and truthful search-on terminal projection differ materially.

| ID | PHP Profile | Material boundary | Credential | Harness and existing evidence | Pre-call pricing gate |
| --- | --- | --- | --- | --- | --- |
| C01 | `gemini-grounded` | Gemini SDK, `gemini-3.6-flash`, required native web search | `GEMINI_API_KEY` | `LaravelAiProfilesLiveTest` — `Gemini grounded`; passed under #2834 | Variable native tokens; cap required |
| C02 | `openrouter-grounded` | OpenRouter SDK, `openai/gpt-5.6-luna`, required native web search | `OPENROUTER_API_KEY` | `LaravelAiProfilesLiveTest` — `OpenRouter grounded`; passed under #2834 | Variable provider cost; cap required |
| C03 | `openrouter-chat` | OpenRouter SDK, `openai/gpt-5.6-terra`, optional search-on projection | `OPENROUTER_API_KEY` | `LaravelAiProfilesLiveTest` — `OpenRouter chat`; passed under #2834 | Variable provider cost; cap required |
| C04 | `claude-chat` | Anthropic SDK, `claude-sonnet-5`, optional search-on projection | `ANTHROPIC_API_KEY` | `LaravelAiProfilesLiveTest` — `Claude chat`; passed under #2834 | Variable native tokens/tools; cap required |
| C05 | `openai-chat` | OpenAI SDK, `gpt-5.6-luna`, optional search-on projection | `OPENAI_API_KEY` | `LaravelAiProfilesLiveTest` — `OpenAI chat`; passed under #2834 | Variable native tokens/tools; cap required |
| C06 | `gemini-chat` | Gemini SDK, `gemini-3.7-flash`, optional search-on projection | `GEMINI_API_KEY` | `LaravelAiProfilesLiveTest` — `Gemini chat`; passed under #2834 | Variable native tokens/tools; cap required |
| C07 | `brave-answers` | Brave Answers chat-completions endpoint | `BRAVE_API_KEY` | Approved ephemeral harness; passed under #2901 | Search plus token pricing; cap required |
| C08 | `exa` | Exa Search endpoint and search-result normalization | `EXA_API_KEY` | Approved ephemeral harness; passed under #2901 | Variable provider cost; cap required |
| C09 | `kagi-fastgpt` | Kagi FastGPT endpoint | `KAGI_API_KEY` | Approved ephemeral harness; passed under #2901 | Current conservative resolver quote and cap required |
| C10 | `perplexity-sonar-pro` | Perplexity Agent API `low`, inline result | `PERPLEXITY_API_KEY` | Approved ephemeral harness; passed under #2901 | Variable provider cost; cap required |
| C11 | `grok` | xAI Responses API, `grok-4.6`, web-only tool | `XAI_API_KEY` | Approved ephemeral harness; passed under #2901 | Tokens plus tool charge; cap required |
| C12 | `openai-research` | OpenAI background Responses lifecycle, `gpt-5.6-sol`, xhigh reasoning | `OPENAI_API_KEY` | `OpenAiResearchDriverLiveTest`; passed under #2836 | Potentially material variable spend; explicit cap required |
| C13 | `perplexity-sonar-deep` | Perplexity Agent API `high`, background poll/retrieve | `PERPLEXITY_API_KEY` | Approved ephemeral harness; passed under #2901 | Potentially material variable spend; explicit cap required |
| C14 | `searchapi` | SearchAPI Google raw search | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — raw google; passed 2026-09-12 under #2986 | Configured-only; external cap required |
| C15 | `searchapi-chatgpt` | SearchAPI ChatGPT surface with web search | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — chatgpt; passed 2026-09-12 under #2986 | Configured-only; external cap required |
| C16 | `searchapi-gemini` | SearchAPI Gemini surface | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — gemini; passed 2026-09-12 under #2986 | Configured-only; external cap required |
| C17 | `searchapi-perplexity` | SearchAPI Perplexity surface | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — perplexity; passed 2026-09-12 under #2986 | Configured-only; external cap required |
| C18 | `searchapi-google-ai-mode` | SearchAPI Google AI Mode surface | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — google ai mode; passed 2026-09-12 under #2986 | Configured-only; external cap required |
| C19 | `searchapi-bing-copilot` | SearchAPI Bing Copilot surface | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — bing copilot; passed 2026-09-12 under #2986 | Configured-only; external cap required |
| C20 | `searchapi-google-ai-overview` | SearchAPI two-stage Google page token then AI Overview | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — google ai overview; passed 2026-09-12 under #2986 | Configured-only two-unit quote; external cap required |
| C21 | `you-answer` | You.com Answer endpoint and citation mapping | `YOU_COM_API_KEY` or `YDC_API_KEY` | `YouProfilesLiveTest` — You Answer; passed 2026-09-15 under #2694 | Current conservative resolver quote and exact acknowledgement required |
| C22 | `you-research-grounded` | You.com synchronous standard Research without background mode | `YOU_COM_API_KEY` or `YDC_API_KEY` | `YouProfilesLiveTest` — You Research grounded; passed 2026-09-15 under #2694 | Current conservative resolver quote and exact acknowledgement required |
| C23 | `you-research-research` | You.com background standard Research submit/poll/retrieve | `YOU_COM_API_KEY` or `YDC_API_KEY` | `YouProfilesLiveTest` — You Research durable; passed 2026-09-15 under #2694 | Current conservative resolver quote and exact acknowledgement required |
| C24 | `dataforseo-chatgpt` | DataForSEO ChatGPT LLM Scraper Standard submit/poll/get | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO ChatGPT Standard; passed 2026-09-12 under #3471 | Current conservative USD 0.0012 quote and exact acknowledgement required |
| C25 | `dataforseo-chatgpt-live` | DataForSEO ChatGPT LLM Scraper Live Advanced | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO ChatGPT Live; passed 2026-09-12 under #3471 | Current conservative USD 0.004 quote and exact acknowledgement required |
| C26 | `dataforseo-gemini` | DataForSEO Gemini LLM Scraper Standard submit/poll/get | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO Gemini Standard; passed 2026-09-12 under #3471 | Current conservative USD 0.0012 quote and exact acknowledgement required |
| C27 | `dataforseo-gemini-live` | DataForSEO Gemini LLM Scraper Live Advanced | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO Gemini Live; passed 2026-09-12 under #3471 | Current conservative USD 0.004 quote and exact acknowledgement required |
| C28 | `dataforseo-google-ai-mode` | DataForSEO Google AI Mode Standard submit/poll/get | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO Google AI Mode Standard; passed 2026-09-12 under #3471 | Current conservative USD 0.0012 quote and exact acknowledgement required |
| C29 | `dataforseo-google-ai-mode-live` | DataForSEO Google AI Mode Live Advanced | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO Google AI Mode Live; passed 2026-09-12 under #3471 | Current conservative USD 0.004 quote and exact acknowledgement required |
| C30 | `brave-search` | Brave Web Search, raw ranked results | `BRAVE_API_KEY` | `ProviderParityProfilesLiveTest` — Brave Search; passed 2026-09-11 | Fixed request quote plus independently enforced account cap |
| C31 | `tavily-search` | Tavily Search, advanced default | `TAVILY_API_KEY` | `ProviderParityProfilesLiveTest` — Tavily Search; passed 2026-09-11 | Configured-only credit rate; current rate and external cap required |
| C32 | `perplexity-search` | Perplexity synchronous Search API | `PERPLEXITY_API_KEY` | `ProviderParityProfilesLiveTest` — Perplexity Search; passed 2026-09-11 | Fixed successful-request quote plus independently enforced account cap |
| C33 | `jina-search` | Jina Search Foundation API | `JINA_API_KEY` | `ProviderParityProfilesLiveTest` — Jina Search; passed 2026-09-11 | No response-level USD quote; external provider/account cap required |
| C34 | `serpapi` | SerpAPI synchronous Google engine | `SERPAPI_API_KEY` | `ProviderParityProfilesLiveTest` — SerpAPI Search; passed 2026-09-11 | Configured-only successful-search rate; current rate and external cap required |
| C35 | `exa-research` | Exa Agent Auto submit/poll/retrieve | `EXA_API_KEY` | `ProviderParityProfilesLiveTest` — Exa Research; passed 2026-09-11 | Variable compute/search spend; explicit external cap required |
| C36 | `gemini-deep-research` | Gemini Interactions Deep Research agent | `GEMINI_API_KEY` | `ProviderParityProfilesLiveTest` — Gemini Deep Research; passed 2026-09-11 | Configured-only/unknown request spend; explicit external cap required |
| C37 | `perplexity-deep-research` | Perplexity Agent API `medium`, background poll/retrieve | `PERPLEXITY_API_KEY` | `ProviderParityProfilesLiveTest` — Perplexity Deep Research; passed 2026-09-11 | Partial token/tool estimate only; explicit external cap required |
| C38 | `grok-x-only` | One xAI Responses request with X Search only | `XAI_API_KEY` | `ProviderParityProfilesLiveTest` — Grok X-only; corrected query passed 2026-09-11 | Partial tokens/search estimate; refresh after 2026-09-21 billing change and apply external cap |
| C39 | `grok-combined` | One xAI Responses request with web and X Search together | `XAI_API_KEY` | `ProviderParityProfilesLiveTest` — Grok combined; passed 2026-09-11 | Partial tokens/search estimate; refresh after 2026-09-21 billing change and apply external cap |
| C40 | `parallel-search` | Parallel provider-managed Search mode | `PARALLEL_API_KEY` | `ProviderParityProfilesLiveTest` — Parallel Search; passed 2026-09-11 | Provider-managed mode prevents a conservative preflight estimate; external cap required |
| C41 | `parallel-turbo` | Parallel Search with `mode=turbo` fixed | `PARALLEL_API_KEY` | `ProviderParityProfilesLiveTest` — Parallel Turbo; passed 2026-09-11 | Fixed request/default-result quote plus external cap |
| C42 | `parallel-research` | Parallel Task API `pro` submit/status/result | `PARALLEL_API_KEY` | `ProviderParityProfilesLiveTest` — Parallel Research; passed 2026-09-11 | Fixed successful processor-request quote plus external cap |
| C43 | `valyu-search` | Valyu Search across web and specialized corpora | `VALYU_API_KEY` | `ProviderParityProfilesLiveTest` — Valyu Search; passed 2026-09-11 | Source-dependent charges prevent a conservative preflight estimate; external cap required |
| C44 | `valyu-research` | Valyu standard Deep Research submit/poll/retrieve | `VALYU_API_KEY` | `ProviderParityProfilesLiveTest` — Valyu Research; passed 2026-09-11 | Standard base/selected-option quote plus external cap |
| C45 | `firecrawl-search` | Optional adapter raw `POST /v2/search` | `FIRECRAWL_API_KEY` | Adapter `FirecrawlSearchLiveTest`; passed 2026-09-11 | Provider credit use has no truthful preflight quantity; search-specific acknowledgement and external cap required |
| C46 | `perplexity-low-frozen` | Perplexity Agent API frozen low configuration from 2026-09-11, inline result | `PERPLEXITY_API_KEY` | `ProviderParityProfilesLiveTest` — Perplexity frozen low; passed 2026-09-15 under #2595 | Output and tool token limits constrain work, not total USD spend; explicit external cap required |
| C47 | `serpbase-search` | One SerpBase Google Search POST, one page, no retry | `SERPBASE_API_KEY` | Approved ephemeral harness passed 2026-09-11; durable dataset: `ProviderParityProfilesLiveTest` — SerpBase Search | Account-configured credit rate and independently enforced account cap required |
| C48 | `serpbase-news` | One SerpBase Google News POST, one page, no retry | `SERPBASE_API_KEY` | Approved ephemeral harness passed 2026-09-11; durable dataset: `ProviderParityProfilesLiveTest` — SerpBase News | Account-configured credit rate and independently enforced account cap required |

## Narrow invocation

For C01–C06, C14–C29, C30–C44, and C46–C48, invoke only the named dataset after
approval rather than a bulk Composer script. Pass only the selected Profile
credential to the child environment (`credential_name` must be the canonical
name from the table):

```bash
credential_name='<CREDENTIAL_NAME>'
credential_value="${CREDENTIAL_VALUE_FROM_SECRET_STORE}"

env -i HOME="$HOME" PATH="$PATH" \
  LIBRARIUM_LIVE_TESTS=1 \
  LIBRARIUM_LIVE_PROFILE='<profile>' \
  LIBRARIUM_LIVE_MAX_USD='<reservation>' \
  LIBRARIUM_LIVE_UNPRICED_ACK='external-cap:<profile>:USD:<reservation>' \
  "${credential_name}=${credential_value}" \
  vendor/bin/pest tests/Live/<test-file> \
    --filter='<exact dataset name from the table>' --fail-on-skipped

unset credential_value
```

C12 uses its single-test `composer test:live:openai-research` command. C07–C11
and C13 were proved with approved ephemeral harnesses; a rerun needs a newly
reviewed narrow harness rather than an ad hoc provider request. C30–C44 and C46–C48
also require `LIBRARIUM_LIVE_PROFILE`, `LIBRARIUM_LIVE_MAX_USD`, and, when
preview pricing cannot support the maximum-cost preflight check, the exact
`LIBRARIUM_LIVE_UNPRICED_ACK` printed by the harness after an external
provider/account cap is applied. C45 uses the adapter's search-specific harness
and acknowledgement under the same child-environment isolation.
`JINA_AI_API_KEY` may supply `credential_value`, but the child name remains
`JINA_API_KEY`. In every case, the operator must first satisfy and record the
approval contract above; the command itself is not authorization.

For all five new durable canaries (C35–C37, C42, and C44), an ambiguous
submission failure is terminal for that attempt: never retry it automatically.
The harness asserts exactly one paid submission request; retrieval retries are
allowed only after a validated provider handle and within the shared deadline.

## Readiness and remaining gates

- C01–C13 passed separately authorized live checks against their exact reviewed
  implementation heads. PlanMode tasks #2834, #2836, and #2901 retain the
  frozen SHAs and redacted receipts. That evidence does not authorize a rerun or
  claim that a later release-candidate SHA was exercised.
- C14–C20 passed on 2026-09-12 against core candidate
  `35e3a8920b1ef1868d76f75a0970fc89d06b3425`. All seven guarded cases passed
  with eight total requests and USD 0.032 reserved under the approved USD 0.05
  ceiling. PlanMode #2986 retains the sanitized reports and exact-manifest
  review evidence.
- C21–C23 passed on 2026-09-15 under #2694. Answer, synchronous Research, and
  durable background Research each returned one successfully mapped result with
  citations. The three separately authorized submissions used zero retries or
  fallbacks and USD 0.105 in configured estimates.
- C46 passed on 2026-09-15 under #2595 at core candidate
  `bca04cc092fccae92a585a29eb22a661783941bf`. The frozen low Agent made exactly
  one authorized POST, returned one grounded answer with citations, and used no
  retry or fallback. The USD 1.00 reservation was an external ceiling; actual
  provider cost was not reported. This completes the release-required live
  canary set.
- C24–C29 passed on 2026-09-12 against core candidate
  `8e47c1b861e23fe91b2279bfcbea099dd1a47c0c`. Each case made exactly one paid
  POST; Standard cases made only bound retrieval GETs, Live cases made none,
  and all six passed within the aggregate USD 0.0156 reservation. PlanMode
  #3471 retains the sanitized reports and exact-manifest review evidence.
- C47 passed at core `51ce3c85cbe44202bc5496c8a459abf58a62400d`, returning
  nine citations and one reported credit. C48 first failed without a response
  or known charge; after removing an extra 20-second request cap, a separately
  approved request passed at core `30fc3177de29c308412d080b06830b7cca3785a3`
  with a 45-second caller deadline, ten citations, and one reported credit.
  Both used pricing `8c6386432240ddae1018cd856279cf0d5d7b6d21` and bounded
  ephemeral harnesses with one request and no retries. Search exercised
  related-search rendering; PAA and the opaque rich modules were not returned
  and remain live-unverified. PlanMode #3650 retains the attempt evidence.
- C30–C37 and C39–C45 passed on 2026-09-11 against core
  `dfce4d2b3f06147ff0e1f94bafd54eb44141d179`, pricing
  `7caa14b59ef56724718c63e2f9b170b296ba8e9e`, and Firecrawl
  `67dfb86f7086e0fbe961298f0d81d171d2b50b55`. C38's first post-merge request
  failed for missing valid citations and was not retried. Its corrected X-native
  query then passed in one separately authorized, credential-isolated request at
  core candidate `05943e28e47b77deca6dec0ad5404653bf97eaf9`. The
  [sanitized receipt](provider-parity-live-2026-09-11.md) retains both attempts;
  all C30–C45 Profiles have now passed their live canary.
- Any rerun, including a final-release revalidation, needs current provider-doc
  review, credentials, a spend cap, and an approved SHA. The bulk
  `test:live:sdk` and `test:live:searchapi` scripts are convenience commands,
  not standing authorization.
