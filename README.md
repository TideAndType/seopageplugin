# TideOrbit

An AI-powered SEO operating system for WordPress + Elementor. It analyzes a
WordPress site, helps you decide what it should rank for, plans the pages and
articles it needs, and (in later phases) generates them using your Elementor
templates — always as **drafts you approve**, never auto-published by default.

> Product name: **TideOrbit**. Internal `SCC_` / `scc_` prefixes and the
> `seo-command-center` text domain are intentionally retained for backward
> compatibility with existing installs.

## Lean installation and WordPress editor performance (1.88.0)

The build removes an unused legacy layout engine and its two orphaned
providers. Production installer ZIPs contain only runtime code and assets;
developer documentation remains available in the GitHub repository.

The TideOrbit dashboard and standard WordPress post editing screens now
load separate assets. Post/page editors receive a small SEO-panel controller
(`assets/js/editor.js`) and scoped styles (`assets/css/editor.css`),
instead of the full SEO dashboard JavaScript and CSS. All SEO panel actions
remain available. The full dashboard files continue to be used on TideOrbit
admin screens, including Layout Engine and LM Studio Visual Recreation.

GitHub Actions creates a clean staging copy, minifies both admin and editor
JS/CSS **at build time only**, syntax-checks the generated JS, and packages
the production copy. WordPress still loads the same asset paths. No npm
packages, build tools, React runtimes or CSS frameworks are installed on the
WordPress site. Previous source ZIPs are no longer committed in `dist/`;
install ZIPs are published as GitHub Actions artifacts.

Use the GitHub Actions `TideOrbit-WordPress-Install-<version>` artifact or
the provided ZIP link for WordPress; a GitHub **Source code** archive
includes unminified developer sources and is not the optimized installer.

## Visual Recreation Mode — zero new frontend dependencies (1.87.0)

From **TideOrbit → Layout Engine → select a draft → Visual Recreation
Mode**, open a 21st.dev component, take a screenshot of the component's visual
preview and use **Choose / upload reference screenshot** to add it to the
WordPress Media Library. TideOrbit accepts PNG, JPEG and WebP screenshots
up to 3 MB. Screenshots stay in Media Library, are not inserted into live page
content, and are not loaded by website visitors.

Click **Analyze design with LM Studio** while a **vision-capable LM Studio
model** is loaded. This makes one OpenAI-compatible multimodal chat call
using a base64 screenshot sent only to the explicitly configured LM Studio
endpoint (or its user-configured tunnel). TideOrbit rejects remote image URLs
and limits data image size. A text-only model cannot analyze the screenshot
and returns a clear error rather than pretending to have seen the image.

The vision model generates a small validated recipe (hero layout, tone,
site-brand accent, width, headline sizing, vertical spacing, card radius,
columns, section treatments, safe background and observations).
Enable **Use this visual recipe when regenerating**, then click
**Regenerate design**. The existing composition validator compiles the recipe
using ONLY native Elementor containers/widgets, responsive settings and the
original immutable content bank. No TSX, React, JavaScript, remote CSS, npm
dependencies or new Elementor add-ons are imported or enqueued.

TideOrbit keeps drafts and live pages separate and preserves existing restore
points. A reference design does not automatically publish changes. When you
want a second visual pass, take a screenshot of the generated Elementor draft,
choose it under **Compare and refine**, then ask LM Studio to compare the
reference and draft images. The resulting refined recipe can be regenerated
and reviewed before another apply. **Screenshot capture is manual; live
browser pixel-diff testing is not claimed.** Native Elementor may approximate
complex effects, bento arrangements and animated interactions rather than
matching React components pixel for pixel.

## 21st.dev Design Discovery (1.86.0)

Go to **TideOrbit → Layout Engine → 21st.dev Design Discovery**. Choose a
component category and click **Scan next components**. TideOrbit retrieves a
fixed 21st.dev category listing plus a small batch of detail pages per click,
stores titles, author, description, tags, dependencies, stated license and
visual pattern signals, and shows a browsable source-linked catalog. This
administrator-only scan requires the site's ability to make outbound HTTPS
requests to 21st.dev. Repeat to fill a category; scan other categories to
broaden the design library.

Saved component metadata is automatically summarized for both the LM Studio
compact art-director and the full Elementor Design Agent, giving them
real-world visual references rather than generic layout instructions. Select
**Use as design reference** next to any discovered component on an open page
to add it to the design direction and click Regenerate Design.

**Boundary:** The scanner imports **references only**, never remote JS/TSX.
No code is executed, added to the website or redistributed. Design treatments
are reproduced with the plugin's existing editable Elementor DSL/widgets,
not pixel-perfect React component clones. Import of specific interactive
components would require a separate, versioned, license-verified Elementor
widget adapter. Scans are user-triggered and conservative to reduce request
timeouts and load on the source.

## LM Studio professional page creation (1.85.0)

When **LM Studio** is the chosen Layout Design provider (or primary AI provider),
newly generated **WordPress pages** with auto-build enabled are no longer
limited to the static section builder. After the full draft copy is saved,
LM Studio produces a short creative art-direction recipe; TideOrbit turns it
into complete native Elementor containers/widgets with intentional typography,
responsive layouts, brand colors, full sections, supplied images, service cards,
FAQ, stats, links and CTA. The agent never rewrites completed copy.

This compact recipe is designed for small local models and tunnel connections.
It requires far less generated JSON than the full schema-aware agent. AI
failures do not destroy the draft: the previous deterministic Page Architect
remains the fallback. Existing mapped Elementor templates remain unchanged.

The **Layout Engine** also uses the compact designer when LM Studio is the
selected layout provider and AI Design Agent is enabled. Draft copies and
rollback remain available. An actual WordPress+Elementor visual smoke test
should still be done before using this on a live published page.

## Status

**Current version: 1.85.0.** The plugin is organized around four areas —
**Dashboard, Create, Optimize, Opportunities** — with an **SEO Copilot** on the
Dashboard that answers plain-language questions using your real data. All seven
foundational phases plus the intelligence engine, CMS-agnostic template system,
and the hardening + simplification passes are implemented. See
[`docs/ROADMAP.md`](docs/ROADMAP.md) for the detailed breakdown.

**v1.83.0 — Schema-aware Elementor Design Agent:** The Layout Engine can now discover the Elementor and add-on widgets actually installed on the site, read a safe subset of their live controls, and turn a freeform design prompt into a validated nested composition of containers/widgets/responsive rules. Finished page copy is exposed to the designer only through immutable content references, raw Elementor JSON/code widgets are rejected, and Apply revalidates against the current post before using the existing snapshot/rollback writer. The deterministic Page Architect remains the fallback when AI or the richer composition path is unavailable.

**Foundation (phases 1–7)**
- **Foundation:** bootstrap/loader/lifecycle, versioned custom tables, security
  helpers, secret-redacting logger, provider-independent AI layer (Claude,
  OpenAI, Gemini, **LM Studio** — primary/fallback, per-task routing, budget
  guard), usage/cost tracking, content analyzer, robots-aware crawler,
  SEO-plugin detection, admin UI, internal REST API.
- **Strategy:** AI topical-map builder, site architecture, content plan,
  cannibalization detection.
- **Content Generation:** multi-step generator (brief → body → metadata → schema
  → draft → quality score), non-destructive metadata, validated schema.
- **Elementor / renderers:** detection + template mapping; a CMS-agnostic
  template engine and renderer abstraction (Gutenberg, native WordPress, and
  optional Elementor Free).
- **Internal Linking, Data Integrations, Scale & Ops:** content graph +
  recommendations; Google Search Console, DataForSEO, competitor analysis (all
  optional, no fabricated data); background jobs, batch generation, publishing
  queue.

**SEO Intelligence Engine & editing (merged, v1.22.1)**
- **Opportunity Engine + Action Queue** (scored, explainable, safe reversible
  execution), **Page Optimizer**, **Health Timeline**, **Experiments**, **Entity
  Authority Graph**, revenue-aware prioritization, automation modes, and an
  honest **AI/GEO Visibility** scaffold — one intelligence layer orchestrating
  the existing systems, never fabricating metrics.
- **Template Mapping 2.0** (central variable registry, typed resolution/
  escaping, validation), **AI-assisted internal linking**, **Meta Editor** (bulk
  edit titles/descriptions with AI suggestions), **Content Ideas**, richer
  competitor gap analysis.

**Recent passes (this branch)**
- **v1.84.2 — Simple Local Grid UX:** Removes latitude/longitude from the normal Local Map Grid workflow. Users now enter a keyword, business name, and a familiar city/ZIP/address; TideOrbit resolves the coordinates automatically through the paired Rank Tracker, with DataForSEO business-location resolution as fallback. Coverage is presented as Neighborhood, City, or Wider Area instead of raw grid spacing. Domain and Google Place ID/CID live under an optional Advanced Matching section, and raw coordinates are no longer shown in result tiles.
- **v1.84.1 — Rank Tracker Connection UX:** Moves the Google Maps Rank Tracker / Browser Runtime pairing controls into **TideOrbit → Connections**, where integrations belong. Connections now includes execution mode, Cloudflare Tunnel URL, write-only pairing key, current bridge status, and a one-click **Test Rank Tracker connection** button that confirms Chromium readiness and queue state.
- **v1.84.0 — TideOrbit Browser Runtime:** Local Map Grid can now use a paired Google-Maps-SERP Playwright/Chromium worker through a Cloudflare Tunnel instead of paying for every grid point. **Automatic** mode tries the local browser first and falls back to DataForSEO when the tunnel/browser is unavailable; **Browser only** and **DataForSEO only** modes are also available. Browser scans are asynchronous: WordPress submits the job, returns immediately, polls through WP-Cron, and imports the completed grid into the same Growth Lab result store. Pairing uses a secret stored in TideOrbit's non-autoloaded credentials option, and the companion scanner exposes only authenticated TideOrbit bridge routes on its dedicated localhost port rather than tunneling the full desktop UI.
- **v1.82.0 — SEO Growth Lab + Publish Preflight:** Adds a measured growth layer on top of the existing SEO Doctor and Opportunity Engine. TideOrbit-generated pages now run a site-aware preflight before TideOrbit publishes or schedules them, catching repeated long passages, duplicate FAQs, likely wrong-city template bleed, weak title/intent alignment and Search Console cannibalization evidence. The new **Opportunities → Growth Lab** adds Search Console-backed cannibalization groups with a recommended keeper URL, GSC-weighted internal-link authority routing with a safe **Strengthen page** action, a DataForSEO-powered Google Maps 3×3/5×5 geo-grid tracker, DataForSEO backlink-gap discovery/classification, and Core Web Vitals root-cause clustering by shared WordPress/Elementor template signature. Maps and backlink data are measured only when DataForSEO is connected, GSC features stay unavailable rather than inventing rankings when Search Console is disconnected, and redirects/link acquisition remain review-first rather than automatic.
- **v1.81.1 — Bug fixes from a live test site:** Ran the plugin on a real WordPress install and fixed what broke.
  - **Titles and descriptions now reach the page.** Without an SEO plugin, TideOrbit saved your SEO title and meta description but never printed them, so the Meta Editor and the Doctor's “Write description” fix had no visible effect. They are now output (`<title>` and `<meta name="description">`), and only when no SEO plugin is active (Yoast, Rank Math, AIOSEO, The SEO Framework, SEOPress, Slim SEO, Squirrly or SmartCrawl).
  - **No more false “orphan page” alarms.** The crawler dropped menu, header and footer links, so every page reached only from the menu was reported as orphaned and unreachable. Menu links now count. A crawl that couldn't read most pages (or the homepage) no longer reports every page as orphaned.
  - **Site check-up works on local / Docker sites.** The crawler refused to fetch the site's own address when it resolved to a private or loopback IP. Your own site is now allowed, on its own port only; other private addresses and cloud metadata stay blocked. PageSpeed now says plainly that Google can't reach a local site instead of spending quota.
  - **Readable text from page-builder markup.** HTML with no spaces between elements produced glued text (“Daytona BeachWhat we fix”) in drafted descriptions, social tags, schema and keyword matching. Descriptions are now drawn from the page's paragraphs, not its headings, and JSON-LD no longer contains a literal `&hellip;`.
  - **Homepage schema fix** now adds Organization markup (what the audit asks for) instead of a WebPage plus a “Home › Home” breadcrumb, and falls back to the site name when the business name was saved blank. A skipped type is explained in the preview.
  - **Links never point at drafts.** Internal-link suggestions, auto-woven links in generated articles and the page plan could link to draft or private pages, which 404 for visitors. Only published pages are offered now.
  - **Broken links say where they are:** evidence now reads “HTTP 404 · linked from /services/”.
  - **Social tags:** og:image falls back to your logo, then site icon. The one-click badge is hidden once tags are on, and the fix explains that a missing image is what's left.
  - **LM Studio fails fast when it isn't running.** Only a connection cut off mid-response (a tunnel blip) is retried. A refused connection, unknown host, timeout or TLS error now fails in under a second instead of after 24 s of retries.
  - **Accurate AI errors.** The message names the provider that actually failed, and says when your primary provider has no key. Status is now 502, not 500.
  - **Correct status codes:** missing template → 404, clone without an ID → 400, Search Console connect before the Google app is set up → 400 (all were 500).
- **v1.81.0 — Ignore + noindex/template exclusion:** Every SEO Doctor problem has an **Ignore** button (hide it on every page) and each affected page has **Ignore page**. Ignores are remembered across check-ups and listed in a new **Ignored** tab with **Undo**; ignored site-crawl problems also stop counting toward the score (re-scored without re-crawling). Templates (Elementor library items, Elementor-template pages, TideOrbit SEO templates) and pages you deliberately set to **noindex** in Yoast, Rank Math, AIOSEO or The SEO Framework (including their post-type defaults) are now left out of every suggestion: the site crawl, content analysis, internal-link suggestions, AEO, site architecture and PageSpeed tests. A page that turns out noindex *without* you having set it is still flagged, since that is usually an accident. Run a fresh check-up to clear previously listed template pages.
- **v1.80.2 — Citation Scanner fix:** Running a citation scan failed with “esc is not defined” because the scanner used an HTML-escaping helper that only existed inside other screens. `admin.js` now has one shared escaper, and the test suite guards against missing helpers and duplicate function names.
- **v1.80.1 — One diagnosis tool:** The separate **Site Audit** screen is merged into the **SEO Doctor** so there is one place that says what is wrong. The check-up now also reads your content (the old “Analyze my site” step) and lets you pick the crawl size (50/150/300 pages). Dashboard cards that repeated the Doctor’s findings (site overview tiles, Top opportunities, keyword cannibalization) were removed; opportunities the crawl already reports are no longer listed twice, and cannibalization/topic gaps are filed under Content. Every Doctor area links to its full tool — including **AI search → AEO / AI Citations** — and `#doctor-<area>` links open the Doctor on that area. Nothing was lost: single-page competitor comparison moved to **Opportunities → Competitors**, Search Console quick wins to **Opportunities → Keywords**, and old Site Audit links redirect to the Doctor. Also fixes a duplicate JavaScript function that stopped the Keywords screen’s “Add to plan” buttons from working.
- **v1.80.0 — SEO Doctor:** The Dashboard now opens with an **SEO Doctor** that answers “what’s wrong with my site?” in one place. A full check-up crawls the site, measures real page speed and Core Web Vitals with Google PageSpeed Insights (real-user field data preferred, lab data labelled, failures shown as *not measured* — never estimated), and merges technical SEO, content, links, site architecture, AI-search readiness and Search Console signals into one ranked list of problems, worst first, plus a health score and an A–F grade per area. Scores only blend engines that actually ran, and no area can earn an A or B while a high-severity problem is open. Each problem explains what is wrong, why it matters, which pages, and how to fix it; safe fixes (meta description written from the page’s own copy, accurate schema, social sharing tags, internal links for orphaned pages) are **previewed first and applied only on click** — they never run from Autopilot — and are recorded for undo. Any problem can be sent to the Action Queue. New checks: missing schema for the page type, thin content, skipped heading levels, missing Open Graph tags, and target keyword missing from the title/H1 (only when a target keyword is known). New optional Open Graph/Twitter card output (off by default, disabled when another SEO plugin is active) and an optional PageSpeed API key under Connections.
- **v1.70.0 — SEO Growth Architect + AEO Expert + Elementor Live Safety:** Site Architecture now defaults to a **Recommended SEO Architecture** future-state blueprint instead of mirroring the current site, with a separate **Current Site** toggle and prioritized **Do now / Do next / Later** growth roadmap. Recommendations combine Architecture Brain decisions, real content coverage, GSC evidence, consolidation, orphan/depth signals, and AEO opportunities. A dedicated **AEO / AI Citations** expert audits answer-ready structure, evidence/source support, entity clarity, structured data, freshness, authorship, text depth, WordPress indexability and robots access for Googlebot, Bingbot and OAI-SearchBot; it never fabricates citation counts or promises inclusion. Content briefs and generation now plan answer-first passages, explicit entities, and evidence requirements while forbidding invented citations and AI-SEO folklore. The Elementor Layout Engine is **draft-first**: Drafts and Live are separate views, published pages are never an automatic fallback, live Apply requires explicit UI and server-side confirmation, every apply preserves a restore snapshot/revision, and a live page can be cloned into a marked draft working copy before design changes. Template-source screens clearly label Elementor Library, Draft, and **LIVE page — copy only** sources.
- **v1.60.0 — SEO Architecture Brain:** Site Architecture is now an evidence-backed decision system rather than a URL-gap viewer. TideOrbit measures existing-page topic coverage from the Content Index, uses Search Console page/query associations with an existing-page-first rule, distinguishes **create page / create article / create location / expand existing / keep / ignore**, infers recursive service hierarchy from real nested URLs, and provides drag/drop parent overrides without rewriting live permalinks. Architecture Health diagnoses weak coverage, orphan/unreachable and deep pages, unsupported service hubs, and likely consolidation candidates. The Consolidation Planner proposes a keeper/merge path with review steps but never merges content or creates a 301 automatically. The Action Center can send genuine page gaps to Content Plan, queue expansion/merge reviews, mark topics covered, ignore them, restore recommendations, and change parents. For **Expand existing**, TideOrbit can generate a constrained missing-section draft from the page and Brand Brain facts, show it for review, and only on explicit approval append it to Elementor or WordPress content with a recovery snapshot/revision and rollback action.
- **v1.54.0 — Intent-aware Site Architecture:** Architecture no longer treats a different slug as proof that a new page is needed. Existing URLs are matched by exact path and conservative topic identity, so a suggestion such as `/24-7-monitoring-alerting/` is reconciled to an existing `/managed-it-services/24-7-monitoring-alerting/` page instead of becoming a duplicate gap. Commercial/transactional subtopics now default to **sections on the parent service page**, while informational subtopics remain eligible for supporting articles and local-intent topics can remain location pages. The Architecture UI separates **Service-page sections · no new URL** from true **Gap · new page** recommendations, and only real page gaps can be sent to Content Plan. The topical-map prompt and UI now follow the same consolidation-first rule.
- **v1.53.3 — The SEO Framework support:** TideOrbit now detects The SEO Framework as an active SEO plugin, labels it correctly throughout the dashboard/API, reads its custom `_genesis_title` and `_genesis_description` values, and writes approved TideOrbit metadata back to those same TSF fields instead of incorrectly falling back to TideOrbit-owned metadata.
- **v1.53.2 — Citation Scanner submit fix:** moves the Citation Scanner behavior into the main TideOrbit admin JavaScript so it initializes after the localized REST configuration is available, switches the scan to the canonical `seo-command/v1/citation-scan` route, and gives the form a safe Opportunities → Citations fallback URL so a JavaScript failure can no longer dump the user on a blank `wp-admin/admin.php?` page.
- **v1.53.1 — Metadata filter warning fix:** the bulk metadata editor now normalizes the optional `filter` argument before validation, eliminating the PHP 8.x `Undefined array key "filter"` warning when the listing is loaded without an explicit filter.
- **v1.53.0 — Technical SEO Brain:** Site Audit now includes a live rendered-site technical crawler and evidence-first diagnostics for indexability, robots directives, XML sitemap health, HTTP errors and redirects, canonicals, duplicate/missing metadata, H1 hierarchy, internal-link architecture, orphan/unreachable/deep pages, broken and redirecting internal targets, hreflang self/reciprocal annotations, malformed JSON-LD, mixed content, mobile viewport, image alt/dimension signals, and server-response outliers. Findings are grouped by severity with affected URLs, evidence, why the issue matters, and a specific remediation. A weighted **Technical SEO Health** score and category scores are diagnostic only—not a Google ranking score or Core Web Vitals field-data score. SEO Copilot can answer technical SEO questions from the saved live audit instead of generic AI guesses. Search Console remains fully website-hosted: Google redirects directly back to WordPress, with no Vercel/broker dependency.
- **v1.52.0 — Search Console connection UX:** Search Console uses a direct Google OAuth flow that returns to the current WordPress admin. The website owner configures one Google OAuth Web application once; after that the normal flow is **Connect Google Search Console → choose account → Allow → back to TideOrbit**. Access is read-only, properties are discovered automatically, and Disconnect revokes the stored authorization. There is no TideOrbit cloud broker or external auth service.
- **v1.50.0 — Smart Page Brain:** generation, SEO diagnostics, and Elementor composition now share one site-aware page plan. The Page Brain uses the content index, entity/link graphs, real brand facts, cannibalization signals, internal-link candidates, schema intent, and optional AI refinement without adding an extra AI call to Quick Generate. **Brand & Evidence Brain** settings let you supply verified services, locations, CTAs, differentiators, proof, credentials, testimonials, voice, and forbidden claims so content can use real evidence without fabricating it. **Topic Coverage + TideScore** replace keyword-density-first scoring for new smart pages with intent, topic/question coverage, metadata, internal links, evidence, schema, conversion structure, and readability diagnostics. The **Smart Page Architect** adds a controlled 60+ component/variant catalog and page-type compositions for service, local service, location, landing, and editorial pages; optional AI layout refinement may only select registered components, while the layout critic validates hierarchy, variety, readability, CTA, accessibility, and SEO structure before Elementor apply. Smart aliases continue to render as native Elementor containers/widgets, inherit site Design Intel/global styling, and reuse mapped Elementor templates. Search Console now supplies a cached post-publish learning loop for CTR, near-ranking topics, and emerging queries, surfaced alongside cannibalization and explicit content gaps in the editor's **Smart SEO** panel. Schema planning follows the Page Brain and only emits supported markup that matches visible content.
- **v1.42.1 — Security & Runtime Hardening:** crawler/competitor fetches now block loopback by default and use safe redirect validation; LM Studio explicitly opts into local endpoints. Elementor layout apply now requires per-post access, captures a rollback snapshot, verifies every write, and restores the prior document on failure. Near-term job processing uses a dedicated cron kick hook, REST DB error suppression is restored after responses, CTA copy is never invented by the design layer, and CI covers PHP 7.4/8.2/8.3.
- **v1.42.0 — Professional Elementor Design Engine:** the article/content engine now hands completed copy to a separate design-only contract. Visual composition no longer reads keyword, search intent, city, or page-type metadata. TideOrbit owns its Elementor widget catalog and uses runtime discovery to choose real Heading, Text Editor, Button, Image, Icon List, Accordion and Counter widgets when available. Long-form content is composed section-by-section into editorial, split-list, media-split, callout, wide and readable treatments, with responsive containers and safe fallbacks. CTAs are only rendered when supplied by the content layer; the design layer does not invent CTA copy or destinations.
- **v1.41.0 — Native Elementor Design Engine foundation:** generated layouts began preferring real Elementor containers and core widgets over one large HTML widget, with Elementor capability discovery and cached site Design Intel.
- **v1.23.0 — Hardening:** centralized outbound-URL SSRF guard (`SCC_URL`) enforced
  before every request (loopback allowed for LM Studio; private/reserved/metadata
  blocked), a proper `robots.txt` matcher (`SCC_Robots`), RFC 3986 crawler URL
  resolution + crawl-identity normalization, crawl/final/canonical separation,
  content-type filtering, JSON-LD de-duplication, REST object-level authorization,
  and stale-job recovery.
- **v1.24.0 — Simpler generation:** two explicit modes — **Normal** (a blog post
  is a plain native WordPress post: no template, no tokens, no page builder, no
  duplicate H1) and **Template** (structured service/location/landing/custom pages
  through the renderer layer). A one-topic quick-generate flow and a decluttered
  Generate screen (progressive disclosure).
- **v1.25.0 — Simpler navigation:** the admin menu is consolidated into tabbed
  hubs; no screen or feature removed.
- **v1.26.0 — Action-oriented product:** the plugin now revolves around four
  areas — **Dashboard**, **Create**, **Optimize**, **Opportunities** — and adds
  an **SEO Copilot** on the Dashboard. Ask in plain language ("what should I work
  on this week?", "find pages losing traffic", "find cannibalization") and it
  routes to the existing Opportunity Engine, returning real, ranked opportunities
  with reason + evidence + a recommended action and one-click Add-to-queue /
  Dismiss. It never fabricates data and says plainly when a source (e.g. Search
  Console) isn't connected. Built entirely on the existing intelligence layer.

Internal REST API at `/wp-json/seo-command/v1/*`; CI validates the supported PHP matrix and the dependency-free regression suite.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — components, AI abstraction, security model, outbound-network safety, generation modes, admin hubs
- [`docs/ROADMAP.md`](docs/ROADMAP.md) — phased plan through v1.25
- [`docs/DATABASE.md`](docs/DATABASE.md) — options + custom tables
- [`docs/API.md`](docs/API.md) — REST routes
- [`docs/TEMPLATES.md`](docs/TEMPLATES.md) — template engine + tokens
- [`docs/RENDERERS.md`](docs/RENDERERS.md) — renderer abstraction
- [`docs/ELEMENTOR_DESIGN_ENGINE.md`](docs/ELEMENTOR_DESIGN_ENGINE.md) — native Elementor component rendering, design profiling, variants, and fallback order

## Install (dev)

Copy the `seo-command-center/` directory into `wp-content/plugins/` and activate.
Requires WordPress 6.0+ and PHP 7.4+.

## Tests

```bash
php tests/run-tests.php
```

See [`tests/README.md`](tests/README.md) for the WordPress integration-test and
manual-testing checklists.

## Principles

- You stay in control: content is saved as a draft unless you explicitly enable
  auto-publishing.
- No destructive actions (delete/redirect/overwrite) without explicit approval.
- No fabricated data: when a data API (DataForSEO / GSC) is not connected, the
  plugin does not invent volume or ranking numbers.
- The plugin does not encourage keyword stuffing, doorway pages, spun content,
  or other manipulative tactics.
