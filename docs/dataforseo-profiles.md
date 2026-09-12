# DataForSEO surface Profiles

The six DataForSEO Profiles collect answers rendered on ChatGPT, Gemini, and
Google AI Mode. They are disabled by default and are intended for explicit
surface-comparison work. They do not join the curated `quick` or `visibility`
groups and never fall back between DataForSEO modes or to another collector.

## Configure the Profiles

Publish `config/librarium.php`, set the provider credentials, then enable only
the Profiles the application intends to use:

```dotenv
DATAFORSEO_LOGIN=your-api-login
DATAFORSEO_PASSWORD=your-api-password
```

```php
// config/librarium.php
'profiles' => [
    'dataforseo-chatgpt' => [
        // Keep the other shipped fields unchanged.
        'enabled' => true,
        'options' => [
            'location_code' => 2840,
            'language_code' => 'en',
            'force_web_search' => true,
        ],
    ],
],
```

Credentials belong only in `librarium.providers.dataforseo.login` and
`librarium.providers.dataforseo.password`; do not put them in Profile options.
Librarium excludes raw and encoded Basic credentials from Profile, preview,
status, event, error, and terminal-result serialization.
Receipts containing configured raw or encoded credential values are rejected
with a fixed diagnostic, including otherwise successful provider responses.

Every Profile requires an explicit positive `location_code` and a
`language_code`. ChatGPT additionally requires the boolean
`force_web_search`, which defaults to `true` in the shipped configuration but
may be set to `false`. Gemini accepts no additional control in this slice.
Google AI Mode may omit both device fields to use provider defaults, or set one
of these complete pairs:

- `device: desktop` with `os: windows` or `os: macos`
- `device: mobile` with `os: android` or `os: ios`

Unknown controls and incompatible combinations fail before HTTP. Scraper
queries are limited to 2,000 characters and AI Mode queries to 700 characters;
Librarium rejects longer rendered prompts instead of truncating them.
Use the location/language lists linked from each surface's endpoint documentation
below; supported values differ by product. Librarium does not discover them at
runtime or translate application location records automatically.

## Choose Standard or Live explicitly

| Surface | Standard | Live |
| --- | --- | --- |
| ChatGPT | `dataforseo-chatgpt` | `dataforseo-chatgpt-live` |
| Gemini | `dataforseo-gemini` | `dataforseo-gemini-live` |
| Google AI Mode | `dataforseo-google-ai-mode` | `dataforseo-google-ai-mode-live` |

Standard is the recommended background-collection strategy. It submits one
task once, retains that submission's reported charge, and privately polls the
matching free Advanced GET until completion or the shared Librarium deadline.
Task statuses `40601` and `40602` remain pending when the provider omits or
nulls the optional `result_count` and `result` fields; contradictory nonempty
result data is rejected. Only retrieval failures are retried. An ambiguous or
malformed paid POST is never retried, and a slow Standard task never switches
to Live.

Live makes one paid POST and waits for its response. It must be selected by its
`-live` Profile name; there is no automatic choice or fallback.

Both modes use the normal API:

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$response = Librarium::query('How do passkeys resist phishing?')
    ->using('dataforseo-chatgpt')
    ->maxCost('0.0012')
    ->run();

Librarium::query('How do passkeys resist phishing?')
    ->using('dataforseo-google-ai-mode-live')
    ->maxCost('0.004')
    ->queue()
    ->then(fn ($response) => /* persist the terminal response */ null);
```

The package's reviewed pricing snapshot binds one conservative `requests: 1`
unit to each exact Profile identity. A consuming application may override the
corresponding identity in `config/ai-pricing.php`. `maxCost()` fails closed if
the rate is missing, stale, non-conservative, or exceeds the ceiling. Pricing
is a preflight estimate; failed or ambiguous provider tasks may still be
billable, and a failed `ResearchResponse` cannot represent that unresolved
charge. Reconcile such cases against the DataForSEO account rather than
assuming zero cost.

## Compare all three surfaces

Applications may add an opt-in group without changing the built-in curated
rosters:

```php
// config/librarium.php
'groups' => [
    // Keep the shipped groups.
    'dataforseo-standard' => [
        'dataforseo-chatgpt',
        'dataforseo-gemini',
        'dataforseo-google-ai-mode',
    ],
],
```

The existing `all` group is derived from every configured Profile. Therefore,
enabled DataForSEO Profiles participate in `all`; disabled or unavailable ones
remain visible in preview diagnostics but are not dispatched.

## Interpret the result

Each successful invocation returns exactly one `surface_observation` with
`surface_snapshot` observation mode and `surface_collector` retrieval:

- `provider` and `collector` are `dataforseo`;
- `surface` is the actual `chatgpt`, `gemini`, or `google_ai_mode` product;
- authentication and personalization remain `unknown`;
- `observed_at` comes from the provider's UTC collection `datetime`;
- only real captured source records become collector-extracted citations;
- missing citations are valid because grounding is optional; and
- Google organic results never substitute for a missing AI Mode overview.

Requested location, language, device, OS, and web-search controls remain request
echoes in bounded provider metadata. They are not measured geography, locale,
authentication, or personalization facts. The task ID is a terminal receipt
reference only; no public resumption or account-wide task-list API is exposed.
Consumers own persistence, evidence policy, and verification thresholds.

Aggregate answer Markdown is preferred. When it is absent, the mapper projects
supported text/table or AI overview elements and reports dropped rich child
types in `provider_meta.omitted_item_types`. That key is absent when aggregate
Markdown is retained: rich content may already be represented there.

## Queue safety and worker loss

`queue()` executes the same operation in one encrypted job with one total
automatic attempt. Keep the default 6,900-second research deadline within the
7,200-second job timeout, and configure worker timeout, queue `retry_after`, and
process `stopwaitsecs` in the order documented in the main README.

This prevents routine job-level resubmission but cannot promise exactly-once
provider execution under every worker failure. If a worker dies after a
Standard POST, the DataForSEO task can remain billable and retrievable in the
provider account while Librarium loses its private in-process handle. The
Driver does not persist, resume, list, or resubmit that task.

Multiple selected Profiles execute serially within one shared deadline. For
routine three-surface Standard collection, enqueue a separate query per Profile;
the application owns concurrency and rate limits. Queue waiting time before a
worker starts is not part of the execution deadline. Polling waits 10–15 seconds
initially, then 25–30 seconds with jitter, always capped by that deadline.

## Endpoint references

- ChatGPT: [Live Advanced](https://docs.dataforseo.com/v3/ai_optimization/chat_gpt/llm_scraper/live/advanced/), [Standard POST](https://docs.dataforseo.com/v3/ai_optimization/chat_gpt/llm_scraper/task_post/), [Advanced GET](https://docs.dataforseo.com/v3/ai_optimization/chat_gpt/llm_scraper/task_get/advanced/)
- Gemini: [Live Advanced](https://docs.dataforseo.com/v3/ai_optimization/gemini/llm_scraper/live/advanced/), [Standard POST](https://docs.dataforseo.com/v3/ai_optimization/gemini/llm_scraper/task_post/), [Advanced GET](https://docs.dataforseo.com/v3/ai_optimization/gemini/llm_scraper/task_get/advanced/)
- Google AI Mode: [Live Advanced](https://docs.dataforseo.com/v3/serp/google/ai_mode/live/advanced/), [Standard POST](https://docs.dataforseo.com/v3/serp/google/ai_mode/task_post/), [Advanced GET](https://docs.dataforseo.com/v3/serp/google/ai_mode/task_get/advanced/)
- [DataForSEO status and error codes](https://docs.dataforseo.com/v3/appendix/errors/)
