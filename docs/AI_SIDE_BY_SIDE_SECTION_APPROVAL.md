# TideOrbit 1.98 — Before/after content comparison & selective section approval

This update extends the version 1.97 page-improvement workflow in **TideOrbit → SEO Campaign → AI citation evidence → SEO opportunities**.

## What changes

After **Approve recommendation → Review an existing WordPress page → Create LM Studio improvement brief**, select **Compare source vs proposed sections**. TideOrbit shows a responsive, two-column **content comparison**:

- **Before:** A *read-only excerpt of the actual existing WordPress and native Elementor widget copy*. The original page title, source page link, approximate word count and Elementor indicator are shown.
- **After:** The independently generated LM Studio suggested sections, each with its heading, proposed copy and separate **Approve for draft**, **Reject** and **Clear decision** buttons.

The UI is a **text/content comparison**, not a pixel-accurate browser screenshot or an automated matching of each source widget to a suggested section. Sections are editorial proposals, not assumed safe replacements. The original page is never mutated.

### Example workflow

1. Use an AI visibility observation and generate an evidence-based opportunity, then approve it.
2. Link one existing editable WordPress post/page.
3. Run the read-only source audit.
4. Generate the editorial suggestions via your own **LM Studio** instance (explicit consent; no automatic hosted-model fallback).
5. Open **Compare source vs proposed sections**.
6. Verify each suggested claim and review the source page. **Approve** only the suggestions you want in the draft; **Reject** inappropriate sections. Unreviewed sections remain **Pending** and excluded.
7. Click **Create draft from approved sections only**. The plugin requires at least one approved section, then creates one distinct WordPress **draft**; if the source uses Elementor, the new draft contains the native copied Elementor layout and a separate native, editable proposal container with **only approved sections**. Rejected and pending model copy is not appended to the draft.
8. Open the draft's WordPress/Elementor editor, merge any approved changes into the intended existing widgets manually, perform fact-checks, check visual design, and publish only using your usual WordPress editorial workflow.

## Controls and security

- Review is available only to WordPress administrators for an **approved, linked existing-page** citation opportunity.
- A **brief revision fingerprint** binds per-section decisions to the exact proposal. If LM Studio regenerates the brief, all approvals are reset; decisions made against an older revision are rejected.
- A **source content fingerprint** is validated before comparison, approval and new draft creation. If someone changes the source page or its Elementor JSON meanwhile, regenerate the brief and repeat editorial review.
- The REST API accepts only integral section indexes within the exact section list and the statuses **approved**, **rejected** or **pending**. Only a real administrator with a valid WordPress REST nonce may submit decisions. The browser controls are convenience; the server enforces the same restrictions.
- Draft creation fails with a clear message when no sections are approved. Only the saved approved section subset is rendered server-side.
- Every draft is **post_status=draft**, separate from the existing page. Prior draft detection avoids accidental duplicate handoffs. The original page and its Elementor metadata are never edited.
- Existing Elementor widgets are cloned, not automatically rewritten. This is intentional: replacing arbitrary native Elementor widgets without semantic mapping could destroy client layouts. Approved copy appears in a distinct editable review section, where an editor can decide exactly how to incorporate it.
- No new frontend scripts or runtime packages are shipped. The two-column comparison is generated within the existing WordPress admin Campaign UI using WordPress REST, simple CSS and safe text rendering. No third-party screenshot capture.
- No additional paid DataForSEO/Perplexity/Gemini request is triggered by comparing, approving or rejecting.
- Model-generated claims are not independently verified; approval means **include in the review draft**, not **factual certification** or permission to publish.

## Test coverage

Automated PHP tests cover source excerpt extraction, source-change rejection, revision conflicts, invalid section IDs/statuses, mixed approved/rejected decisions, approved-only native Elementor widget content, preserving source Elementor JSON, draft-only creation, editor-compatible Elementor metadata, and repeated creation protection.

### Deployment verification

After installing version 1.98 on **staging**, try the workflow against one actual published Elementor page and confirm (a) side-by-side copy is the real page copy, (b) approved sections appear only in the new draft, (c) rejected sections are absent, (d) the original Elementor JSON is unchanged, and (e) no page is automatically published. These end-to-end behavior checks cannot be proven by unit tests alone.

The feature is a supervised editorial tool, not an automatic promise of Google/ChatGPT/Gemini/Perplexity citations.
