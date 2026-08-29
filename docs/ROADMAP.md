# SEO Command Center — Roadmap

Development proceeds in phases. A phase is not "done" until it is stable
(activates cleanly, no fatal errors, tests pass, security reviewed). We do not
start a phase until the previous one is stable.

Legend: ✅ done · 🚧 in progress · ⬜ not started

---

## Phase 1 — Foundation 🚧
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

## Phase 6 — Data Integrations ⬜
- Google Search Console (queries, impressions, clicks, CTR, position, pages)
- DataForSEO (volume, difficulty, SERP, related) — optional
- Competitor analysis (public, robots-respecting)

## Phase 7 — Scale & Operations ⬜
- Batch generation (one / selected / all-approved, queue, pause/resume/retry)
- Background jobs (WP-Cron dispatcher, resumable, retry, logging)
- Usage tracking dashboards + monthly budget enforcement
- Publishing queue + workflow (preview, approve, publish, schedule)

---

## Cross-cutting (every phase)
- Follow WPCS; sanitize input, escape output, prepared queries.
- No destructive actions without explicit approval.
- No fabricated data when an API is disconnected.
- Git commit at the end of each phase.
- Update the four docs when architecture changes.
