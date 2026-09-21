# TideOrbit Native Elementor Design Engine

Introduced in **v1.41.0**.

## Goal

TideOrbit should build pages that remain genuinely editable in Elementor instead
of placing every designed section inside a single HTML widget.

The design engine keeps the existing safe pipeline:

1. analyze real page content,
2. select an allowlisted semantic block layout,
3. choose a deterministic visual variant,
4. map only verified content into the block,
5. render native Elementor containers/widgets when the installed runtime supports them,
6. fall back to the existing semantic HTML widget renderer when it does not,
7. preserve crawlable `post_content` as a builder-independent fallback.

Existing mapped Elementor templates still have first priority.

## Components

### `SCC_Elementor_Capabilities`

Discovers the active Elementor version and available widgets at runtime. TideOrbit
never assumes a third-party widget exists. It also reports whether **EMCP Tools**
(`EMCP_TOOLS_VERSION`) is active, but EMCP is optional and is not required for
page generation.

### `SCC_Design_Intel::profile()`

Builds a cached, read-only design profile from:

- the active Elementor Kit's system/custom colors,
- system/custom typography,
- Kit content width,
- spacing/gap/radius signals sampled from up to five existing Elementor pages.

Missing values use conservative defaults. No AI call is made.

### `SCC_Block_Variant_Selector`

Keeps AI away from raw Elementor JSON. The semantic layout may contain `hero`,
`content`, `stats`, etc.; TideOrbit deterministically chooses a visual variant
such as `split-image`, `editorial`, `numbered-cards`, or `split`.

### `SCC_Native_Elementor_Blocks`

Produces real Elementor Flexbox Containers and core widgets:

- Heading
- Text Editor
- Button
- Image

Current native block coverage includes hero, content, intro, benefit lists, card
grids, stats, process steps, FAQ stacks, related/location grids, CTA, TOC,
comparison and highlight blocks.

The renderer uses Elementor's current container setting names
(`flex_gap`, `flex_align_items`, `flex_justify_content`, responsive width
settings, etc.).

## Fallback order

For every block:

1. mapped existing Elementor template,
2. TideOrbit native Elementor block,
3. legacy TideOrbit HTML-widget block.

This keeps v1.41 backward compatible with existing mappings and generated pages.

## EMCP Tools

TideOrbit detects EMCP Tools when installed so a later agent workflow can use
its MCP surface for visual inspection, snapshots, rollback and iterative edits.
The core renderer deliberately does **not** depend on EMCP; page generation must
continue to work when that plugin is absent or disabled.

## Next expansion

The native block renderer is intentionally structured so additional variants can
be added without changing the semantic content engine. Useful next variants are
background-image heroes, trust/logo rows, icon feature grids, pricing sections,
testimonial cards, lead-form heroes, image-card carousels and Elementor-native
accordion/tab implementations where the installed widget schema is verified.
