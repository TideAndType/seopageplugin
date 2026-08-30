# SEO Command Center — Renderer Architecture

The renderer layer is what makes the plugin **CMS-agnostic**. The SEO strategy
engine and the content/template engine produce a **ContentObject**; a renderer
turns that object + a Template into an actual WordPress page. **The SEO engine
knows nothing about Elementor or any page builder.**

```
SEO Strategy Engine → Content + Template Engine → Renderer → WordPress Page
```

## The contract

`SCC_Renderer_Interface`:

```php
interface SCC_Renderer_Interface {
    public function get_id(): string;        // 'wordpress' | 'gutenberg' | 'elementor'
    public function get_label(): string;
    public function is_available(): bool;     // can this renderer run right now?
    public function render( SCC_Content_Object $content, $template ): array|WP_Error;
    // returns: [ 'post_content' => string, 'post_meta' => array, 'post_name' => string ]
}
```

`post_meta` may include builder-specific data (e.g. `_elementor_data`,
`_elementor_edit_mode`). Everything the renderer returns is applied by the
generator via `wp_insert_post` + `update_post_meta` — the renderer never writes
directly, so failures are contained.

## Renderers shipped

| id | Class | Availability | Output |
|----|-------|--------------|--------|
| `gutenberg` (default) | `SCC_Gutenberg_Renderer` | always | Block-editor markup (`<!-- wp:… -->`). Fully editable in Gutenberg; valid WordPress content if the plugin is removed. |
| `wordpress` | `SCC_WordPress_Renderer` | always | Classic semantic HTML (`<h2>`, `<p>`, `<ul>`). Works in the Classic Editor and any theme. |
| `elementor` | `SCC_Elementor_Renderer` | only if Elementor is active **and** a source template is mapped | Duplicates an existing Elementor page's `_elementor_data`, fills placeholders, assigns to the new page. **Elementor Free is enough** — no Pro, no Theme Builder, no Pro-only APIs. |

Adding **Bricks**, **Divi**, or any other builder later = implement
`SCC_Renderer_Interface` and register it. No change to the SEO engine, content
generator, internal-link engine, metadata engine, schema engine, or
architecture engine.

## Selection & fallback

`SCC_Renderer_Manager::pick( $preferred )`:
1. Try the template's own `renderer` (if the template pins one).
2. Else the user's **Default Renderer** setting (`default_renderer`, default
   `gutenberg`).
3. If the chosen renderer's `is_available()` is false (e.g. Elementor
   requested but not installed), **fall back** in order: elementor → gutenberg →
   wordpress. Generation never fails because an optional builder is missing.

## Elementor specifics (optional integration)

- `is_available()` returns false unless `SCC_Elementor::is_active()` **and** a
  mapped source template exists for the content type.
- Rendering **duplicates** the source `_elementor_data` (via
  `SCC_Elementor_Builder`, which already regenerates every element id) and fills
  `{{PLACEHOLDER}}` tokens. **The original template is never modified.**
- If any step fails, the generator falls back to Gutenberg/native and logs it —
  the page is still created.

## Plugin independence

Gutenberg and WordPress renderers write standard `post_content`. If the plugin
is deactivated or removed:
- generated posts remain, fully editable;
- block content remains valid;
- metadata remains wherever the active SEO plugin stored it;
- schema stops outputting (it was plugin-provided) but nothing breaks;
- internal links remain normal `<a>` tags.
