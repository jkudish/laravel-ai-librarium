# SerpBase Profiles

`serpbase-search` and `serpbase-news` are enabled core Profiles. Their semantic
mapping is recorded in the [Profile binding matrix](profile-bindings.md).

SerpBase Search and News each issue exactly one synchronous POST and never
retry or paginate automatically. Both accept `hl`, `gl`, and a positive `page`;
Search alone accepts `device` (`default`, `pc`, or `mobile`). Search's
`include_rich_results` option defaults to `false`. When enabled, documented
organic/news fields remain strict, People Also Ask uses only the documented
`question`, `snippet`, `link`, and `displayed_link` fields, and related-search
strings are suggestions rather than evidence. The undocumented
`featured_snippet`, `top_stories`, and `knowledge_graph` shapes are rendered as
bounded sanitized JSON-like text without interpreting child keys or deriving
citations. Unsupported or unsafe values are omitted. Search, News, and
related-search rendering passed live smoke checks; PAA and the opaque rich
modules were not returned and remain live-unverified. Offline rich tests use
synthetic payloads.

Responses exceeding 2 MB or 100 primary results are rejected. Retained text
fields are capped at 2,000 UTF-8 bytes. Options require native integer/boolean
types rather than coercible strings. News date text remains unstructured;
relative dates are never converted into inferred publication timestamps.

SerpBase's public policy says requests may log query text, locale, search type,
status, latency, credits charged, API/account identifiers, and IP metadata. It
does not promise zero retention, per-request suppression, self-service deletion,
or a fixed public retention period. Do not send sensitive queries unless that
policy fits the consuming application's privacy requirements.
