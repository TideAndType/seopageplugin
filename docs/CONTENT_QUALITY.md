# Content quality standard (baked into generation)

Every generated page follows these rules. They are enforced in the content
generation prompt (`includes/generation/class-scc-generator.php` →
`generate_body()`), with a post-processing safety net for punctuation.

## Every page
- **Lead the title with the primary commercial keyword**, outcome-oriented.
  Any clever tagline is a supporting line in the opening, not the H1.
- **No em/en dashes** (— –). Converted to plain punctuation in the prompt and
  again in `strip_dashes()` as a safety net. Hyphenated words (E-E-A-T,
  well-designed) are preserved.
- **Accuracy + E-E-A-T**: real, practical expertise; concrete specifics,
  numbers, steps, trade-offs; honest caveats. Never invent facts, statistics,
  prices, awards, clients or testimonials.
- **Current terminology**: "Google Business Profile", never "GMB"/"GBP". No SEO
  myths or folklore (e.g. image geotagging as a ranking tactic).
- **No overpromising**: never guarantee results, #1 rankings, or "undeniable
  authority". Frame as building stronger signals of relevance, trust and
  authority, with realistic expectations.
- **No hype/AI cliches**: avoid "acquisition machine", "unlock", "in today's
  digital landscape", "game-changer", "supercharge", "leverage".
- **No duplicate intro / no generic "Overview"** that restates the opening.
- **FAQs** live in a native `<details>` accordion + FAQPage schema.

## Service / money pages (pillar, service, location, or commercial/
## transactional/local intent)
Structured to inform **and** convert:
1. Open with the reader's problem and the outcome they want (calls, foot
   traffic, leads, visibility, local authority). Sell outcomes, not keywords.
2. A clear multi-step **process** (e.g. Audit → Optimization & Fixes →
   Authority Building → Measurement & Growth).
3. A concrete **"What's included"** section grouped into labelled H3 groups
   with bullet lists (Google Business Profile, Website SEO, Local Authority,
   Reputation, Competitive Intelligence…), covering ongoing management and
   competitor/market analysis as distinct items where relevant.
4. Trust signals + objection handling.
5. **One specific CTA** tied to the offer (e.g. a free local SEO audit) —
   never a weak "let's talk".
- Target **1,500–2,000 words**.
- **Local** = genuinely useful local content and real local angles (e.g. "how
  salt air affects HVAC systems in coastal Florida"), not city-name swapping.
  Natural service+location phrasing where it fits ("Emergency Plumbing in
  Daytona Beach").
- **Buyer FAQs**: how long it takes, what it costs (what drives price, honest
  range, no invented figure), guarantees (answer honestly: none), how it
  differs from the alternative, multiple locations / service-area businesses,
  review management.

## Topical cluster
Money pages should link to supporting content (local SEO mistakes, GBP
optimization, local SEO audit, citation management, local schema, Maps
rankings, local keyword research, review management, local link building,
city/service pages). Internal links are woven automatically by the link engine.
