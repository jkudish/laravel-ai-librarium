# Laravel AI Librarium

Research queries for Laravel applications, built on the official
[Laravel AI SDK](https://github.com/laravel/ai).

> The package is under active development and does not have a stable release.
> The Composer command below applies after a compatible package release.

## Install after release

```bash
composer require jkudish/laravel-ai-librarium
php artisan vendor:publish --tag=librarium-config
```

Requirements:

- PHP 8.4+
- Laravel 13
- Laravel AI SDK `^1.0.1`

## Run a query

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$response = Librarium::query('Compare current agent memory architectures.')
    ->using('gemini-grounded')
    ->run();
```

`run()` returns a typed `ResearchResponse`. Each result keeps its effective
Profile, provider, citations, usage, and provenance. Provider content and URLs
remain untrusted evidence. Escape content for its output context and verify
important claims against their cited sources.

Preview the exact selection and known pricing before dispatch:

```php
$research = Librarium::query('Compare current agent memory architectures.')
    ->using('gemini-grounded')
    ->fallbacks(['openrouter-grounded']);

$preview = $research->preview(); // No research provider call.
$response = $research->run();    // May make paid provider calls.
```

Unknown cost stays unknown. `maxCost()` rejects a request unless every
reachable quote is complete, current, conservative, and in one currency. It is
a pre-dispatch check, not a provider-enforced ceiling or final-bill guarantee.
See [exact-request spending bounds](docs/spending-bounds.md) for the separate
single-Profile bound API.

## Queue and recover longer work

```php
$queued = Librarium::query('Prepare a research report.')
    ->using('perplexity-sonar-deep')
    ->queue()
    ->onQueue('research');
```

The package dispatches one encrypted Laravel job. Your application owns queue
infrastructure, terminal-response storage, tenant authorization, retention,
and recovery policy.

For supported durable Profiles, persist the returned handle before retrieval:

```php
use Jkudish\LaravelAiLibrarium\ResearchHandle;

$submitted = Librarium::query('Prepare a research report.')
    ->using('exa-research')
    ->submit();

$saved = $submitted->handle->toArray(); // Application-owned persistence.
$outcome = Librarium::retrieve(ResearchHandle::fromArray($saved));
```

Do not automatically resubmit uncertain paid work. Read
[collection and durable recovery](docs/collection-and-recovery.md) before
building retry or worker-recovery behavior.

## Use the Artisan commands

The guided command always shows its compiled selection, availability, and
pricing preview before its action menu:

```bash
php artisan librarium:query
```

Automation must select its behavior explicitly. `--agent` without `--run` or
`--queue` is preview-only and makes no research provider call:

```bash
# Preview only.
php artisan librarium:query 'Compare memory systems.' \
  --agent --profile=gemini-grounded

# Synchronous dispatch. May make a paid provider call.
php artisan librarium:query 'Compare memory systems.' \
  --agent --profile=gemini-grounded --run

# Dispatch one encrypted Laravel job.
php artisan librarium:query 'Compare memory systems.' \
  --agent --group=deep --queue

php artisan librarium:status 01M13EXAMPLEREQUESTID --agent
php artisan librarium:profiles --agent
```

Agent-mode JSON uses versioned preview, queue, status, and profile envelopes.
A completed `--run` returns the public `ResearchResponse::toArray()` shape.
Validation errors leave stdout empty and use bounded stderr messages.

## Profiles and groups

The current source configures 46 core Profiles. Forty are enabled by
default but still require valid configuration and credentials. Six DataForSEO
Profiles are disabled until an application opts in. This count is separate
from the frozen 39-row TypeScript/PHP release-contract snapshot.

Built-in groups:

- `quick`: six curated low-latency Profiles
- `visibility`: five SearchAPI surface observations and three API baselines
- `deep`: every configured `research_report` Profile
- `all`: every configured Profile

Groups and fan-out execute sequentially under one request-wide deadline.
Unavailable Profiles are reported and omitted. Invalid configuration fails
preflight. See the [Profile binding matrix](docs/profile-bindings.md) for the
current roster, historical interchange baseline, and semantic mappings.

## Evidence and execution limits

- Search, grounded answers, research reports, and surface observations keep
  distinct result and provenance semantics.
- Agreement is not proof. SearchAPI surfaces share one collector and are not
  independent confirmations.
- Provider identities fail closed unless a custom Profile explicitly allows
  routing.
- Missing usage or cost is unknown, not zero.
- Queued status is transient. The package does not publish research-history
  tables.
- Webhooks are wake hints where supported. Polling remains authoritative.
- Live canaries are paid, separately authorized release checks. Ordinary tests
  make no provider calls.

## Documentation

- [Collection, redaction, and durable recovery](docs/collection-and-recovery.md)
- [Profile binding matrix](docs/profile-bindings.md)
- [Live provider canary inventory](docs/live-provider-canaries.md)
- [DataForSEO Profiles](docs/dataforseo-profiles.md)
- [SearchAPI Profiles](docs/searchapi-profiles.md)
- [SerpBase Profiles](docs/serpbase-profiles.md)
- [You.com Profiles](docs/you-com-profiles.md)
- [Non-SDK Driver authoring](docs/non-sdk-drivers.md)
- [Release checklist](docs/release-checklist.md)

## Development

Install dependencies, then run the nonmutating checks:

```bash
composer install
composer test
composer test:conformance
composer analyse
composer format:check
composer validate --strict
```

`composer format` rewrites files with Pint. `composer compatibility:check`
checks an already resolved dependency lane. `composer release:check` is a
separate fail-closed release-readiness guard.

For an exact committed candidate, run `composer pr:check` from a clean
worktree. It executes the repository-owned offline plan and writes a local
exact-SHA receipt. It does not install dependencies, call providers, publish,
release, deploy, or write a GitHub status. Signoff requires separate human
approval of the exact pushed SHA and is outside routine development.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the contributor path.

## License

Laravel AI Librarium is open-source software licensed under the
[MIT license](LICENSE.md).
