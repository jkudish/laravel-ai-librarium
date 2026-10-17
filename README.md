# Laravel AI Librarium

A Laravel-native research query abstraction built on the official [Laravel AI SDK](https://github.com/laravel/ai).

> This package is under active development and does not yet have a stable release.

## Usage

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\ResearchHandle;

$response = Librarium::query('Compare current agent memory architectures.')
    ->using('gemini-grounded')
    ->fallbacks(['openrouter-grounded'])
    ->run();
```

Use `collect()` when the application needs the normalized result plus the
provider response. Provider bodies are automatically redacted before the
serializable result is returned:

```php
$collected = Librarium::query('Compare current agent memory architectures.')
    ->using('openrouter-grounded')
    ->collect();

$collected->response;
$collected->evidence;
$collected->diagnostics;
```

Use one exact durable Profile to separate paid submission from later
retrieval. Persist the returned handle before calling `retrieve()`:

```php
$submitted = Librarium::query('Prepare a research report.')
    ->using('exa-research')
    ->submit();

$handle = $submitted->handle->toArray(); // application-owned persistence
$outcome = Librarium::retrieve(ResearchHandle::fromArray($handle));
```

See [collection and durable recovery](docs/collection-and-recovery.md) for
redaction, exact sensitive capture, retry diagnostics, supported recoverable
Profiles, provider credential paths and controls, and spending-limit semantics.

Use `queue()` for the same research path in one encrypted Laravel job:

```php
$research = Librarium::query('Compare current agent memory architectures.')
    ->group('deep')
    ->queue()
    ->then(fn ($response) => /* store or present the terminal response */)
    ->catch(fn ($exception) => /* report the infrastructure failure */);
```

## Anthropic prompt caching

Direct Anthropic Laravel AI Profiles cache instructions and tool definitions
with the default five-minute TTL. The conversation also uses automatic
`cache_control` when `max_steps` is greater than one (or omitted, using the
SDK's multi-step default). A one-step request never caches its unique message
content. Anthropic's minimum prefix length still applies: a short prefix may
not be cached. Reserve input at the 1.25× write rate, not the ordinary input
rate; settle exclusive uncached, read and write partitions at their real rates.
The no-search Claude bound uses `cache_write_input_tokens_5m`; see
[spending bounds](docs/spending-bounds.md).

Anthropic token counts come from each step's raw provider usage, not SDK
totals that default missing input to zero or coerce numeric strings. Each
metric requires a nonnegative JSON integer on every step; a missing or invalid
count stays unknown while the answer is retained. Raw `input_tokens` already
excludes cache tokens and is never reduced by the read/write counts again.
Consumers computing a bill must also validate their retained raw usage for
unsupported billable quantities and pricing qualifiers.

Shipped OpenRouter Profiles use OpenAI models and remain unchanged, as do
direct OpenAI and Google requests. Standalone custom OpenRouter `anthropic/*`
Profiles do **not** inherit these defaults: Laravel AI SDK 1.0.1 ignores the
caching attributes on its OpenRouter gateway. Consumers can register their own
gateway with `AiManager::extend`, as agentsy-platform does, to apply static
breakpoints and multi-step automatic caching to those models only. This is an
SDK integration limitation, not an OpenRouter API limitation; OpenRouter
[supports explicit and automatic Anthropic caching](https://openrouter.ai/docs/guides/best-practices/prompt-caching#anthropic-claude).

## Artisan commands

`librarium:query` provides a guided Laravel Prompts workflow for operators. It
collects a missing query, lets the operator choose one eligible Profile, a
group, or multiple Profiles, and offers the existing fallback, timeout, and
cost-ceiling safeguards. Group choices explain their purpose and show how many
Profiles are ready, while custom fan-out is available near the top of the
selection menu. It always displays a compact compiled Profile, grouped
availability, and pricing preview before offering the default `Run now`,
`Queue`, or `Exit without dispatching` action. Human-mode string results render
Markdown headings, emphasis, lists, links, tables, and code for readable
terminal output; agent-mode JSON preserves the original Markdown string.

```bash
php artisan librarium:query
php artisan librarium:query 'Compare current agent memory architectures.' \
    --profile=gemini-grounded \
    --fallback=openrouter-grounded \
    --timeout=600 \
    --max-cost=0.25 \
    --currency=USD
```

Supplying a query or selection does not bypass the preview or interactive
action menu. `--run` and `--queue` select and dispatch that action immediately
after displaying the preview, without asking the operator to repeat the
choice. With neither flag, the interactive action menu defaults to `Run now`;
choose `Exit without dispatching` for preview-only use. A non-TTY process must
use either `--agent` or Laravel's `--no-interaction`; otherwise the command
exits instead of waiting for input. `--no-interaction` uses plain human output
and never implies JSON.

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

### Pricing previews

Librarium resolves preview costs through `jkudish/laravel-ai-pricing`; it does
not maintain a second rate table. Every selected primary and every compatible
fallback receives a `CostQuote`. A fallback quote is multiplied by the number
of times the runtime may execute it. The current global fallback reserve tries
each Profile at most once, while `reachable_from` records every compatible
primary. The human preview summarizes known currency subtotals, unknown
profiles or units, whether usage assumptions are conservative, and pricing
source freshness in one compact statement. When the operator selects the
interactive maximum-cost safeguard, Librarium compiles and displays pricing
first, then suggests the conservative pre-dispatch estimate as the default. If
no suitable estimate exists, it explains why and exits without dispatching
rather than asking the operator to invent one. Agent previews expose the same
details beneath the versioned `pricing.schema_version: 1` object.

Preview resolution is offline by default. It can use configured rates, the
pricing package's reviewed snapshot, and previously synchronized last-known-good
catalog data, but it does not fetch a catalog or call a research provider.
Refreshing remote catalogs remains an explicit operator action:

```bash
php artisan ai:pricing:sync
```

Profiles declare a pricing `provider`, `model`, explicit usage units, and
whether those units are intended as a conservative estimate. Known cost from a
partial or non-conservative quote remains useful preview information.
`--max-cost` fails before dispatch unless every reachable quote is complete,
current, conservative, and in one currency; unknown cost is never treated as
zero. It does not send a budget to the provider or cap charges after dispatch.
Configured rates are considered current until the application changes them.
Other catalog prices must be no older than `librarium.pricing.max_age_days` (30
by default).

SearchAPI remains configured-only. To price its built-in
`searchapi:search` identity, applications must define the
`successful_search_request` rate in `config/ai-pricing.php`; otherwise its quote
is unavailable. Provider-reported actual totals continue to take precedence in
the pricing resolver after execution.

SerpBase is also configured-only because public headline minimums do not
describe an account's actual credit price. Configure a `credits` rate for
`serpbase:search` and/or `serpbase:news`; each built-in Profile reserves one
credit and remains unavailable to hard-budget preflight until its account rate
is configured.

Release ordering: the `laravel-ai-pricing` update blocking fallback for both
SerpBase identities must land before this package's SerpBase support. The
SerpBase integration test verifies that the installed dependency contains that
policy; a passing test against the previous pricing package is insufficient.

`ResearchPreview::preflightCostCheckAvailable` and the agent JSON
`preflight_cost_check_available` member indicate only that `maxCost()` can
perform this quote comparison. They do not indicate a provider-enforced
spending ceiling. `hardCostCeilingAvailable` and `hard_ceiling_available` remain
deprecated compatibility aliases with the same preflight-only meaning. The
legacy preview `cost` JSON member and the corresponding `maximumEstimatedCost`,
`currency`, and `costEstimateComplete` properties are derived from the same
aggregated `CostQuote` values. New consumers should use the versioned `pricing`
object.

Applications that authorize spend can separately query an exact single-Profile
request with `spendingBound()`. It returns either a provider-native monetary
ceiling, complete billable quantities for an authoritative pricing lookup, or
an explicit unknown result. See [exact-request spending bounds](docs/spending-bounds.md)
for the public contract, supported Profiles, and ownership boundaries.

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
| `openrouter-grounded` | OpenRouter | `openai/gpt-6-luna` | Required |
| `openrouter-chat` | OpenRouter | `openai/gpt-5.6-terra` | Optional, on by default |
| `claude-chat` | Anthropic | `claude-sonnet-5` | Optional, on by default |
| `openai-chat` | OpenAI | `gpt-6-luna` | Optional, on by default |
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
| `brave-search` | Brave Web Search | Search results | Inline | `services.brave.key` |
| `exa` | Exa Search | Search results | Inline | `services.exa.key` |
| `exa-research` | Exa Agent Auto | Research report | Background | `services.exa.key` |
| `tavily-search` | Tavily Search | Search results | Inline | `services.tavily.key` |
| `jina-search` | Jina Search Foundation | Search results | Inline | `services.jina.key` |
| `serpapi` | SerpAPI Google Search | Search results | Inline | `services.serpapi.key` |
| `serpbase-search` | SerpBase Google Search | Search results | Inline | `services.serpbase.key` |
| `serpbase-news` | SerpBase Google News | Search results | Inline | `services.serpbase.key` |
| `kagi-fastgpt` | Kagi FastGPT | Grounded answer | Inline | `services.kagi.key` |
| `you-answer` | You.com Answer | Grounded answer | Inline | `YOU_COM_API_KEY` or `YDC_API_KEY` |
| `you-research-grounded` | You.com Research (`standard`) | Grounded answer | Inline | `YOU_COM_API_KEY` or `YDC_API_KEY` |
| `you-research-research` | You.com Research (`standard`) | Research report | Background | `YOU_COM_API_KEY` or `YDC_API_KEY` |
| `perplexity-sonar-pro` | Perplexity Agent preset `low` | Grounded answer | Inline | `services.perplexity.key` |
| `perplexity-low-frozen` | Perplexity Agent frozen low configuration | Grounded answer | Inline | `services.perplexity.key` |
| `perplexity-search` | Perplexity Search | Search results | Inline | `services.perplexity.key` |
| `perplexity-deep-research` | Perplexity Agent preset `medium` | Research report | Background | `services.perplexity.key` |
| `grok` | xAI `grok-4.6` with web search | Grounded answer | Inline | `ai.providers.xai.key` |
| `grok-x-only` | xAI `grok-4.6` with X Search | Grounded answer | Inline | `ai.providers.xai.key` |
| `grok-combined` | xAI `grok-4.6` with web and X Search | Grounded answer | Inline | `ai.providers.xai.key` |
| `gemini-deep-research` | Gemini Interactions Deep Research | Research report | Background | `ai.providers.gemini.key` |
| `openai-research` | OpenAI `gpt-5.6-sol` with web research | Research report | Background | `ai.providers.openai.key` |
| `perplexity-sonar-deep` | Perplexity Agent preset `high` | Research report | Background | `services.perplexity.key` |
| `parallel-search` | Parallel Search, provider-managed mode | Search results | Inline | `services.parallel.key` |
| `parallel-turbo` | Parallel Search, fixed `turbo` mode | Search results | Inline | `services.parallel.key` |
| `parallel-research` | Parallel Task API, `pro` processor | Research report | Background | `services.parallel.key` |
| `valyu-search` | Valyu Search, web and specialized corpora | Search results | Inline | `services.valyu.key` |
| `valyu-research` | Valyu Deep Research, `standard` mode | Research report | Background | `services.valyu.key` |
| `dataforseo-chatgpt` | DataForSEO ChatGPT LLM Scraper Standard | Surface observation | Background poll | `librarium.providers.dataforseo.*` |
| `dataforseo-chatgpt-live` | DataForSEO ChatGPT LLM Scraper Live | Surface observation | Inline | `librarium.providers.dataforseo.*` |
| `dataforseo-gemini` | DataForSEO Gemini LLM Scraper Standard | Surface observation | Background poll | `librarium.providers.dataforseo.*` |
| `dataforseo-gemini-live` | DataForSEO Gemini LLM Scraper Live | Surface observation | Inline | `librarium.providers.dataforseo.*` |
| `dataforseo-google-ai-mode` | DataForSEO Google AI Mode Standard | Surface observation | Background poll | `librarium.providers.dataforseo.*` |
| `dataforseo-google-ai-mode-live` | DataForSEO Google AI Mode Live | Surface observation | Inline | `librarium.providers.dataforseo.*` |

OpenAI, Gemini Deep Research, and xAI use Laravel AI's standard provider
configuration even though these Drivers make focused HTTP requests. Add the
remaining credentials to the consuming application's `config/services.php`:

```php
'brave' => ['key' => env('BRAVE_API_KEY')],
'exa' => ['key' => env('EXA_API_KEY')],
'jina' => ['key' => env('JINA_API_KEY')],
'kagi' => ['key' => env('KAGI_API_KEY')],
'parallel' => ['key' => env('PARALLEL_API_KEY')],
'perplexity' => ['key' => env('PERPLEXITY_API_KEY')],
'searchapi' => ['key' => env('SEARCHAPI_KEY')],
'serpapi' => ['key' => env('SERPAPI_API_KEY')],
'serpbase' => ['key' => env('SERPBASE_API_KEY')],
'tavily' => ['key' => env('TAVILY_API_KEY')],
'valyu' => ['key' => env('VALYU_API_KEY')],
```

Each Profile keeps its provider's option schema, endpoint, and lifecycle.
Background Drivers submit paid work at most once and reconcile only known
handles within the shared deadline. Unknown or account-specific pricing remains
unavailable or partial rather than becoming an invented zero. See the
[profile binding matrix](docs/profile-bindings.md) for exact semantics and
pricing dispositions.

DataForSEO login/password credentials, explicit location/language controls,
Standard-versus-Live behavior, pricing, surface semantics, opt-in comparison
groups, and worker-loss caveats are documented in the
[DataForSEO Profile guide](docs/dataforseo-profiles.md). These six Profiles ship
disabled and do not change the curated `quick` or `visibility` rosters.

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
mapping, pricing-quote assumptions, failed-call billing uncertainty, and the
paid live gate.

SerpBase Search and News each issue exactly one synchronous POST and never
retry or paginate automatically. Both accept `hl`, `gl`, and a positive `page`;
Search alone accepts `device` (`default`, `pc`, or `mobile`). Search's
`include_rich_results` option defaults to `false`. When enabled, documented
organic/news fields remain strict, People Also Ask uses only the documented
`question`, `snippet`, `link`, and `displayed_link` fields, and related-search
strings are suggestions rather than evidence. The undocumented
`featured_snippet`, `top_stories`, and `knowledge_graph` shapes are rendered as
bounded sanitized JSON-like text without interpreting child keys or deriving
citations. Unsupported or unsafe values are omitted. Search, News, and
related-search rendering passed live smoke checks; PAA and the opaque rich
modules were not returned and remain live-unverified. Offline rich tests use
synthetic payloads.

Responses exceeding 2 MB or 100 primary results are rejected. Retained text
fields are capped at 2,000 UTF-8 bytes. Options require native integer/boolean
types rather than coercible strings. News date text remains unstructured;
relative dates are never converted into inferred publication timestamps.

SerpBase's public policy says requests may log query text, locale, search type,
status, latency, credits charged, API/account identifiers, and IP metadata. It
does not promise zero retention, per-request suppression, self-service deletion,
or a fixed public retention period. Do not send sensitive queries unless that
policy fits the consuming application's privacy requirements.

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
| `searchapi-google-ai-overview` | `google`, then `google_ai_overview` only for a page token | Surface observation | `searchapi` / `google_ai_overview` |

Each SearchAPI Profile resolves its bearer credential from
`config('services.searchapi.key')`. The credential travels in the
`Authorization` header only and never enters the URL, progress, errors,
provider metadata, or results. Set an optional `options.zero_retention` boolean
to require SearchAPI zero retention; the request fails closed with a clear error
when the account does not support it. Each HTTP request times out after 20
seconds unless the Profile sets `options.request_timeout_seconds` (1 to 600);
the shared research deadline still caps every request.

`searchapi-google-ai-overview` reads an overview returned inline by the first
`google` request and only follows `page_token` when no inline overview is
present (SearchAPI may report "not available" beside a token whose page
loads one). A SERP without an overview fails as `searchapi.no_ai_overview`;
an overview the driver cannot read fails as `searchapi.ai_overview_unparsed`.
Overview reference links are Google redirects. Citations resolve them with URL
parsing only, never a fetch: a `url` or `q` parameter that is a publisher URL
becomes the citation; otherwise (Google's opaque `goto` tokens) the citation
is the publisher origin from the link's `favicon` URL. The raw redirect is kept
as the Source `provider_reference` when it fits 255 characters.

### Surface provenance migration

SearchAPI surface Profiles now declare and emit `surface_observation`,
`surface_collector`, and `surface_snapshot`. Applications that copied an older
Profile configuration must update all three values together; contradictory
surface semantics are rejected during Profile-catalog preflight before a Driver
is constructed. The raw `searchapi` Google search Profile is unchanged.

Previously stored terminal responses remain readable. When historical
provenance omits `observation_mode`, Librarium preserves that absence and the
original result kind instead of retroactively claiming a surface observation.
Surface context is limited to locale, country, device, authentication, and
personalization (`present`, `absent`, or `unknown`). Unknown facts should remain
unknown or omitted; this field does not resolve the broader declared-versus-
measured account-context design tracked in #2994.

Configure provider credentials through Laravel AI's normal provider
configuration. Librarium resolves values such as
`config('ai.providers.gemini.key')`; it never stores configuration paths or
exposes credentials through previews and terminal results.

## Profile groups

`quick` is the default group and runs these eligible Profiles in order:
`gemini-grounded`, `openrouter-grounded`, `brave-answers`, `exa`, and
`kagi-fastgpt`, then `parallel-turbo`.

`visibility` runs the six SearchAPI surface Profiles followed by
`perplexity-sonar-pro`, `gemini-grounded`, and `grok`. `deep` is derived from
every configured `research_report` Profile rather than maintained as a second
list; the built-in catalog currently yields You.com standard, OpenAI,
Perplexity high and medium, Exa, Gemini Deep Research, Parallel Research, and
Valyu Research. `all` similarly derives all 47 configured core Profiles; the
two SerpBase Profiles join only this derived group and do not change `quick`.
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
[live provider canary inventory](docs/live-provider-canaries.md) for the 48
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

Provider-native polling and webhook verification remain inside Drivers. Public
durable handles are available only through explicit `submit()` and `retrieve()`
for the supported Profiles listed in
[collection and durable recovery](docs/collection-and-recovery.md).

## Boundaries

- The core package does not publish database tables or own consumer persistence.
- Applications own evidence policy, verification thresholds, conversations, and domain workflows.
- TypeScript Librarium remains the local-agent and CLI runtime; both implementations share a language-neutral interchange rather than runtime internals.
- Substantial optional integrations, including Firecrawl, live in separate adapter packages.
- `ResearchStarted`, `ResearchProgressed`, `ResearchCompleted`, and `ResearchFailed` provide non-broadcast application integration points without adding persistence to the package.

## Requirements

- PHP 8.4+
- Laravel 13
- Laravel AI SDK ^1.0

Development currently follows the latest `jkudish/laravel-ai-pricing`
`dev-main` through Packagist. Librarium must not be released or published with
this development constraint; replace it with the corresponding stable version
after the pricing package is released. The [PHP 1.0 release
checklist](docs/release-checklist.md) records every remaining compatibility,
cross-repository, live, and exact-SHA gate.

Merge the SerpBase configured-only pricing policy into the pricing package
before merging these PHP Profiles. Consumers must install pricing that includes
that policy; an older pricing build can incorrectly attempt remote fallback for
SerpBase instead of requiring an account-configured credit rate.

## Development

```bash
composer install
composer test
composer test:conformance
composer analyse
composer format
composer format:check
composer validate --strict
composer compatibility:check
```

Run `compatibility:check` after resolving each supported dependency lane. It
does not resolve or install packages itself. `composer release:check` is a
separate fail-closed readiness guard and is expected to report the recorded 1.0
blockers until the release checklist is complete.

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
write. The command requires a nonblank `GH_SIGNOFF_TOKEN` before GitHub access
and maps it to `GH_TOKEN` only for the pinned extension lookup, read-only remote
ref and PR checks, and final status write. Local Git operations and every
candidate subprocess remain credential-free. It never falls back to ambient
`GH_TOKEN`, `GH_REPO`, provider credentials, or saved GitHub CLI login; the
repository and all remote selectors remain pinned.
Authenticated command failures report only the operation and exit code, not
raw command output, so truncated or encoded credentials cannot leak into errors.

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
