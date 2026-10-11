# Parallel and Valyu research Profiles

`parallel-research` and `valyu-research` run one durable provider research task
each. Every processor or mode the provider documents is configurable, and the
shipped defaults stay at Parallel `pro` and Valyu `standard`. The option names
match the TypeScript sibling (`jkudish/librarium`), in snake_case instead of
camelCase.

Sources were checked on 2026-10-11:

- Parallel processors: <https://docs.parallel.ai/task-api/guides/choose-a-processor>
- Parallel pricing: <https://docs.parallel.ai/getting-started/pricing>
- Parallel Create Task Run: <https://docs.parallel.ai/api-reference/tasks/create-task-run>
- Valyu pricing and runtimes: <https://docs.valyu.ai/pricing>
- Valyu recommended timeouts: <https://docs.valyu.ai/guides/deepresearch>
- Valyu DeepResearch: <https://docs.valyu.ai/api-reference/endpoint/deepresearch-create>

## Parallel processors

The Profile `model` is the processor (TypeScript calls it `processor`). Set
`pricing.model` to `research-<processor>` with it; a Profile whose pricing
identity names a different processor is reported unavailable rather than
quoted at the wrong price. Parallel bills one charge per successful run.

| Processor | Price per run (USD) | Documented latency | Default deadline |
| --- | ---: | --- | ---: |
| `lite` | 0.005 | 10 s – 2 min | 30 min |
| `base` | 0.01 | 15 s – 3 min | 30 min |
| `core` | 0.025 | 60 s – 5 min | 30 min |
| `core2x` | 0.05 | 60 s – 10 min | 30 min |
| `pro` (default) | 0.10 | 2 – 10 min | 30 min |
| `ultra` | 0.30 | 3 – 25 min | 45 min |
| `ultra2x` | 0.60 | 5 – 50 min | 75 min |
| `ultra4x` | 1.20 | 5 – 90 min | 135 min |
| `ultra8x` | 2.40 | 5 min – 2 h | 180 min |

Processors through `pro` finish within 10 minutes and keep a 30-minute
deadline. The `ultra` processors get 1.5 times their documented upper latency,
rounded up to 15 minutes, because the latency excludes queue time. The delisted
`pro-fast` and `ultra-fast` processors are rejected.

```php
'parallel-research' => [
    // ...
    'model' => 'ultra',
    'options' => [
        'include_domains' => ['sec.gov', '.edu'],
        'after_date' => '2026-01-01',
        'metadata' => ['tenant' => 'acme'],
    ],
    'pricing' => [
        'provider' => 'parallel',
        'model' => 'research-ultra',
        'usage' => ['processor_requests' => 1],
        'conservative' => true,
    ],
],
```

### Task Run options

| Option | Sent as | Rules |
| --- | --- | --- |
| `include_domains` | `source_policy.include_domains` | 1–200 domains, domain/path prefixes, or bare extensions such as `.edu` |
| `exclude_domains` | `source_policy.exclude_domains` | Same; at most 200 entries combined with `include_domains` |
| `after_date` | `source_policy.after_date` | `YYYY-MM-DD` |
| `location` | `advanced_settings.location` | ISO 3166-1 alpha-2 country code |
| `output_schema` | `task_spec.output_schema` | A text description keeps a Markdown report. A JSON Schema object sends `{type: json, json_schema}` and returns a `json` result whose content is the provider's object. The default is a text description asking for a thorough cited report. |
| `metadata` | `metadata` | Flat object; keys of at most 16 characters; string, number, or boolean values; strings of at most 512 characters |
| `memory_scope_key` | `memory_scope_key` | 1–128 characters of `A-Z`, `a-z`, `0-9`, `_`, `-`. Omit it to use Parallel's personal memory. |
| `request_timeout_seconds` | not sent | 1–86400; replaces the processor's default deadline |

These documented parameters are not exposed:

| Parameter | Reason |
| --- | --- |
| `previous_interaction_id` | The interaction ID equals the private run ID, which Librarium keeps out of results. |
| `mcp_servers` | Carries credential headers and adds non-web tools the Profile provenance does not describe. |
| `advanced_settings.data_sources` | Partner sources are not web corpora, and pay-per-use partners are billed outside the processor price. |
| `enable_events` | Librarium polls the run and does not read the event stream. |
| `webhook` | The package has no Parallel webhook receiver; durable recovery polls. |
| `task_spec.input_schema`, object `input` | The query is always a text prompt. |
| `parallel-beta` header | Beta features are not part of the stable request contract. |

## Valyu modes

`options.mode` selects the DeepResearch mode. Set `pricing.model` to
`research-<mode>`; a mismatched pricing identity makes the Profile unavailable.
Valyu prices each task at a fixed amount per mode.

| Mode | Price per task (USD) | Documented runtime | Default deadline (Valyu's recommended timeout) |
| --- | ---: | --- | ---: |
| `fast` | 0.10 | ~5 min | 10 min |
| `standard` (default) | 0.50 | ~10–20 min | 30 min |
| `heavy` | 2.50 | up to ~90 min | 120 min |
| `max` | 15.00 | up to ~180 min | 180 min |

`max` is never a default. A Profile configured with `mode: max` is left out of
the built-in `deep` and `all` groups and runs only when selected by ID or
through a custom group.

`options.output_formats` is `['markdown', 'pdf']` (default, so the signed PDF
link is exposed) or `['markdown']` to opt out of the PDF. Valyu's pricing
states that PDF output is not a billed deliverable.
`options.request_timeout_seconds` (1–86400) replaces the mode's default
deadline.

## Deadlines

A default deadline applies while `run()` polls, synchronously or in a queued
job. It starts when the driver begins and is capped by the shared research
deadline (`librarium.timeout`, default 6900 seconds, or
`PendingResearch::timeout()`). `request_timeout_seconds` replaces the default,
and the shared deadline still caps it. Durable `submit()`/`retrieve()` calls
have no per-processor deadline: the application decides when to stop
retrieving.

The `ultra4x`, `ultra8x`, `heavy`, and `max` defaults exceed the shipped
6900-second shared timeout. To give them their full default, raise
`librarium.timeout` and keep `librarium.queue.timeout` above it. A stopped
Parallel run keeps running remotely and is billed on completion. Librarium
asks Valyu to cancel a task it stops waiting for.

## Valyu PDF link

When Valyu returns `pdf_url`, the result's `provider_meta["valyu:research"]`
artifact records it:

```json
{"kind": "pdf", "url": "https://storage.valyu.ai/…", "expires_at": "2026-09-10T13:10:00Z"}
```

The URL is a bearer link: anyone holding it can download the report until it
expires. Store and share it like a credential.

- `expires_at` appears only when the signed URL states an expiry: AWS SigV4
  `X-Amz-Date` plus `X-Amz-Expires`, Google V4 `X-Goog-Date` plus
  `X-Goog-Expires`, or an epoch `Expires` (S3 SigV2, CloudFront). Valyu
  documents no separate expiry field.
- The URL is withheld, leaving `{"kind": "pdf"}`, unless it is an HTTPS URL
  without user information that contains neither the credential nor the
  private task ID.
- The driver replaces every occurrence of the URL with `[REDACTED]` before a
  provider response is captured. Redacted evidence, sensitive collections,
  operational diagnostics, and error messages therefore never contain it.
  Only the result carries it, including `--agent` result JSON.
