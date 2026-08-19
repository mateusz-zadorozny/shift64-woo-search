# Product Filter and Filter Pill Blocks implementation

Source doc: .ai/specs/2026-07-30-product-filter-pill-blocks.md

## Goal

Register a Site Editor-placed `shift64-woo-search/product-filters` container
whose repeatable `shift64-woo-search/filter-pill` children each select one
eligible Redis facet and render a progressively enhanced, canonical-URL-driven
filter control beside the inherited Product Collection, sharing the pill
trigger/panel visual primitive that Product Sort will later consume.

## Scope

- A pure facet eligibility service combining the Facets settings, the live
  Redis index schema, and taxonomy existence into per-facet readiness states.
- A capability-gated editor-only REST route exposing that eligibility to the
  block inspector.
- Two API v3 metadata blocks (constrained InnerBlocks parent + dynamic child)
  with inspector configuration, setup guidance, and deterministic editor
  previews.
- Server-rendered progressive checkbox/radio forms writing canonical
  `filter_{taxonomy}` / `query_type_{taxonomy}` URLs via the shared
  Catalog State service.
- Request-scoped facet-count provisioning that reuses the Product Collection
  result envelope (or computes/memoizes counts once when pills render before
  the collection), with degraded-count status.
- An Interactivity API store (`shift64-woo-search/product-filters`) providing
  the shared pill trigger/panel primitive: one-open-at-a-time,
  draft/apply/clear, desktop disclosure, narrow-screen tray, focus handling,
  and router navigation with pagination reset.
- Documented, stable primitive selectors/helpers plus a visual fixture, and
  the spec lifecycle flip.

## Non-goals

- No changes to the legacy classic-theme filter bar
  (`frontend/class-shift64-woo-search-filters.php`) — its removal belongs to
  the legacy-removal spec.
- No Product Sort block implementation (owned by the catalog-sorting spec);
  this run only publishes the reusable primitive.
- No price/rating/stock/custom-taxonomy facets; no term search or virtual
  scrolling for high-cardinality facets.
- No new persistent options, Redis fields, shortcode, hook placement, or
  admin appearance settings.
- No Playwright in the hermetic validation gate (CI-only, per AGENTS.md).

## Naming decisions

- The spec names the eligibility source `Shift64_Woo_Search_Facet_Registry`,
  but that class name is already taken by the legacy per-request facet-context
  registry (`includes/class-shift64-woo-search-facet-registry.php`). The new
  service is `Shift64_Woo_Search_Facet_Eligibility` — same contract as the
  spec's registry, non-colliding name.
- Attribute-facet readiness derives from the live index schema (FT.INFO
  attribute identifiers, via a new `Shift64_Woo_Search_Schema` helper) rather
  than a stored "rebuild generation" option, which does not exist; the index
  schema is the authoritative record of what the completed rebuild contains.

## Implementation Plan

### Phase 1: Facet eligibility, blocks, and progressive rendering

1. Add `Shift64_Woo_Search_Facet_Eligibility` with entries (key, taxonomy,
   type `category|brand|attribute`, label, operators, status
   `ready|disabled|rebuild-required|taxonomy-missing`) derived from the
   Facets settings, live index schema fields, and taxonomy existence; add the
   `Schema::get_index_field_names()` helper. PHPUnit covers category, brand,
   attributes, and every unavailable state.
2. Add the capability-gated `GET /shift64-woo-search/v1/editor/facets` REST
   route returning facets, settings URL (`admin.php?page=shift64-woo-search&tab=results&section=facets`),
   and `rebuildRequired`; PHPUnit covers the response schema and that
   unauthorized requests leak nothing.
3. Register `product-filters` (InnerBlocks parent, allowedBlocks Filter Pill,
   clear-all attributes, layout supports, context provision) and
   `filter-pill` (facet/label/selectionMode/queryType/showCounts/hideEmpty/
   orderBy/maxOptions/applyLabel/clearLabel attributes, hidden from global
   inserter) as API v3 metadata blocks with editor inspector fed by the REST
   route, setup guidance when no facet is ready, and deterministic preview
   counts. JS unit tests cover editor helpers; blocks build committed.
4. Implement dynamic server rendering: parent wrapper with router region and
   clear-all; pill button + progressive GET form (checkbox/radio options from
   the facet provider) writing canonical URLs via `Catalog_State::build_url`
   with paging reset. PHPUnit covers escaping, invalid/ineligible saved
   facets, deleted terms, option ordering/clamping, and Clear/Clear All
   parameter semantics.

### Phase 2: Redis counts and interactive pill surfaces

5. Add the request-scoped facet provider: reuse the first STATUS_REDIS
   envelope from `Product_Collection_Results`, or compute counts once on
   demand through the shared query service with a canonical-state memo so
   pill-before-collection render order never doubles Redis aggregations;
   expose degraded-count status when aggregation fails. PHPUnit covers
   search/category/brand/attribute combinations, AND/OR, reuse vs on-demand
   compute, and degradation.
6. Implement the `shift64-woo-search/product-filters` Interactivity API store
   and the shared trigger/panel primitive: one open surface per parent,
   draft vs applied selection, Apply/Clear, Escape/backdrop close, focus
   restoration, desktop disclosure panel, narrow-screen modal tray with
   stacking tokens, and multi-parent isolation. JS unit tests cover the
   store's pure helpers.
7. Connect Apply/Clear/Clear All to the shared catalog navigation utility
   (public router with full-reload fallback, single history entry, pagination
   reset) and add Playwright coverage (CI-only) for navigation, Back, no-JS
   fallback, direct URL hydration, and Redis-failure degradation.

### Phase 3: Shared visual-contract hardening and docs

8. Extract and document the stable pill-primitive selectors and action
   helpers as an internal shared module with a visual fixture that downstream
   controls (Product Sort) can reuse; assert selector parity in tests.
9. Update merchant docs (README/docs) with forward links to the Product Sort
   and legacy-removal specs, and flip the spec status header and
   `.ai/specs/README.md` index row to implemented in this PR.

## Risks

- FT.INFO attribute parsing differs across phpredis/RESP combos — the new
  schema helper must tolerate both string and array shapes (mirrors the
  existing `get_index_info` defensive parsing).
- Facet buckets store term names, not slugs; mapping to term slugs/IDs for
  canonical URLs must drop unknown terms safely.
- Duplicate Redis aggregation cost if the provider memo misses; covered by a
  dedicated unit test asserting single execution per request state.
- Desktop disclosure + narrow tray focus semantics can regress accessibility;
  Playwright covers keyboard/Escape/backdrop, and the primitive keeps native
  checkbox/radio inputs.
- The blocks ship alongside the legacy hook bar until the legacy-removal spec
  lands; CSS namespaces are kept distinct (`shift64-woo-search-product-filters*`
  vs legacy `shift64-woo-search-filter*`).

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Facet eligibility, blocks, and progressive rendering

- [x] 1.1 Add facet eligibility service and schema field helper — 76abec8
- [x] 1.2 Add capability-gated editor facets REST route — 0f49742
- [x] 1.3 Register Product Filters and Filter Pill metadata blocks with editor UI — 11ed5fe
- [x] 1.4 Implement progressive server rendering and canonical URL forms — 2d5d6b8

### Phase 2: Redis counts and interactive pill surfaces

- [ ] 2.1 Add request-scoped facet provider with memoized counts
- [ ] 2.2 Implement Interactivity store and shared pill primitive
- [ ] 2.3 Connect router navigation and add Playwright coverage

### Phase 3: Shared visual-contract hardening and docs

- [ ] 3.1 Extract and document the reusable pill primitive with visual fixture
- [ ] 3.2 Update docs and flip the spec lifecycle status
