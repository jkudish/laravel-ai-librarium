# Changelog

## 1.0.0 - 2026-10-27

First stable release.

- Research with `Librarium::research()` and `deep`, `only`, `skip`,
  `maxCost`, `options`, `run`, `queue` and `dryRun`. Read stored work with
  `Librarium::find()` and `Run::refresh()`; list sources with
  `Librarium::sources()`.
- Return immutable `librarium.run/1` Runs with per-source answers, citations,
  provider-reported costs, skips, notices and optional `summary_of`. The Run
  JSON, source catalog and fixtures come from
  [librarium-spec](https://github.com/jkudish/librarium-spec) and are shared
  with [Librarium for JavaScript](https://github.com/jkudish/librarium).
- Store every non-dry Run before provider calls, with redacted evidence and
  private diagnostics.
- Provide encrypted filesystem, opt-in query-builder database, cache with TTL
  and memory stores, custom store registration with `extendStore()` and shared
  `RunStoreContractTests`. Ship one publishable migration that never runs
  automatically; no Eloquent models.
- Restrict built-in files to local Laravel disks with the shared nonexpiring
  JS/PHP marker. Support process-local ArrayStore or standalone plain Redis 7+
  with atomic compare-and-set for cache storage. Reject unsupported,
  decorated, failover and cluster backends, and Redis connections with native
  command retries enabled; custom RunStores support other backends.
- Reject failed persistence before submission, retain late accepted handles,
  validate every request option, sanitize operational logs, preserve opaque
  JSON object/list shapes, reject encoded known-key reflections and redact them
  from evidence, and order arbitrary-precision timestamps with exact exclusive
  cursors. Forward quick OpenAI `effort` through the native Laravel AI SDK
  option.
- Queue research as one encrypted, one-attempt job. Save signed handles for
  every durable driver, including OpenAI and You.com research, DataForSEO
  standard engines and optional async collectors. Never resubmit paid work or
  fall back to another source automatically.
- Use the shared source catalog: 16 research sources, five named-only engines,
  source options and user-saved groups. Budget admission is greedy in catalog
  order; unpriced sources are skipped under a cap. Accepted work may still
  bill above it.
- Add Laravel HTTP Firecrawl search to core; optional browser collection lives
  in [laravel-ai-librarium-firecrawl](https://github.com/jkudish/laravel-ai-librarium-firecrawl).
- Add `librarium:research`, `librarium:show`, `librarium:sources`,
  `librarium:prune` and the `Tools\RunEvidence` agent tool. `librarium:research`
  prints progress and detaches on Ctrl-C at the next safe poll boundary; the
  signal handler never reenters Run storage while a read or commit holds its
  marker.
- Cache static instructions and tool definitions on direct Anthropic requests
  for five minutes, plus automatic conversation caching only for multi-step
  budgets. The no-search Claude driver bound reserves its context as
  `cache_write_input_tokens_5m`. Read Anthropic token partitions from every
  step's raw usage; missing, non-integer or unsafe counts stay unknown.
  OpenRouter `anthropic/*` models do not inherit caching under SDK 1.0.1.
- Add opt-in frozen Perplexity research templates
  (`PerplexityResearchConfiguration`, `research-{medium,high,deep}-2026-10-09`
  for GPT-6 Luna, GPT-6.1 Sol and Gemini 3.8 Flash; no Claude through
  Perplexity) and Exa research levels (`ExaResearchConfiguration`: `auto` with
  a native USD 1 cap, `ultra` with USD 5 and 3,000 seconds). Register one as a
  custom source binding; its frozen options identify it and any drift fails
  before HTTP. Perplexity templates have no proven spending bound.
- Support Exa's metered `ultra` effort with `max_duration_seconds` (300 to
  10,800, `ultra` only) and the `time_limit_reached` stop reason;
  `effort: max` is rejected.
- Prove the Parallel Research driver bound: one `processor_requests` unit
  under `parallel:research-{processor}`, with no pay-per-use add-ons.
- Require PHP 8.4+, Laravel 13.30+, Laravel AI SDK ^1.0.1 (excludes 1.0.0,
  affected by [GHSA-6qhr-3g93-pxhw](https://github.com/laravel/ai/security/advisories/GHSA-6qhr-3g93-pxhw)),
  and laravel-ai-pricing ^0.3.1 || ^0.4.1 with dated rate windows.
  Compatibility is checked on PHP 8.4 with the lowest and PHP 8.5 with the
  highest allowed dependencies.
