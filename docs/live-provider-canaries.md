# Live provider canary approval gates

Ordinary verification is offline. A live call requires approval for the exact
candidate, source/depth/options, fixed question, one-attempt policy and an
independently enforced per-call and aggregate USD cap. A flag or credential is
not authorization. Use isolated child environments containing only the chosen
credential and controls; never print keys, raw bodies, handles or signed URLs.

Coverage should include materially different source variants and collectors,
not repeat saved groups. DataForSEO standard/live, You answer/inline research/deep,
Google collectors and verticals, Grok web/X/both, and each durable provider are
distinct boundaries. Firecrawl search is core; browser Interact/Agent is adapter.
Cloro standard (async task) and live (sync) are distinct boundaries per
surface, and its `google` web and news calls are a SERP boundary.

`composer test:live:cloro` (needs `LIBRARIUM_LIVE_TESTS=1` and `CLORO_API_KEY`)
runs four standard surfaces, three live surfaces and Google web and news once
each: about 47 credits. Cloro's free plan allows one concurrent request, so the
cases run sequentially and must not run in parallel. It reports credits, not
USD; read them from `x-credits-charged` or `credits.creditsCharged` in
evidence and check `GET /v1/credits` before and after.

Before spending, retain heads, runtime/dependency inventory, current provider
contract review, the approved ledger and exact payload controls. Unknown or
variable costs need an external cap; `max_cost` alone does not enforce a bill.
Afterward retain sanitized outcomes, submissions, citations count, latency and
reported charge or explicit unknown. An ambiguous submit is terminal for the
attempt; only known-handle retrieval may continue. No automatic retry/fallback.

The `tests/Live` harnesses use `research()` and the public Run surface, with an
in-memory Run store. Legacy profile ids remain frozen dataset names and canary
selectors; they map to explicit sources, depth and options, not public APIs.
Existing opt-in, credential, USD-cap and unpriced-account-cap acknowledgements
remain required. Migration and offline checks do not authorize live execution.
Review the selected harness and controls before an authorized canary; do not
run bulk scripts or treat historical approval as current authority.
Historical receipts remain in [history](history/README.md); they document only
their named heads and do not complete new-candidate live acceptance.
