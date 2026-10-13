# Runs, storage and recovery

Every non-dry-run request saves a Run before its first provider call. Public
Run JSON follows `schema/run.schema.json` in the pinned shared spec package.
`Run::fromArray()` validates it and round-trips optional `summary_of` from
JavaScript summary Runs. PHP does not synthesize summary Runs.

## Read a stored Run

```php
$run = Librarium::find($id);
$current = $run?->refresh();
$current?->result('openai');
$current?->evidence('openai');
$current?->diagnostics();
```

A Run is an immutable snapshot. `refresh()` returns a new snapshot after one
observation of each running durable source. It never submits queued sources,
replays paid work or cancels tasks. Temporary retrieval failures leave the task
recoverable; permanent failures end its result. `run()` and the encrypted queue
job submit then observe until completion or the shared wait timeout.

Durable variants include deep Exa, Gemini, OpenAI, Parallel, Perplexity, Valyu
and You.com, plus quick DataForSEO standard engine tasks. Collector Agent
modes can also be durable. Inline drivers remain inline at any quick variant.

The private state holds HMAC-signed handles, request options, submission leases
and diagnostics. Handles bind the Run, provider, variant, driver, model,
retrieval semantics, options and credential identity. Tampering, app-key
rotation or credential/config changes fail before retrieval HTTP. Retain a
controlled recovery deployment with the original binding if rotating keys.
No credential is persisted. A lost POST response may still mean paid work was
accepted; do not repeat it automatically. Process loss after acceptance but
before handle persistence cannot be made exactly-once by a local store.

## Choose a store

```php
// config/librarium.php
'runs' => [
    'store' => 'filesystem', 'disk' => 'local', 'path' => 'librarium',
    'encrypt' => true, 'keep_days' => null,
],
```

- **filesystem:** local Laravel disks only. Read/write/delete use the common
  JS/PHP `run.json.lock` exclusive-create marker, with no age-based expiry and
  token-checked release. All writers/readers must participate in this protocol.
  A blocked operation fails after ten seconds; this does not expire the owner.
  If its owner crashed, confirm no process can still access that Run before
  manually removing the marker. Never remove a live owner's marker.
  Default encryption uses Laravel's app key; key rotation requires recovering
  old ciphertext before removing the old key. Set `encrypt: false` only for a
  protected interchange directory.
- **database:** set `connection` and `table` (default `librarium_runs`). Publish
  with `php artisan vendor:publish --tag=librarium-migrations`, review the
  migration, then run it under your application's database authorization.
  One query-builder row stores run, state, files and version. No automatic
  migration and no Eloquent model. The supplied migration creates the default
  table; customize it if changing the configured name/connection.
- **cache:** set `cache_store` and `ttl` (seconds, default 604800). Supported
  backends are undecorated ArrayStore (process-local nonexpiring token lock)
  and plain standalone RedisStore. Redis requires Redis 7+, uncompressed and
  unserialized PhpRedis or Predis 3.4+'s native StreamConnection, native command
  retries disabled, and permission to run Lua with INFO/GET/TYPE/SET/SADD/DEL/SREM
  ACL preflight. INFO confirms the server is not a cluster node before mutation.
  Configure the dedicated Laravel Redis connection with `max_retries: 0`;
  leave serializer/compression/retry options unset or disabled. Redis commits
  compare the complete prior JSON and atomically write document TTL and index
  membership. Cluster, decorated, memoized and failover caches are rejected.
  Expiry or eviction deletes recovery material: use persistent storage if
  losing a paid task handle is unacceptable. Stale index members never restore
  expired documents and are removed by explicit deletion.
- **memory:** process-local, useful for tests. Not shared between workers.

Other disks/caches can implement the [RunStore contract](writing-a-run-store.md).
The built-in stores are not transactions over provider acceptance. A lost
Redis commit response is indeterminate; inspect persisted state, never treat
the exception as permission to submit again. Filesystem state is written last,
but a crash mid-write can leave an incomplete folder requiring operator recovery.
Deletion retains empty filesystem directories so another writer cannot bypass
the shared marker while the first writer deletes files.

All stores implement optimistic concurrency. `save($doc, null)` creates;
`save($doc, $version)` updates only that version. A stale writer throws
`RunStoreConflict`. Runtime observations merge onto the latest document and
never regress terminal results or submit a claimed source again.

## Shared filesystem layout

```text
<path>/<run_id>/run.json
<path>/<run_id>/state.json
<path>/<run_id>/run.json.lock (only while owned, or after an owner crash)
<path>/<run_id>/answers/<source>.md
<path>/<run_id>/evidence/<source>.json
```

`run.json` is exactly public Run JSON. `state.json` is
`{"version": N, "state": {...}}`; no plain-state fallback exists. Writers
update it last. Files are UTF-8 strings. Unencrypted folders from JavaScript
are readable by PHP and vice versa. Encryption is PHP-specific.

## Evidence and retention

Every Run retains redacted exchanges separately from public results/events.
Credentials and encoded forms, common secret-bearing keys, auth-bearing URLs
and unsafe headers are stripped before storage. Unparseable or unsafe bodies
are omitted with a reason, never returned verbatim. Configure extra dotted
`redaction.paths` and regex `redaction.patterns` for application-private data;
reasoning text is not automatically confidential. Evidence may still contain
private source material. Restrict access, encrypt storage and choose retention.
There is no raw sensitive capture API.

Diagnostics distinguish temporary/rate-limit/permanent/unknown failures and
not-sent/rejected/possibly-accepted/accepted submissions. Retry dates are hints,
not permission to spend again. Only provider-reported USD is reported cost.
Credits or missing cost remain unknown. A pending task's paid submission cost
is retained in its signed handle rather than counted again on every refresh.

Set `runs.keep_days` to a positive integer and run `librarium:prune` to delete
old Runs. Unset retention does nothing. Pruning can delete pending handles;
providers may still finish and bill, so choose retention longer than task life.

## Worker deadlines

Defaults: wait timeout 6900 seconds; encrypted job timeout 7200 seconds; one
attempt. Configure worker timeout at least the job timeout, queue `retry_after`
above worker timeout, and process-manager stop wait above both. Timeout stops
local waiting, not billing. Saved durable handles survive worker failure.
Results without handles become `interrupted`; recovery never resubmits them.
Webhooks are verified wake hints; retrieval remains authoritative.
