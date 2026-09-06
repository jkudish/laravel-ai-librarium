# Live provider canary approval gates

This is the release-candidate inventory of materially unique live checks for the
29 Profiles landed in PHP. It is a plan, not authorization. No live provider or
paid call was made while producing or verifying this document.

## Approval contract

Each canary ID below is an independent approval gate. Approval for one ID does
not authorize another ID, a Profile group, a bulk Composer script, a retry, or
a changed model, query, timeout, or cost ceiling. Before dispatch, record:

1. the exact commit or release candidate under test;
2. the canary ID, Profile, query, model or provider-managed target, and timeout;
3. the credential-owning account and a hard per-call and aggregate spend cap;
4. whether one retry is authorized and what failure permits it; and
5. the terminal receipt location with secrets and raw provider payloads removed.

Unknown cost is not zero. A canary with unknown or variable pricing must not run
until the approver supplies a dollar ceiling that the operator can enforce. A
Librarium `--max-cost` ceiling is valid only when `laravel-ai-pricing` returns a
complete, current, conservative, single-currency quote for the full reachable
plan; otherwise the operator needs an independently enforced provider/account
ceiling. The ordinary suite remains network-free. Existing live tests
additionally require `LIBRARIUM_LIVE_TESTS=1`; the environment flag alone is
not approval.

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
| C14 | `searchapi` | SearchAPI Google raw search | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — raw google; not run | Configured-only; unavailable until the application supplies a current conservative rate |
| C15 | `searchapi-chatgpt` | SearchAPI ChatGPT surface with web search | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — chatgpt; not run | Configured-only; unavailable until the application supplies a current conservative rate |
| C16 | `searchapi-gemini` | SearchAPI Gemini surface | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — gemini; not run | Configured-only; unavailable until the application supplies a current conservative rate |
| C17 | `searchapi-perplexity` | SearchAPI Perplexity surface | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — perplexity; not run | Configured-only; unavailable until the application supplies a current conservative rate |
| C18 | `searchapi-google-ai-mode` | SearchAPI Google AI Mode surface | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — google ai mode; not run | Configured-only; unavailable until the application supplies a current conservative rate |
| C19 | `searchapi-bing-copilot` | SearchAPI Bing Copilot surface | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — bing copilot; not run | Configured-only; unavailable until the application supplies a current conservative rate |
| C20 | `searchapi-google-ai-overview` | SearchAPI two-stage Google page token then AI Overview | `SEARCHAPI_KEY` | `SearchApiProfilesLiveTest` — google ai overview; not run | Configured-only two-unit quote; unavailable until the application supplies a current conservative rate |
| C21 | `you-answer` | You.com Answer endpoint and citation mapping | `YOU_COM_API_KEY` or `YDC_API_KEY` | `YouProfilesLiveTest` — You Answer; not run | Current conservative resolver quote and exact acknowledgement required |
| C22 | `you-research-grounded` | You.com synchronous standard Research without background mode | `YOU_COM_API_KEY` or `YDC_API_KEY` | `YouProfilesLiveTest` — You Research grounded; not run | Current conservative resolver quote and exact acknowledgement required |
| C23 | `you-research-research` | You.com background standard Research submit/poll/retrieve | `YOU_COM_API_KEY` or `YDC_API_KEY` | `YouProfilesLiveTest` — You Research durable; not run | Current conservative resolver quote and exact acknowledgement required |
| C24 | `dataforseo-chatgpt` | DataForSEO ChatGPT LLM Scraper Standard submit/poll/get | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO ChatGPT Standard; not run | Current conservative USD 0.0012 quote and exact acknowledgement required |
| C25 | `dataforseo-chatgpt-live` | DataForSEO ChatGPT LLM Scraper Live Advanced | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO ChatGPT Live; not run | Current conservative USD 0.004 quote and exact acknowledgement required |
| C26 | `dataforseo-gemini` | DataForSEO Gemini LLM Scraper Standard submit/poll/get | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO Gemini Standard; not run | Current conservative USD 0.0012 quote and exact acknowledgement required |
| C27 | `dataforseo-gemini-live` | DataForSEO Gemini LLM Scraper Live Advanced | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO Gemini Live; not run | Current conservative USD 0.004 quote and exact acknowledgement required |
| C28 | `dataforseo-google-ai-mode` | DataForSEO Google AI Mode Standard submit/poll/get | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO Google AI Mode Standard; not run | Current conservative USD 0.0012 quote and exact acknowledgement required |
| C29 | `dataforseo-google-ai-mode-live` | DataForSEO Google AI Mode Live Advanced | `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | `DataForSeoProfilesLiveTest` — DataForSEO Google AI Mode Live; not run | Current conservative USD 0.004 quote and exact acknowledgement required |

## Narrow invocation

For C01–C06 and C14–C29, invoke only the named dataset after approval rather
than a bulk Composer script:

```bash
LIBRARIUM_LIVE_TESTS=1 vendor/bin/pest tests/Live/<test-file> \
  --filter='<exact dataset name from the table>' --fail-on-skipped
```

C12 uses its single-test `composer test:live:openai-research` command. C07–C11
and C13 were proved with approved ephemeral harnesses; a rerun needs a newly
reviewed narrow harness rather than an ad hoc provider request. In every case,
the operator must first satisfy and record the approval contract above; the
command itself is not authorization.

## Readiness and remaining gates

- C01–C13 passed separately authorized live checks against their exact reviewed
  implementation heads. PlanMode tasks #2834, #2836, and #2901 retain the
  frozen SHAs and redacted receipts. That evidence does not authorize a rerun or
  claim that a later release-candidate SHA was exercised.
- C14–C29 remain unverified live. Their committed opt-in harness is runnable one
  dataset at a time after the approval contract above is satisfied.
- Any rerun, including a final-release revalidation, needs current provider-doc
  review, credentials, a spend cap, and an approved SHA. The bulk
  `test:live:sdk` and `test:live:searchapi` scripts are convenience commands,
  not standing authorization.
