# SEO Command Center

An AI-powered SEO Command Center for WordPress + Elementor. It analyzes a
WordPress site, helps you decide what it should rank for, plans the pages and
articles it needs, and (in later phases) generates them using your Elementor
templates — always as **drafts you approve**, never auto-published by default.

> Working name / prefix: **SEO Command Center** · `scc` · text domain
> `seo-command-center`. Built to grow into a commercial plugin, not a one-off
> script.

## Status

**Current version: 1.25.0.** All seven foundational phases plus the intelligence
engine, CMS-agnostic template system, and the recent hardening + simplification
passes are implemented. See [`docs/ROADMAP.md`](docs/ROADMAP.md) for the detailed
breakdown.

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
- **v1.25.0 — Simpler navigation:** the admin menu is consolidated into three
  tabbed hubs (Content / SEO / Strategy); no screen or feature removed.

Internal REST API at `/wp-json/seo-command/v1/*`; **398** passing unit tests.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — components, AI abstraction, security model, outbound-network safety, generation modes, admin hubs
- [`docs/ROADMAP.md`](docs/ROADMAP.md) — phased plan through v1.25
- [`docs/DATABASE.md`](docs/DATABASE.md) — options + custom tables
- [`docs/API.md`](docs/API.md) — REST routes
- [`docs/TEMPLATES.md`](docs/TEMPLATES.md) — template engine + tokens
- [`docs/RENDERERS.md`](docs/RENDERERS.md) — renderer abstraction

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
