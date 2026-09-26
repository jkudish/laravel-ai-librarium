# Changelog

All notable changes to this project will be documented in this file.

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
