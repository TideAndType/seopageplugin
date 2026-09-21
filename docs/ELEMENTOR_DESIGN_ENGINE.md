# TideOrbit Professional Elementor Design Engine

Introduced as a native foundation in **v1.41.0** and separated into a dedicated
article-to-design pipeline in **v1.42.0**.

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
SCC_Design_Composer
        ↓
SCC_Content_Mapper
        ↓
SCC_Native_Elementor_Blocks
        ↓
Editable Elementor document
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

For every component the fallback order remains:

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

The next design-side additions can focus entirely on presentation:

- richer image-box/service-card schemas,
- native testimonial widgets,
- lead-form composition when Elementor Pro Form is installed,
- gallery/carousel treatments when media exists,
- background-image hero variants,
- logo/trust rows,
- pricing/testimonial component families,
- visual inspection and repair tooling.

Those additions should continue to respect the same boundary: content is
complete before the design engine begins.
