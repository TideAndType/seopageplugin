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

### Unified intelligence layer (Opportunity Engine + Action Queue)

`SCC_Opportunity_Engine` is an **orchestration/read-model** layer — it does not
re-implement any analysis. It gathers signals from the existing systems (GSC
`gsc_signals`, the Topical Authority scorecard, cannibalization, the link graph,
and the latest site analysis) and turns them into a single ranked list of
**explainable** opportunities. Each opportunity carries a transparent score (a
sum of labelled factor points, never an opaque average), a confidence, and an
explicit data-availability state (`verified` / `partial` / `estimated` /
`unavailable`). Missing external data (e.g. GSC not connected) simply omits those
factors and downgrades confidence — it is never fabricated. Results are cached in
a transient so the dashboard stays fast; `POST /opportunities/refresh` recomputes.

`SCC_Content_Decay` is one of the engine's signal sources: it compares page-level
Search Console performance across two consecutive windows (recent 90 days vs the
prior 90 days via `SCC_GSC::compare_pages`) and flags genuinely declining pages.
It is confidence-thresholded — a page needs a meaningful baseline (prior clicks
or impressions) and a significant drop (≈30%+ clicks/impressions, or average
position worsened by 3+) before it counts as decay, so normal fluctuation is
never mislabelled. Each finding carries causes (clicks down, impressions down,
rankings declining, stale), a severity, a confidence, and a concrete refresh
plan; these become `content_decay` opportunities (verified data) in the engine.
When GSC is not connected it returns `{available:false}` — never fabricated.

`SCC_Intent_Drift` is a second GSC-only signal source: rather than requiring
historical SERP snapshots, it reads the REAL queries each page earns impressions
for, classifies each query's intent from its wording (informational / commercial
/ local, via transparent lexicons), weights by impressions, and compares the
intent mix of the recent window against the prior one. When the dominant intent
flips with a real baseline (≥200 impressions per window) and a meaningful share
shift, it emits an `intent_drift` opportunity with a from→to recommendation. The
query data is verified GSC data but the per-query labelling is heuristic, so
these opportunities are marked **partial** confidence — never "verified". No SERP
scraping, no DataForSEO dependency.

`SCC_Page_Optimizer` is the per-page face of the same intelligence: for one post
it composes a component scorecard (Content, Technical, Metadata, Internal linking,
Schema, Intent, GSC opportunity) from the existing per-page systems (SEO report,
latest analysis item, link graph, content decay, intent drift, and a cached GSC
page-metrics map) plus a prioritized fix list. Unknown components are excluded and
the weights renormalized (never guessed). It surfaces in the post editor's SEO
meta box via `GET /page/{id}/optimize` (capability-checked). It reuses existing
analysis — it does not re-crawl.

`SCC_Action_Queue` (backed by the `scc_seo_actions` table) persists the
opportunities the user chooses to act on, with a full lifecycle and logging.
Execution is deliberately conservative: only genuinely SAFE, deterministic,
reversible actions (currently internal-link insertion via the existing
`SCC_Link_Engine`/`SCC_Link_Inserter`) can be run automatically — everything else
stays `approved` and routes the user to the existing workflow. "Fix Everything
Safe" runs only the safe subset. The layer orchestrates existing execution
systems; it never becomes a second SEO system beside them.

Internal-link recommendations are deterministic by default (content-index
relevance + anchor engine). An optional **`link_ai_enabled`** setting (the
"AI-assisted" toggle on the Internal Links page) adds a single AI pass over a
page's outbound candidates: the model reads the page and picks the most natural
anchor and best targets **from the verified candidate list only**.
`SCC_Link_Engine::merge_ai_links()` guarantees the AI can never introduce a page
or URL of its own, and accepts an AI anchor only if it appears verbatim in the
page; if the AI call fails, the deterministic recommendations stand. The
inserter (`SCC_Link_Inserter`) is Elementor-aware — it writes the link into the
`_elementor_data` widget that holds the anchor text, not unused `post_content`.

## 5. Admin UI

WordPress-native admin (top-level menu + submenus) but styled to feel like a
modern SEO SaaS. One top-level page `seo-command-center` with submenus matching
the product nav (Dashboard, Action Queue, Topical Authority, Keyword Strategy,
Site Architecture, Content Plan, Competitor Gaps, SEO Audit, Internal Links,
Publishing Queue, Templates, Settings, API Connections).

The **Action Queue** screen is the operational hub of the intelligence layer: it
lists every computed opportunity (ranked + explained) with "Add to queue", and
the persistent action queue with its full lifecycle (approve / snooze / dismiss /
run). "Fix Everything Safe" runs only the deterministic, reversible subset
(internal links) — it never edits content, publishes, deletes, or redirects. The
Dashboard's "What should I do next?" card is a top-5 preview that links here.

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

## 6. Intelligence layer — completion (Phase 8)

The unified intelligence layer now spans the full closed loop, all orchestrating
existing systems (no parallel SEO system, no fabricated data):

- **Opportunity Engine** + **Action Queue** — scored, explainable opportunities
  and a persistent action lifecycle with safe deterministic execution.
- **Signals**: GSC striking-distance & untapped demand, topical-authority gaps,
  cannibalization, orphans, thin content, missing metadata, **Content Decay**,
  **Intent Drift** (both GSC-only).
- **Page Optimizer** — per-page component scorecard + prioritized fixes in the
  editor.
- **Health Timeline** (`scc_seo_snapshots`) — daily transparent health score +
  GSC totals, so progress is visible over time.
- **Experiments** (`scc_seo_experiments`) — before/after measurement of a change
  against its GSC baseline, in correlation language only.
- **Entity Authority Graph** — organization/service/location entities and their
  supporting-page gaps, derived from business + topical-map data.
- **Revenue-aware prioritization** — configurable per-intent business-value
  points so priority follows value, not volume.
- **Automation modes** — conservative / assisted / autopilot, gating what may run
  unattended (safe, reversible actions only; audited; capped).
- **AI/GEO Visibility** — provider-agnostic scaffold that reports "not connected"
  honestly (no fabricated AI-answer metrics) and surfaces real citation-readiness
  factors.

Surfaced in admin via the **Action Queue** and **Insights** screens, the
Dashboard "What should I do next?" card, the editor's "Optimize this page", and
Settings (automation mode + business value).

## Outbound network safety (`includes/net/`)

A small, dependency-free layer centralizes every outbound-URL decision so the
crawler, the AI providers, and the integrations behave consistently.

- **`SCC_URL`**
  - `is_safe_outbound_url()` — the SSRF guard. It is called **immediately before**
    an outbound request (not only when a setting is saved). Loopback
    (`127.0.0.0/8`, `::1`, and the literal host `localhost`) is allowed so local
    model servers such as **LM Studio** keep working; RFC1918 private ranges,
    link-local (incl. the cloud metadata endpoint `169.254.169.254`), multicast,
    reserved, unspecified, and IPv6 ULA/link-local targets are refused, as are
    URLs with embedded credentials or a non-HTTP(S) scheme. Hostnames are resolved
    and the resolved IPs are judged too (a basic DNS-rebinding mitigation).
    Overridable via the `scc_allow_outbound_url` filter.
  - `resolve()` — RFC 3986 relative-reference resolution for the crawler.
  - `normalize_for_crawl()` / `strip_tracking_params()` — a stable crawl identity
    (lowercased scheme/host, no default port, no fragment, tracking-only query
    params such as `utm_*`/`fbclid`/`gclid` dropped) so one page is not crawled
    many times.
- **`SCC_Robots`** — a proper robots.txt matcher: multiple user-agent groups,
  `Allow`/`Disallow` with longest-match precedence (Allow wins ties), `*`
  wildcards and `$` end-anchors, comments and blank lines. The crawler evaluates
  it under its product token `SEO-Command-Center`.

The guard is applied where the target is user-configurable or user-supplied (the
LM Studio provider and the crawler). Fixed vendor endpoints (Anthropic, OpenAI,
Google) are not attacker-controllable, so they are intentionally not subjected to
a per-request DNS lookup.

REST routes additionally enforce **object-level authorization**: post-scoped
mutations (metadata, schema, publishing, link analysis, page optimize) verify
`current_user_can( 'edit_post', $id )` on the specific object, so relaxing the
plugin capability via `scc_required_capability` never grants blanket per-object
access.

## Generation modes: Normal vs Template

SEO Command Center is "normal WordPress, with an SEO intelligence layer." The
generator (`SCC_Generator`) has two explicit paths, chosen deterministically by
content type — the AI never decides:

- **NORMAL (native)** — the default. Content types in `SCC_Generator::NATIVE_TYPES`
  (`article`/`blog`/`blog_post`/`post`) become a plain WordPress **post**: the
  sanitized AI body (H2 sections, lists, an FAQ, a closing CTA — and **no in-body
  `<h1>`**, because the theme renders the post title as the page H1) is saved as
  `post_content` verbatim. No template, no tokens, no page builder — never pulled
  into Elementor even if a builder is the site's default renderer. Native excerpt,
  tags (from the keywords) and an existing-only category are applied through core
  taxonomies (`resolve_existing_category()` never creates duplicate categories).
- **TEMPLATE (advanced)** — structured pages (`service`/`location`/`landing`/
  `custom`, …) go through the existing template + renderer layer
  (`SCC_Template_Selector` → Elementor / Gutenberg / native renderer). Tokens
  (`{{TITLE}}`, `{{SERVICE}}`, `{{CITY}}`, …) apply here only. Choosing a template
  family for any type (even an article) opts it into TEMPLATE mode — the escape
  hatch — so nothing existing breaks.

`is_native_mode( $content_type, $manual_family )` decides; `post_type_for()` maps
native types to `post` and structured types to `page`. Tokens and the whole
template system remain fully supported for TEMPLATE mode and existing mappings —
they are simply no longer required to generate an ordinary blog post.

The Generate screen mirrors this: a **Content type** selector (Blog Post default)
with a topic and a few optional fields up front, and everything else behind
**Advanced**. The admin menu is grouped into Content / SEO / Strategy /
Automation / Settings sections (no screens removed).
