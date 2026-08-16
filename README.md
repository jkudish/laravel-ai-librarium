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
| `openrouter-grounded` | OpenRouter | `openai/gpt-4o-mini` | Required |
| `openrouter-chat` | OpenRouter | `openai/gpt-5.6-terra` | Optional, on by default |
| `claude-chat` | Anthropic | `claude-sonnet-5` | Optional, on by default |
| `openai-chat` | OpenAI | `gpt-5-mini` | Optional, on by default |
| `gemini-chat` | Gemini | `gemini-3.6-flash` | Optional, on by default |

The Profiles use Laravel AI's native `WebSearch`. Applications may disable it
for an optional-search Profile by setting its `options.web_search` value to
`false`; the terminal result then reports an ungrounded `model_answer` without
invented retrieval facts.

Configure provider credentials through Laravel AI's normal provider
configuration. Librarium resolves values such as
`config('ai.providers.gemini.key')`; it never stores configuration paths or
exposes credentials through previews and terminal results.

To run the opt-in live canary, make `GEMINI_API_KEY` available to the process
without committing it, then run:

```bash
composer test:live:gemini
```

The ordinary test suite always excludes live provider calls. The dedicated
command fails when the credential is absent or the canary is skipped.

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
