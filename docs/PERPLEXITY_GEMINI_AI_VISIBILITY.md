# TideOrbit 1.95 — Perplexity Agent + Gemini grounded observations

The **SEO Campaign → AI citation monitoring** panel now adds opt-in direct API measurements of one user-specified non-sensitive business question using (1) Perplexity's **Agent API** and (2) Google's **Gemini Developer API with Google Search grounding**. The dashboard displays a comparable set of citation observations for the website and up to four competitor domains in the **same returned answer**.

## What these measurements are (and are not)

- **Perplexity Agent API** (official endpoint `POST https://api.perplexity.ai/v1/agent`, `preset: "fast"`) returns a search-grounded answer with output text and often a `search_results` item. Only inline numbered citations such as `[1]` explicitly mapping to a retrieved source ID, or explicit URL citation annotations on the answer, count as **citations**. Retrieved-but-uncited search results are deliberately excluded. When the answer lacks verifiable citation markers/annotations, **citation state is unknown**, not zero.
- **Gemini Developer API** (`POST .../models/gemini-2.5-flash:generateContent` with `tools: [{google_search:{}}]`) returns a generated answer and sometimes grounding metadata. Only `groundingSupports[].groundingChunkIndices` that reference actual `groundingChunks[].web.uri` URLs count as verified cited sources. A retrieved web page appearing only in `groundingChunks` does **not** count as a citation, and absent supports mean unknown citation status. Google grounding links sometimes point to Google redirect URLs, which will not be credited as first-party domains unless their actual returned URL host matches the website; TideOrbit does not follow redirects or infer final destinations.
- **Consumer app differences:** API answers are not identical to the personalized Perplexity website/app or Google's Gemini app, and neither provider offers a census of all consumer answers that mentioned the brand. The reported question is not automatically localized; location words in a prompt are **not** equivalent to controlled geolocation.
- **DataForSEO Google AI Overviews/AI Mode and ChatGPT indexed datasets** remain in place, with separate evidence labels and counts.

## Setup

1. Install TideOrbit v1.95.
2. Under **TideOrbit → SEO Campaign → AI citation monitoring**, save your exact brand name and optional aliases.
3. Under **Direct-model API connections**, enter your own Perplexity API key and your own Google Gemini Developer API key independently. API keys are stored in existing private/non-autoloaded WordPress credentials, never returned by the campaign REST endpoint. The Perplexity Agent API is used because legacy Sonar Chat Completions support ended September 27, 2026.
4. Optionally add up to four competitor domains (domain only, no paths). Competitor matches are determined from cited URLs in the same answer.
5. Choose **Perplexity Agent** or **Gemini + Google Search grounding** from the AI surface dropdown, enter an exact **non-sensitive** search question, and explicitly confirm the paid call.
6. Review the per-platform **AI visibility scorecard** and citation evidence rows in the campaign's selected reporting month. The scorecard displays observed answers, brand mentions, site citations with verifiable denominators, uncertain citation statuses and separately labeled indexed samples.

No model requests are made automatically or by saving credentials. The existing best-effort **10 paid AI visibility requests per WordPress site per day** limit now covers the direct providers as well as DataForSEO. Each API may have its own separate rate limits, usage charges or grounded-search fees, and the per-site limit is **not a cross-site billing guarantee**. Set hard spend limits and restrictions with both providers when available.

**Privacy:** Questions are transmitted to the selected AI provider; never include visitor/customer records, health conditions, email addresses, contact details or other confidential information in them. The WordPress connector does **not** read CRM contacts or WordPress visitor data and only retains the question, timestamp, brand mention flag, domain citation status, model/source labels, and bounded cited URLs. It does not store the full AI answer.

## How the scorecard works

Only successful, completed answer observations can contribute to the observed-answer denominator. `domain_cited = true` or `false` counts in the **verified-citation denominator** only when the response contained explicit reliable citation references. If the provider supplied only retrieved results, the outcome is **unknown** and excluded from the denominator. A separately measured Google AI Overview `ai_result_not_shown` is not a positive answer or verified citation. Indexed DataForSEO domain mentions are **sample metrics, not live answer success rates**, so they never enter the denominator for other providers.

Competitors are comparisons within **one grounded answer**: e.g. owner cited / alpha not cited / beta cited. This is descriptive measurement, not a global AI visibility rank, consumer traffic measurement, qualified lead count or attribution of revenue.

The plugin does not scrape Perplexity or Gemini consumer web interfaces, bypass account controls or silently switch to LM Studio-generated answers.

## Verification

Deterministic regression tests cover Perplexity's typed Agent API output, citation marker mapping, retrieved-only ambiguity, Gemini groundingSupports indices, missing response rejection, host/spoof matching, competitor comparison, isolated provider credentials, and coverage-aware summary denominators.

These tests do not connect to your paid Perplexity or Google API accounts. **Live verification requires your own provider credentials and one explicitly authorized call** in a staging/production WordPress site. Provider model support, quota, and response fields may vary.

### Official provider references

- [Perplexity Agent API quickstart](https://docs.perplexity.ai/docs/agent-api/quickstart)
- [Perplexity migration from legacy Sonar](https://docs.perplexity.ai/docs/agent-api/migrate-from-sonar/overview)
- [Gemini Grounding with Google Search](https://ai.google.dev/gemini-api/docs/google-search)
- [Gemini Generate Content API](https://ai.google.dev/api/generate-content)
- [Gemini pricing and Search grounding](https://ai.google.dev/gemini-api/docs/pricing)
