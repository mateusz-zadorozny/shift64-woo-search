# Native WooCommerce Catalog Sorting Engine and Product Sort Block — Execution Plan

Source doc: .ai/specs/2026-07-29-native-woocommerce-catalog-sorting.md

## Goal

Add a context-agnostic Redis sorting engine for WooCommerce's native catalog modes, candidate-set pass-through for unknown/third-party modes with large-catalog protection, and expose sorting to shoppers via a block-theme-only Site Editor `shift64-woo-search/product-sort` block.

## Scope

- Add pure `Shift64_Woo_Search_Sort` service resolving orderby modes, default catalog sorts, and candidate ceilings.
- Generalize candidate pass-through (`wc` mode) with a configurable match ceiling (default: 10,000) and graceful native fallback.
- Extend `Shift64_Woo_Search_Query`, `Shift64_Woo_Search_Archive`, and `Shift64_Woo_Search_Product_Collection_Query_Service` to support Redis sorting (`price`, `popularity`, `rating`, composite `menu_order`, `date` when indexed) and pass-through modes.
- Register dynamic `shift64-woo-search/product-sort` block with ordered checklist, label overrides, context-aware option filtering, and Interactivity API client navigation.
- Add `date` NUMERIC SORTABLE field to RediSearch schema, indexer, migration, and CLI health reporting (Phase 2).
- Add full PHPUnit coverage, update specs index, and update changelog.

## Non-goals

- Do not add classic theme hooks, widgets, shortcodes, or automatic DOM injection.
- Do not add appearance settings in WP Admin (appearance uses Site Editor block supports).
- Do not import private WooCommerce JavaScript store state.
- Do not silently truncate large candidate sets.
- Do not expose Search Relevance outside product-search contexts.

## Implementation Plan

### Phase 1: Engine and Product Sort Block

1. `Shift64_Woo_Search_Sort` service — orderby → mode/SORTBY resolution table, default-sort resolution (store default + `menu_order`→`relevance` search remap), candidate ceiling filter.
2. Generalize candidate-set pass-through (`wc` mode) — chunked/bounded ID fetch, ceiling filter, decline-to-intercept overflow behavior.
3. Wire `redis` mode for `popularity`/`rating` through `Shift64_Woo_Search_Query`, and route unknown orderby to `wc` mode.
4. Composite `menu_order` sort via `FT.AGGREGATE` with failure fallback to MySQL bail-out.
5. Adapt Product Collection query adapter and service to execute resolved sort modes.
6. Register `shift64-woo-search/product-sort` block metadata, dynamic rendering, canonical Woo/context option resolution, and label overrides.
7. Connect Product Sort block to Product Collection navigation via Interactivity API script module.

### Phase 2: `date` Field and Finalization

8. Schema + indexer: add `date` NUMERIC SORTABLE; indexer writes timestamp; `setup` creates it fresh.
9. Migration + gating: `FT.ALTER` schema addition, `date` flips from `wc` mode to `SORTBY date DESC` when indexed, CLI `health` reporting.
10. Docs & Spec lifecycle: changelog entry; flip spec status and index row to implemented.

## Risks

- `FT.AGGREGATE` composite sort syntax must fail safely back to native WooCommerce if RediSearch encounters an issue.
- Candidate set ceiling must prevent MySQL `post__in` query thrashing on massive result sets.
- Interactivity API script module must be client-navigation compatible and degrade to plain link navigation when JavaScript is unavailable or enhanced navigation is disabled.
- Direct URLs using omitted sort modes must be rendered as temporary selected options to remain truthful.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Engine and Product Sort Block

- [x] 1.1 `Shift64_Woo_Search_Sort` service — 6962ba6
- [x] 1.2 Generalize candidate-set pass-through (`wc` mode) — 79def0f
- [x] 1.3 Wire `redis` mode for `popularity` and `rating` — 79def0f
- [x] 1.4 `menu_order` composite sort via `FT.AGGREGATE` — 79def0f
- [x] 1.5 Adapt Product Collection query integration — 79def0f
- [x] 1.6 Register `shift64-woo-search/product-sort` block — 3d360ac
- [x] 1.7 Interactivity API script module for Product Sort — 3d360ac

### Phase 2: `date` Field and Finalization

- [x] 2.1 Schema + indexer `date` field — 46f9bcf
- [x] 2.2 Migration, health check, and dynamic mode flipping — 46f9bcf
- [x] 2.3 Documentation, spec lifecycle, and changelog update — 46f9bcf
