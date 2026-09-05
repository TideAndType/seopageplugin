# SEO Command Center — Roadmap

Development proceeds in phases. A phase is not "done" until it is stable
(activates cleanly, no fatal errors, tests pass, security reviewed). We do not
start a phase until the previous one is stable.

Legend: ✅ done · 🚧 in progress · ⬜ not started

---

## Phase 1 — Foundation ✅
The functional base everything else builds on.

- ✅ Plugin foundation (bootstrap, singleton, loader, activation/deactivation)
- ✅ Custom database tables + activation/uninstall lifecycle
- ✅ Security helpers (capabilities, nonces, sanitizers)
- ✅ Diagnostic logger (secret-redacting)
- ✅ AI provider abstraction (interface + manager with primary/fallback + budget)
- ✅ Claude provider (Anthropic Messages API)
- ✅ OpenAI provider (Chat Completions API)
- ✅ AI usage / cost tracking
- ✅ WordPress content analyzer (posts, pages, CPTs, headings, links, meta)
- ✅ Site crawler (rendered fetch + parse, robots-aware for external URLs)
- ✅ SEO-plugin detection (Yoast / Rank Math / AIOSEO)
- ✅ Admin dashboard, Site Analysis, Settings, API Connections pages
- ✅ Internal REST API scaffold (analyze, settings, ai-test)

## Phase 2 — Strategy ✅
- ✅ Keyword / topic strategy builder (structured topical map via AI JSON, not keyword lists)
- ✅ Site architecture engine (Pillar → Service → Location → Supporting), marks existing vs new pages
- ✅ Content plan / calendar (CRUD, statuses, seed-from-architecture)
- ✅ Keyword → URL mapping (recommended slugs per cluster)
- ✅ Keyword cannibalization detection (token-overlap heuristic + recommendation options)

## Phase 3 — Content Generation ✅
- ✅ Multi-step AI content generator (brief → outline/entities/questions →
  draft body + metadata → validated schema → draft → quality score)
- ✅ Content briefs (approve before generate)
- ✅ SEO metadata generation, non-destructively written to the active SEO
  plugin's keys (Yoast/Rank Math) or `_scc_*` fallback
- ✅ Schema generation (validated, duplicate-aware) + front-end JSON-LD output
- ✅ Draft creation via `wp_insert_post` (draft by default; content via wp_kses)
- ✅ Content types: blog article, service, location (locally-specific prompt),
  landing/page
- ✅ Content quality score (labelled internal optimization score, not a ranking
  guarantee)
- ✅ Regenerate-section support (intro/conclusion/faq/cta/meta title/description)

## Phase 4 — Elementor ✅
- ✅ Elementor detection (graceful fallback to standard content when absent)
- ✅ Template detection (elementor_library) + "designate as SEO template" meta box
- ✅ Template → content-type mapping UI + store (one active per type)
- ✅ Placeholder system (`{{TITLE}}`, `{{CITY}}`, custom …) with detection
- ✅ Populate Elementor `_elementor_data` while preserving design (tree walk,
  regenerated element IDs, only text settings touched); wired into the generator

## Phase 5 — Internal Linking ✅
- ✅ Content graph model (parses posts, resolves internal hrefs to post IDs,
  inbound/outbound counts)
- ✅ Link recommendations (topical overlap, prioritizes under-linked/orphan
  targets, natural anchor that already appears in the source)
- ✅ Natural link insertion with anti-spam guards (per-page cap, first
  occurrence only, never inside headings/existing anchors, phrase must exist)
- ✅ Internal-link dashboard (orphans, under-linked, over-linked, apply flow)

## Phase 6 — Data Integrations ✅
- ✅ Google Search Console (OAuth refresh-token exchange + Search Analytics
  query; quick-win detection for positions 4–20 with impressions) — optional,
  honest disconnected state, no fabricated data
- ✅ DataForSEO (search volume, competition, CPC, related keywords via Basic
  auth) — optional
- ✅ Competitor analysis (public structure + topic coverage via the
  robots-respecting crawler; content-gap diff vs. own site)

## Phase 7 — Scale & Operations ✅
- ✅ Batch generation (all-approved from the plan, with a cost-confirmation
  prompt and a per-batch size cap)
- ✅ Background jobs (WP-Cron dispatcher, bounded per tick, resumable,
  retry with attempt cap, auto-pause on budget, failure logging)
- ✅ Usage tracking dashboard tiles + monthly budget enforcement (queue pauses
  automatically when reached)
- ✅ Publishing queue + workflow (preview, approve/unapprove, publish, schedule)

---

## v1.1 — Advanced Internal Linking, Meta Optimization & Schema ✅
Built on the existing architecture (AI layer, DB, REST, jobs, Elementor, settings).

- **Searchable content index** (`scc_content_index`): per-post tokens, headings,
  existing anchors, outbound links, keyword/intent — built incrementally on
  `save_post`, refreshable in the background. No full-site crawl per request.
- **Internal Link Autopilot**: contextual (TF-vector) relevance, not keyword
  match; new→existing and existing→new opportunities with confidence, reason,
  natural anchor, and the exact sentence; anchor-variation engine; confidence
  thresholds; auto-insert high-confidence via the job queue; site-wide
  reoptimization scan; orphan/under/over-linked dashboard; full change history
  with one-click revert. Anti-spam guards throughout.
- **AI Meta Optimizer**: classified variants (keyword/CTR/benefit/local/
  commercial/brand) with reasons, optionally informed by real Search Console
  performance; non-destructive apply with a 30-day cooldown; metadata history;
  configurable storage (active SEO plugin vs. plugin keys).
- **Automatic Schema Engine**: page-appropriate type detection, JSON-LD
  generation from dynamic WP + user-provided business data (never invented),
  conflict detection vs. SEO-plugin/rendered-page schema, validation with
  warnings, and a per-post editor workflow (generate/preview/validate/save/
  disable). Added `Person` and `NewsArticle` types.
- **Unified SEO Command Center editor panel** (meta box) with a readiness score
  (labelled internal, not a ranking guarantee) tying all three systems together.

## v1.2 — CMS-agnostic template engine & renderer abstraction ✅
Decouples page generation from Elementor. See `docs/TEMPLATES.md` and
`docs/RENDERERS.md`.

- **Three layers:** SEO Strategy Engine → Content + Template Engine → Renderer.
  The SEO engine references no page builder.
- **`SCC_Content_Object`** — standardized, renderer-independent page representation.
- **Native template engine** (`scc_templates`): `SCC_Template` (+ default
  structures per type), `SCC_Template_Store` (CRUD, **versioning**, **cloning**),
  deterministic `SCC_Template_Selector` (manual → rule → content-type → default →
  fallback; the AI never picks), `SCC_Template_Map` for content-type → template +
  renderer rules.
- **Renderer abstraction** (`SCC_Renderer_Interface` + `SCC_Renderer_Manager`):
  **Gutenberg** (default, block markup), **native WordPress** (classic HTML),
  and **optional Elementor Free** (duplicates `_elementor_data`, no Pro / Theme
  Builder). Automatic fallback when a builder is unavailable — generation never
  fails. Adding Bricks/Divi = a new renderer only.
- Generator refactored to: build content object → weave internal links → select
  template → select renderer (with fallback) → render → create draft. Metadata,
  schema, and internal linking remain renderer-independent. Generated pages are
  **plain WordPress content** that survives plugin removal. Existing Elementor
  mapping retained for backward compatibility.

## v1.3–v1.22 — SEO Intelligence Engine, Template Mapping 2.0, AI linking, Meta Editor & Content Ideas ✅
Merged into `ttgmbapp` as **v1.22.1**. Turns the toolset into an intelligent SEO
operating system that decides what to do next, explains why, executes safe
actions, and measures results. All additive, human-controlled, draft-first, and
never fabricates metrics. Adds tables `scc_seo_actions`, `scc_seo_snapshots`,
`scc_seo_experiments` (DB version 1.18.0).

- ✅ **Opportunity Engine** — transparent factor-point scoring, confidence, and
  data-availability states (`verified/partial/estimated/unavailable`). Signals:
  GSC striking-distance & untapped demand, topical-authority gaps,
  cannibalization, orphans, thin content, missing metadata, **Content Decay**,
  **Intent Drift** (both GSC-only).
- ✅ **Action Queue** (`scc_seo_actions`) — full lifecycle + logging; safe,
  reversible deterministic execution only (internal links); "Fix Everything
  Safe"; a Dashboard "What should I do next?" card and a dedicated screen.
- ✅ **Page Optimizer** (per-page scorecard + prioritized fixes), **Health
  Timeline** (`scc_seo_snapshots`), **Experiments** (`scc_seo_experiments`,
  correlation language only), **Entity Authority Graph**, revenue-aware
  prioritization, **automation modes** (conservative/assisted/autopilot), and an
  honest provider-agnostic **AI/GEO Visibility** scaffold.
- ✅ **Template Mapping 2.0** — `SCC_Template_Variables` central token registry,
  type-aware resolution/escaping, validation, preview, and a token reference.
- ✅ **AI-assisted internal linking** — opt-in; the AI picks natural anchors from
  verified candidates only and never invents URLs.
- ✅ **Meta Editor** — bulk-edit meta titles/descriptions from the admin with AI
  suggestions, per-row and "suggest/save all", and missing/present filters.
- ✅ **Content Ideas** — plain-language page ideas grounded in existing pages +
  real GSC demand + business + pillars, with a refine follow-up.
- ✅ **Site-wide template exclusion** (builder/template CPTs no longer leak into
  pages/posts), multi-page competitor gap analysis, sitemap-aware topical maps,
  Elementor link-insertion fix, Publishing Queue removal.

## v1.23 — Security & reliability hardening ✅
Additive; no DB changes. Adds the `includes/net/` layer.

- ✅ **Centralized outbound-URL SSRF guard** (`SCC_URL::is_safe_outbound_url`),
  enforced immediately before each outbound request. Loopback (`127.0.0.0/8`,
  `::1`, `localhost`) is allowed so LM Studio keeps working; RFC1918 private,
  link-local incl. the `169.254.169.254` metadata endpoint, multicast, reserved,
  IPv6 ULA/link-local, credentialed URLs and non-HTTP(S) schemes are refused;
  hostnames are resolved and resolved IPs judged (DNS-rebinding mitigation).
  Applied to the LM Studio provider and the crawler.
- ✅ **`SCC_Robots`** — a real robots.txt matcher (Allow/Disallow longest-match
  precedence, `*` wildcards, `$` anchors, multiple user-agent groups).
- ✅ **Crawler**: RFC 3986 relative-URL resolution; crawl-identity normalization
  (drops fragments + tracking params); crawl/final/canonical separation;
  content-type filtering (refuses PDF/image/video/binary); JSON-LD extraction
  de-duplicates identical blocks and tolerates malformed ones (no double-parse).
- ✅ **REST object-level authorization** on post-scoped mutations
  (`current_user_can('edit_post', $id)`), so relaxing `scc_required_capability`
  never grants blanket per-object access. `/settings` write path validates the
  LM Studio URL through the same guard.
- ✅ **Jobs**: stale-`processing` recovery so a dead worker never locks the queue.

## v1.24 — Simpler generation: Normal vs Template ✅
"Normal WordPress, with an SEO layer." Additive; no DB changes.

- ✅ Two explicit, deterministic generation modes (`SCC_Generator::is_native_mode`).
  **NORMAL** (`article`/`blog`/`post`) → a plain WordPress post: the sanitized AI
  body (H2 sections, lists, FAQ, CTA, **no in-body `<h1>`**) becomes
  `post_content` verbatim — no template, no tokens, no page builder, never pulled
  into Elementor. **TEMPLATE** (`service`/`location`/`landing`/`custom`) keeps the
  existing template + renderer + token path.
- ✅ Native excerpt + taxonomy via core WordPress: excerpt from the meta
  description, tags from the keywords, existing-only category matching (never
  creates duplicate categories).
- ✅ `POST /generate/quick` (topic → draft, reuses the existing generator) and a
  rewritten Generate screen: a Content-type selector (Blog Post default) with a
  topic + a few optional fields, everything else behind **Advanced**.
- ✅ Tokens and the whole template system remain fully supported for TEMPLATE mode
  and existing mappings — simply no longer required for an ordinary blog post.

## v1.25 — Simpler navigation: tabbed hubs ✅
- ✅ The ~15-item flat admin menu is consolidated into 8 items via three tabbed
  hubs — **Content** (Plan/Ideas/Generate/Publishing), **SEO** (Audit/Keywords/
  Architecture/Internal Links/Meta), **Strategy** (Opportunities/Topical
  Authority/Competitors). Each hub delegates to the existing, unchanged screens;
  every screen stays registered as a hidden route so deep links keep working. No
  screen or feature removed.

---

## Cross-cutting (every phase)
- Follow WPCS; sanitize input, escape output, prepared queries.
- No destructive actions without explicit approval.
- No fabricated data when an API is disconnected.
- Git commit at the end of each phase.
- Update the four docs when architecture changes.
