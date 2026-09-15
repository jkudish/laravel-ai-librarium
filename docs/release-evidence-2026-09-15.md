# PHP 1.0 completed release-gate evidence

This report records the accepted outcomes used to reconcile completed gates in
`tools/release-readiness.json`. It is release-candidate evidence, not a release,
provider-call authorization, or provider-role change.

## PHP compatibility

Pull request [#38](https://github.com/jkudish/laravel-ai-librarium/pull/38)
completed both supported dependency lanes:

- PHP 8.4 with the lowest supported dependency set; and
- PHP 8.5 with the highest supported dependency set.

Both lanes passed the package compatibility plan. Exact-SHA signoff also passed
before the pull request was merged.

Release-readiness gates:

- `php-8.4-lowest-compatibility`: complete
- `php-8.5-highest-compatibility`: complete

## Consumer-surface collector evaluation

PlanMode task #2593 completed the SearchAPI and Firecrawl evaluation. SearchAPI
references completed, while two separately authorized Firecrawl attempts
stopped at `interaction_transport_failure` before an Interact response or
output, on different corpus cases. The zero-retry, stop-first policy prevented
a complete paired packet, so Oracle was not invoked.

The accepted product decision is to use SearchAPI and DataForSEO for routine
consumer answers. Firecrawl was not promoted to that role. Specialized
evaluation pull request [#156](https://github.com/jkudish/librarium/pull/156)
was closed unmerged; only the generic script-provider regression was retained
in [#174](https://github.com/jkudish/librarium/pull/174).

Release-readiness gate `2593`: complete with a terminal no-promotion decision.

## SearchAPI live canaries

PlanMode task #2986 completed C14–C20 on 2026-09-12. All seven guarded cases
passed against core candidate
`35e3a8920b1ef1868d76f75a0970fc89d06b3425`. The run made eight total requests
within its approved USD 0.05 ceiling and retained sanitized evidence. Pull
request [#39](https://github.com/jkudish/laravel-ai-librarium/pull/39) records
the accepted outcome.

Release-readiness gate `2986`: complete.

## DataForSEO acceptance

PlanMode task #3471 completed C24–C29 on 2026-09-12 against core candidate
`8e47c1b861e23fe91b2279bfcbea099dd1a47c0c`. All six guarded cases passed. Each
made exactly one paid POST; Standard cases used only bounded retrieval GETs,
and Live cases made no retrieval GETs. Total reserved cost remained within USD
0.0156. Pull request
[#39](https://github.com/jkudish/laravel-ai-librarium/pull/39) records the
accepted outcome.

Release-readiness gate `3471`: complete.

## You.com live canaries

PlanMode task #2694 completed C21–C23 on 2026-09-15. You.com Answer,
synchronous standard Research, and durable background Research each returned
one successfully mapped result with citations. The three independently gated
submissions used zero retries or fallbacks and USD 0.105 in configured
estimates.

The first C21 test process exposed a live-harness observability defect after the
provider result passed its semantic assertions: Laravel HTTP request recording
was not enabled for the final request assertion. The harness was corrected
without repeating C21, then C22 and C23 passed against the corrected exact
target. Sanitized receipts retained no credentials, response bodies, result
content, citation text, private handles, or provider identifiers.

This evidence completes the You.com subset of the remaining-live-canaries gate.

## Perplexity frozen-low live canary

C46 passed on 2026-09-15 under #2595 at exact core target
`bca04cc092fccae92a585a29eb22a661783941bf`. The frozen
`perplexity-low-frozen` Agent configuration made exactly one authorized POST and
returned one successfully mapped grounded answer with nonempty content and
citations. It used zero retries or fallbacks. The USD 1.00 reservation was an
external ceiling; the provider did not report actual USD cost. Credential scan
found zero occurrences, no provider response or result content was retained,
and the raw test log was deleted.

Together with C21–C23, this completes the aggregate remaining-live-canaries
gate.

## Stable pricing release and constraint

`jkudish/laravel-ai-pricing` v0.1.2 was published on 2026-09-15 from merge
commit `4a7fd874fdaa86abc0b77bd0c4d431cfeddb8b75`. Its fresh `main` checks passed
the PHP 8.4/8.5 Linux and Windows matrix, the lowest supported Brick Math lane,
quality checks, and a clean Laravel 12 consumer installation. Composer and
Packagist resolve v0.1.2 to that same source commit.

Librarium now requires the stable `^0.1.1` compatibility range, which resolves
to v0.1.2 and excludes future 0.2 breaking changes. The release checker pins
that exact constraint so another stable but incompatible range cannot silently
satisfy the gate.

Release-readiness gate `stable-pricing-release`: complete.

## Gates intentionally left open

This report does not complete PlanMode/Agentsy dogfood (#2594) or the accepted
TypeScript v2 release tag. Those remain independent release gates.
