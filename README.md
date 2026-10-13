# Laravel AI Librarium

Research across configured sources, built on the official [Laravel AI SDK](https://github.com/laravel/ai).
Every execution produces a stored **Run**: answers, citations, provider-reported costs and redacted evidence.

## Install

PHP 8.4+, Laravel 13.30+ and Laravel AI SDK `^1.0.1` are required.

```bash
composer require jkudish/laravel-ai-librarium
php artisan vendor:publish --tag=librarium-config
```

The PHP 1.0 candidate is unreleased. It currently requires the shared
`jkudish/librarium-spec` data at the exact source/dist commit in `composer.json`.
The package repository gives that pin a development version without Composer's
commit-ref warning, so strict validation still passes. Consumers of the candidate
must copy that repository declaration; Composer does not inherit repositories.
A stable spec constraint is a release gate.

Credentials come from `config/ai.php` and `config/services.php`. Per-source
`key` overrides belong in `config/librarium.php`, never in request options.

## Ask, select, and run

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$run = Librarium::research('Compare Plausible, Fathom and Matomo for an EU SaaS.')
    ->deep()
    ->only(['exa', 'openai'])
    ->skip(['exa'])
    ->maxCost('1.00')
    ->options(['openai' => ['effort' => 'high']])
    ->run();

$run->id();
$run->result('openai');
$run->citations();
$run->evidence('openai'); // Redacted provider exchanges, separate from public JSON.
$run->diagnostics();
$run->toArray();         // librarium.run/1, shared with JavaScript.
```

Omit `only()` to use every configured research source. Engines are named-only.
Quick is the default depth. Deep selects durable research variants; sources
without one are skipped. Groups are user-saved lists, with no built-in groups.
Names must be configured; an unknown name or a group/source collision is an error.

`maxCost()` admits sources greedily in catalog order, skipping unpriced or
over-budget work. It is **not a provider-enforced ceiling**: accepted calls can
finish and bill above the cap. Unknown reported cost stays `null`, not zero.
`dryRun()` returns a planned Run without provider calls or storage:

```php
$plan = Librarium::research('Compare EU analytics tools')->deep()->dryRun();
Librarium::sources(); // Catalog, configured state, options and offline price hints.
```

## Queue and recover

```php
use Jkudish\LaravelAiLibrarium\Run;

$queued = Librarium::research('Prepare a cited report')->deep()->only(['openai'])
    ->queue()->onQueue('research')->then(fn (Run $run) => /* consume snapshot */ null);
$id = $queued->id();

$run = Librarium::find($id)?->refresh(); // One observation of every pending durable task.
```

`queue()` stores the Run first, then dispatches one encrypted, one-attempt job.
`run()` waits up to `librarium.timeout`; a still-running Run remains recoverable.
Durability is a driver property, not a depth: quick DataForSEO standard tasks
and the optional Firecrawl agent collector also save signed handles.
There is no automatic paid resubmission or fallback. Inline work cannot be resumed
after process loss; submitted tasks with saved handles can.

Run stores are local Laravel `filesystem` disks (default; encrypted with the
app key), `database`, `cache` with a TTL, and `memory`. Built-in cache support is
process-local ArrayStore or plain standalone Redis 7+ with native atomic CAS;
unsupported disks/caches require a custom RunStore. The database store uses the
query builder and one optional migration, never Eloquent or an automatic migration.
See [Runs, storage and recovery](docs/runs-storage-recovery.md) and
[writing a RunStore](docs/writing-a-run-store.md).

## Sources and engines

The shared [source catalog](https://github.com/jkudish/librarium-spec/blob/main/sources.json)
defines names and options. See [sources and options](docs/sources-and-options.md).

```php
// config/librarium.php
'sources' => ['parallel' => ['processor' => 'pro']],
'engines' => ['collector' => 'searchapi'],
'groups' => ['visibility' => ['chatgpt', 'gemini-app', 'copilot']],
```

Firecrawl search is built into core using Laravel HTTP. Browser engines need the
optional `jkudish/laravel-ai-librarium-firecrawl` adapter (SDK Interact or Agent);
it registers the `firecrawl` collector and supports custom engines by URL.
Results from engines carry `observation: surface`; API answers carry `api`.
Agreement is not independent confirmation. Treat all provider text and URLs as
untrusted, escape rendered content, and verify consequential claims.

## Artisan

```bash
php artisan librarium:research "Compare EU analytics tools" --deep --only=openai --dry-run --json
php artisan librarium:research "Prepare a cited report" --deep --only=openai --no-wait
php artisan librarium:show [run-id] --wait --json
php artisan librarium:sources
php artisan librarium:prune
```

Research waits with progress by default; Ctrl-C detaches and prints the recovery
command. `show` defaults to the latest Run and accepts `--source`.
`--json` emits unformatted public data. Exit codes: 0 success/running/planned,
2 invalid request, 3 not found, 4 partial, 5 failed, 130 detached.
The Laravel AI evidence tool `Tools\RunEvidence` reads stored Runs.

## Development

```bash
composer update
composer test
composer test:conformance
composer analyse
composer format:check
composer validate --strict
```

Tests use Testbench, SDK fakes and HTTP fakes; no provider calls. Run PHP 8.4
with `--prefer-lowest` and PHP 8.5 with the highest allowed dependencies.
Commit all intended changes, require a clean worktree, and run `composer pr:check`
at the exact candidate SHA. Do not commit `composer.lock`.
See the [release checklist](docs/release-checklist.md) for separate review,
signoff, paid canary and publication gates.

The former pre-release query/profile, collection and recovery APIs are removed;
there is no PHP config migration. Old directories are not Run folders.

## License

[MIT](LICENSE.md).
