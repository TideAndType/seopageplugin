# Tests

## Unit tests (no WordPress required)

```bash
php tests/run-tests.php
```

`run-tests.php` covers the deterministic, dependency-light logic with WP
functions stubbed in `bootstrap.php`:

- `SCC_Security` sanitizers + secret masking
- `SCC_AI_Response` JSON extraction (Markdown-fence stripping, error state)
- `SCC_Crawler` HTML parsing (title, meta, canonical, headings, images/alt,
  internal vs external links) and JSON-LD `@type` / `@graph` extraction
- `SCC_SEO_Meta` plugin labels
- `SCC_Logger` secret redaction (top-level, nested, free-text Bearer/`sk-` tokens)

These are pure-logic tests and are **not** a substitute for WordPress
integration tests.

## Integration tests (require a WordPress test install)

The following need a real WordPress (and, where noted, Elementor / an SEO
plugin). They are documented here and scaffolded for the WP PHPUnit harness;
running them requires `wp-env` or a `WP_TESTS_DIR` setup.

| Area | What to verify |
|------|----------------|
| Activation | Tables created (`dbDelta`), default `scc_settings` written, `scc_db_version` set. |
| Deactivation | Scheduled cron cleared; **no** data dropped. |
| Uninstall | Data dropped only when `remove_data_on_uninstall` is enabled. |
| DB upgrade | `maybe_upgrade_db` re-runs installer when version differs. |
| REST auth | Every route returns 401/403 without `manage_options`. |
| REST nonce | Requests without the `wp_rest` nonce are rejected. |
| Analyzer | `run()` writes one `scc_analyses` row + per-post `scc_analysis_items`; totals correct on a fixture site. |
| SEO meta | Correct meta keys read for Yoast / Rank Math; AIOSEO table read guarded when absent. |
| AI providers | `complete()` maps request → wire format; usage row recorded; fallback path taken on primary error (mock HTTP). |
| Budget guard | `complete()` returns 402 error when month-to-date cost ≥ budget. |
| Secrets | `/settings` GET never returns raw keys; JS bundle never contains keys. |
| Elementor | `analyze_post` extracts text from `_elementor_data`; `is_elementor` flag set. |

## Manual testing checklist

1. Install the plugin on a test WordPress + Elementor site.
2. Activate → confirm no fatals, menu appears, tables exist.
3. Add a Claude or OpenAI key under **API Connections** → **Test** returns OK.
4. Run **Analyze my site** → dashboard stats + Site Analysis table populate.
5. Save **Settings** → reload shows persisted values.
6. Deactivate/reactivate → data preserved.
