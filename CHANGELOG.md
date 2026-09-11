# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

- Add the Laravel-native query, preview, synchronous, queued, event, and
  terminal-response APIs without package-owned persistence.
- Add six Laravel AI SDK Profiles and seventeen focused HTTP Profiles, including
  You.com Answer and Research, three durable research paths, and six SearchAPI
  surface observations.
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
- Support Laravel AI SDK 0.11 with a package-owned queued research test fake.
- Document the reconciled 40-row TypeScript/PHP binding matrix and separately
  approval-gated live provider canaries.
