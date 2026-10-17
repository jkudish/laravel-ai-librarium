# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

- Read Anthropic token partitions from every step's raw provider usage instead of SDK-normalized totals. Missing, negative, non-integer or unsafe aggregate counts stay unknown, so a cache write cannot manufacture a zero uncached-input count and numeric strings cannot become measured integers. Answers remain available; other providers' usage mapping is unchanged.
- Enable five-minute instruction and tool-definition caching on direct Anthropic Laravel AI Profiles, plus automatic conversation caching only for multi-step budgets. One-step unique messages, OpenAI, Google and shipped OpenRouter requests stay unchanged. Reserve the no-search Claude context in `cache_write_input_tokens_5m` instead of plain input (USD 3.14 rather than 2.64 with 64k output at the reviewed global SKU). Result usage remains exclusive uncached/read/write partitions. Standalone custom OpenRouter `anthropic/*` Profiles are an SDK 1.0.1 limitation: its OpenRouter gateway ignores caching attributes. Consumers may register their own gateway, as agentsy-platform does; this package adds no second gateway layer. See [prompt caching](README.md#anthropic-prompt-caching).
- Add opt-in frozen Exa research Profile templates `exa-research-auto` (`effort: auto`, native USD 1 cap) and `exa-research-ultra` (`effort: ultra`, native USD 5 cap, 3,000-second duration ceiling), revision `research-{level}-2026-10-09`. Template Profiles must match exactly; drift returns an `unknown` bound and fails before HTTP. No shipped group membership changes.
- Correct the Exa Agent highest metered effort from the retired `max` beta to `ultra`, drop the `agent-max-effort-2026-07-27` beta header, add `options.max_duration_seconds` (300–10,800, `ultra` only, sent as `budget.maxDurationSeconds`) and accept the `time_limit_reached` stop reason. A Profile still configured with `effort: max` is now rejected before dispatch.
- Prove the `parallel-research` spending bound: one `processor_requests` unit under `parallel:research-pro`. The driver sends no pay-per-use data sources or MCP servers and never resubmits.
- Add opt-in frozen Perplexity research Profile templates for medium, high and capped deep diagnostics across GPT-6 Luna, GPT-6.1 Sol and Gemini 3.8 Flash (no Claude through Perplexity, ARV2-TC-D19) (`research-{depth}-2026-10-09`). No shipped group membership changes. Reject model, revision, request-option and 500-byte prompt-limit drift before dispatch. Inline requests emit explicit model, steps, output, reasoning, default service tier, Fast Search and fetch settings; no preset, fallback, sandbox or proven spending bound. Provider-reported model and charge remain authoritative. Consumers must register/enable templates and own estimate reservations and live admission.
- Read Google AI Overviews returned inline by SearchAPI's first `google` request, and follow `page_token` only when no inline overview is present. A SERP without an overview now fails as `searchapi.no_ai_overview` and an unreadable overview as `searchapi.ai_overview_unparsed`, instead of one `searchapi.invalid_response`. Overview citations resolve Google redirect links by URL parsing only: a `url`/`q` publisher URL becomes the citation, otherwise the publisher origin from the link's `favicon` URL; the raw redirect is kept as `provider_reference` when it fits 255 characters. Previously every redirect citation was dropped.
- Add `options.request_timeout_seconds` (strict integer, 1 to 600) to SearchAPI and Perplexity Agent Profiles. Defaults stay 20 and 30 seconds, and the shared research deadline still caps each request. The Perplexity option is a transport control: it never reaches the request payload, and the frozen and managed request semantics are otherwise unchanged.
- Upgrade the Luna targets and pricing identities in `openrouter-grounded`, `openai-chat`, and `perplexity-low-frozen` to GPT-6 Luna. The frozen Perplexity configuration is now `frozen-low-2026-10-03`; the prior `frozen-low-2026-09-11` identifier is rejected rather than silently changing its meaning. Published application profile bindings must update model, pricing identity, and frozen configuration together. Sol and Terra targets are unchanged.
- Require `laravel/ai` `^1.0.1` to exclude 1.0.0, affected by [GHSA-6qhr-3g93-pxhw](https://github.com/laravel/ai/security/advisories/GHSA-6qhr-3g93-pxhw) (SSRF via client-supplied file URLs in the SDK's Vercel AI and AG-UI adapters).
- Fail closed on a negative input remainder: when a provider's inclusive input total is smaller than its own reported cache partitions (for example input 10 with cached 30), the Laravel AI, Gemini Deep Research, and Perplexity Agent mappers now report every input-side partition (prompt, cache write, cache read) as unknown instead of clamping the prompt count to zero and pricing the cache partitions from a report that cannot be reconciled. The output side stays reported as measured, and a provider-reported actual cost stays authoritative.
- Fail closed on an inconsistent multi-step aggregate: laravel/ai folds every step's usage into one total, so one step whose cache partitions exceed its own input total could hide inside a consistent-looking aggregate (input 10 with cached 30 plus input 100 with cached 0 folds to input 110 with cached 30). The Laravel AI mapper now validates each step's own partitions (`uncachedInputTokens() < 0`) before trusting the aggregate and nulls the whole input side when any step is inconsistent.
- Carry Gemini Interactions `usage.total_tool_use_tokens` ("tokens present in tool-use prompt(s)") as a reported, unpriced `tool_use_tokens` audit count instead of dropping it. Google's Interactions example arithmetic proves the field is not additive to the reported token totals (input 100 + output 25 + thought 0 = total 125 with tool_use 50), but the reference does not state verbatim that those tokens sit inside `total_input_tokens`, and Google bills tool tokens by category — Grounding with Google Search retrieved context is documented free while URL-context and File-search retrieved tokens are charged as input — so no independently verified charge covers the field and it is never added to the priced partitions, which derive only from the provider's inclusive input and cache totals. The Laravel AI driver reads the count from the 1.0 raw response bodies because laravel/ai 1.0's Gemini parser drops the field. `tool_use_tokens` is a wire field of this PHP value object beyond the checksum-pinned v1 interchange schema (like `server_tool_search_requests`): payloads carrying it are rejected by older package versions after a rollback; the frozen v1 schema is unchanged.
- Label Google citation offsets as bytes: Gemini Interactions `url_citation` `start_index`/`end_index` are documented byte offsets (end exclusive), so Gemini Deep Research locators are now labeled `bytes:` instead of the incorrect `characters:`. OpenAI-compatible surfaces (OpenAI, OpenRouter, xAI) document character positions and keep the `characters:` label.
- Widen `jkudish/laravel-ai-pricing` from `^0.1.1` to `^0.1.1 || ^0.2.0`.
- Widen `jkudish/laravel-ai-pricing` to `^0.1.1 || ^0.2.0 || ^0.3.0 || ^0.4.1`. Verified against v0.4.1: the 0.3 and 0.4 changes are additive for this package (rate windows only affect windowed definitions, which no shipped Profile uses).
- Remove the pricing usage from the `grok-x-only` and `grok-combined` Profiles: since xAI's September 21, 2026 change, X Search bills $5 per 1k posts fetched and $10 per 1k profiles fetched rather than per call, and no documented control bounds fetched items, so a `searches` quantity can no longer bound the route's spend. Their quotes now resolve unavailable and any maximum-cost preflight including them fails closed instead of quoting web-search calls the X routes do not perform; the web-only `grok` Profile keeps its native `searches` count because Web Search still bills per call. Settlement is unchanged (the driver settles on `cost_in_usd_ticks`).

## 2.0.0 - 2026-09-26

- **Breaking:** require `laravel/ai` `^1.0` only; support for the 0.10.3–0.11 range is dropped. The SDK 1.0 gateway parsers report inclusive token totals and the package's result mappers derive the interchange contract's exclusive input partitions themselves; the OpenAI Responses and Gemini Deep Research mappers build the SDK 1.0 `TextUsage` value object, which exists only in 1.0. Historical 0.11 package revisions stay replay-accepted downstream.
- Read Gemini Interactions usage from the 1.0 raw body (`usage.total_input_tokens`, `total_output_tokens`, `total_cached_tokens`, `total_thought_tokens`) so a reported zero stays `0` instead of collapsing to `null`.
- Fold Gemini Deep Research thought tokens into the completion total, matching the SDK 1.0 Gemini gateway parser's own inclusive output semantics.

## 1.0.0 - 2026-09-15

- Add the Laravel-native query, preview, synchronous, queued, event, and
  terminal-response APIs without package-owned persistence.
- Add six Laravel AI SDK Profiles and 41 focused HTTP Profiles, including
  You.com Answer and Research, recoverable durable research, SearchAPI and
  DataForSEO surface observations, and the accepted TypeScript v2 parity set.
- Add provider-safe option validation, credential handling, deadlines,
  fallbacks, webhook wake hints, provenance, citations, usage, and errors.
- Add optional SerpBase Google Search and News Profiles with one-request
  billing bounds, strict ranked evidence, opt-in sanitized rich-result text,
  and account-configured credit pricing.
- Require published OpenRouter Profiles that enable web search to declare a
  supported `search_engine`; no engine is selected implicitly.
- Pin and verify the accepted TypeScript terminal-interchange snapshot offline.
- Replace static Profile cost estimates with offline, provenance-aware
  `laravel-ai-pricing` quotes and fail-closed maximum-cost preflight checks.
- Add an optional exact-request spending-bound API with provider-native ceilings,
  complete fixed-operation billable quantities, and an explicit unknown result.
- Preserve Exa Research API-output observation provenance across synchronous and
  durable results, and validate explicit native run budgets from $1 through $100.
- Support Laravel AI SDK 0.11 with a package-owned queued research test fake.
- Document and checksum the reconciled 39-row TypeScript v2/PHP binding matrix,
  the nine post-baseline PHP Profiles, and separately approval-gated live
  provider canaries.
- Prove application-defined Drivers through the public query runtime and add
  executable compatibility and release-readiness checks.
