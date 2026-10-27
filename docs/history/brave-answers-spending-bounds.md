# Brave Answers spending-bound evidence

Per-dimension evidence for the `brave-answers` Profile's spending-bound
classification. This document records what Brave's official contract
documents today and which guarantees would be indispensable before a
complete pre-dispatch bound could be claimed for this route.

## Verdict

No documented complete bound is established for the shipped `brave-answers`
route (one single-search grounded answer). The Profile therefore stays
`SpendingBoundKind::unknown`, and its option-free configuration rejects
every provider control rather than silently serializing one.

This is a statement about the documented official contract, not a
mathematical impossibility. The offline regressions in
`tests/Feature/BraveAnswersSpendingBoundTest.php` prove Librarium's local
fail-closed behavior — the classification stays `unknown`, pseudo-controls
are rejected loudly, and the exact paid payload carries no cap. They do not
prove the absence of provider capabilities, and Brave's changelog shows the
contract is actively evolving (a default changed as recently as 2026-08-21).
Re-derive this verdict from the sources below before changing anything.

## Official sources

All URLs accessed 2026-09-18 (Pacific). The paid endpoint for every claim
below is `POST https://api.search.brave.com/res/v1/chat/completions`.

| Source | URL |
| --- | --- |
| Answers service documentation (pricing, spending limits, modes, changelog) | https://api-dashboard.search.brave.com/documentation/services/answers |
| Answers API reference (request body, enforced parameter ranges) | https://api-dashboard.search.brave.com/api-reference/summarizer/answers |
| Pricing plans | https://api-dashboard.search.brave.com/documentation/pricing |
| Public pricing page | https://brave.com/search/api |
| Official Brave Answers skill file (parameter semantics, mode table, usage examples) | https://github.com/brave/brave-search-skills/blob/main/skills/answers/SKILL.md |

## Billed dimensions

The service documentation defines the cost of one answer as:

> `cost = (searches × $4/1000) + (input_tokens × $5/1000000) + (output_tokens × $5/1000000)`

Each response returns per-request usage metadata with the keys
`X-Request-Requests`, `X-Request-Queries`, `X-Request-Tokens-In`,
`X-Request-Tokens-Out`, and per-dimension cost keys. The documentation's
example shows `"X-Request-Queries": 2` on a single request, and the skill
file's research example shows `"X-Request-Queries":8,"X-Request-Tokens-In":15000,"X-Request-Tokens-Out":2000`.

So one exact request can bill three quantities: **searches (queries)**,
**input tokens**, and **output tokens**. A complete bound must cap all
three, including Brave-injected content (system prompt, retrieved context)
and any mixer-internal model calls.

## Documented request controls

| Control | Documented semantics | Numeric range |
| --- | --- | --- |
| `max_completion_tokens` | API reference: "`integer nullable`" with no further description. Skill file: "Upper bound on completion tokens". | None documented |
| `web_search_options.search_context_size` | Enum `low` / `medium` / `high` (OpenAI-compatible) | Qualitative only |
| `research_maximum_number_of_tokens_per_query` | "Maximum number of tokens to gather per research query." | Min 1024, Max 16384, Default 8192 |
| `research_maximum_number_of_queries` | "Maximum number of search queries research mode may issue in total." | Min 1, Max 50, Default 20 |
| `research_maximum_number_of_iterations` | "Maximum number of research iterations." | Min 1, Max 5, Default 4 |
| `research_maximum_number_of_seconds` | "Time budget for research, in seconds." | Min 1, Max 300, Default 180 |
| `research_maximum_number_of_results_per_query` | "Maximum number of search results to consider per research query." | Min 1, Max 60, Default 30 since 2026-08-21 (was 60) |
| `research_allow_thinking` | "Accepted for compatibility. Ignored by the mixer engine behind this API, which always runs research with extended thinking off." | — |
| Account monthly credit limit | "Control your spending by setting monthly credit limits in your account." / "Limits are checked before answering. If limits aren't exceeded when a question starts, it will be answered in full even if it exceeds limits during processing. You'll only be charged up to your imposed limit." | Account-month granularity, not a request parameter |

Mode constraints, quoted from the API reference: `enable_research` is
"Whether to answer with iterative deep research instead of a single search.
Requires `stream=true`." and `enable_citations` "Requires `stream=true` and
is not supported in research mode." The skill file's mode table describes
single-search as "Searches: 1" — descriptive documentation, not a
request-contract guarantee (see searches below). In research mode, "only
the final answer is emitted; intermediate drafts are dropped".

## Why addable controls still leave a billed dimension unbounded

The shipped Profile currently rejects every option, so no consumer can
serialize any control today. Suppose that rejection were lifted and the
provider's documented controls were added. Each path still leaves at least
one billed dimension without a numeric maximum:

- **Single-search with `max_completion_tokens`.** Even assuming the
  skill-file semantics ("upper bound on completion tokens") are enforced
  for the one model call — and choosing a conservative local range, which
  can substitute for the missing documented range — output would be capped,
  but billed **input** remains unbounded: retrieved context for the single
  search is governed only by the qualitative `search_context_size`, Brave's
  internal system prompt size is undocumented, and no tokenizer or
  context-rejection ceiling is documented for the user prompt. Billed
  **searches** are also not contractually pinned to one: the request
  contract contains no control or guarantee capping queries in single-search
  mode, and the documentation's own usage example shows two queries on one
  request.
- **Research mode with every `research_maximum_*` cap set explicitly.**
  This changes the route: `enable_research` is mutually exclusive with this
  route's `enable_citations` grounding. Even accepting that change, the
  enforced caps bound *gathering* (tokens per research query, queries,
  iterations), not *billing*: Brave documents no relationship between those
  caps and aggregate billed `X-Request-Tokens-In`/`X-Request-Tokens-Out`
  across the mixer's internal model calls, which re-feed accumulated
  context, generate intermediate drafts that are dropped from the stream,
  and in the official example bill 15,000 input tokens. Treating
  `queries × tokens_per_query` as a billed-input ceiling would invent
  billing semantics the contract does not state. `max_completion_tokens`'s
  scope over mixer-internal completions is likewise undocumented.
- **`web_search_options.search_context_size`.** Qualitative values with no
  documented numeric token meaning contribute nothing to a numeric maximum
  on any path.
- **Account monthly credit limits.** A real provider-side charge clamp, but
  it is checked at question start, applies at account-month granularity,
  and is not serialized in the request — so it cannot back a per-request
  `SpendingBound`.

## Shipped-adapter limitations versus provider limitations

Deliberate Librarium choices (changeable locally, not provider facts):

- The `brave-answers` Profile accepts no options (empty
  `profileOptionRules()`), so consumers cannot serialize any control;
  attempts fail validation loudly instead of being silently ignored.
- The paid payload sends exactly `model`, `messages`, `stream`, and
  `enable_citations` — no cap of any billed dimension.

Provider-contract gaps (independent of our adapter):

- No request-serialized enforced numeric ceiling over billed input tokens
  for the exact request (prompt + system prompt + retrieved context, and
  aggregate across mixer iterations in research mode).
- No contractual pin or control capping billed searches in single-search
  mode.
- No documented enforcement scope of `max_completion_tokens` over all
  billed completion tokens (a conservative local range can substitute for
  missing range documentation, but not for missing enforcement semantics).

## Indispensable missing guarantees

1. A request-serialized, provider-enforced numeric maximum over total
   billed input tokens for the exact request — or an equivalent per-request
   native monetary ceiling enforced at billing time (as Exa's
   `max_cost_dollars` is).
2. A contractual pin, or enforced request control, that single-search mode
   bills at most one search.
3. Documented enforcement of `max_completion_tokens` covering every billed
   completion token, including mixer-internal generations.

Absent guarantee 1 in particular, no combination of addable controls closes
every billed dimension. With all three, a bound could be expressed as
billable quantities under the `brave:answers` pricing identity or a native
monetary ceiling, and the shared spending-bound inventory would need a
coordinated update.

## Integration note

The shared `docs/spending-bounds.md` brave-answers paragraph remains at its
baseline wording. If the integration owner wants readers to find this
evidence, the optional cross-link is: extend that paragraph's first sentence
with "see `docs/brave-answers-spending-bounds.md` for the per-dimension
evidence and the provider guarantees that would be required."
