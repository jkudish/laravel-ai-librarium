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

## Gates intentionally left open

This report does not complete PlanMode/Agentsy dogfood (#2594), the remaining
live canaries, the stable `laravel-ai-pricing` release, or the accepted
TypeScript v2 release tag. Those remain independent release gates.
