# You.com Answer and Research Profiles

Librarium exposes all three accepted You.com semantic Profiles. They share
credential resolution, while preserving separate package-native selectors,
depth, result identity, and execution behavior.

| Laravel Profile | Cross-language semantic target | Endpoint | Effort | Execution | Estimate |
| --- | --- | --- | --- | --- | --- |
| `you-answer` | `you-answer/grounded` | `POST https://api.you.com/v1/answer` | single search | inline | exact $0.005/request |
| `you-research-grounded` | `you-research/grounded` | `POST https://api.you.com/v1/research` | `standard` | inline | exact $0.05/request |
| `you-research-research` | `you-research/research` | `POST https://api.you.com/v1/research` | `standard` | background provider task | exact $0.05/request |

These mappings follow the accepted Librarium task #2693 identities and terminal
semantics without importing its TypeScript coordinator or background-worker
architecture. The Laravel Profile ID is the package-native selector and terminal
Profile value. The provider value remains separately `you-answer` or
`you-research`. None is an alias for another. No cross-depth fallback is
configured; incompatible grounded-answer and research-report semantics also
prevent either inline Profile from serving as the durable Research fallback.

Laravel AI does not currently expose these You.com endpoints through a truthful
prompt primitive, so all three Profiles use small Laravel HTTP Drivers. They use
the normal Librarium request-wide deadline and runtime. `you-answer` performs
one provider request in `run()`. `queue()` sends the same compiled plan through the
package's encrypted, one-attempt Laravel job and returns the same terminal
semantics; it never exposes a You.com handle. `you-research-grounded` sends one
synchronous standard-effort Research request and never sends `background`.
`you-research-research` submits the accepted `you-research/research` provider
task with `background: true`, polls and retrieves it within the same
request-wide deadline, and returns only a terminal response. The remote task is
durable, while its handle remains private to the Driver. Other effort tiers and
structured output need separate explicit Profiles and are not silently enabled.

## Credentials

`YOU_COM_API_KEY` remains canonical for compatibility. `YDC_API_KEY`, the name
used in current You.com examples, is a fallback only when `YOU_COM_API_KEY` is
missing or blank:

```dotenv
YOU_COM_API_KEY=...
# Or, when the canonical variable is not set:
YDC_API_KEY=...
```

All three Profiles resolve one value into their in-memory Profile configuration.
Secrets do not enter previews, serialized Profiles, results, provider metadata,
errors, or URLs.

## Answer options and evidence

Configure Answer controls under `librarium.profiles.you-answer.options`:

```php
'options' => [
    'freshness' => 'month',
    'country' => 'CA',
    'language' => 'EN',
    'exclude_domains' => ['spam.example'],
    'boost_domains' => ['primary.example'],
],
```

Supported keys are `freshness`, `country`, `language`, `include_domains`,
`exclude_domains`, and `boost_domains`. The Driver validates the final rendered
query as nonblank and at most 400 UTF-16 units, matching the accepted mapping.
It validates current documented
country/language inventories, real and ascending freshness date ranges, domain
syntax, nonempty domain lists, and the 500-domain cap. `include_domains` cannot
coexist with either other domain control; `exclude_domains` and `boost_domains`
may coexist. Accepted domain values are trimmed and lowercased on the wire to
match the cross-language mapping. Unknown options fail before HTTP.

The answer remains the result content. Every citation becomes provider-reported
evidence, and all verbatim excerpts, numbered inline-reference counts, and every
documented considered `results.web` field are retained in the allowlisted
`you-com:answer` provider metadata. Considered-but-uncited results remain
distinct and never become citations.

Answer and inline Research are explicit opt-in: neither is in `quick`,
`visibility`, `deep`, or the default group. Durable Research participates only
in the non-default `deep` group. All three represent API output, not a capture
of You.com's consumer UI.

## Pricing, billing, and privacy

The Answer guide documents $5 per 1,000 calls, so preview reserves exactly 5,000
micro-USD per request. The current billing page documents standard Research at
$50 per 1,000 calls, so each Research Profile estimates exactly 50,000
micro-USD. These network-free estimates make hard `maxCost()` preflight
possible.

The official documentation reviewed on 2026-08-23 does not state whether every
non-2xx Answer or Research attempt is billed. Librarium therefore does not
invent actual spend for failed calls; reconcile failed attempts only after
You.com confirms billing behavior or provides an authoritative usage record.

Zero Data Retention is an enterprise account-level capability. It is not a
request option and Librarium does not claim that it is enabled for an account.

Official references:

- <https://you.com/docs/guides/answer>
- <https://you.com/docs/api-reference/answer/v1-answer>
- <https://you.com/docs/guides/research>
- <https://you.com/docs/api-reference/research/v1-research>
- <https://you.com/docs/administration/billing>
- <https://you.com/docs/administration/zero-data-retention>

## Paid live smoke gate

Ordinary tests use Laravel HTTP fakes. Each live canary requires two independent
opt-ins, a credential, and an exact dataset filter. Run only the separately
approved Profile:

```bash
LIBRARIUM_LIVE_TESTS=1 \
LIBRARIUM_YOU_LIVE_SPEND_USD=0.005 \
YOU_COM_API_KEY=... \
vendor/bin/pest tests/Live/YouProfilesLiveTest.php \
  --filter='You Answer' --fail-on-skipped
```

Use `LIBRARIUM_YOU_LIVE_SPEND_USD=0.05` with the exact `You Research grounded`
or `You Research durable` dataset filter for either Research canary. Approval
for one dataset does not authorize either other dataset. `YDC_API_KEY` may
replace `YOU_COM_API_KEY`. Do not run these commands as part of ordinary CI.
Capture only redacted latency, evidence, error, and spend proof for the release
gate; do not persist provider secrets or raw responses.
