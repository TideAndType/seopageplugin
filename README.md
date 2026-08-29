# SEO Command Center

An AI-powered SEO Command Center for WordPress + Elementor. It analyzes a
WordPress site, helps you decide what it should rank for, plans the pages and
articles it needs, and (in later phases) generates them using your Elementor
templates — always as **drafts you approve**, never auto-published by default.

> Working name / prefix: **SEO Command Center** · `scc` · text domain
> `seo-command-center`. Built to grow into a commercial plugin, not a one-off
> script.

## Status

**Phase 1 (Foundation) is implemented.** See [`docs/ROADMAP.md`](docs/ROADMAP.md)
for the full plan.

Phase 1 delivers:

- Plugin foundation (bootstrap, singleton, loader, activation/deactivation/uninstall lifecycle)
- Custom database tables + versioned upgrades
- Security helpers (capabilities, nonces, typed sanitizers, secret masking)
- Secret-redacting diagnostic logger
- **Provider-independent AI layer** — interface + manager with primary/fallback
  routing and a monthly budget guard; **Claude** and **OpenAI** providers
- AI usage / estimated-cost tracking
- WordPress content analyzer (posts, pages, CPTs, headings, links, images/alt,
  word count, metadata, schema, Elementor detection, thin-content &
  cannibalization heuristics)
- HTTP crawler (rendered fetch + parse, robots-aware for external URLs)
- SEO-plugin detection (Yoast / Rank Math / AIOSEO), read-only
- Admin dashboard, Site Analysis, Settings, and API Connections pages
- Internal REST API (`/wp-json/seo-command/v1/*`)

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
