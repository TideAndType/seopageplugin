# TideOrbit SEO Architecture Brain

## Purpose

The Architecture Brain decides **where a topic belongs** instead of assuming every keyword variation deserves a URL.

It starts from the Keyword Strategy map, reconciles real WordPress URLs, then adds deterministic evidence from the Content Index, Google Search Console (when connected), and Technical SEO.

## Decision types

- **Keep existing page** — the page exists and sufficiently represents the topic.
- **Expand existing page** — the topic belongs on an existing URL but content coverage is incomplete.
- **Create service page** — a distinct commercial task has no appropriate existing page.
- **Create supporting article** — a distinct informational task deserves its own supporting resource.
- **Create location page** — a genuine local intent can justify unique local content.
- **Ignore / Mark covered** — explicit user overrides.

Commercial keyword variants are not treated as separate pages merely because their suggested slugs differ.

## Existing-page content coverage

Coverage is derived from the TideOrbit Content Index:

- page title
- indexed terms
- headings
- the planned primary keyword/topic
- related/supporting topics

The result is a diagnostic coverage percentage plus missing and covered topics. It is not keyword-density scoring.

## Search Console behavior

Search Console evidence is **existing-page-first**. If Google already associates a commercial topic with a real page and there is meaningful impression evidence, TideOrbit recommends strengthening that page rather than creating another URL.

Informational intent is kept distinct so topics such as pricing, comparisons, guides, and educational questions are not automatically absorbed into broad service pages.

## Service hierarchy

Real URL ancestry informs hierarchy. For example:

```
/managed-it-services/
/managed-it-services/24-7-monitoring/
/managed-it-services/24-7-monitoring/alert-escalation/
```

is rendered recursively as:

```
Managed IT Services
└── 24/7 Monitoring
    └── Alert Escalation
```

User drag/drop overrides change the **planning hierarchy only**. They do not silently modify WordPress slugs or permalinks.

## Consolidation Planner

Potential near-duplicate pages are surfaced for human review. The plan includes:

1. recommended keeper URL,
2. page proposed for consolidation,
3. overlap evidence,
4. review/move-content guidance,
5. internal-link update guidance,
6. a 301 recommendation only after review.

TideOrbit **never automatically merges pages or creates the redirect** from Architecture Brain.

## Existing-page expansion

For an **Expand existing page** recommendation:

1. click **Draft missing section**;
2. TideOrbit generates one evidence-constrained section using only the existing page and known Brand Brain facts;
3. review the heading and section content;
4. click **Apply to page** explicitly;
5. TideOrbit creates a recovery point first;
6. Elementor pages receive a native editable Elementor section; non-Elementor pages receive native WordPress content;
7. **Undo last expansion** restores the saved recovery point.

The generation prompt forbids invented testimonials, statistics, credentials, services, locations, guarantees, clients, certifications, prices, or outcomes.

## Architecture Health

Architecture Health is a TideOrbit structural diagnostic, **not a Google ranking score**. Signals include:

- real new-page gaps,
- existing pages needing expansion,
- weak topic coverage,
- likely consolidation candidates,
- orphan/unreachable pages from Technical SEO,
- excessive click depth,
- empty/unsupported service hubs.

## Content Plan and Action Queue

Only genuine new-page decisions can enter Content Plan.

Existing-page expansions and consolidation recommendations are review workflows. They can be promoted into Action Queue but are not included in the safe automatic action list.
