# TideOrbit 1.93 — Google Business Profile and HighLevel reporting

The SEO Campaign dashboard now supports two additional **optional, per-site read-only reporting integrations**. Both are disabled until an administrator connects and validates them.

## Google Business Profile Performance

1. In Google Cloud, use the same Google OAuth Web Client ID/Secret already configured under **TideOrbit → Connections** for Search Console/GA4.
2. Enable the **Business Profile Performance API** for the project. Google's Business Profile APIs may require **separate API access approval** even when the API is enabled (quota 0).
3. Add the **exact redirect URI displayed in TideOrbit → SEO Campaign → Google Business Profile** to the Google OAuth Web Client's Authorized redirect URIs. This is different from the GA4 callback because it includes `scc_provider=gbp`.
4. Under **TideOrbit → SEO Campaign**, enter a numeric Business Profile location ID (`locations/1234567890`, **not** a Place ID or Maps CID). Click **Authorize Profile Performance**.
5. Google asks for `https://www.googleapis.com/auth/business.manage`. **Important:** this is a broad management-capable Google permission, but this TideOrbit connector contains **only GET read requests** and cannot change business hours, publish posts, or reply to reviews. Read the consent prompt carefully.
6. Approve with an account that already has access to the business. Select a month and click **Refresh reporting month** to test the API and store the first snapshot.

The connector requests Google Business Profile Performance API `locations/{id}:fetchMultiDailyMetricsTimeSeries` with these metrics:
`WEBSITE_CLICKS`, `CALL_CLICKS`, `BUSINESS_DIRECTION_REQUESTS`, and Search/Maps mobile/desktop impressions.

The data is normalized to a dated reporting period, retained as a per-month snapshot for up to 24 months, and refreshed weekly through the existing campaign cron after approval. **Missing metrics are null/unavailable, never invented zeros.** Google Business Profile `CALL_CLICKS` counts call-button presses, not answered calls or qualified leads. Some metrics may have only partial measured-day coverage.

The GBP refresh grant is stored separately as `gbp_refresh_token` in non-autoloaded WordPress credentials. Disconnect removes this token and saved GBP snapshots but **never deletes the independent GA4 or GSC refresh tokens**. The connector does not issue write requests.

## GoHighLevel CRM source-based opportunity summary

1. In the client's HighLevel **sub-account**, create a **Private Integration Token** with the minimum permission **`opportunities.readonly`**. Do not grant CRM write or messages permission.
2. Copy the **sub-account Location ID**. Optionally specify a single **Pipeline ID** to keep reporting scoped and manageable.
3. Under **TideOrbit → SEO Campaign → GoHighLevel**, enter the Location ID, optional Pipeline ID and token, then save.
4. Select a month and click **Refresh reporting month**.
5. TideOrbit queries only `GET https://services.leadconnectorhq.com/opportunities/search` with the supported **Version: v3** header. No contacts, contact IDs, emails, names, phone numbers, or individual opportunities are saved to WordPress. Only per-month aggregate summaries are retained.

Each record must contain a verifiable creation date. Results are counted as opportunities **created in the report month**, and their status is the **current** status when the report was captured. Only explicit `google organic`, `bing organic`, `SEO` or `organic search`-like `source` labels count as **organic-labeled**. A generic `Google`, Google Ads, blank source, or unmapped label does not count as verified organic attribution.

`current_won_opportunity_value` is the recorded monetary value of opportunities whose **current status** is won, **not cash collected or sales closed in that month**. A CRM source label is not independent proof that SEO caused the lead; campaigns should validate source tagging, UTMs, and business-side CRM configuration.

The API scan uses bounded pagination of up to 5 pages of 100. If the CRM reports more than 500 records or a page is missing, TideOrbit aborts without saving a partial summary. For large pipelines, narrow reporting to a dedicated pipeline until a larger-scale cursor-based integration is implemented.

Reports save at most 24 monthly aggregates. Automatic read-only refreshes run at most weekly after explicit connection. Disconnect removes only the HighLevel token and opportunity summary snapshots, without modifying Google, Bing or Clarity integrations.

## Production checklist

- Back up WordPress database and stage plugin updates before production.
- Confirm that OAuth consent for GBP actually returns a refresh token and that its exact callback URL is registered in Google Cloud.
- Run a real Google Business Profile Performance API request; permission/quotas can block access even for an authorized Google account.
- Test a HighLevel sub-account with a private `opportunities.readonly` token and verify API pagination/date filtering.
- Ensure CRM opportunity source values are clean and meaningful; missing source labels never receive organic attribution.
- Confirm report totals against Google and HighLevel native dashboards. Filter semantics and latency can differ.
- Recheck automatic weekly capture on a low-traffic WordPress site with WP-Cron; an external cron trigger may be needed.
- Avoid inserting customer medical/sensitive data into SEO reports; TideOrbit intentionally does not persist contacts or messages.

**Not included:** Google listing edits/review replies; HighLevel contact timeline or personally identifying lead records; paid ad revenue attribution; AI citation verification; a multi-client agency SaaS hub. Those are distinct integrations or review workflows.

Official references: [GBP Performance API](https://developers.google.com/my-business/reference/performance/rest), [fetchMultiDailyMetricsTimeSeries](https://developers.google.com/my-business/reference/performance/rest/v1/locations/fetchMultiDailyMetricsTimeSeries), [HighLevel search opportunities](https://marketplace.gohighlevel.com/docs/ghl/opportunities/search-opportunity), [HighLevel Private Integrations](https://help.gohighlevel.com/support/solutions/articles/155000003054-private-integrations-everything-you-need-to-know).
