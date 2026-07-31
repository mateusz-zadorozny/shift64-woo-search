# Specs index

Single glance at what is implemented and what is not. Every spec carries a
`> **Status:**` line right under its title; this table mirrors those lines.

**Lifecycle rule (see AGENTS.md):** the PR that implements a spec flips the
spec's Status header and this table row in the same PR. Statuses: `draft` →
`implemented — PR #N, date` (or `superseded — see <spec>`). Files are never
moved or deleted — paths are referenced from other specs, `.ai/runs/` plans,
and PRs.

| Spec | Status | Where |
| --- | --- | --- |
| [Native WooCommerce Brands Support](2026-07-18-native-woocommerce-brands-support.md) | ✅ implemented | PR #11 (issue #10) |
| [Playwright E2E Foundation](2026-07-18-playwright-e2e-foundation.md) | ✅ implemented | PR #14 |
| [Filter Bar Gutenberg Block & Placement Modes](2026-07-21-filter-bar-gutenberg-block-and-placement-modes.md) | superseded | see Product Filter and Filter Pill Blocks |
| [Admin Settings Information Architecture](2026-07-22-admin-settings-information-architecture.md) | ✅ implemented | PR #33 |
| [50k Demo Product Generator Scaling](2026-07-27-50k-demo-product-generator-scaling.md) | ✅ implemented | issue #18 |
| [Native WooCommerce Catalog Sorting Engine and Product Sort Block](2026-07-29-native-woocommerce-catalog-sorting.md) | 🚧 draft | — |
| [Context-Aware Product Visibility in Redis Queries](2026-07-30-context-aware-product-visibility.md) | ✅ implemented | PR #46 |
| [Licensing Attribution Correction](2026-07-30-licensing-attribution-correction.md) | ✅ implemented | PR #49 |
| [readme.txt Publication Readiness](2026-07-30-readme-txt-publication-readiness.md) | ✅ implemented | PR #50 |
| [Repository Docs Restructure](2026-07-30-repository-docs-restructure.md) | ✅ implemented | PR #52 |
| [Block Theme Product Collection Integration](2026-07-30-block-theme-product-collection-integration.md) | ✅ implemented | PR #51; foundation for block-native controls |
| [Composable Search Blocks](2026-07-30-composable-search-blocks.md) | 🚧 draft | depends on Product Collection integration for result navigation |
| [Product Filter and Filter Pill Blocks](2026-07-30-product-filter-pill-blocks.md) | 🚧 draft | depends on Product Collection integration |
| [Block Theme-Only Legacy Surface Removal](2026-07-30-block-theme-only-legacy-removal.md) | 🚧 draft | final cleanup after all block-native specs |

## Recommended implementation flow

1. **Correct shared visibility** —
   `2026-07-30-context-aware-product-visibility.md`.
2. **Build the Product Collection foundation** —
   `2026-07-30-block-theme-product-collection-integration.md`.
3. **Build the Search parent/child blocks** —
   `2026-07-30-composable-search-blocks.md`.
4. **Build Product Filters and the shared pill primitive** —
   `2026-07-30-product-filter-pill-blocks.md`.
5. **Finish the sorting engine and expose Product Sort** —
   `2026-07-29-native-woocommerce-catalog-sorting.md`. Its pure engine steps
   may begin earlier, but its block waits for steps 2 and 4.
6. **Remove the legacy frontend** —
   `2026-07-30-block-theme-only-legacy-removal.md`, only after every previous
   block-native spec is implemented and jointly QA'd.

Each draft is intended to be handed to `om-auto-implement-spec` separately.
The final cleanup is deliberately last because it removes rollback-compatible
frontend surfaces.
