# SEO Command Center

An AI-powered SEO Command Center for WordPress + Elementor. It analyzes a
WordPress site, helps you decide what it should rank for, plans the pages and
articles it needs, and (in later phases) generates them using your Elementor
templates — always as **drafts you approve**, never auto-published by default.

> Working name / prefix: **SEO Command Center** · `scc` · text domain
> `seo-command-center`. Built to grow into a commercial plugin, not a one-off
> script.

## Status

**All seven phases are implemented.** See [`docs/ROADMAP.md`](docs/ROADMAP.md)
for the detailed breakdown.

- **Phase 1 — Foundation:** bootstrap/loader/lifecycle, versioned custom tables,
  security helpers, secret-redacting logger, provider-independent AI layer
  (Claude + OpenAI, primary/fallback, budget guard), usage/cost tracking,
  WordPress content analyzer, robots-aware crawler, SEO-plugin detection, admin
  dashboard/analysis/settings/connections, internal REST API.
- **Phase 2 — Strategy:** AI topical-map builder, site architecture engine
  (Pillar → Service → Location → Supporting), content plan, cannibalization
  detection.
- **Phase 3 — Content Generation:** multi-step generator (brief → body →
  metadata → schema → draft → quality score), non-destructive metadata,
  validated schema, regenerate-section.
- **Phase 4 — Elementor:** detection, template mapping, `{{PLACEHOLDER}}`
  system, design-preserving page population.
- **Phase 5 — Internal Linking:** content graph, recommendations, natural
  insertion with anti-spam guards, dashboard.
- **Phase 6 — Data Integrations:** Google Search Console, DataForSEO, and
  robots-respecting competitor analysis (all optional).
- **Phase 7 — Scale & Operations:** background job queue (WP-Cron, resumable,
  retry, auto-pause on budget), batch generation, usage tiles, publishing queue
  + workflow (approve / publish / schedule).

Internal REST API at `/wp-json/seo-command/v1/*`; **87** passing unit tests.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — components, AI abstraction, security model
- [`docs/ROADMAP.md`](docs/ROADMAP.md) — phased plan (1–7)
- [`docs/DATABASE.md`](docs/DATABASE.md) — options + custom tables
- [`docs/API.md`](docs/API.md) — REST routes

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
