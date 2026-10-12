# TideOrbit 1.92: Bing Webmaster Tools & Microsoft Clarity

TideOrbit → **SEO Campaign** now has two independent, read-only Microsoft integrations. Both are opt-in, and neither is implicitly connected just because another Microsoft or Google credential exists.

## Bing Webmaster Tools

1. Verify the website in **Bing Webmaster Tools**.
2. In Bing Webmaster Tools → **Settings → API Access**, generate an API key.
3. Under **TideOrbit → SEO Campaign → Bing Webmaster Tools**, enter the **exact verified site URL** (e.g. `https://example.com/`) and the API key.
4. Save, then select a month and click **Measure Bing report month**.
5. The first capture validates the exact site's verified status via `GetUserSites`. It then reads `GetRankAndTrafficStats` and `GetQueryStats`, normalizes dated Bing records, and saves a 24-month rolling history of monthly results.

The integration uses Microsoft's JSON endpoint `https://ssl.bing.com/webmaster/api.svc/json/` over HTTPS. Microsoft's legacy SOAP and POX protocols retired on August 31, 2026; this implementation **does not** use SOAP or POX. The API requires an API key in the query string, so TideOrbit restricts the host and method, disables redirects, and never includes the URL, key, or HTTP transport diagnostics in its REST error responses. The key is saved in the existing non-autoloaded private `scc_credentials` option. There is no sitemap submission or URL submission in this release.

Bing's reported clicks and impressions include various Bing search surfaces, so they **must not be combined as if equivalent to Google Search Console or GA4 Organic Search**. A month with no returned dated rows is labeled *unavailable*, never measured zero. Query position is weighted by impressions on the dated query records returned by Bing; top query coverage may be incomplete.

## Microsoft Clarity

1. Create or open an existing Clarity project in [Microsoft Clarity](https://clarity.microsoft.com/).
2. Install its tracking script through the **official Microsoft Clarity WordPress plugin**, Google Tag Manager, or your existing consent-aware tracking setup.
3. Ensure that session-recording consent and the privacy notice are handled appropriately by your website. This matters for clinical/healthcare websites and other sensitive-content sites; review Clarity masking and consent configuration before enabling it. **TideOrbit does not inject a second tracking script.**
4. Open **Clarity → Settings → Data Export** and generate an API access token. It is *not* a Microsoft identity OAuth token.
5. In **TideOrbit → SEO Campaign → Microsoft Clarity**, enter the project ID and Data Export token, then click **Save Clarity connection**.
6. Select **Capture recent Clarity insights**. Captured snapshots of traffic, engagement, scrolling, rage clicks, dead clicks, and other supported numeric metrics will appear on the campaign report. Source labels and timestamps show which measurements actually came from Microsoft.

Clarity's documented API is `https://www.clarity.ms/export-data/api/v1/project-live-insights?numOfDays=1` with an `Authorization: Bearer` header. This endpoint only provides the last **1–3 days**, not a historical month query. TideOrbit therefore captures a **trailing 24-hour snapshot** at most once per day and saves up to **90 snapshot dates**, showing collected windows instead of falsely fabricating complete monthly uniques or conversions.

Clarity permits only **10 requests per project per day**. TideOrbit reserves a maximum of **8 requests per day** locally to leave two for other uses; Microsoft also enforces the provider quota. The local counter is best-effort under concurrent requests and does not represent a global cap across multiple WordPress installations sharing the same project. A Microsoft 429 or invalid token is reported as an error, not zero.

To minimize storage of potentially identifying data, the Clarity parser deliberately discards dimension labels, identifiers, URLs and other nonnumeric values. It stores a curated subset of numeric metrics, capped to eight numeric rows per metric. Use the Clarity web dashboard for actual session recordings and heatmaps.

## What these connectors do not claim

- No Bing URL submission, sitemaps, or IndexNow automation in this release.
- No Clarity session recording playback or heatmap embedding in WordPress.
- No full-month historical Clarity export when daily snapshots do not exist.
- No automated CRM attribution or qualified lead matching.
- No live Microsoft account or Clarity project is connected until an administrator supplies and tests that site's credentials.

## Testing and support

CI regression tests cover Bing JSON-date parsing, traffic grouping and missing-data states, separate credential handling, Clarity numeric-only parsing, refusal of unmeasured results, and per-project quota protection. Live Microsoft API verification requires real account credentials supplied within WordPress and cannot be executed by CI.

Official documents:
- [Bing Webmaster API access](https://learn.microsoft.com/en-us/bingwebmaster/getting-access)
- [Bing JSON protocols](https://learn.microsoft.com/en-us/bingwebmaster/api-protocols)
- [Clarity Data Export API](https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-data-export-api)
