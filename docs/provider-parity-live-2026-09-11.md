# Provider parity live receipt — 2026-09-11

## Exact tested heads

- Core: `dfce4d2b3f06147ff0e1f94bafd54eb44141d179`
- Pricing: `7caa14b59ef56724718c63e2f9b170b296ba8e9e`
- Firecrawl: `67dfb86f7086e0fbe961298f0d81d171d2b50b55`

Composer resolved the exact pricing head for core. The Firecrawl run resolved
the exact core and pricing heads from disposable clean checkouts.

## Execution contract

- Authorized aggregate reservation: USD 5.00.
- All 16 canaries ran sequentially with their documented per-canary reservation.
- Exactly one request or paid submission was attempted per Profile.
- No failed submission was retried.
- Durable retrieval occurred only after a validated private handle.
- No credential, raw provider body, private handle, or auth-bearing URL was
  printed or retained.
- Reservation ceilings are not actual-spend claims. The providers did not
  report actual aggregate spend uniformly.

The jBeast children received only their relevant credential. The Amp orb Pest
children inherited the orb's other provider credential names, although each
canary configured and used only its named Profile credential. Future runs must
use an explicit child-environment allowlist.

## Results

| Profile | USD reservation | Outcome | Assertions | Sanitized note |
| --- | ---: | --- | ---: | --- |
| `brave-search` | 0.02 | PASS | 7 | One request. |
| `tavily-search` | 0.10 | PASS | 7 | One request. |
| `perplexity-search` | 0.02 | PASS | 7 | One request. |
| `jina-search` | 0.10 | PASS | 7 | One request; the credential alias was mapped only in the child. |
| `serpapi` | 0.10 | PASS | 7 | One request. |
| `exa-research` | 0.60 | PASS | 7 | One paid submission; handle accepted; retrieval completed. |
| `gemini-deep-research` | 2.00 | PASS | 7 | One paid submission; terminal report contained citations. |
| `perplexity-deep-research` | 0.50 | PASS | 7 | One paid submission; terminal report contained citations. |
| `grok-x-only` | 0.20 | FAIL | 2 | One request returned no valid citations; no retry. |
| `grok-combined` | 0.25 | PASS | 7 | One request with web and X Search. |
| `parallel-search` | 0.10 | PASS | 7 | One request. |
| `parallel-turbo` | 0.01 | PASS | 7 | One fixed-turbo request. |
| `parallel-research` | 0.15 | PASS | 7 | One paid submission; retrieval completed. |
| `valyu-search` | 0.15 | PASS | 7 | One request. |
| `valyu-research` | 0.60 | PASS | 7 | One paid submission; retrieval completed. |
| `firecrawl-search` | 0.10 | PASS | 7 | One request. |

## C38 disposition

The X-only canary used the common query asking for official Laravel web
documentation. The adjacent combined web-and-X canary passed with the same
credential and endpoint. This weighs against a persistent shared authentication
or transport problem but does not establish C38's root cause. A query/tool
mismatch is a working hypothesis. The harness now asks C38 for a recent post
from the official Laravel X account and its citation; that revised query remains
unverified live and was not rerun under this approval.

The prior reviewed candidate passed C38. This result therefore does not prove a
driver regression, but the corrected canary needs a new candidate SHA and a
separately approved USD 0.20 attempt.
