# SEO Command Center — Technical Architecture

> Working name: **SEO Command Center** (text domain / prefix: `seo-command-center` / `scc`)
> An AI-powered SEO Command Center for WordPress + Elementor.

This document describes the technical architecture for the plugin. It is a living
document; each phase updates the relevant sections.

---

## 1. Goals & Constraints

**Product goal.** Help a site owner / agency answer: *"What should this site rank
for, what pages should it have, what content should it create, and how should it
all be internally linked?"* — then generate the recommended pages/articles inside
WordPress, using existing Elementor templates where present, always as drafts by
default.

**Hard constraints (enforced in code, not just docs):**

- **Human stays in control.** Generated content is saved as `draft` unless the
  user explicitly enables auto-publishing. There is no code path that publishes
  AI content without that opt-in.
- **No destructive actions without approval.** The plugin never deletes,
  redirects, or overwrites existing pages or another SEO plugin's metadata
  without explicit user approval.
- **No fake data.** When an external data API (DataForSEO, GSC) is not connected,
  the plugin does not fabricate volume/ranking numbers. AI strategic
  recommendations are clearly labelled as AI opinion, not measured data.
- **WordPress-native.** Use WP APIs (WP_Query, `wp_insert_post`, post meta,
  Settings API, REST API, WP-Cron) rather than raw SQL wherever a safe API
  exists. Custom tables only where WP has no suitable home for the data.
- **Security first.** Capability checks, nonces, sanitization on input, escaping
  on output, prepared statements for the unavoidable custom-table queries, and
  API keys never exposed to front-end JS.
- **Provider-independent AI.** The rest of the plugin never references a concrete
  AI vendor; it talks to an interface.
- **Multisite-ready.** No hard-coded assumptions (table names go through
  `$wpdb->prefix`, options are site-scoped) that would block a later multisite
  pass.

---

## 2. High-Level Component Map

```
seo-command-center.php  ── bootstrap, constants, activation/deactivation hooks
│
└── SCC_Plugin (singleton)  ── wires everything together on `plugins_loaded`
    │
    ├── Core
    │   ├── SCC_Loader            action/filter registrar
    │   ├── SCC_Activator         create tables, default options, capabilities
    │   ├── SCC_Deactivator       clear scheduled cron (data preserved)
    │   └── SCC_DB                custom table names + schema + typed accessors
    │
    ├── Security
    │   └── SCC_Security          capability + nonce + sanitizer helpers
    │
    ├── Logging
    │   └── SCC_Logger            structured diagnostic log (redacts secrets)
    │
    ├── AI layer  (provider-independent)
    │   ├── SCC_AI_Provider_Interface
    │   ├── SCC_AI_Manager        primary/fallback routing, budget guard
    │   ├── SCC_Claude_Provider   Anthropic Messages API
    │   ├── SCC_OpenAI_Provider   OpenAI Chat Completions API
    │   └── SCC_AI_Usage          token + cost accounting (scc_api_usage table)
    │
    ├── Analysis engine
    │   ├── SCC_Analyzer          reads WP content via WP_Query/meta
    │   ├── SCC_Crawler           HTTP fetch + HTML parse (wp_remote_get)
    │   └── SCC_SEO_Meta          detect + read Yoast / Rank Math / AIOSEO
    │
    ├── Admin
    │   ├── SCC_Admin             menu registration, asset enqueue
    │   └── SCC_Settings          Settings API groups + sanitizers
    │
    └── REST
        └── SCC_REST              /seo-command/v1/* controllers
```

Phases 2–7 add sibling components (`SCC_Keyword_Strategy`, `SCC_Architecture`,
`SCC_Content_Plan`, `SCC_Generator`, `SCC_Elementor`, `SCC_Internal_Links`,
`SCC_GSC`, `SCC_DataForSEO`, `SCC_Jobs`) that plug into the same loader/REST/DB
scaffolding built in Phase 1.

---

## 3. AI Provider Abstraction

The single most important architectural decision: nothing outside `includes/ai/`
knows which vendor is answering.

```php
interface SCC_AI_Provider_Interface {
    public function get_id(): string;               // 'claude' | 'openai'
    public function get_label(): string;
    public function is_configured(): bool;          // has a key
    public function list_models(): array;           // id => label
    public function complete( array $request ): SCC_AI_Response;  // the one call
    public function estimate_cost( int $in, int $out, string $model ): float;
}
```

`$request` is a normalized array: `system`, `messages` (role/content), `model`,
`max_tokens`, `temperature`, `json` (bool — request structured JSON). Each
provider translates that to its own wire format. `SCC_AI_Response` carries
`content`, `input_tokens`, `output_tokens`, `model`, `cost`, `error`.

`SCC_AI_Manager::complete()` is the entry point the rest of the plugin uses:
1. Enforce the monthly budget guard (reject if over limit).
2. Try the configured **primary** provider.
3. On transport/rate error, try the **fallback** provider if configured.
4. Record usage via `SCC_AI_Usage` regardless of outcome.

Adding a provider later = implement the interface + register it; no other file
changes.

**Shipped providers:** `claude` (Anthropic Messages API), `openai` (Chat
Completions), `gemini` (Google Generative Language `generateContent`), and
`lmstudio` (a local, OpenAI-compatible server — e.g. LM Studio at
`http://localhost:1234/v1`; no per-token cost, key optional, base URL
configurable so WordPress can reach it on localhost/LAN/tunnel). Any of them can
be the primary or fallback provider.

---

## 4. Analysis Engine

Two complementary sources, so the plugin works even when the site is not
publicly reachable (staging, auth walls):

- **`SCC_Analyzer` (internal, authoritative).** Uses `WP_Query` to enumerate
  posts, pages, CPTs, taxonomies; reads content, headings, images, internal vs.
  external links, word count, and SEO meta (via `SCC_SEO_Meta`). This is the
  primary path and needs no network.
- **`SCC_Crawler` (external, best-effort).** Uses `wp_remote_get()` to fetch
  rendered URLs and parse with `DOMDocument` — used to see the site as Google
  would (rendered title, canonical, schema JSON-LD, breadcrumbs). Respects
  `robots.txt` for external competitor URLs; internal fetch is allowed. Never
  bypasses auth/paywalls.

Analysis results are summarized and stored in `scc_analyses` (one row per run)
plus per-URL rows so the dashboard reads pre-computed numbers instead of
re-crawling on each page load.

`SCC_SEO_Meta` detects the active SEO plugin once and maps its meta keys:

| Plugin      | Title key                 | Description key                  |
|-------------|---------------------------|----------------------------------|
| Yoast       | `_yoast_wpseo_title`      | `_yoast_wpseo_metadesc`          |
| Rank Math   | `rank_math_title`         | `rank_math_description`          |
| AIOSEO      | (aioseo_posts table)      | (aioseo_posts table)             |
| none        | `_scc_meta_title`         | `_scc_meta_description`          |

Reads are non-destructive. Writes (Phase 3) only touch the active plugin's keys
after explicit approval, and fall back to `_scc_*` keys when no SEO plugin is
active.

---

## 4a. CMS-agnostic template & rendering (v1.2)

The page-generation path is split into three decoupled layers so the plugin does
**not** depend on Elementor (or any specific builder):

```
SEO Strategy Engine  →  Content + Template Engine  →  Renderer  →  WordPress Page
   (what to create)       (structure + fields)         (how it's built)
```

- **SEO Strategy Engine** (existing strategy/generation classes) decides *what*
  page to create: type, primary keyword, intent, structure, links, metadata,
  schema. It references no builder.
- **Content + Template Engine**: `SCC_Content_Object` is the standardized,
  renderer-independent representation of a page (title, h1, intro, sections,
  benefits, process, local_content, faq, cta, metadata, schema, internal_links).
  `SCC_Template` / `SCC_Template_Store` define reusable structures; deterministic
  `SCC_Template_Selector` chooses one (never the AI). See `docs/TEMPLATES.md`.
- **Template Variables (Mapping 2.0)**: `SCC_Template_Variables` is the single
  authoritative registry of every `{{TOKEN}}` — label, category, data type and
  safety flags — extensible via the `scc_template_variables` filter. It resolves
  the content object to raw values once, escapes each by type (text/rich/html/
  url/list; schema is never visible text), validates a template's tokens, and is
  what `SCC_Content_Object::variables()` now delegates to. No new tables; no AI
  or DB call per token. See `docs/TEMPLATES.md`.
- **Renderer**: `SCC_Renderer_Interface` implementations (`gutenberg` default,
  `wordpress`, optional `elementor`) turn the content object + template into
  `post_content` (+ optional builder meta). `SCC_Renderer_Manager` picks one with
  automatic fallback when an optional builder is unavailable. See
  `docs/RENDERERS.md`.

Internal linking, metadata, and schema all operate on the **content object**
before/after render and are renderer-independent. Elementor is now one optional
renderer, not a dependency; the pre-existing `SCC_Template_Mapping` +
`SCC_Elementor_Builder` are reused by the Elementor renderer and remain intact.

## 5. Admin UI

WordPress-native admin (top-level menu + submenus) but styled to feel like a
modern SEO SaaS. One top-level page `seo-command-center` with submenus matching
the product nav (Dashboard, Site Analysis, Keyword Strategy, Site Architecture,
Content Plan, Generate Content, Elementor Templates, Internal Links, SEO Audit,
Schema, Publishing Queue, Settings, API Connections).

- Views live in `includes/admin/views/*.php` and only render markup; all data is
  prepared by the controller and passed in, all output escaped.
- Phase 1 ships the Dashboard, Site Analysis, Settings, and API Connections as
  functional pages. The remaining nav items render an honest "Available in Phase
  N" placeholder rather than fake widgets.
- JS talks to the internal REST API with a nonce; no API keys are ever printed
  into the page or JS.

---

## 6. REST API

Namespace `seo-command/v1`. Every route declares a
`permission_callback` requiring `manage_options` (filterable capability
`scc_required_capability`) and is called from admin JS with the `wp_rest` nonce.
See `docs/API.md` for the full route list.

---

## 7. Background Jobs (scaffolded Phase 1, used Phase 7)

Long analysis/generation runs must not block the request. A lightweight job model
(`scc_jobs` table + WP-Cron dispatcher) is defined in Phase 1's DB layer and
fully driven in Phase 7. Jobs are resumable (store `cursor` + `payload`), record
attempts, and log failures. WP-Cron is the default dispatcher; the design leaves
room for a real queue/Action Scheduler later.

---

## 8. Security Model

- **Access:** all admin pages and REST routes gate on `manage_options`
  (filterable). API-key settings additionally re-check capability on save.
- **CSRF:** Settings API nonces for form posts; `wp_rest` nonce for REST.
- **Input:** every request field passes through a typed sanitizer
  (`SCC_Security::sanitize_*`).
- **Output:** all dynamic output escaped at the point of echo.
- **SQL:** custom-table access goes through `SCC_DB` which always uses
  `$wpdb->prepare()`.
- **Secrets:** API keys stored in an autoload-`no` option; masked in the UI
  (only last 4 chars shown); never enqueued to JS; redacted by `SCC_Logger`.
- **Remote requests:** `wp_remote_*` with timeouts, size caps, and
  SSL verification on.

---

## 9. Coding Standards

WordPress Coding Standards (WPCS). All classes prefixed `SCC_`, functions/hooks
`scc_`, constants `SCC_`. Text domain `seo-command-center`. Files use the
one-class-per-file convention with `class-scc-*.php` naming.

## Topical Authority Engine (read model)

`SCC_Topical_Authority` (includes/strategy/) turns existing data into an
explainable topical-authority scorecard. It is a pure read model — it does not
store anything, call the AI layer, or add tables. It reuses:

- `SCC_Keyword_Strategy::latest()` — the topical map (pillars/subtopics with
  existing-vs-gap status, priorities, GSC quick-wins).
- `SCC_Analyzer::latest()` — content depth (thin-content share).
- `SCC_Link_Graph` / `SCC_Link_Engine` — internal-link health + opportunities.
- `SCC_Cannibalization` — overlap count.

`compute($map, $signals, $quick)` is the pure, unit-tested scoring function;
`scorecard()` gathers the live signals and calls it. Weights are filterable via
`scc_topical_authority_weights`. Surfaced at Admin → Topical Authority and via
`GET /topical-authority`.
