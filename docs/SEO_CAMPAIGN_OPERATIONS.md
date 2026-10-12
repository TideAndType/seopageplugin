# TideOrbit 1.90 — SEO Campaign Operations

## Purpose

TideOrbit now maps Tide & Type's recurring SEO engagements onto executable monthly work rather than treating keyword research, site scans and content generation as disconnected utilities.

Go to **TideOrbit → SEO Campaign** in WordPress. This is a **single-site campaign workspace**, not a cross-site agency hub.

## Plan profiles

| Campaign | Markets | Tracked search themes | Default monthly content |
| --- | ---: | ---: | ---: |
| Local SEO | 1 | 10 | 1 |
| SEO Growth | 3 | 25 | 3 |
| SEO Authority | 5 | 50 | 4 (can be raised) |

The campaign generates a monthly deliverable ledger with editing/review statuses, notes and evidence URLs. Work items are **not** marked complete by merely performing a scan; an operator must verify that delivery occurred.

## Measurements

- **Google Search Console:** Read-only, using the existing authorized GSC connector. Manually refresh or capture weekly using the existing jobs cron. Captures measured query-level **impression-weighted average position** (rolling 28-day window), clicks and impressions. These are **not exact SERP positions** and are not segmented by user-specified market unless a dedicated appropriate data source is used.
- **Organic SERP rank:** Optional explicit **paid DataForSEO** action, five target queries per click and 20 per day (best-effort WordPress-side cap). It asks for user confirmation, accepts the configured market and records unavailable, found and not-found separately. **Maintain a hard spend ceiling at your DataForSEO account.** It never runs on cron.
- **Google Maps grid:** Existing Browser Runtime/DataForSEO grid scans now keep the last 60 summary results instead of replacing the only last snapshot. A scan with no measured points has null visibility, not zero; partial measurements disclose coverage.
- **Citation directory inventory:** Saving a campaign seeds a status ledger from the existing citation-directory catalog. Staff can record missing, claimed, submitted, corrected/verified and other statuses with notes and evidence. No automated directory submission is performed.
- **Authority outreach:** After the existing Backlink Gap scan, an explicit import pulls prospects into a manual outreach pipeline (identified, qualified, pitched, responded, earned, etc.) with evidence URLs. No messages are sent.
- **GA4, GBP, CRM, reviews, links, AEO citations:** Manual evidence rows and deliverable tracking are available. These APIs are **not connected by this release**. Records clearly say `manual_entry` and `verified=false` even when an evidence URL is supplied. Do not claim automated or verified results.
- **Monthly reporting:** Live admin report assembled from stored tasks and measured GSC/SERP snapshots plus the separately labeled manual evidence ledger. Use Print / save PDF from the browser. No outbound email or scheduled third-party access is initiated.

## Public citation endpoint safety

The existing public citation shortcode/REST endpoint is now **disabled by default**. Administrators can explicitly enable it in Campaign Operations. Even when enabled, it uses the free search path by default and does **not** use stored paid DataForSEO credentials. A second explicit opt-in allows paid calls with a daily best-effort counter of 10. The client limiter hashes IP only, not User-Agent. Transient/option counters alone cannot guarantee strict spending protection with simultaneous requests; use provider-side financial caps and/or an atomic persistent request ledger if offering public scans at scale.

## Reliability repairs

- Internal links record a typed storage target (`post_content` vs `_elementor_data`) and post-write SHA256. Undo refuses to overwrite a page changed after the operation, restores the correct storage and verifies the value written. Legacy Elementor history with JSON-array snapshots is detected; ambiguous backups fail closed.
- Normal Elementor `wp-page` and `wp-post` are not excluded as design templates.
- The AI manager attempts **only** the configured primary and explicitly chosen fallback. Local-only operations cannot unexpectedly fall through to other configured hosted APIs.
- DataForSEO task-level failure inside HTTP 200 propagates as `WP_Error` rather than empty results.
- Background job timestamps are consistently stored as UTC going forward. Cron dispatch has a narrowly scoped internal authorization context for link-autopilot and no longer silently reports every link failure as success.

## Manual integration and production checklist

1. Backup database and Elementor posts before installing this release.
2. Use a staging WordPress installation, with Elementor installed, to test **create → insert link → verify front-end → undo → verify front-end**, including stale/conflicting edits.
3. Validate the new database version `1.21.0` upgrade adds typed history columns.
4. Test queue execution via WP-Cron and the non-cookie authenticated loopback, especially a site in a non-UTC timezone.
5. Test GSC configured and disconnected; confirm unmeasured keywords display unavailable, not zero.
6. Explicitly opt into paid DataForSEO calls only after reviewing live API costs.
7. Verify the campaign plan for each client. The plan creates workflow tasks but does not publish content or modify GBP.
8. Check manual report output and print/PDF readability across a low-traffic and high-traffic site.

### Future provider work requiring authorization / credentials

- GBP OAuth, location management, posting, insights, review monitoring and owner-approved replies.
- GA4 Data API and attribution; any CRM/GoHighLevel plugin connection.
- Automated directory submission with provider-supported verification and human checkpoints.
- Real ChatGPT/Gemini/Perplexity citation measurements from permissioned services.
- A central multi-site agency dashboard and automated report delivery.
- Database-backed **atomic** paid-request ledger with financial spend limits.
- Actual WordPress+Elementor end-to-end integration tests in CI (existing tests use stubs).

None of these are claimed as complete in this version.
