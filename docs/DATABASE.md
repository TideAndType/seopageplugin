# SEO Command Center — Database Plan

## Principles

- Prefer WordPress storage. Content lives in `wp_posts`; SEO metadata lives in
  post meta (or the active SEO plugin's storage). Plugin configuration lives in
  the options table via the Settings API.
- Use **custom tables only** for plugin-specific structured/relational data that
  has no natural WordPress home (analysis snapshots, strategies, plans, job
  queue, usage ledger, link recommendations, template mappings).
- All custom tables are prefixed `{$wpdb->prefix}scc_` so multisite gets
  per-site tables automatically.
- Tables are created on activation with `dbDelta()` and versioned via the
  `scc_db_version` option so upgrades are safe. `charset_collate` from
  `$wpdb->get_charset_collate()`.
- Every read/write goes through `SCC_DB`, which always uses `$wpdb->prepare()`.

## Options (Settings API)

| Option key               | Autoload | Contents |
|--------------------------|----------|----------|
| `scc_settings`           | yes      | General, SEO, Elementor, publishing, limits (non-secret) |
| `scc_credentials`        | **no**   | API keys (Claude, OpenAI, DataForSEO, GSC). Never enqueued to JS. |
| `scc_db_version`         | yes      | Schema version string for upgrade routine |

## Custom Tables

Phase 1 creates the full set so later phases have their storage ready; Phase 1
actively writes to `scc_analyses`, `scc_analysis_items`, `scc_api_usage`, and
`scc_logs`.

### `scc_analyses` — one row per analysis run
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| created_at | DATETIME | |
| status | VARCHAR(20) | queued/running/complete/failed |
| type | VARCHAR(40) | site/content/seo-audit |
| summary | LONGTEXT | JSON: totals, counts, flags |
| totals | LONGTEXT | JSON: pages, posts, links, missing meta … |

### `scc_analysis_items` — one row per analyzed URL/post
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | |
| analysis_id | BIGINT UNSIGNED | FK → scc_analyses.id (index) |
| post_id | BIGINT UNSIGNED | 0 if external/crawled only (index) |
| url | TEXT | |
| post_type | VARCHAR(40) | |
| title | TEXT | |
| h1 | TEXT | |
| meta_title | TEXT | |
| meta_description | TEXT | |
| word_count | INT | |
| internal_links | INT | |
| external_links | INT | |
| images | INT | |
| images_missing_alt | INT | |
| has_schema | TINYINT(1) | |
| is_elementor | TINYINT(1) | |
| flags | LONGTEXT | JSON: thin, missing_meta, no_h1, orphan … |

### `scc_keyword_strategies` — Phase 2
| id, created_at, name, inputs(JSON), topical_map(JSON), status |

### `scc_content_plan` — Phase 2/3
| id, created_at, title, url, primary_keyword, secondary(JSON), intent,
  page_type, word_count, parent, links_to(JSON), links_from(JSON), cta,
  schema_type, priority, status, post_id |

### `scc_internal_links` — Phase 5
| id, source_post_id, target_post_id, anchor, context, status, created_at |

### `scc_template_mappings` — Phase 4
| id, template_id, template_name, content_type, placeholders(JSON), active |

### `scc_jobs` — Phase 7 (schema defined in Phase 1)
| id, type, status, payload(JSON), cursor, attempts, max_attempts,
  scheduled_at, started_at, finished_at, last_error, created_at |

### `scc_content_index` — searchable content index (v1.1)
| post_id (PK), url, post_type, title, primary_keyword, intent, tokens(JSON
term-freq), headings(JSON), anchors(JSON), outbound(JSON post ids), updated_at |
Built incrementally on `save_post`; powers relevance/link analysis without a
per-request crawl.

### `scc_change_history` — revert log (v1.1)
| id, post_id, change_type(internal_link/meta_title/meta_description/schema),
previous_value, new_value, reason, confidence, trigger_source, reverted,
created_at | Every automatic change is reversible.

### `scc_meta_history` — metadata experiments (v1.1)
| id, post_id, field, previous_value, new_value, variants(JSON), reason,
perf_before(JSON), perf_after(JSON), created_at | Backs the 30-day cooldown and
before/after comparison.

> `scc_internal_links` gained `direction`, `confidence`, `reason`, `sentence`
> columns in v1.1 (added via `dbDelta` on upgrade to DB version 1.1.0).

### `scc_api_usage` — usage/cost ledger (written from Phase 1)
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | |
| created_at | DATETIME | index |
| provider | VARCHAR(40) | claude/openai/dataforseo/gsc |
| model | VARCHAR(80) | |
| operation | VARCHAR(80) | e.g. analyze, generate, test |
| input_tokens | INT | |
| output_tokens | INT | |
| cost | DECIMAL(10,5) | estimated USD |
| status | VARCHAR(20) | ok/error |

### `scc_logs` — diagnostic log (written from Phase 1)
| id, created_at, level, source, message, context(JSON) |
Secrets are redacted before insert. Log is capped (oldest rows pruned).

## Lifecycle

- **Activation** (`SCC_Activator`): create/upgrade tables via `dbDelta`, set
  default `scc_settings`, register the capability, store `scc_db_version`.
- **Deactivation** (`SCC_Deactivator`): clear scheduled cron events only. No data
  is dropped (deactivation ≠ uninstall).
- **Uninstall** (`uninstall.php`): only drops tables / deletes options if the
  user enabled "remove data on uninstall" in settings. Default keeps data.

### `scc_seo_actions` — Phase 8 (Intelligence layer)

Persists the **unified SEO action queue**. Opportunities from
`SCC_Opportunity_Engine` are transient (computed + cached); when the user acts on
one it is promoted here as a durable action with a lifecycle.

| Column | Notes |
|--------|-------|
| `id` | PK |
| `opportunity_id` | Stable id from the engine (dedupes promotes) |
| `type` | Action type (e.g. `add_internal_links`, `create_page`, `improve_meta`) |
| `title`, `target` (JSON), `reason` | What + where + why |
| `score`, `confidence`, `priority` | From the engine |
| `expected_impact`, `effort`, `risk` | Decision context |
| `status` | new / reviewing / approved / in_progress / completed / dismissed / snoozed / failed |
| `source` | Originating system (gsc, topical_authority, cannibalization, link_graph, analyzer) |
| `payload` (JSON) | factors + metrics + recommended_action |
| `result` (JSON) | Execution outcome |
| `snoozed_until` | When a snoozed action returns |
| `created_at`, `updated_at` | Timestamps |

Created via `dbDelta` on the `SCC_DB_VERSION` 1.13.0 upgrade; no existing data is
touched. Removed only on opt-in uninstall.
