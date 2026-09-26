# Elementor Layout Safety

## Draft-first picker

The Layout Engine has two explicit source lists:

- **Drafts** — default
- **Live** — published pages, shown only when the user switches to Live

TideOrbit no longer falls back to recent published pages when there are no generated drafts.

## Safe working copy

A published page exposes **Make draft copy** as the primary safe action.

The working copy:

- is created as a WordPress draft
- copies the source content and TideOrbit/Elementor layout data needed for design work
- is marked with `_scc_layout_working_copy`
- stores the source id in `_scc_layout_clone_of`
- does not modify the published source

## Editing a live page

Opening a live page directly is still possible for intentional maintenance.

Before **Apply to LIVE Page** can run:

1. the screen shows a live-page warning;
2. the user must check an explicit acknowledgement;
3. the REST request includes `confirm_live`;
4. the server rejects published-page writes without that flag.

The client warning is therefore not the only protection.

## Restore points

The existing verified Elementor writer saves:

- a TideOrbit Elementor/page snapshot
- a WordPress revision when revisions are available

before applying a layout.

The Layout Engine exposes **Restore previous TideOrbit layout** through `/layout/restore`.

Restore uses the same saved snapshot instead of trying to reverse-engineer the changed Elementor document.

## Template-source safety

Elementor template mapping/import screens label sources as:

- Elementor Library
- Draft page — copy only
- LIVE page — copy only

Using a live page as a template source is read-only; generation clones its design. It does not edit the source.
