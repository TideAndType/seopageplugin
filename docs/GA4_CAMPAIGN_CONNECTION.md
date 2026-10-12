# TideOrbit 1.91 — Verified GA4 Organic Search integration

This release adds a **real, read-only Google Analytics Data API** connection to the existing per-site **TideOrbit → SEO Campaign** screen. It does not need Composer, third-party SDKs, Vercel, or a hosted TideOrbit service.

## One-time connection

1. Under **TideOrbit → Connections**, configure a Google OAuth Web application's **Client ID** and **Client Secret** (the same OAuth client already used for Google Search Console).
2. In that Google Cloud project, enable **Google Analytics Data API**. Ensure the consenting Google user can read the chosen GA4 property.
3. In Google Cloud **Credentials → OAuth 2.0 Client IDs → your Web client**, add this WordPress URL as an **Authorized redirect URI** (copy the exact URL displayed in TideOrbit → SEO Campaign): `https://YOUR-SITE/wp-admin/admin.php?page=seo-command-center-campaign`.
4. Find the **GA4 numeric Property ID** under Google Analytics Admin → Property details. Do **not** use the `G-...` measurement ID.
5. Open **TideOrbit → SEO Campaign**, save that property ID, and select **Connect Google Analytics (read-only)**.
6. Approve the new **analytics.readonly** OAuth permission with a Google account that has access to the property.
7. Once redirected back to WordPress, select a report month and click **Refresh Analytics** to verify connectivity and populate measurements.

GA4 OAuth uses its **own state bound to the logged-in WordPress administrator** and its own refresh token (`ga4_refresh_token`). It never silently broadens the existing GSC access grant, overwrites the GSC refresh token, or writes into Analytics. API credentials and refresh tokens are not returned by the reporting REST routes.

## Report semantics

The GA4 Data API `properties/{PROPERTY_ID}:runReport` call requests:
- `sessionDefaultChannelGroup == Organic Search`, an explicit **session-scoped** channel filter;
- `sessions`, `totalUsers`, and `keyEvents` (GA4-configured key events);
- exact month dates ending two days before the current day for incomplete months.

Only a successfully measured GA4 response is stored and displayed. HTTP errors, failed refresh grants, malformed responses or a missing metric row **never become false zeros**. An actual successful report with `rowCount: 0` is recorded as a true zero. GA4 responses that indicate privacy thresholding are labeled accordingly.

Metrics are **not identical** to Google Search Console clicks; GA4 key events do **not** necessarily mean qualified leads, booked appointments, or customers. CRM conversions and GBP call metrics are still manually documented until their own authorized integrations are available.

Historical GA4 snapshots are stored as per-month records in a WordPress option (max 24 months, autoload off). Automatic read-only captures run at most weekly through the existing TideOrbit job schedule after authorization, including the previous month in the first week. You can refresh any nonfuture month manually.

Changing the property ID clears snapshots for the previous property; disconnecting removes the GA4 local refresh token and Analytics snapshots, while keeping GSC unchanged. The corresponding grant in your Google Account can also be revoked manually if desired.

## Test checklist

- Validate configured and disconnected states, and ensure no API requests run before consent.
- Connect a real permitted property and confirm the Analytics Data API returns `sessions`, `totalUsers`, and `keyEvents` filtered to Organic Search.
- Confirm denied consent / invalid OAuth state does not store a refresh token; callback requires an administrator.
- Revoke Analytics access, then confirm the dashboard shows an error instead of zero metrics.
- Change property IDs, confirm old snapshots disappear and no unrelated GSC credentials are changed.
- Compare completed historical months and an incomplete current month.
- Verify existing Elementor content generation and links remain unaffected.

**Existing unit tests** validate parsing, malformed provider output, GA4 empty/zero distinction, date boundaries, and GSC isolation. A live end-to-end Google OAuth authorization requires administrator action with the real Google Account and property and cannot be simulated by CI.

References: [Google Analytics Data API runReport](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport), [Data API dimensions and metrics](https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema).
