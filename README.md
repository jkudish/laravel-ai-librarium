# Laravel AI Librarium

A Laravel-native research query abstraction built on the official [Laravel AI SDK](https://github.com/laravel/ai).

> This package is under active development and does not yet have a stable release.

## Usage

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$response = Librarium::query('Compare current agent memory architectures.')
    ->using('gemini-grounded')
    ->fallbacks(['openrouter-grounded'])
    ->run();
```

Use `queue()` for the same research path in one encrypted Laravel job:

```php
$research = Librarium::query('Compare current agent memory architectures.')
    ->group('deep')
    ->queue()
    ->then(fn ($response) => /* store or present the terminal response */)
    ->catch(fn ($exception) => /* report the infrastructure failure */);
```

The package ships these Laravel AI SDK Profiles:

| Profile | Provider | Model | Web search |
| --- | --- | --- | --- |
| `gemini-grounded` | Gemini | `gemini-3.6-flash` | Required |
| `openrouter-grounded` | OpenRouter | `openai/gpt-5.6-luna` | Required |
| `openrouter-chat` | OpenRouter | `openai/gpt-5.6-terra` | Optional, on by default |
| `claude-chat` | Anthropic | `claude-sonnet-5` | Optional, on by default |
| `openai-chat` | OpenAI | `gpt-5.6-luna` | Optional, on by default |
| `gemini-chat` | Gemini | `gemini-3.7-flash` | Optional, on by default |

The Profiles use Laravel AI's native `WebSearch`. Applications may disable it
for an optional-search Profile by setting its `options.web_search` value to
`false`; the terminal result then reports an ungrounded `model_answer` without
invented retrieval facts.

The package also ships SearchAPI.io Profiles served by a small Laravel HTTP
Driver. `searchapi` returns raw Google search results; the surface Profiles
observe a cited answer rendered on a consumer AI product:

| Profile | Engine | Result | Collector / surface |
| --- | --- | --- | --- |
| `searchapi` | `google` | Raw search results | none |
| `searchapi-chatgpt` | `chatgpt` (web search on) | Surface observation | `searchapi` / `chatgpt` |
| `searchapi-gemini` | `gemini` | Surface observation | `searchapi` / `gemini` |
| `searchapi-perplexity` | `perplexity` | Surface observation | `searchapi` / `perplexity` |
| `searchapi-google-ai-mode` | `google_ai_mode` | Surface observation | `searchapi` / `google_ai_mode` |
| `searchapi-bing-copilot` | `bing_copilot` | Surface observation | `searchapi` / `bing_copilot` |
| `searchapi-google-ai-overview` | `google` then `google_ai_overview` | Surface observation | `searchapi` / `google_ai_overview` |

Each SearchAPI Profile resolves its bearer credential from
`config('services.searchapi.key')`. The credential travels in the
`Authorization` header only and never enters the URL, progress, errors,
provider metadata, or results. Set an optional `options.zero_retention` boolean
to require SearchAPI zero retention; the request fails closed with a clear error
when the account does not support it.

Configure provider credentials through Laravel AI's normal provider
configuration. Librarium resolves values such as
`config('ai.providers.gemini.key')`; it never stores configuration paths or
exposes credentials through previews and terminal results.

OpenAI background research can use signed webhooks as wake-up hints while
polling remains the source of truth. Configure the OpenAI dashboard endpoint as
`POST /librarium/webhooks/openai` and set its signing secret at
`config('services.openai.webhook_secret')`. Without a secret, the endpoint
returns 404 and background research continues by polling.
Apply application or edge rate limits appropriate for your expected webhook
volume. The package does not impose a universal request limit; dropped webhook
deliveries do not affect correctness because polling remains authoritative.

The background lifecycle uses Laravel's HTTP client because Laravel AI does not
currently expose Responses API retrieval. Once OpenAI returns a completed
response, Librarium normalizes it into Laravel AI's public `AgentResponse`,
`Usage`, `Meta`, and `UrlCitation` value objects before mapping it into the
terminal `ResearchResult`. This preserves SDK response-shape compatibility; it
does not imply that Laravel AI manages the background request, dispatches its
prompt events, or runs its middleware and conversation lifecycle.

To exercise the background research Driver against OpenAI, make
`OPENAI_API_KEY` available to the process, then run:

```bash
LIBRARIUM_LIVE_TESTS=1 composer test:live:openai-research
```

The canary uses `gpt-5.6-sol` by default. Set `OPENAI_RESEARCH_MODEL` to test
another Responses API model that supports background execution, `xhigh`
reasoning, and web search.

To run the opt-in live canary, make `GEMINI_API_KEY` available to the process
without committing it, then run:

```bash
LIBRARIUM_LIVE_TESTS=1 composer test:live:gemini
```

Every live test requires the explicit `LIBRARIUM_LIVE_TESTS=1` opt-in, even when
a provider credential is present. The ordinary test suite therefore cannot make
a live provider call. The dedicated command fails when the opt-in or credential
is absent, or when the canary is skipped.

To validate every SDK-backed Profile, configure the Gemini, OpenRouter,
Anthropic, and OpenAI credentials, then run:

```bash
LIBRARIUM_LIVE_TESTS=1 composer test:live:sdk
```

To exercise the SearchAPI Profiles against the live endpoint, make
`SEARCHAPI_KEY` available to the process, then run:

```bash
LIBRARIUM_LIVE_TESTS=1 SEARCHAPI_KEY=... composer test:live:searchapi
```

Queued research uses the dedicated `research` queue by default. Configure its connection through `librarium.queue.connection`, or use normal pending-dispatch methods such as `onConnection()` and `onQueue()` on the queued response.

The default research deadline is 6,900 seconds inside a 7,200-second queue timeout. This leaves five minutes for normalization, events, callbacks, and worker cleanup. Multiple Profiles execute sequentially in 1.0 and share that research deadline, so a slow earlier Profile can leave less time for later fan-out slots.

For safe long-running work, keep these limits in order:

```text
request/job timeout < Horizon supervisor timeout < queue retry_after
process stopwaitsecs > Horizon supervisor timeout
```

The queue connection's `retry_after` must exceed every Horizon worker timeout to prevent duplicate execution. Supervisor `stopwaitsecs` must exceed the worker timeout so deployment or restart signals do not terminate active research early.

Provider-native polling, resumable handles, and webhook verification stay inside Drivers and optional adapter packages. The public API remains `run()` and `queue()`; it does not expose provider execution modes or handles.

## Boundaries

- The core package does not publish database tables or own consumer persistence.
- Applications own evidence policy, verification thresholds, conversations, and domain workflows.
- TypeScript Librarium remains the local-agent and CLI runtime; both implementations share a language-neutral interchange rather than runtime internals.
- Substantial optional integrations, including Firecrawl, live in separate adapter packages.
- `ResearchStarted`, `ResearchProgressed`, `ResearchCompleted`, and `ResearchFailed` provide non-broadcast application integration points without adding persistence to the package.

## Requirements

- PHP 8.4+
- Laravel 13
- Laravel AI SDK >=0.10.3,<0.11.0

## Development

```bash
composer install
composer test
composer analyse
composer format
```
