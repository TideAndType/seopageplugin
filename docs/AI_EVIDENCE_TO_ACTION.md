# TideOrbit 1.96 — AI Citation Evidence → SEO Opportunities → Existing WordPress Workflows

TideOrbit now turns **already saved** AI search measurements into an optional, evidence-grounded editorial review queue. It does **not** submit new questions to an AI provider, incur API charges, publish pages, overwrite Elementor designs, or treat AI visibility as a direct lead or ranking factor.

## User workflow

1. Install TideOrbit **1.96** and open **TideOrbit → SEO Campaign**.
2. Run one of the opt-in paid source measurements already implemented: a Google AI Overview/AI Mode question, a Perplexity Agent API answer, or a grounded Gemini Developer API answer. The feature **does not need additional credentials** beyond these existing integrations.
3. Select the reporting month in **Monthly SEO report**.
4. Under **AI citation evidence → SEO opportunities**, click **Analyze saved AI evidence for selected report month**. This step costs **nothing** and uses saved observations only.
5. Review each suggested opportunity's platform, observed question, capture date, brand-mentioned flag, citation confidence, cited competitor domains, and editorial checklist.
6. Choose **Approve recommendation** or **Dismiss**, optionally saving verification notes. Re-running evidence analysis does not erase a human approval/dismissal.
7. After approval, choose either:
   - **Review an existing WordPress page**: search for an actual page/post by title, select it, and record a review handoff. TideOrbit returns the normal WordPress editor URL. From there you can use Elementor or your existing design tools. **No existing content, metadata, or Elementor JSON is altered.**
   - **Suggest a NEW article in Content Plan**: enter an editor-approved article title and a unique lowercase URL slug/path. TideOrbit checks that the URL doesn't already exist as a WordPress page and avoids a duplicate title/keyword/slug in Content Plan. Then it creates a **recommended** Content Plan entry. It does not create a WordPress page or change publication settings.

The latter is a handoff to TideOrbit's **existing** Content Plan, Brief, Generate Draft, and Elementor systems. You must still approve the Content Plan entry and follow the separate existing draft workflow.

## Evidence classification

| Recorded evidence | Action |
|---|---|
| Verified answer, competitor cited, own site not cited | High-priority **competitor citation gap** review |
| Verified answer, brand mentioned but own domain not cited | Medium-priority **brand without citation** review |
| Verified answer, neither brand nor domain cited | Medium-priority **source coverage** review |
| Source metadata retrieved but actual citation mapping unverified | Low-priority **evidence verification** only; disallows new article handoff |
| Own website cited | Not automatically called a gap |
| No AI answer shown for one query | Not called a website/content problem |
| DataForSEO sampled ChatGPT/Google domain-count aggregate | Not treated as evidence for a specific new article |

**These are editorial hypotheses**, not diagnoses that a page is missing, inadequate or needs a replacement. Before writing anything, confirm the site doesn't already have a page on the subject; check the cited competitor's actual content and its trustworthiness. No recommendation claims that adding FAQs, schema, internal links or mentions guarantees AI search placement.

Direct-provider competitor comparisons are counted **only when the underlying provider returned verified citation markers/supports**. Merely retrieved or inferred competitor URLs cannot create a verified competitor gap. Domain names are validated and observations must match the current WordPress site's domain. A repeated query/platform within one month is deduplicated to its latest saved observation, preserving human review status.

## Controls and safeguards

- All queue-refresh, review, and handoff endpoints are administrator-only and use WordPress REST nonce protection.
- Evidence review never makes an external paid API request; previous paid checks retain their separate source label and measured date.
- Queue is bounded at **120 entries** per site using one non-autoloaded WordPress option, with a maximum of 35 shown per report view.
- Notes are sanitized; post/page selection searches only editable `post` or `page` records.
- New content titles and URL paths are validated and checked for duplicate Content Plan signatures and existing WordPress pages.
- No live post editing, draft generation or publishing is performed from this feature. Existing Content Plan settings (including any separate auto-publish setting) are not silently changed.
- A completed handoff cannot be repeated accidentally. Review states persist across source refresh.
- The returned editor URL is an ordinary WP edit link; selecting an Elementor editor afterward is up to the editor.
- Measurements are not enhanced by calling LM Studio or recreating third-party model answers.

## Implementation

- `includes/agency/class-scc-ai-opportunities.php`: pure evidence classification, persistent review queue, explicit WordPress handoffs.
- `includes/agency/class-scc-campaign-operations.php`: admin-only REST operations and report-state integration.
- `includes/admin/views/campaign.php` and `assets/js/campaign.js`: review UI, search-based existing-page selection and Content Plan recommendations.
- PHP regression tests verify source coverage checks, competitor citation quality, deduplication, human approvals, invalid or misleading evidence refusal, URL/path validation and no auto-publishing calls.

To exercise a **real** WordPress handoff, test on staging first: create a sample proposal for a topic not present on the site, approve it and send it to Content Plan. Confirm it appears as `recommended` and that no actual page was created. Then separately test existing-page review on an actual Elementor page and confirm its Elementor data remains unchanged.
