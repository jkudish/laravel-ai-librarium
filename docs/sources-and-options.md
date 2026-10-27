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
| claude | Web-search chat | None | model |
| exa | Search | Research | None |
| firecrawl | Search | None | sources: web/news, limit |
| gemini | Grounded | Deep Research | quick: grounded/chat |
| google | SERP search | None | collector: searchapi/serpapi/serpbase, vertical: web/news (news requires serpbase) |
| grok | Web search | None | search: web/x/both |
| jina | Search | None | None |
| kagi | FastGPT | None | None |
| openai | Web-search chat | Research | model, effort |
| openrouter | Grounded | None | quick: grounded/chat, model |
| parallel | Turbo | Research | quick: turbo/search, processor (pro default) |
| perplexity | Sonar Pro | Research | quick: sonar-pro/search/agent, deep_effort: medium/high |
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
