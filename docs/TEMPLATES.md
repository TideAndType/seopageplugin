# SEO Command Center — Native Template Engine

The template engine is **independent of any page builder**. A template defines
*structure and fields* — not how they are painted. A renderer decides that.

## Model

A template (`scc_templates` row) has:

| Field | Meaning |
|-------|---------|
| `id` | Row id (a specific version). |
| `family` | Stable slug grouping all versions of one template. |
| `name`, `description` | Human labels. |
| `content_type` | Which content type it serves (article, service, location, location_service, landing, case_study, about, faq, custom). |
| `template_type` | Same vocabulary; the template's own kind. |
| `structure` | JSON: ordered `sections`, each with ordered `fields`. |
| `renderer` | Optional pinned renderer id, or empty to use the default. |
| `elementor_source_id` | Source Elementor page id (0 = native). |
| `status` | `active` / `draft` / `archived`. |
| `version` | Integer, incremented on update. |
| `created_at`, `modified_at` | Timestamps. |

### Structure JSON

```json
{
  "sections": [
    { "key": "hero", "label": "Hero",
      "fields": [
        { "key": "h1",    "label": "H1",           "type": "text",     "required": true },
        { "key": "intro", "label": "Introduction", "type": "richtext", "required": true },
        { "key": "cta",   "label": "CTA",          "type": "richtext", "required": false }
      ]
    },
    { "key": "service_overview", "label": "Service Overview",
      "fields": [ { "key": "service_description", "label": "Description", "type": "richtext" } ] }
  ]
}
```

Field `type`: `text`, `richtext`, `list`, `faq`, `image`. Fields may declare
`required`, `default`, and a `variable` (a `{{TOKEN}}` the content engine fills).

## Fields / variables

Templates reference content via variables the content engine populates —
never hard-coded city/service names:

```
{{TITLE}} {{H1}} {{INTRO}} {{CONTENT}} {{SERVICE}} {{SERVICE_DESCRIPTION}}
{{CITY}} {{STATE}} {{PRIMARY_KEYWORD}} {{SECONDARY_KEYWORDS}} {{BENEFITS}}
{{PROCESS}} {{LOCAL_CONTENT}} {{FAQ}} {{CTA}} {{AUTHOR}}
{{DATE_PUBLISHED}} {{DATE_MODIFIED}}
```

Custom fields/variables can be added per template.

## Built-in template types

Blog Post · Service Page · Location Page · Location + Service Page · Landing
Page · Case Study · About Page · FAQ Page · Custom Page. Each ships a sensible
default `structure` (see `SCC_Template::default_structure()`), which the native
template editor can then customize.

## Deterministic template selection

`SCC_Template_Selector::select( $content_type, $manual_family = '' )` — the AI
never picks the template. Priority (first match wins):

1. **Manually selected template** (explicit family passed from the content plan / UI).
2. **Exact rule** — a rule in the template map matching the content type (and, later, industry/location/category conditions).
3. **Content-type mapping** — the active template mapped to that content type.
4. **Default template** — the family flagged as default.
5. **WordPress fallback** — a minimal built-in structure so generation never dies.

The content-type → template + renderer mapping is stored in the option
`scc_template_map` and edited under **SEO Command Center → Templates**.

## Versioning

Updating a template creates a **new version row** (same `family`, `version+1`,
old rows set to `archived`/inactive). **Existing generated pages are never
changed** — they are plain WordPress content. New pages use the newest active
version. A user can intentionally rebuild an existing page against the newest
template.

## Cloning

`Duplicate Template` copies a family into a **new family** (e.g. "Local Service
Template" → "Local Service Template — Premium") with its own version history.
The original is untouched.

## Safety

- Generation **never modifies a source template** (native or Elementor); it
  always builds a new content instance.
- On failure: the template is preserved, partial changes are rolled back, the
  error is logged, and the user can retry.
