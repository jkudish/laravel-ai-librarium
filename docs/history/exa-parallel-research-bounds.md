# Exa Research levels and Parallel Research bounds

Sources accessed **2026-10-09**:

- Exa [pricing](https://exa.ai/docs/admin/pricing), [Agent quickstart](https://exa.ai/docs/agent/quickstart),
  [Agent Ultra](https://exa.ai/docs/agent/agent-ultra), and the OpenAPI source of truth
  [`exa-spec.yaml`](https://exa.ai/docs/exa-spec.yaml) (`AgentEffort`, `AgentBudget`,
  `AgentStopReason`, `AgentCostDollars`).
- Parallel [pricing](https://docs.parallel.ai/getting-started/pricing),
  [processors](https://docs.parallel.ai/task-api/guides/choose-a-processor), and
  [data connectors](https://docs.parallel.ai/resources/data-connectors).

## Exa Agent research levels

Exa's metered efforts are `auto` and `ultra`. Both bill Agent Compute Units
(USD 0.10 per ACU) and search tool calls (USD 0.005 per search) up to a per-run
cap. `budget.maxCostDollars` accepts USD 1–100 and applies only to `auto` and
`ultra`; without it the cap defaults to USD 5 (`auto`) and USD 20 (`ultra`).
`budget.maxDurationSeconds` (300–10,800) applies only to `ultra`; as a run
approaches either limit the agent stops starting new work and completes with
`stopReason` `budget_reached` or `time_limit_reached`. Fixed efforts (`minimal`
through `xhigh`, USD 0.012–1.00 per request) reject a budget.

The current API names the highest metered effort `ultra`. The earlier `max`
beta effort and its `Exa-Beta: agent-max-effort-2026-07-27` header no longer
appear in the OpenAPI enum, so the driver now accepts `ultra` and rejects
`max` before dispatch.

`ExaResearchConfiguration` (revision `2026-10-09`) freezes two opt-in Profile
templates. They are disabled, have no credential, and belong to no group:

| Profile | Request | Native bound |
| --- | --- | --- |
| `exa-research-auto` | `effort: auto`, `budget.maxCostDollars: 1` | USD 1 |
| `exa-research-ultra` | `effort: ultra`, `budget.maxCostDollars: 5`, `budget.maxDurationSeconds: 3000` | USD 5 |

The driver accepts a template Profile only when its options equal the template
exactly, including `options.configuration`. The general `exa-research` Profile
cannot claim a configuration. Any other value, including a larger cap, returns
an `unknown` spending bound and fails `exa_research.invalid_profile` before HTTP.
Completed runs report `costDollars.total`, which the mapper returns as
`Usage::actualCost` in USD. A consumer settles at that amount. The cap is a
provider control, so a consumer must still record any reported total above it
rather than clamp it.

## Parallel Research

`parallel-research` runs one Task API run on the `pro` processor. Parallel
charges USD 100 per 1,000 successful runs (USD 0.10 per run), regardless of
output fields, and never bills failed runs. The only documented surcharges are
pay-per-use data connectors and bring-your-own MCP servers, both opt-in per run;
the driver sends neither. Polling and result retrieval are free, and the driver
never resubmits.

The driver therefore proves `billable_quantities`: one `processor_requests`
unit under `parallel:research-pro`, resolved by `jkudish/laravel-ai-pricing`.
Any request the driver would refuse (another Profile id, processor, option or
invalid input) returns `unknown`. Parallel reports no charge in the result, so
`Usage` stays absent; the consumer settles the run from the priced quantity.
