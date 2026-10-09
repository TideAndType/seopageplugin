# TideOrbit Professional Elementor Design Engine

Introduced as a native foundation in **v1.41.0**, separated into a dedicated
article-to-design pipeline in **v1.42.0**, and expanded with the schema-aware
Elementor Design Agent in **v1.83.0**.

## Boundary

The content/article system decides **what the page says**.

The Elementor design system decides **how the finished content is presented**.

SEO strategy stops before the design boundary. Keyword, search intent, city and
page type are retained elsewhere for product compatibility, but they are not
inputs to visual composition.

```
Completed article/page
        ↓
SCC_Design_Handoff
        ↓
SCC_Elementor_Content_Bank ───── exact finished copy/media refs
        ↓
SCC_Elementor_Widget_Schema ──── live Elementor/add-on controls
        ↓
SCC_Elementor_Design_Agent ───── prompt + design DNA + widget schemas
        ↓
SCC_Elementor_Composition ────── validate controlled nested DSL
        ↓
Compile to editable Elementor containers/widgets
        ↓
Verified snapshot / rollback writer

Fallback: Page Architect → Design Composer → Native Elementor Blocks
```

## Design handoff

`SCC_Design_Handoff` converts finished content into a design-only contract:

- headline and introduction,
- body HTML split into H2-led sections,
- section word/paragraph/list/table/quote/media signals,
- supplied hero media,
- supplied stats and process steps,
- supplied FAQs and related links,
- supplied CTA copy/label/destination.

It deliberately excludes keyword, search-intent, local-targeting and page-type
metadata.

## Professional design composer

`SCC_Design_Composer` selects presentation from content structure and the
site's Elementor design profile.

Current section treatments include:

- editorial,
- readable/narrow prose,
- wide/table sections,
- split-list sections,
- alternating media-left/media-right sections,
- callout sections,
- split-image or text-first heroes,
- counter proof bands,
- numbered process cards,
- FAQ accordion or stacked fallback,
- related-content card/bento grids,
- split or centered CTA sections.

Composition is deterministic: identical finished content produces the same
visual plan even if SEO metadata changes.

## LM Studio compact art-direction workflow (v1.85.0)

Local models are best at short creative decisions, not huge nested JSON trees.
When Layout Design is routed to LM Studio, TideOrbit now asks for a concise
art-direction recipe (hero treatment, palette tone, responsive section rhythm,
preferred widths). The server **constructs** the real Elementor widget tree
from the completed, immutable content bank; missing required content is
rejected by the same composition validator.

Newly generated WordPress **pages** automatically use this professional design
path when the existing auto-layout option is enabled and LM Studio is chosen
for Layout Design (or as the primary AI provider). Blog posts, explicit
Elementor template mappings, and all existing rollback/draft protections
are preserved.

The compact approach reduces token usage and tunnel exposure, but very slow
local models or Cloudflare Quick Tunnel limits can still interrupt requests;
in those cases the previous deterministic layout is used as a fallback.

## Schema-aware Design Agent

When **AI Design Agent** is enabled, TideOrbit no longer asks the model merely
to order pre-registered section IDs. It gives the model a constrained design
surface inspired by Elementor's MCP composition workflow:

- live discovery of the widgets registered by Elementor Free, Elementor Pro,
  and compatible third-party Elementor add-ons,
- compact schemas for the controls each selected widget actually exposes,
- a site design-DNA snapshot (colors, typography, widths, spacing and radius),
- immutable references to the already-finished page copy and media,
- a nested composition DSL for containers, widgets, responsive rules and
  semantic collections such as FAQ, stats, steps, related pages and locations.

The model may decide presentation, nesting, proportions, spacing and supported
widget usage. It may **not** emit raw Elementor JSON, PHP, HTML/shortcode/code
widgets, or replacement SEO copy.

A composition is rejected if it uses an unavailable widget/control, writes
literal model copy into a content field, drops required finished content,
duplicates the H1, exceeds the element/depth limits, or references content that
is no longer present.

The proposed composition is stored server-side behind an opaque preview token.
On Apply, TideOrbit re-reads the current post and rebuilds the content bank
before validating again. This prevents an older preview from overwriting copy
that changed after the preview was generated.

## Elementor widget catalog

`SCC_Elementor_Widget_Catalog` is owned by TideOrbit. It is not an EMCP
dependency.

TideOrbit discovers the widgets actually installed on the site and only emits
widget structures it knows how to populate safely. The current catalog includes
Elementor Free and optional Pro roles, with active rendering support for:

- Heading,
- Text Editor,
- Button,
- Image,
- Icon List,
- Accordion,
- Counter.

Additional catalog entries make future support possible for Image Box,
Testimonial, Video, Image Carousel, Google Maps, Form, Call to Action and Loop
Grid without coupling the design engine to a third-party MCP plugin.

## Site design DNA

`SCC_Design_Intel::profile()` reads the active Elementor Kit and recent
Elementor pages to inherit:

- system/custom colors,
- heading/body font families,
- content width,
- typical vertical section spacing,
- container gaps,
- border radius.

Missing values use conservative defaults. No AI call is required.

## Safety and fallbacks

The design engine never executes model-generated raw Elementor JSON.

For AI-designed pages, TideOrbit first attempts the validated schema-aware
composition. If the AI designer is unavailable or its plan cannot pass the
validator, the existing Page Architect remains the guaranteed fallback.

Inside the fallback component renderer the order remains:

1. an explicitly mapped existing Elementor template,
2. TideOrbit's native Elementor component renderer,
3. the legacy semantic HTML-widget renderer.

A crawlable `post_content` copy is also retained.

## CTA ownership

The design layer does not create marketing copy or destinations. CTA sections
are composed only when the completed content supplies a CTA signal. Button text
and URLs are passed through from content fields or an explicit CTA link in the
article.

## Responsive behavior

Generated native components include mobile/tablet width fallbacks and stack
multi-column compositions on small screens. The renderer uses Flexbox Containers
and site-derived spacing rather than a fixed page template.

## Future expansion

The remaining high-value design-side work is mostly iterative rather than a
new architecture:

- visual screenshot inspection and automatic critique/repair,
- richer semantic collection renderers for pricing/testimonials/logos,
- deeper Atomic Editor class/variable integration where available,
- interaction/animation schemas with conservative accessibility limits,
- reusable learned section patterns from approved site designs.

Those additions should continue to respect the same boundary: content is
complete before the design engine begins.
