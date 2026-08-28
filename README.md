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

## Artisan commands

`librarium:query` provides a guided Laravel Prompts workflow for operators. It
collects a missing query, lets the operator choose one eligible Profile, a
group, or multiple Profiles, and offers the existing fallback, timeout, and
cost controls. It always displays the compiled Profile, availability, warning,
and cost preview before offering `Run now`, `Queue`, or the default
`Exit without dispatching` action. Run and queue each require a separate,
default-no confirmation after that preview.

```bash
php artisan librarium:query
php artisan librarium:query 'Compare current agent memory architectures.' \
    --profile=gemini-grounded \
    --fallback=openrouter-grounded \
    --timeout=600 \
    --max-cost=0.25 \
    --currency=USD
```

Supplying a query or selection does not dispatch provider work. `--run` and
`--queue` select an action, but the interactive command still requires the
post-preview confirmation. A non-TTY process must use either `--agent` or
Laravel's `--no-interaction`; otherwise the command exits instead of waiting
for input. `--no-interaction` uses plain human output and never implies JSON.

Use `--agent` for automation. It disables prompts, ANSI decoration, and
spinners, writes one deterministic JSON value to stdout on success, and never
dispatches unless `--run` or `--queue` is explicit. Repeat `--profile` to fan
out, and repeat `--fallback` to preserve fallback priority:

```bash
# Preview only
php artisan librarium:query 'Compare memory systems.' \
    --agent --profile=gemini-grounded

# Explicit synchronous dispatch
php artisan librarium:query 'Compare memory systems.' \
    --agent --profile=gemini-grounded --run

# Explicit queued dispatch
php artisan librarium:query 'Compare memory systems.' \
    --agent --group=deep --queue

php artisan librarium:status 01M13EXAMPLEREQUESTID --agent
php artisan librarium:profiles --agent
```

Preview, queued, status, and profile-listing JSON use small envelopes with
`schema_version: 1` and a matching `type`. A completed `--run` writes the public
`ResearchResponse::toArray()` value directly, without a command envelope.
Validation and command errors leave stdout empty and write a bounded,
credential-safe message to stderr when it is available.

Agent-mode exit codes are stable:

| Code | Meaning |
| ---: | --- |
| `0` | Preview, queue submission, status/profile lookup, or succeeded research completed |
| `1` | Unexpected command/infrastructure failure |
| `2` | Invalid or conflicting input |
| `3` | Requested status was not found |
| `4` | Research completed with a partial terminal response |
| `5` | Research completed with a failed terminal response |

`librarium:status {requestId?}` is a read-only lookup of the package's
transient queue status; it is not persisted history. `librarium:profiles` is a
read-only availability view and omits credentials, Profile options, prompts,
and secret-bearing configuration. Queue workers and the configured queue
backend remain application responsibilities. These commands can make paid
provider calls only when an operator explicitly runs or queues a query, so the
consuming application remains responsible for provider credentials, budgets,
and authorization to make live calls.

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
composer format:check
composer validate --strict
```

### Pull-request verification and signoff

GitHub is the collaboration and status surface for this repository, not its
verification authority. GitHub Actions workflows are intentionally absent.
Pull-request verification is performed manually in a trusted Amp orb against
the exact candidate commit:

```bash
composer install
composer pr:check
```

`composer install` is setup only and is never part of `pr:check`. The check
requires a clean worktree and runs the repository's deterministic offline plan:
strict Composer validation, platform requirements, the package test suite,
PHPStan/Larastan, non-mutating Pint, and final diff/worktree/HEAD drift guards.
It does not install dependencies, run live tests, make provider or other network
calls, publish, release, deploy, mutate a database, run Node, or perform
application-owned work. Candidate subprocesses receive an allowlisted
environment with a disposable home and no ambient GitHub, dedicated signoff,
provider, or Composer credentials.

On success, the command atomically writes a regular mode-`0600` receipt beneath
Git administrative storage. The receipt binds the exact full commit SHA,
`jkudish/laravel-ai-librarium`, the immutable verification-plan ID, successful
completion time, accepted PHP policy, and bounded PHP, Composer, platform, and
architecture evidence, including the exact installed Composer-package set used
by the checks. Any new commit or change in plan, repository, policy, actual
runtime, or installed dependency resolution makes the receipt stale and
requires a fresh `composer pr:check`.

Signoff requires a separate human approval naming the exact full lowercase SHA:

```bash
composer pr:signoff -- --approved-sha <full-lowercase-sha>
```

The command fails closed unless the receipt, clean local HEAD and branch, pushed
branch, and repository-pinned open PR all still identify that SHA. It also
requires the canonical `basecamp/gh-signoff` extension. After initial
eligibility and dedicated-token readiness, it reruns the complete ordered
`pr:check` plan in the same credential-free candidate environment, replaces and
revalidates the exact-SHA receipt, then repeats remote, PR, worktree, branch, and
HEAD checks immediately before one exact unforced `gh signoff --commit <sha>`
write. Read-only GitHub checks use the existing GitHub CLI login. Only the final
status-writing subprocess maps the dedicated `GH_SIGNOFF_TOKEN` to `GH_TOKEN`;
ambient `GH_TOKEN` and `GH_REPO` cannot redirect earlier checks or selectors.

Install the extension with `gh extension install basecamp/gh-signoff`. Store
`GH_SIGNOFF_TOKEN` as an orb secret and limit its fine-grained access to this
repository with **Contents: read**, **Pull requests: read**, and **Commit
statuses: read/write**. Never put the token in repository files or ordinary
shell history, and never inspect or print its value, length, shape, or effective
permissions.

Signoff is a manual, non-enforced status attestation unless a future separately
authorized GitHub ruleset changes that policy. Exact-SHA approval authorizes
only the guarded signoff write. It is not self-approval, merge authorization,
cryptographic commit signing, ruleset configuration, release, publication,
deployment, production verification, database work, or permission for paid or
live provider calls.
