# TideOrbit 1.94 — Actual AI citations, separate from AEO readiness

TideOrbit → **SEO Campaign → AI citation monitoring** provides explicit **paid and on-demand** provider-backed checks with saved evidence, no background AI scanning or silent API spend.

## Measurement modes

| Surface | Provider | What is measured | What is **not** measured |
| --- | --- | --- | --- |
| Google AI Overview | DataForSEO Google Organic Live Advanced | One particular question + Google search location, actual AI Overview presence, brand text mention and first-party URLs in cited references | Universal ranking, all user searches, all locales, a promise that Google always shows the feature |
| Google AI Mode | DataForSEO AI Mode Live Advanced | One particular question + location, brand mention and matching cited URLs when the API returns the structured AI answer | Google Gemini chatbot behavior or global AI Mode traffic |
| ChatGPT indexed dataset | DataForSEO LLM Mentions Target Metrics, `platform: chat_gpt` | Number of first-party-domain **source citations** in the provider's indexed ChatGPT response dataset, US English | Every user chat, real-time ChatGPT.com results, independent ChatGPT-native API analytics, actual impressions |
| Google AI dataset | DataForSEO LLM Mentions Target Metrics, `platform: google` | Domain source citations in the provider's indexed Google AI-response dataset, US English | All AI Overviews ever shown or clicks/conversions |
| Perplexity | Not yet integrated | **Unavailable** | Do not invent counts |
| Gemini | Not yet integrated | **Unavailable** | Do not invent counts |

DataForSEO's LLM Mentions `search_scope: ["sources"]` means citations actually recorded in the model's sources, **not** generic search-result retrieval and not mention text. Source citations must be distinguished from brand mentions without a cited domain. Domain matching checks real URL hosts, including subdomains, never text-like suffixes such as `mydomain.com.evil.example`.

## Setup

1. Under **TideOrbit → Connections**, configure **DataForSEO login + API password** (already used for organic rank checking).
2. Open **TideOrbit → SEO Campaign → AI citation monitoring**.
3. Save the exact business/brand name, optionally up to four aliases. Domain is locked to the current WordPress site. Changing the brand/aliases resets stored AI citation history to prevent combining reports for different business identities.
4. Choose an AI surface. For Google live-query modes, type the exact question and target Google location (e.g. `United States` or a DataForSEO supported locality); for indexed ChatGPT/Google counts, the dataset is **United States, English** and the question is not used.
5. Click **Run paid AI visibility check**, then explicitly confirm the paid DataForSEO call.
6. The current report month now lists saved observations with timestamps, provider labels, measured AI presence, separately detected brand mentions, source-domain citations and matching cited URLs if present.

**One click = one paid DataForSEO request** (and Google async AI Overview loading can incur an additional per-request charge). The plugin keeps a best-effort **10-request daily per-site soft cap** stored as a single non-autoload WordPress option. High concurrency and multiple WordPress sites sharing the same account can exceed your intended financial limit, so also configure DataForSEO's own hard budget controls. No paid requests are scheduled in WordPress cron.

## Correct interpretation

- `measured` means the provider returned a structured AI result for the exact query/market. Domain cited = **true** only when a matching first-party cited source URL is present.
- `ai_result_not_shown` means no AI result was returned in one otherwise valid provider SERP at that moment. It is **not** proof an AI answer never appears for that query.
- `measured_sample` counts real cited-domain mentions in DataForSEO's indexed dataset, not a literal count of all conversations, search impressions, or traffic.
- A valid provider response with `mentions: 0` means zero **within that dataset query**, and is different from missing data.
- Missing/incomplete provider responses cause errors and never overwrite snapshots with fake zero data.
- Local LM Studio prompts are intentionally **not** considered real ChatGPT or Perplexity citation results; those would measure a different model/answer distribution.
- The dashboard's existing AEO readiness score remains a separate content-optimization assessment and never becomes an AI citation count.

## Testing

PHP unit tests check strict cited-URL matching, nested references, brand-mention/citation separation, absent-AI vs missing-provider states, explicit zero vs malformed sample, direct WordPress host target, local quota accounting, and explicit paid consent.

A live DataForSEO request requires your configured API account. CI cannot prove actual live query coverage, paid provider account balance or search-provider consistency.

Source documentation:
- [DataForSEO Google AI Overview parsing](https://dataforseo.com/help-center/parse-google-ai-overviews-ai-mode)
- [Google Organic Live Advanced](https://docs.dataforseo.com/v3/serp/google/organic/live/advanced/)
- [Google AI Mode Live Advanced](https://docs.dataforseo.com/v3/serp/google/ai_mode/live/advanced/)
- [LLM citations as sources (not generic search results)](https://dataforseo.com/help-center/how-to-get-llm-citation-data-with-llm-mentions-api)
- [LLM Target Metrics Live](https://docs.dataforseo.com/v3/ai_optimization/llm_mentions/target_metrics/live/)
