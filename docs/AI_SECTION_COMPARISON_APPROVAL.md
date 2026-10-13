# TideOrbit 1.98 — Side-by-side comparison and selective AI section approval

This is a **human-controlled editor review step**, extending TideOrbit 1.97's page-specific LM Studio brief and independent Elementor/WordPress draft creation. It adds no new AI provider, paid API call, frontend tracking script, WordPress table or image upload.

## Steps

1. Open **TideOrbit → SEO Campaign**, locate an **approved AI citation opportunity**, and link it to an **existing WordPress page/post**.
2. Select **Audit existing page**, then **Create LM Studio improvement brief**. This explicitly sends a bounded text excerpt to your configured LM Studio endpoint only (no hosted AI fallback).
3. Select **View before / after and select sections**. The two-column comparison shows:
   - **BEFORE:** a read-only, bounded excerpt of the actual page text, extracted from post content and readable Elementor text widgets, plus a link to the original page.
   - **AFTER:** individual LM Studio-proposed sections, each with its own checkbox and complete suggested text.
4. Verify every suggestion and check only the sections you want. Click **Save selected sections** and confirm. A **minimum of one** selection is required, and the server validates integer indices, uniqueness and range. You cannot approve a nonexistent or duplicate section.
5. The **Create draft with N approved sections** button becomes enabled after approval. Confirm it to create a **new independent draft**. Only the selected content sections are copied into its improvement proposal.
6. On a source page made with Elementor, the new draft retains the original design/layout and appends one **native Elementor container** containing the selected headings and proposed copy in an editable text-editor widget. **Unselected sections are excluded**. For regular WordPress content, selected copy is added to the new draft body instead.

The original post and its Elementor JSON are never modified by approval, preview or draft creation.

## Accuracy and limitations

- This is a **readable-content before/after comparison**, **not a screenshot or pixel-perfect visual diff**. It shows the current page's text excerpt and separate proposed additions. Existing sections are preserved, not rewritten or removed.
- A proposed section is **unverified AI-generated text**. Approval only authorizes copying it into a separate draft for further editing. It is **not permission to publish**. Verify statistics, citations, service claims, accessibility, internal linking and intended search intent yourself.
- The before excerpt is capped at 3,000 characters for a responsive admin comparison. It is labeled as truncated when applicable. The underlying page can contain more material.
- Source edits after brief generation invalidate approval and draft creation. Regenerate the brief after changes, then select the sections again.
- Regenerating the LM Studio brief **resets the selected-section approvals**. Approval requires the same brief timestamp that the editor previewed. A conflicting newer brief is rejected.
- Existing briefs from 1.97 start with **zero approved sections** until reviewed. A draft cannot be created with all sections implicitly selected.
- Previous drafts continue to be protected by the existing duplicate draft guard. The feature does not modify old drafts, automatically publish anything, or transfer edits to a live page.
- Admin REST permissions plus WordPress REST nonce checks guard preview and approvals. The stored data is bounded by the existing 30-brief cap and no extra tables are added.

## Verification checklist

On staging, select an existing Elementor page with a valid source citation opportunity. Generate a brief with at least two sections, select one, save approvals, create a separate draft, and inspect its native Elementor JSON. Confirm that exactly the approved section was copied and that the source Elementor JSON and live page were not touched. Test a changed source page, regenerating a brief, repeat clicks, and selecting zero sections to verify that the server refuses unsafe actions.

Deterministic PHP tests cover strict section indices, duplicate prevention, backward compatibility with unapproved legacy briefs, selected-only widget HTML, immutable source layouts, and approval source revalidation. These tests cannot replace a live Elementor rendering test.
