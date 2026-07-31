# Block Theme Product Collection Integration — Execution Plan

Source doc: .ai/specs/2026-07-30-block-theme-product-collection-integration.md

## Goal

Make an inherited WooCommerce Product Collection consume Redis-backed product
membership, totals, facets, sorting, and canonical URL state while WooCommerce
and WordPress retain ownership of block rendering, pagination, navigation,
history, and accessibility.

## Scope

- Add immutable Product Collection context, catalog-state, and result contracts.
- Add a context-driven Redis catalog query service and preserve existing callers.
- Adapt only eligible inherited Product Collection queries through WooCommerce's
  public `query_loop_block_query_vars` filter.
- Scope Redis totals to the exact adapted query and isolate request results by
  collection/request key.
- Publish canonical storefront URL and navigation helpers for downstream
  block-native controls.
- Extend PHPUnit, block-theme Playwright fixtures, and merchant documentation.
- Mark the source spec and index row implemented in this PR.

## Non-goals

- Do not add Product Filter, Filter Pill, Product Sort, search-control, grid,
  pager, product-card, or template-part blocks.
- Do not import WooCommerce private Product Filters JavaScript state.
- Do not remove the classic archive or taxonomy integrations.
- Do not change the Redis schema, persistent options, Store API, or REST API.
- Do not make Playwright part of the hermetic agentic validation gate.

## Implementation Plan

### Phase 1: Query and URL foundation

1. Add pure Product Collection eligibility/context and catalog-state parsers,
   with PHPUnit coverage for eligibility, paging forms, invalid values, and
   canonical round trips.
2. Extract a context-driven Redis catalog request service returning ordered IDs,
   totals, facets, and effective sort while preserving existing query callers.
3. Register the late Product Collection query adapter, private query marker,
   empty-result guard, and marker-scoped total adjustment, with isolation tests.
4. Add block-theme E2E fixtures for inherited Product Collection search
   membership, no results, direct paging, and refresh.

### Phase 2: Router compatibility and shared state

5. Add the request-scoped result registry and read-only accessors, with isolation
   and fallback-state tests.
6. Add a shared canonical URL/navigation utility covering progressive fallback,
   page reset, enhanced-navigation detection, and full-reload fallback.
7. Extend block-theme Playwright coverage for request/history behavior,
   Back/Forward restoration, `forcePageReload`, and the ownership matrix.
8. Document the supported Site Editor template contract and flip the source
   spec plus specs index to implemented.

## Risks

- `found_posts` must be keyed to a private marker so unrelated Query Loops never
  inherit a Product Collection total.
- The adapter must fail back to WooCommerce's untouched query when Redis is
  unavailable or rejects a request.
- Empty Redis pages need an impossible membership constraint because an empty
  `post__in` means no restriction in WordPress.
- URL normalization must reject unknown taxonomies, terms, operators, and sort
  values without copying nonce or editor-preview parameters.
- Multiple inherited Product Collections are unsupported as a template design,
  but their request keys and stored results still must remain isolated.

## Progress

PR: #51

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Query and URL foundation

- [x] 1.1 Add pure Product Collection eligibility/context and catalog-state parsers — be02cc5
- [x] 1.2 Extract a context-driven Redis catalog request service — be02cc5
- [x] 1.3 Register the Product Collection query adapter and scoped total adjustment — be02cc5
- [x] 1.4 Add block-theme E2E foundation fixtures and coverage — be02cc5

### Phase 2: Router compatibility and shared state

- [x] 2.1 Add the request-scoped result registry and read-only accessors — be02cc5
- [x] 2.2 Add shared canonical URL and navigation utilities — be02cc5
- [x] 2.3 Extend block-theme navigation and ownership coverage — be02cc5
- [x] 2.4 Document the supported Site Editor template contract and mark the spec implemented — ae1f10c

## Post-review fixes

- Route inherited Product Collection query consumers through a scoped child
  context because WooCommerce otherwise clones the already-executed main query
  and bypasses `query_loop_block_query_vars`.
- Distinguish Redis command failure from a legitimate zero-result response so
  the adapter preserves native WooCommerce fallback behavior.
- Keep unimplemented Woo/third-party sort modes native, reject disabled facet
  taxonomies, and reset every query-ID paging parameter in canonical URLs.
