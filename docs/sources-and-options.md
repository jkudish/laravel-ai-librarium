# Sources and options

The exact installed `jkudish/librarium-spec/sources.json` is the name, order and
option authority ([librarium-spec](https://github.com/jkudish/librarium-spec)). `Librarium::sources()` and `librarium:sources --json` expose
it with configured state and offline pricing hints. PHP binding ids are internal
driver identities, not selectors. Credentials live in Laravel config, never
request options. A source with a key and `enabled !== false` is configured.

Omit `only()` to use every configured research source. Quick is the default
depth; `deep()` selects durable research variants and skips sources without
one. Engines run only when named. Every name must be configured: an unknown
name or a group/source collision is an error.

## Research sources

| Source | Quick | Deep | Public options |
| --- | --- | --- | --- |
| brave | Answers | None | results: answer/search |
| claude | Web-search chat | None | model, collector: direct/dataforseo |
| exa | Search | Research | None |
| firecrawl | Search | None | sources: web/news, limit |
| gemini | Grounded | Deep Research | quick: grounded/chat, collector: direct/dataforseo |
| google | SERP search | None | collector: searchapi/serpapi/serpbase, vertical: web/news (news requires serpbase) |
| grok | Web search | None | search: web/x/both |
| jina | Search | None | None |
| kagi | FastGPT | None | None |
| openai | Web-search chat | Research | model, effort, collector: direct/dataforseo |
| openrouter | Grounded | None | quick: grounded/chat, model |
| parallel | Turbo | Research | quick: turbo/search, processor (pro default) |
| perplexity | Sonar Pro | Research | quick: sonar-pro/search/agent, deep_effort: medium/high, collector: direct/dataforseo |
| tavily | Search | None | None |
| valyu | Search | Research | mode (standard default), output |
| you | Answer | Research | quick: answer/research |

```php
$run = Librarium::research('Find current official Laravel documentation')
    ->only(['firecrawl', 'google'])
    ->options(['firecrawl' => ['sources' => ['web', 'news'], 'limit' => 5],
        'google' => ['collector' => 'serpbase', 'vertical' => 'news']])
    ->run();
```

Unknown options fail preflight. Advanced driver controls remain internal to
driver bindings or custom variants; the simplified source API does not accept
arbitrary transport, credentials or provider routing controls.
Firecrawl makes one POST to `https://api.firecrawl.dev/v2/search` and normalizes
web/news citations; credits are not invented USD charges. Search-only answers
are `null`; their citations and stored evidence carry findings.

## Retrieved pages versus citations (spec 1.1)

`citations` contains pages the answer cites. Optional `search_results` contains
the provider's retrieval set, including uncited pages, in provider order with
exact-URL duplicates removed (first occurrence kept). A retrieved page was not
necessarily read or rejected. Missing/null means retrieval is unavailable;
`[]` means the provider reported an empty set. Search-only sources omit it.

The PHP API is `ResearchResult::$searchResults`, a nullable
`Collection<int, Responses\SearchResult>`. Each readonly `SearchResult` has
`url`, `title`, `snippet` and `publishedAt` (`?CarbonImmutable`), with
`fromArray()`/`toArray()`/JSON support. `ResearchResult::toArray()` and Run results
use `search_results: [{url, title, snippet, published_at}]`; all four entry keys
are emitted, with `published_at` in RFC 3339 UTC or null. When the collection is
null the key is omitted, so pre-1.1 serialized responses round-trip unchanged.
Strict 1.0 Run readers must upgrade before reading 1.1 retrieval fields.

ChatGPT's DataForSEO scraper maps `chatgpt_search_result` entries from
`search_results` (`description` becomes `snippet`), while its `sources` remain
citations. Gemini scraper and Google AI Mode do not report a retrieval list.
The Perplexity agent retains every `search_results` entry, not just cited ones.
For direct Claude, only text-block `web_search_result_location` citations count
as citations; web-search tool results and fetched pages form `search_results`.
Claude grounding (including required-grounding validation) now depends on real
citations, not the SDK's conflated retrieval/citation metadata.

OpenAI Responses web-search profiles request
`include: ['web_search_call.action.sources']`. Quick OpenAI uses the SDK's
provider options and raw step replay blocks; background OpenAI research already
requests the same expansion and maps its raw output. All web-search call source
lists contribute retrieval, while `url_citation` annotations remain citations.
Absent `action.sources` means unknown, not empty. Disabling web search adds no
source-expansion include and leaves retrieval null (the SDK may independently
include encrypted reasoning for reasoning models). No SDK patch is needed.

## Optional DataForSEO LLM Responses

Requires `jkudish/librarium-spec` 1.1.0 or later and
`services.dataforseo.login` / `services.dataforseo.password`. This collector is
PHP-only, Live-only and never selected by default. It calls the provider's model
API through DataForSEO; it is not a consumer-surface snapshot. Deep variants
are unchanged and still require their direct credentials.

```php
$run = Librarium::research('Compare current analytics options')
    ->only(['openai'])
    ->options(['openai' => ['collector' => 'dataforseo', 'model' => 'gpt-4.1-mini']])
    ->run();
```

| Source | Internal profile | Platform path | Default model_name |
| --- | --- | --- | --- |
| openai | dataforseo-llm-chatgpt | chat_gpt | gpt-4.1-mini |
| claude | dataforseo-llm-claude | claude | claude-opus-5 |
| perplexity (quick: sonar-pro) | dataforseo-llm-perplexity | perplexity | sonar-pro |
| gemini (quick: grounded) | dataforseo-llm-gemini | gemini | gemini-2.5-flash |

Requests POST once to `/v3/ai_optimization/{platform}/llm_responses/live`,
without retries. `user_prompt` is the literal question (1–500 Unicode characters;
longer questions fail before HTTP). `model_name` is required; the public `model`
option on openai/claude overrides it. Configure other driver options under
`librarium.sources.<source>.options`:

- `model_name`: model supported by that platform (defaults above).
- `max_output_tokens`: default 2048, maximum 4096; minimum 16 for ChatGPT,
  1 for the others. Reasoning-model minimums can be higher; use a model-supported
  value. With web search, output can exceed this limit, so it is not a cost cap.
- `force_web_search`: optional bool, ChatGPT/Claude only. ChatGPT reasoning
  models do not support it. Even true does not guarantee citations.
- `web_search_country_iso_code`: optional two-letter uppercase country,
  ChatGPT/Claude/Perplexity only (Perplexity Sonar models).
- `web_search_city`: optional city, ChatGPT/Claude only. Some ChatGPT models do
  not support location controls; consult the model endpoint before overriding.

Web search is always enabled. Answer text comes from message text sections,
citations from `annotations`, retrieval from `search_results` (including dates),
and `fan_out_queries` is retained in `providerMeta`. Provenance separates the
model provider from `collector: dataforseo`.

DataForSEO limits Live work to 30 concurrent tasks per account **per platform**;
applications sharing credentials must coordinate that limit across workers.
The provider execution limit is 120 seconds; the shared HTTP client allows up
to 125 seconds for transport, bounded further by the research deadline.
[Live pricing](https://dataforseo.com/pricing/ai-optimization/llm-responses) is
$0.0006 per task plus model charges. The reported task `cost` already includes
`money_spent` and is used once as actual USD cost. No honest hard spending bound
is known: these profiles are `unpriced` and skipped when `max_cost` is set.

Request/response fields were checked against the current Live references on
2026-11-03: [ChatGPT](https://docs.dataforseo.com/v3/ai_optimization/chat_gpt/llm_responses/live/),
[Claude](https://docs.dataforseo.com/v3/ai_optimization/claude/llm_responses/live/),
[Perplexity](https://docs.dataforseo.com/v3/ai_optimization/perplexity/llm_responses/live/),
[Gemini](https://docs.dataforseo.com/v3/ai_optimization/gemini/llm_responses/live/).
Tests use shortened documented examples and faked HTTP; no live compatibility
or provider-account/model entitlement is claimed.

## Anthropic prompt caching

The direct Anthropic `claude` source caches instructions and tool definitions
with the default five-minute TTL. The conversation also uses automatic
`cache_control` when `max_steps` is greater than one, or omitted so the SDK's
multi-step default applies. A one-step request never caches its unique message
content. Anthropic's minimum prefix length still applies, so a short prefix
may not be cached. Reserve input at the 1.25× write rate, not the ordinary
input rate; settle exclusive uncached, read and write partitions at their real
rates. The no-search Claude driver bound uses `cache_write_input_tokens_5m`.

Anthropic token counts come from each step's raw provider usage, not SDK
totals that default missing input to zero or coerce numeric strings. Each
metric requires a nonnegative JSON integer on every step; a missing or invalid
count stays unknown while the answer is retained. Raw `input_tokens` already
excludes cache tokens and is never reduced by the read/write counts again.
Consumers computing a bill must also validate their retained raw usage for
unsupported billable quantities and pricing qualifiers.

The shipped OpenRouter variants use OpenAI models and are unchanged, as are
direct OpenAI and Google requests. Custom OpenRouter `anthropic/*` models do
not inherit these defaults: Laravel AI SDK 1.0.1 ignores the caching
attributes on its OpenRouter gateway. Register your own gateway with
`AiManager::extend` to apply static breakpoints and multi-step automatic
caching to those models. This is an SDK integration limitation; OpenRouter
[supports explicit and automatic Anthropic caching](https://openrouter.ai/docs/guides/best-practices/prompt-caching#anthropic-claude).

## Engines are named-only sources

`chatgpt`, `gemini-app`, `google-ai-mode`, `google-ai-overview` and `copilot`
carry `observation: surface`. Set `engines.collector` to `searchapi`,
`dataforseo`, or the optional `firecrawl` adapter; override per engine.
DataForSEO supports ChatGPT, Gemini App and Google AI Mode; SearchAPI supports
all five. Engines never join default selection and have no deep variant.

```php
// config/librarium.php
'engines' => [
    'collector' => 'dataforseo',
    'options' => ['location_code' => 2840, 'language_code' => 'en'],
    'priority' => 'standard',
],
'groups' => ['visibility' => ['chatgpt', 'gemini-app', 'google-ai-mode']],
```

Use `services.dataforseo.login/password`. ChatGPT defaults `force_web_search`
to true; Google AI Mode permits desktop/windows-or-macos or mobile/android-or-ios.
Scraper questions are limited to 2000 characters, AI Mode to 700, with no
silent truncation. Standard priority saves a signed provider task in Run state;
live makes one inline POST. Select priority per request with
`options(['chatgpt' => ['priority' => 'live']])`. No switch or fallback occurs.
Task-level submission charges survive refresh and are not counted twice.

SearchAPI uses `services.searchapi.key` and bearer headers. AI Overview uses
an inline overview if present, otherwise its page-token GET. Organic results
never substitute for a missing overview. Zero-retention and timeout controls
belong in the configured collector `options` and are validated by its driver.

SerpBase uses `services.serpbase.key`. The Google source's `vertical: news`
selects its news endpoint. Locale/page/device controls are driver-level rather
than shared source options. It makes one POST without retries or pagination;
relative news dates stay text. Its policy allows query/account/IP logging;
no zero-retention promise is inferred.

## Deep options and waiting

Parallel processors: lite, base, core, core2x, pro, ultra, ultra2x, ultra4x,
ultra8x. Valyu modes: fast, standard, heavy, max. Mapping these options also
changes pricing identity; a model override is not priced as the original model.
Valyu `output` selects markdown or markdown/pdf. Bearer artifact links are not
projected into public Run JSON; redacted exchanges omit them.
You quick research is inline; You deep research saves a recoverable UUID task.
OpenAI deep uses background Responses with high effort by default.

Deep provider tasks can outlive `librarium.timeout`; notices name longer
provider deadlines. Waiting stops without cancelling or resubmitting tasks.
Use `find()->refresh()` later. Raising the shared timeout also requires raising
the queue/worker timeout. Rates remain in laravel-ai-pricing.

## Custom sources and groups

Add a source under `sources.<name>` with a `key`, and `quick` and/or `deep`
driver binding. Deep drivers must implement `DurableDriver`. See
[driver authoring](writing-a-driver.md). Custom engines by URL belong in
`engines.custom` and need a collector that supports them.
Opt-in frozen research templates register the same way: a
`PerplexityResearchConfiguration::profiles()` entry as a `quick` binding, an
`ExaResearchConfiguration::profiles()` level as a `deep` binding, each with
its own `key`. Their frozen options identify the reviewed configuration; any
change fails before HTTP.
Groups are lists of source names, not execution modes. There are no built-in
groups or reserved `custom:` prefix; collisions with any source/engine fail.
