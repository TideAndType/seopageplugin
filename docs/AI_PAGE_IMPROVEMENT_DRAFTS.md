# TideOrbit 1.97 — Source-specific AI page audit and LM Studio review drafts

This release extends the existing **TideOrbit → SEO Campaign → AI citation evidence → SEO opportunities** workflow. An evidence-backed recommendation first must be approved and linked to a **specific existing WordPress post/page**. The new controls perform three distinct operations, each explicitly initiated by a WordPress administrator:

1. **Audit existing page** — free, deterministic and read-only. Reads the page title and the readable contents of its native WordPress content and Elementor text widgets. Compares the observed text to the actual AI citation question, records terms seen/not observed, content word count, Elementor presence, cited competitor *domains*, source date and evidence confidence. Missing query terms are **not proof that the page lacks relevant content**; human editorial review is required.
2. **Create LM Studio improvement brief** — explicit consent sends a bounded excerpt (up to 10,000 characters) of *readable text* plus question/audit signals to the **configured LM Studio endpoint only**, using the existing TideOrbit LM Studio provider. No fallback to cloud Claude, OpenAI or Gemini. Structured returned JSON includes editorial rationale, suggested headings, 2–5 draft sections and fact-check notes. AI content is sanitized, bounded and stored for review. Invalid/incomplete outputs fail closed with no draft. This may take longer depending on your local model and WordPress/Cloudflare tunnel.
3. **Create separate Elementor/WordPress review draft** — explicit confirmation creates a **new** WordPress post/page with `post_status=draft`. If the source is an Elementor page, TideOrbit copies the original native Elementor JSON in memory, keeps the existing layout untouched in the original page, and appends an isolated review section using standard Elementor container, heading, and text-editor widgets **in the new draft only**. If the source is a normal WordPress post, proposed content appears in the new draft's ordinary post body. The new draft contains an explicit “not approved for publication” heading and verification checklist.

## Steps

1. Open **TideOrbit → SEO Campaign** and select the month of the measured AI query.
2. Click **Analyze saved AI evidence**, review a suggestion, and **Approve** it.
3. Choose **Review an existing WordPress page**, search for a page/post by title, select it, and record the handoff.
4. In that opportunity, click **Audit existing page**. Read the term and page-coverage signals, then optionally **Create LM Studio improvement brief**. Confirm sending this bounded page excerpt to your LM Studio endpoint. This is **not** sent to a hosted model by automatic fallback.
5. Carefully review the brief, check proposed factual claims and company-specific details, then click **Create separate Elementor/WordPress review draft**. Confirm.
6. Open the new **draft in Elementor** or the normal WordPress editor, polish its text/layout, verify citations/claims and accessibility, and follow your ordinary editorial/publishing approvals. Nothing publishes automatically. The original source page remains untouched.

### Safety and privacy

- **Never edits** the source page's post content, title, status, metadata, _elementor_data, or revision.
- **Explicit administrator action** for all three steps, verified by WordPress REST permissions and nonce. Generating a brief also requires a second explicit consent acknowledging the page excerpt is sent to the configured LM Studio tunnel.
- **Source fingerprint** of title, WordPress content, Elementor JSON and last modification date must match the approved brief at draft creation; if a colleague edits the source, generate a fresh brief before creating the draft.
- **Duplicate draft guard:** WordPress checks for prior drafts/pending copies marked with the source post and evidence opportunity. It will not quietly create unlimited duplicates on repeat clicks.
- **No user-supplied URLs** are crawled by this workflow. It reads only a page/post selected from the local editable WordPress post list. Page content is sent as untrusted source data to LM Studio, with explicit instructions against following injected instructions, duplicating competitor text or fabricating facts.
- **No sensitive data intended:** Only publicly appropriate non-sensitive business page excerpts should be used. Check content for contact data, medical information or secrets before running LM Studio, especially if your LM Studio connection travels through a public tunnel.
- **Review-only generated wording:** Source citations and claims are **not** considered verified by the model. Brand mentions and AI citation outcomes are observations, not guarantees of rankings, leads, or future citations.
- **Lightweight:** No external JS framework, no new database tables, no tracking script on your pages, and no new image uploads. Old brief data is capped at 30 stored items.
- **Existing content creation workflow remains:** A different handoff mode (new Content Plan recommendation) keeps using TideOrbit's existing approved Content Plan → Brief → Generate Draft path, not this existing-page review path.

### What this is not

The feature is an editable *comparison draft*, not an automatic live-page patch or a publishable, fact-checked final article. A copied Elementor page may contain theme/dynamic widget behavior that requires editor review, and the isolated appended review section is deliberately separate from the existing design. It does not reconstruct an entirely new polished page based solely on the AI proposal, and does not rewrite original Elementor widgets in place. New references must be supplied and reviewed by the editor.

### Staging verification

Tests use deterministic unit fixtures for Elementor widget extraction, site content signals, AI schema validation and clone isolation. They do not connect to your actual LM Studio instance or run a real WordPress/Elementor browser. Before production:
- Verify LM Studio endpoint/model and tunnel availability in TideOrbit Settings.
- On a **staging site**, test an approved existing-page opportunity with a real Elementor page.
- Confirm the original page/Elementor source JSON remains byte-for-byte unchanged after all operations.
- Confirm the separate WordPress draft is in **Draft** status, opens in Elementor with native widgets, and shows the proposed review section.
- Check source-change warnings and repeat-click duplicate protection.
- Review privacy, required fact checks and existing security/hosting plugins before publishing any draft manually.
