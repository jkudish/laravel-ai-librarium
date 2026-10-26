# Budget and cost

`dryRun()` plans without storage or provider traffic. Price estimates come
from `laravel-ai-pricing ^0.3.1 || ^0.4.1`; Librarium does not own a second
rate table. Money is an exact nonnegative USD decimal string. Estimates must be complete,
conservative, current and USD; otherwise they are unknown. Dated rates use the
highest price in the planned wait interval. Non-configured catalog observations
older than 30 days are not used for budget admission.

With `maxCost()` (or config `max_cost`), selection walks catalog order:

1. Drop excluded names and sources without the selected depth.
2. Skip unknown bounded estimates as `unpriced`.
3. Admit each source that fits the remaining cap; skip others as `over_budget`.

During execution, reported spend plus reservations stops unstarted work.
An in-flight call can finish above the cap. A delayed queue start can also
outlive the interval originally quoted. Provider/account controls and app
authorization remain necessary for a hard dollar ceiling.

Without a cap, unpriced sources run and aggregate estimated cost is `null`.
Explicitly named unconfigured sources fail before storage, rather than silently
disappearing. Skips are listed in catalog order.

`reported` is provider-reported USD only. Missing cost is `null`, never `"0"`.
The aggregate is complete only when every result reported its charge; reported
zero is valid. Partial reported totals are not a final bill. Credits or token
counts cannot be relabeled as provider-reported USD. Diagnostics about
possibly accepted submissions require account reconciliation, not resubmission.

Grok X/both remain unpriced because fetched posts/profiles are not bounded by
the request. Variable tool invocations, prompt/context growth and account
pricing can defeat a total bound even when output tokens are capped.
The [historical pricing analyses](history/README.md) retain provider evidence;
their old public method names are no longer application APIs.
