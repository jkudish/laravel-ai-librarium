# Sources and options

The exact installed `jkudish/librarium-spec/sources.json` is the name, order and
option authority. `Librarium::sources()` and `librarium:sources --json` expose
it with configured state and offline pricing hints. PHP binding ids are internal
driver identities, not selectors. Credentials live in Laravel config, never
request options. A source with a key and `enabled !== false` is configured.

## Research sources

| Source | Quick | Deep | Public options |
| --- | --- | --- | --- |
| brave | Answers | — | results: answer/search |
| claude | Web-search chat | — | model |
| exa | Search | Research | — |
| firecrawl | Search | — | sources: web/news, limit |
| gemini | Grounded | Deep Research | quick: grounded/chat |
| google | SERP search | — | collector: searchapi/serpapi/serpbase, vertical: web/news (news requires serpbase) |
| grok | Web search | — | search: web/x/both |
| jina | Search | — | — |
| kagi | FastGPT | — | — |
| openai | Web-search chat | Research | model, effort |
| openrouter | Grounded | — | quick: grounded/chat, model |
| parallel | Turbo | Research | quick: turbo/search, processor (pro default) |
| perplexity | Sonar Pro | Research | quick: sonar-pro/search/agent, deep_effort: medium/high |
| tavily | Search | — | — |
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
