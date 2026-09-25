# TideOrbit

An AI-powered SEO operating system for WordPress + Elementor. It analyzes a
WordPress site, helps you decide what it should rank for, plans the pages and
articles it needs, and (in later phases) generates them using your Elementor
templates — always as **drafts you approve**, never auto-published by default.

> Product name: **TideOrbit**. Internal `SCC_` / `scc_` prefixes and the
> `seo-command-center` text domain are intentionally retained for backward
> compatibility with existing installs.

## Status

**Current version: 1.52.0.** The plugin is organized around four areas —
**Dashboard, Create, Optimize, Opportunities** — with an **SEO Copilot** on the
Dashboard that answers plain-language questions using your real data. All seven
foundational phases plus the intelligence engine, CMS-agnostic template system,
and the hardening + simplification passes are implemented. See
[`docs/ROADMAP.md`](docs/ROADMAP.md) for the detailed breakdown.

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
- **v1.52.0 — One-click Search Console connection:** the normal Connections screen now uses a single **Connect Google Search Console** action with read-only access and automatic property discovery. A stateless TideOrbit authorization broker keeps the Google OAuth client secret off customer WordPress sites and gives the plugin only an opaque sealed connection token; short-lived Google access tokens are fetched on demand. The broker uses a one-time verifier/challenge exchange, supports revocation, and stores no database state. Existing self-hosted Google OAuth Client ID/secret/refresh-token setup remains under **Advanced** for users who want to own the OAuth app themselves.
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
