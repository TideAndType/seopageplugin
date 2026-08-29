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
| 5 | GET | `/internal-links` | Link recommendations / orphan report. |
| 5 | POST | `/internal-links/apply` | Insert approved links. |
| 6 | POST | `/gsc/connect`, GET `/gsc/data` | Search Console. |
| 6 | POST | `/dataforseo/keywords` | DataForSEO lookups. |
| 6 | POST | `/competitors/analyze` | Public competitor analysis. |
| 7 | POST | `/jobs`, GET `/jobs/{id}` | Batch job control. |

## Error handling

- `401`/`403` — missing capability or nonce.
- `400` — validation failure (message names the field).
- `402` — monthly AI budget exceeded (from `SCC_AI_Manager`).
- `502` — upstream AI/API transport error (after fallback attempted).
- All errors are logged (redacted) via `SCC_Logger`; responses never leak keys
  or stack traces.
