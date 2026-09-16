# SearchAPI Profiles

The package ships SearchAPI.io Profiles served by a small Laravel HTTP
Driver. `searchapi` returns raw Google search results; the surface Profiles
observe a cited answer rendered on a consumer AI product:

| Profile | Engine | Result | Collector / surface |
| --- | --- | --- | --- |
| `searchapi` | `google` | Raw search results | none |
| `searchapi-chatgpt` | `chatgpt` (web search on) | Surface observation | `searchapi` / `chatgpt` |
| `searchapi-gemini` | `gemini` | Surface observation | `searchapi` / `gemini` |
| `searchapi-perplexity` | `perplexity` | Surface observation | `searchapi` / `perplexity` |
| `searchapi-google-ai-mode` | `google_ai_mode` | Surface observation | `searchapi` / `google_ai_mode` |
| `searchapi-bing-copilot` | `bing_copilot` | Surface observation | `searchapi` / `bing_copilot` |
| `searchapi-google-ai-overview` | `google`, then `google_ai_overview` only for a page token | Surface observation | `searchapi` / `google_ai_overview` |

Each SearchAPI Profile resolves its bearer credential from
`config('services.searchapi.key')`. The credential travels in the
`Authorization` header only and never enters the URL, progress, errors,
provider metadata, or results. Set an optional `options.zero_retention` boolean
to require SearchAPI zero retention; the request fails closed with a clear error
when the account does not support it. Each HTTP request times out after 20
seconds unless the Profile sets `options.request_timeout_seconds` (1 to 600);
the shared research deadline still caps every request.

`searchapi-google-ai-overview` reads an overview returned inline by the first
`google` request and only follows `page_token` when no inline overview is
present (SearchAPI may report "not available" beside a token whose page
loads one). A SERP without an overview fails as `searchapi.no_ai_overview`;
an overview the driver cannot read fails as `searchapi.ai_overview_unparsed`.
Overview reference links are Google redirects. Citations resolve them with URL
parsing only, never a fetch: a `url` or `q` parameter that is a publisher URL
becomes the citation; otherwise (Google's opaque `goto` tokens) the citation
is the publisher origin from the link's `favicon` URL. The raw redirect is kept
as the Source `provider_reference` when it fits 255 characters.
