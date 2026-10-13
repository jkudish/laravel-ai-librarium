# Changelog

## 1.0.0 - 2026-10-13

Unreleased first-release candidate; this date is not a publication claim.

- Replace the pre-release query/profile surface with `research`, `deep`,
  `only`, `skip`, `maxCost`, `options`, `run`, `queue`, `dryRun`, `find` and
  `sources`. No config migration is needed before PHP 1.0.
- Return immutable `librarium.run/1` Runs with per-source answers, citations,
  truthful provider-reported costs, skips, notices and optional `summary_of`.
- Store every non-dry Run before calls. Include redacted evidence and private
  diagnostics; sensitive raw capture and public handle management are removed.
- Provide encrypted filesystem, opt-in query-builder database, cache/TTL and
  memory stores, custom store registration and shared contract tests. Ship one
  publishable migration, never automatically executed; no Eloquent models.
- Restrict built-in files to local Laravel disks with the shared nonexpiring
  JS/PHP marker; support process-local Array or standalone plain Redis 7+ CAS
  for cache storage. Reject unsupported/decorated/failover/cluster backends;
  custom RunStores support other backends. Disable native Redis command replay.
- Reject failed persistence before submission, retain late accepted handles,
  validate every request option, sanitize operational logs, preserve opaque
  JSON object/list shapes, and sort mixed-precision timestamps chronologically.
  Forward quick OpenAI effort through the native Laravel AI SDK option seam.
- Keep the encrypted one-attempt job, drivers, redaction, pricing and deadlines.
  Save signed handles for every durable driver, including OpenAI and You.com
  research, DataForSEO standard engines and optional async collectors.
- Use the shared source catalog: 16 research sources, five named-only engines,
  source options in place of extra profiles, and user-saved groups only.
  Budget admission is greedy in catalog order; unpriced sources are skipped
  under a cap. Accepted work may still bill above it.
- Add Laravel HTTP Firecrawl search to core; optional browser collection stays
  in its adapter. Add `librarium:research`, `show`, `sources`, `prune` and the
  Run evidence tool. Artisan research supports progress and Ctrl-C detachment.
- Pin `jkudish/librarium-spec` schemas and selection/Run/store fixtures rather
  than the retired TypeScript repin and `contracts/v1` machinery.
- Require PHP 8.4+, Laravel 13.30+, Laravel AI SDK ^1.0.1 (excludes the 1.0.0
  SSRF advisory), and laravel-ai-pricing ^0.3.1 with dated rate windows.
  Verify lowest PHP 8.4 and highest PHP 8.5 lanes and exact-head offline gating.
