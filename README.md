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

The package also ships focused Laravel HTTP Drivers for provider capabilities
that Laravel AI does not expose through its prompt API:

| Profile | Provider target | Result | Execution | Credential config |
| --- | --- | --- | --- | --- |
| `brave-answers` | Brave Answers (`brave`) | Grounded answer | Inline | `services.brave.key` |
| `exa` | Exa Search | Search results | Inline | `services.exa.key` |
| `kagi-fastgpt` | Kagi FastGPT | Grounded answer | Inline | `services.kagi.key` |
| `you-answer` | You.com Answer | Grounded answer | Inline | `YOU_COM_API_KEY` or `YDC_API_KEY` |
| `you-research-grounded` | You.com Research (`standard`) | Grounded answer | Inline | `YOU_COM_API_KEY` or `YDC_API_KEY` |
| `you-research-research` | You.com Research (`standard`) | Research report | Background | `YOU_COM_API_KEY` or `YDC_API_KEY` |
| `perplexity-sonar-pro` | Perplexity Agent preset `low` | Grounded answer | Inline | `services.perplexity.key` |
| `grok` | xAI `grok-4.6` with web search | Grounded answer | Inline | `ai.providers.xai.key` |
| `openai-research` | OpenAI `gpt-5.6-sol` with web research | Research report | Background | `ai.providers.openai.key` |
| `perplexity-sonar-deep` | Perplexity Agent preset `high` | Research report | Background | `services.perplexity.key` |

OpenAI and xAI use Laravel AI's standard provider configuration even though
these Drivers make focused HTTP requests. Add the remaining credentials to the
consuming application's `config/services.php`:

```php
'brave' => ['key' => env('BRAVE_API_KEY')],
'exa' => ['key' => env('EXA_API_KEY')],
'kagi' => ['key' => env('KAGI_API_KEY')],
'perplexity' => ['key' => env('PERPLEXITY_API_KEY')],
'searchapi' => ['key' => env('SEARCHAPI_KEY')],
```

Librarium stores the resolved values in its in-memory Profile catalog, not the
configuration paths. Credentials are excluded from previews, serialized
Profiles, terminal results, provider metadata, and safe Driver errors.

You.com Answer, inline Research, and durable Research are independently
selectable and never alias or automatically fall back across depth.
`YOU_COM_API_KEY` is canonical; `YDC_API_KEY` is a nonbreaking fallback for the
provider's current documentation convention. Answer and inline Research are
explicit opt-in and outside all curated groups; durable standard Research
participates only in the non-default derived `deep` group. See
[the You.com Profile guide](docs/you-com-profiles.md) for options, evidence
mapping, exact request estimates, failed-call billing uncertainty, and the
paid live gate.

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

## Profile groups

`quick` is the default group and runs these eligible Profiles in order:
`gemini-grounded`, `openrouter-grounded`, `brave-answers`, `exa`, and
`kagi-fastgpt`.

`visibility` runs the six SearchAPI surface Profiles followed by
`perplexity-sonar-pro`, `gemini-grounded`, and `grok`. `deep` is derived from
every configured `research_report` Profile rather than maintained as a second
list; the built-in catalog currently yields `openai-research` and
`perplexity-sonar-deep`, plus standard-effort `you-research-research`. `all`
similarly derives every configured Profile.
Profiles that are disabled or missing a credential remain visible in preview
diagnostics but are not executed by a group. Invalid Profile configuration
fails preflight instead of being silently skipped.

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

## Live validation

Live checks are paid, separately authorized release gates. See the
[live provider canary inventory](docs/live-provider-canaries.md) for the 20
materially unique checks, their current harness coverage, credentials, pricing
uncertainty, and the approval record required before each dispatch. Approval of
one canary does not authorize another canary, a retry, or a bulk Profile group.

Every existing live test requires `LIBRARIUM_LIVE_TESTS=1` even when a provider
credential is present. The flag is a technical opt-in, not spending approval.
The ordinary `composer test` and `composer test:conformance` commands exclude
`tests/Live`, prevent stray HTTP requests and AI prompts, and make no provider
calls.

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
composer test:conformance
composer analyse
composer format
composer validate --strict
```
