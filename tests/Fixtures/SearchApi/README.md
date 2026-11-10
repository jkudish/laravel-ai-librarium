# SearchAPI Google AI Overview fixtures

Sanitized shapes observed from SearchAPI on 2026-10-04 (140 retained Google
AI Overview responses). Keys, nesting and link formats match the observed
responses; all answer text, titles, tokens and sites are synthetic.

- `google-inline-ai-overview.json`: the first `google` response carries the
  overview inline (10 of 140 observed). No second request is needed.
- `google-page-token.json`: the first response carries only `page_token`
  beside a misleading "not available" `error` (122 of 140); the second
  `google_ai_overview` request (`google-ai-overview-page.json`) loads it.
- `google-no-ai-overview.json`: no `ai_overview` key (5 of 140).

Every observed `reference_links[].link` was a Google redirect: `goto?url=`
with an opaque encrypted token (869 of 927 links), shopping/search links, or
a plain publisher URL. The publisher origin is available in the `favicon`
URL's `url` parameter.

## SearchAPI Bing Copilot repeated list items

`bing-copilot-repeated-list-items.json` is shaped like the Bing Copilot
answers observed on 2026-11-10 (179 retained answers, 156 affected). SearchAPI
repeated each list item's lead in both `markdown` and `text_blocks`: as
"A. A", as "A A", or as "A. A" followed by more detail without a full stop.
Citations follow the item, including an empty `[9]()` link and a URL with
parentheses. The fixture also keeps text that must not change: a repeated
single word, a one-word "No. No" lead, a table rule and a table row with
repeated cells. All text, titles and sites are synthetic.
