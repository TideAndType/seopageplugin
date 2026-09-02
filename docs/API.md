# SEO Command Center — Internal REST API

Namespace: **`seo-command/v1`** (base: `/wp-json/seo-command/v1/`).

## Conventions

- **Auth:** every route sets `permission_callback` to a check requiring the
  `manage_options` capability (filterable via `scc_required_capability`).
- **CSRF:** admin JS sends the `X-WP-Nonce` header (`wp_create_nonce('wp_rest')`).
  Cookie auth + nonce is the supported client; there is no public/anonymous
  access to any route.
- **Input:** arguments are declared with `args` + `sanitize_callback` +
  `validate_callback`. Bodies are JSON.
- **Output:** `WP_REST_Response` with a consistent envelope
  `{ ok: bool, data: {...} }` or `WP_Error` with an HTTP status.
- **Secrets:** no route ever returns a stored API key. Key fields are
  write-only; reads return a masked hint (`••••1234`) and a boolean `configured`.

## Phase 1 routes

| Method | Route | Purpose |
|--------|-------|---------|
| GET  | `/status` | Plugin/version, detected SEO plugin, Elementor present, providers configured. |
| GET  | `/settings` | Non-secret settings + masked credential hints. |
| POST | `/settings` | Update settings and/or credentials (validated & sanitized). |
| POST | `/ai/test` | Send a tiny prompt to a provider to verify the key. Body: `{provider}`. Returns model + latency; records usage. |
| POST | `/analyze` | Run a WordPress content analysis. Body: `{post_types?, limit?, crawl?}`. Returns an analysis id + summary. |
| GET  | `/analysis/latest` | Latest analysis summary + items for the dashboard. |
| GET  | `/analysis/{id}` | A specific analysis run. |
| GET  | `/usage` | AI usage + estimated cost for the current month. |
| GET  | `/logs` | Recent diagnostic log rows (secrets already redacted). |

## Planned routes (later phases)

| Phase | Method | Route | Purpose |
|-------|--------|-------|---------|
| 2 | POST | `/keywords` | Build a keyword/topical strategy from business inputs. |
| 2 | GET/POST | `/architecture` | Read/generate recommended site architecture. |
| 2 | GET/POST | `/content-plan` | Read/create content plan entries. |
| 2 | GET | `/cannibalization` | Keyword-overlap findings. |
| 3 | POST | `/brief` | Generate a content brief for approval. |
| 3 | POST | `/generate` | Run the multi-step generator → draft. |
| 3 | POST | `/regenerate-section` | Regenerate one section of a draft. |
| 4 | GET | `/templates` | List Elementor templates + mappings. |
| 4 | POST | `/templates/map` | Map a template to a content type. |
| 4 | GET | `/templates/variables` | Authoritative template-variable registry (Template Mapping 2.0): token, label, description, category, type, flags. |
| 4 | GET | `/templates/{id}/variables` | Tokens detected in an Elementor template + validation status. |
| 4 | POST | `/templates/validate` | Validate a template's tokens. Body: `{template_id}` or `{elements:[...]}`, optional `{required:[...]}`. |
| 4 | POST | `/templates/preview-vars` | Resolve variables to a sample `TOKEN => value` map (optional `{entry_id}` for a real content-plan entry). |
| 5 | GET | `/internal-links` | Link recommendations / orphan report. |
| 5 | POST | `/internal-links/apply` | Insert approved links. |
| 6 | POST | `/gsc/connect`, GET `/gsc/data` | Search Console. |
| 6 | POST | `/dataforseo/keywords` | DataForSEO lookups. |
| 6 | POST | `/competitors/analyze` | Public competitor analysis (single URL, token gaps). |
| 6 | POST | `/competitors/gap-map` | AI competitive gap map: crawl up to 5 competitor URLs, compare to real site pages, return a content map of missing pages. Body: `{urls:[...]}`. |
| 7 | POST | `/jobs`, GET `/jobs/{id}` | Batch job control. |
| 8 | GET | `/opportunities` | Ranked, explained SEO opportunities (Opportunity Engine). Each has a transparent factor breakdown, score, confidence, and data-availability state. |
| 8 | POST | `/opportunities/refresh` | Recompute opportunities from current data (also refreshes content-decay). |
| 8 | GET | `/content-decay` | Declining pages from a GSC period comparison (recent 90d vs prior 90d), with causes, severity, confidence and a refresh plan. `?refresh=1` recomputes. Returns `{available:false}` when GSC is not connected. |
| 8 | GET | `/intent-drift` | Pages whose search-intent mix is shifting, inferred from the GSC query mix (recent 90d vs prior 90d). Real query/impression data with a transparent wording classifier (marked partial confidence). `?refresh=1` recomputes. `{available:false}` without GSC. |
| 8 | GET | `/page/{id}/optimize` | Per-page SEO scorecard: component scores (Content, Technical, Metadata, Internal linking, Schema, Intent, GSC opportunity — unknown components excluded, not guessed) + prioritized fixes. Capability-checked (`edit_post`). |
| 8 | GET / POST | `/health-timeline` / `/health-timeline/snapshot` | SEO health snapshots over time / capture one now. Health is a transparent blend of measured coverage; GSC clicks/impressions only when connected. |
| 8 | GET / POST | `/experiments` | List / start an SEO experiment (captures a GSC baseline for a page). |
| 8 | POST / DELETE | `/experiments/{id}` | Evaluate (correlation, never causation) / delete an experiment. |
| 8 | GET | `/entities` | Entity authority graph (organization, services, locations) + supported/gap coverage, derived from business + topical-map data. |
| 8 | GET | `/ai-visibility` | Provider-agnostic AI-answer visibility status (honest "not connected" by default) + on-page citation-readiness factors. |
| 8 | GET / POST | `/actions` | List the action queue / promote an opportunity into it (`{opportunity_id}` or `{opportunity}` + `status`). |
| 8 | PUT | `/actions/{id}` | Change status (`approved`/`dismissed`/`snoozed` (+`days`)/…). |
| 8 | POST | `/actions/{id}/execute` | Execute a SAFE deterministic action (refuses non-safe types). |
| 8 | POST | `/actions/fix-safe` | Execute every safe, approved/new action ("Fix Everything Safe"). |
| 7 | POST | `/publishing/{approve\|unapprove\|publish\|schedule\|remove}` | Queue actions. `remove` trashes a generated draft (reversible) and detaches its content-plan entry. |

## v1.1 routes — advanced linking / meta / schema

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `/index/reindex`, GET `/index/status` | Build/inspect the content index. |
| POST | `/links/analyze` | New→existing + existing→new opportunities for a post. When the `link_ai_enabled` setting is on, an AI pass reads the page and picks the most natural anchor + best targets from the verified candidate list (never inventing a URL). |
| GET  | `/links/recommendations` | Stored recommendations (filter by direction/confidence). |
| POST | `/links/apply` | Insert one recommendation (records revert history). |
| POST | `/links/apply-high` | Insert all high-confidence recommendations. |
| POST | `/links/scan` | Site-wide reoptimization scan. |
| GET  | `/metadata` | List pages + current meta title/description for the bulk Meta Editor (`?search`, `?post_type`, `?paged`). |
| POST | `/metadata/save` | Manually set a page's meta title/description (writes the active SEO plugin's keys; records revert history; `edit_post`). |
| POST | `/meta/variants` (Meta Editor) | Per-row "Suggest with AI" — classified, GSC-aware title/description variants optimized for SEO + click-through; clicking one fills the row. |
| POST | `/meta/variants` | Generate classified metadata variants (GSC-aware). |
| POST | `/meta/apply` | Apply a variant (cooldown-guarded; `force` to override). |
| GET  | `/meta/opportunities` | GSC pages at position 4–20 with low CTR. |
| GET  | `/meta/history` | Metadata change history for a post. |
| POST | `/schema/recommend` | Recommended + not-recommended types + conflicts. |
| POST | `/schema/generate` | Generate validated JSON-LD nodes + warnings. |
| POST | `/schema/save`, `/schema/disable` | Persist/remove a post's schema. |
| GET/POST | `/schema/settings` | Organization/business info (never invented). |
| GET  | `/history`, POST `/history/revert` | Change history + revert (links/meta/schema). |
| GET  | `/seo-report` | Unified per-page readiness (internal score). |

## Error handling

- `401`/`403` — missing capability or nonce.
- `400` — validation failure (message names the field).
- `402` — monthly AI budget exceeded (from `SCC_AI_Manager`).
- `502` — upstream AI/API transport error (after fallback attempted).
- All errors are logged (redacted) via `SCC_Logger`; responses never leak keys
  or stack traces.

## Topical Authority

`GET /seo-command/v1/topical-authority` — returns the explainable coverage
scorecard computed from the latest topical map plus the analyzer, internal-link
graph and cannibalization detector. No parameters. Response `data`:

- `has_map` (bool) — false when no topical map exists yet.
- `score` (0-100) — weighted, explainable overall.
- `components[]` — `{key,label,pct,weight,known}`. Unknown components (e.g. no
  site analysis yet) are excluded from the weighting rather than guessed.
- `clusters[]` — `{name,score,status(strong|attention|missing),existing_subs,new_subs,priority,url}`.
- `opportunities` — `{high,medium,low,items[]}` (new/gap topics to create).
- `totals` — topics, keywords, covered/missing keywords, existing/missing
  topics, cluster status counts, cannibalization, link opportunities.

Read-only, capability-gated like every other route. Deterministic — no AI call.
