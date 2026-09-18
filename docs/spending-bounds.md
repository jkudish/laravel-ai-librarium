# Exact-request spending bounds

Use `spendingBound()` when an application must decide whether it can authorize
one exact Profile request before dispatch. The method is local and makes no HTTP
request:

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;

$research = Librarium::query('Prepare a cited report.')
    ->using('exa-research');

$bound = $research->spendingBound();

if ($bound->kind === SpendingBoundKind::NativeMonetaryCeiling) {
    $bound->maximumCost; // Money: provider-enforced ceiling for this request
}

if ($bound->kind === SpendingBoundKind::BillableQuantities) {
    $bound->pricingIdentity; // ModelIdentity for an authoritative rate lookup
    $bound->quantities;      // pricing Usage containing every billable unit
}
```

The query must use `using()` with one Profile and no fallbacks. Groups, fan-out,
implicit selection, and fallback paths are rejected because they do not name
one exact operation.

## Result contract

`SpendingBound` has exactly three kinds:

| Kind | Populated fields | Meaning |
| --- | --- | --- |
| `native_monetary_ceiling` | `maximumCost` | The exact paid request sends a provider control that caps all billed dimensions. |
| `billable_quantities` | `pricingIdentity`, `quantities` | The driver proves a complete maximum quantity for every billable unit. The application must resolve authoritative rates for that identity. |
| `unknown` | none | No total bound is proven. Do not treat this as zero or derive authority from a preview estimate. |

The serialized keys are `kind`, `maximum_cost`, `pricing_identity`, and
`quantities`. Monetary values use `jkudish/laravel-ai-pricing`'s `Money` value;
quantities use its `ModelIdentity` and `Usage` values. Librarium does not resolve
rates inside this capability.

`ResearchPreview` pricing and `maxCost()` remain estimates and pre-dispatch
comparisons. They are not provider enforcement and must not be promoted to a
`SpendingBound`. Likewise, response `Usage::actualCost` is settlement evidence,
not a pre-dispatch bound. A missing actual cost does not invalidate a proven
pre-dispatch quantity maximum.

## Supported exact operations

| Profile(s) | Bound |
| --- | --- |
| `claude-chat` | `anthropic:claude-sonnet-5-global` input/output quantities only when `web_search` is `false`, `max_steps` is `1`, `max_output_tokens` is explicitly set from 1 through 128,000, and `max_prompt_bytes` is an explicit positive limit that the rendered prompt satisfies. The billing SKU records the Claude API's default global routing while the source-native request and result model remains `claude-sonnet-5`; generic and US-only identities do not use this rate. The input maximum is the model's 1M-token context ceiling; the output maximum is the request's `max_tokens`. Search-enabled requests remain `unknown` because search-result context is billed as input and `max_uses` does not bound its aggregate tokens. |
| `openrouter-chat` | `openrouter:openai/gpt-5.6-terra-272k` quantities only when `web_search` is `true`, `search_engine` is `exa`, `max_tool_calls` is 1 through 30, `max_results` is 1 through 10, `max_steps` is `1`, `max_prompt_bytes` is a satisfied positive limit, and `max_searches` is absent: `(max_tool_calls + 1)` completions at the pinned endpoint's 1,050,000-token context and 128,000-token per-completion output maximum, plus `exa_search_requests` equal to the budget. Any other configuration — including every other OpenRouter model — returns `unknown`. See [Grounded Laravel AI spending bounds](grounded-spending-bounds.md). |
| `exa-research` | Native USD ceiling only for `auto` or `max` with an explicit `max_cost_dollars` from 1 through 100. The same value is sent as `budget.maxCostDollars`. No configured cap returns `unknown`; provider defaults are not pinned. Fixed efforts return `unknown` and reject a budget. |
| Six `dataforseo-*` Profiles | One `requests` unit under the exact Standard or Live pricing identity. Polling and retrieval do not add task units. |
| `searchapi`, five one-stage surface Profiles | One `successful_search_request`. |
| `searchapi-google-ai-overview` | Two `successful_search_request` units. This remains the pre-dispatch maximum if stage two fails; the bound is not a claim that two successful receipts were observed. |
| `exa` | One request, zero additional results, and zero summary pages. |
| `kagi-fastgpt` | One uncached query. |
| `brave-search` | One search request. |
| `tavily-search` | Two credits for the fixed advanced search payload. |
| `perplexity-search` | One request only for the shipped option-free operation. Enhanced options return `unknown`. |
| `serpapi` | One successful search request. |
| `serpbase-search`, `serpbase-news` | One credit. Search with rich results enabled returns `unknown`. |

The shipped `claude-chat` configuration remains search-enabled and therefore
returns `unknown`. A consumer may configure the documented no-search controls
to obtain a model-only bound; this is not a bound for web-search-backed Claude.
`brave-answers` also remains `unknown`: `max_completion_tokens` bounds output,
but Brave documents only qualitative `search_context_size` values and no hard
ceiling for every billed input token, including retrieved context
([evidence](brave-answers-spending-bounds.md)).

All other shipped Profiles return `unknown` without driver boilerplate. Their
model/tool/token limits, provider-managed work, optional surcharges, or
account-rate semantics do not currently prove all-dimensional bounds. This
includes other Laravel AI model Profiles, You.com, Perplexity Agent
([evidence](perplexity-spending-bounds.md)), xAI Grok
([evidence](xai-grok-spending-bounds.md)), OpenAI Research, Jina, Gemini Deep
Research, Parallel, and Valyu. `unknown` records absent documentation or
controls, not impossibility; each linked page lists the provider guarantee
that would change its verdict.

## Ownership

- The driver owns the exact request controls and complete quantity semantics.
- `jkudish/laravel-ai-pricing` owns rates for a pricing identity.
- The consuming application owns authorization, reservations, ledgers,
  retries, and settlement.

Recompute the bound after changing the query builder or Profile configuration.
Each result is a snapshot bound only to the exact request and Profile controls
that existed when `spendingBound()` was called; it is not an execution permit.
Mutating the builder or Profile configuration invalidates that result. A
consumer enforcing spend must rebuild and revalidate its exact stored execution
controls and recompute the bound immediately before the paid POST, with no
fallbacks. Both the controls and the recomputed bound must exactly match the
persisted approved values; a package upgrade can change a bound even when the
controls are unchanged. Do not dispatch
when policy requires a proven bound and the result is `unknown`.
