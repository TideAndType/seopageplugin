# TideOrbit Elementor Layout Engine (current architecture)

TideOrbit's active layout pipeline uses **SCC_Layout_Service** with
**SCC_Page_Architect** and **SCC_Elementor_Design_Agent**. LM Studio's compact
**SCC_Elementor_Design_Blueprint** expands a design recipe into native Elementor
containers, widgets and responsive styles. **SCC_Visual_Recreation** generates
bounded art-direction recipes from screenshots without importing React.

All layouts use **SCC_Elementor_Content_Bank** for locked SEO copy and
**SCC_Elementor_Composition** for validation and Elementor serialization. The
service persists a preview token until the editor confirms the apply action;
**SCC_Block_Elementor_Renderer** backs up live/draft state for rollback.

Fallback paths are built using **SCC_Page_Architect** and
**SCC_Layout_Validator**, not the retired `SCC_Layout_Engine`,
`SCC_Layout_Rule_Provider`, or `SCC_Layout_AI_Provider`.

These three legacy classes had no production callers and were removed in
v1.88.0. Their old test cases were also removed; the active architecture,
content preservation, and validation tests remain.

The `seo-command-center/docs/` directory is for developers only and is
excluded from installable WordPress ZIP archives as of v1.88.0.
