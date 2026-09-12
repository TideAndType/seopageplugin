# AI Elementor Layout Engine

Turns the plugin's generated SEO content into a polished, **editable** Elementor
page by choosing from a **controlled library of blocks** — never by having the AI
emit raw Elementor JSON.

```
SEO data → content → content structure → layout decision
        → block selection → content population → Elementor page
```

The AI is **optional**. With no AI provider the deterministic rules always
produce a valid layout. The AI, when enabled, only picks the **order of block
IDs**, which is validated against the registry before anything renders.

## Architecture

| Class | File | Responsibility |
|-------|------|----------------|
| `SCC_Block_Registry` | `includes/layout/class-scc-block-registry.php` | The allowlist of blocks + metadata. |
| `SCC_Layout_Analyzer` | `…/class-scc-layout-analyzer.php` | Content object / post → normalized structure. |
| `SCC_Layout_Rule_Provider` | `…/class-scc-layout-rule-provider.php` | Deterministic layout (always available). |
| `SCC_Layout_AI_Provider` | `…/class-scc-layout-ai-provider.php` | Optional AI ordering (via `SCC_AI_Manager`). |
| `SCC_Layout_Engine` | `…/class-scc-layout-engine.php` | Chooses provider, gates by available content, resolves duplication. |
| `SCC_Layout_Validator` | `…/class-scc-layout-validator.php` | Allowlist, dedupe, min/max, required, order. |
| `SCC_Content_Mapper` | `…/class-scc-content-mapper.php` | Content → per-block variables; slices FAQ/CTA out of the body. |
| `SCC_Block_Elementor_Renderer` | `…/class-scc-block-elementor-renderer.php` | Blocks → Elementor tree + crawlable HTML fallback. |
| `SCC_Design_Intel` | `…/class-scc-design-intel.php` | Reads the Elementor kit palette (cached). |
| `SCC_Layout_Service` | `…/class-scc-layout-service.php` | Façade used by REST + admin UI. |

## Registering a new block

Blocks are filterable — add one without touching core:

```php
add_filter( 'scc_layout_blocks', function ( $blocks ) {
    $blocks['pricing-table'] = array(
        'name'          => 'Pricing table',
        'description'   => 'Tiered pricing.',
        'purpose'       => 'commerce',
        'content_types' => array( 'service', 'landing' ),
        'intents'       => array( 'transactional', 'commercial' ),
        'min' => 0, 'max' => 1,
        'fields'        => array( 'SECTION_TITLE', 'TIERS' ),
        'repeatable'    => false,
        'supports_images' => false, 'supports_cta' => true, 'supports_links' => true,
        'render'        => 'html',   // reuses the generic HTML renderer
        'required'      => false,
    );
    return $blocks;
} );
```

To render it richly, add a `case` in `SCC_Content_Mapper::vars_for()` (produce its
variables) and in `SCC_Block_Elementor_Renderer::inner_html()` (produce markup).
Unknown render hints fall back to a heading + text.

## Mapping a block to an existing Elementor template

To reuse the site's real design, map a block ID to an Elementor template/section
post ID via the `scc_block_template_map` option:

```php
update_option( 'scc_block_template_map', array(
    'service-grid' => 952988, // an Elementor template/section post id
) );
```

When present (and Elementor active), that template's data is **cloned**, its
`{{TOKENS}}` are filled from the block's variables (`{{SERVICE_TITLE}}`, …) via
the existing `SCC_Placeholders`, element IDs are regenerated, and it is spliced
into the page instead of the generic structure.

## Layout rules

`SCC_Layout_Rule_Provider::decide()` returns a content‑centric layout per type
(the generated article renders once as the `content` block; FAQ/CTA/stats/steps
are lifted into their own blocks). Types: `service`, `local_service`, `location`,
`landing`, `comparison`, `article`/`blog_post`/`informational`.

`SCC_Layout_Engine` then:
1. **Gates** blocks whose content is absent (`has_content()`), keeping `required` blocks.
2. **Resolves conflicts** — when the full `content` block is present, drops
   prose-duplicating card blocks (service-grid, benefits, …) to protect SEO
   (single H1, no duplicate headings).
3. **Validates** with `SCC_Layout_Validator`.

## AI provider interface

Any `SCC_AI_Manager` provider works. The engine sends the content type, intent,
keyword, an availability summary and the **allowed block IDs**, and expects only:

```json
{ "layout": ["hero", "content", "faq", "cta"] }
```

Non-string entries and unknown IDs are stripped; an empty/invalid result falls
back to the rules. The AI can never introduce a block, HTML, or Elementor code.

## Content mapping

Each block declares `fields` (tokens). `SCC_Content_Mapper` populates them from
the analysis, e.g. `hero` → `HERO_TITLE`, `HERO_SUBTITLE`, `CTA_TEXT`, `CTA_URL`;
`faq` → `FAQ_ITEMS`; `service-grid` → `CARDS[{SERVICE_TITLE, SERVICE_DESCRIPTION,
SERVICE_URL}]`. The `content` block's body has the FAQ, any trailing CTA, and any
lifted `stats`/`process-steps` regions removed so nothing renders twice.

## Elementor rendering

- Text blocks (hero, content, intro, cta) → native Elementor **containers + core
  widgets** (`heading`, `text-editor`, `button`) so they inherit the site's Kit
  styles and stay fully editable.
- Visual blocks (grid, stats, steps, faq, related, …) → an Elementor **HTML
  widget** carrying the plugin's semantic `scc-*` markup (styled by the existing
  front-end CSS, accessible, crawlable).
- A **native HTML fallback** is written to `post_content`, so the page is
  readable and crawlable even if Elementor is removed.
- Pages use Elementor Free only (containers + core widgets). No Pro APIs.

## Security / validation

- Block IDs are allowlisted against the registry; unknown IDs are dropped.
- The AI returns only block IDs — never executed as code.
- URLs pass through `esc_url` / `esc_url_raw`; text through `esc_html`.
- Mapped Elementor template IDs are validated (`(int)` > 0, block must exist).
- A hard cap of `SCC_Layout_Validator::MAX_BLOCKS` (16) bounds page size.
- Images are only used when a real URL/featured image exists — never invented.

## REST

- `POST /seo-command/v1/layout/propose` `{ post_id, use_ai }` → `{ layout, blocks, source, content_type, search_intent, elementor_active, ai_available }`.
- `POST /seo-command/v1/layout/apply` `{ post_id, layout:[ids] }` → writes the Elementor page, returns edit/Elementor URLs.

## Extending

- New block → `scc_layout_blocks` filter (+ optional mapper/renderer cases).
- New provider → implement a `decide( array $analysis ): ?string[]` class and
  call it from `SCC_Layout_Engine` (or replace the AI provider).
- Reuse site design → `scc_block_template_map` option.
- Cache: the registry is per-request; `SCC_Design_Intel` caches the palette for
  12h (`SCC_Design_Intel::flush()` to clear).
