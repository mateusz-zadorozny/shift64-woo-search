# Block Theme Product Collection Integration

> **Status:** implemented — PR #51, 2026-07-30

## 📝 TLDR

Make an inherited WooCommerce Product Collection the single result-grid and
pagination surface for Redis-backed product searches in supported block
themes. Shift64 supplies query membership, totals, facets, and sorting state;
WooCommerce and the WordPress Interactivity Router retain rendering,
pagination, navigation, history, and accessibility ownership.

## 📝 Problem Statement

The current archive interceptor and AJAX fragment replacement were designed
around classic WooCommerce loops and theme hooks. They cannot be the
foundation for Site Editor-placed controls without duplicating Product
Collection rendering and fighting WooCommerce's router.

## 📝 Proposed Solution

Add a narrowly scoped Product Collection query adapter plus shared canonical
URL and request-state services. The adapter recognizes an inherited
`woocommerce/product-collection`, delegates matching to Redis, and returns
ordinary `WP_Query` arguments while leaving the block renderer and pager
untouched.

The supported template has one inherited Product Collection for the archive
result grid. Its Product Template and Query Pagination children remain normal
WooCommerce/Core blocks, so merchants keep complete Site Editor control over
cards and pagination. Shift64 does not register a grid, pager, template part,
or product-card block.

The adapter uses WooCommerce's public PHP extension point and WordPress's
public router contract. It does not consume WooCommerce's private Product
Filters JavaScript store. "No hooks" in the product direction means no theme
placement hooks or theme markup interception; a scoped query filter remains
necessary to integrate a dynamic third-party retrieval engine with
`WP_Query`.

## 📝 Decisions

1. The minimum supported layout contains exactly one inherited
   `woocommerce/product-collection` per archive template.
2. `query.isProductCollectionBlock` and `query.inherit` must both be true.
   Arbitrary standalone Product Collections keep native WooCommerce queries.
3. WooCommerce owns Product Collection rendering and WordPress owns enhanced
   navigation. Shift64 never swaps grid or pagination fragments.
4. Filter and sort controls communicate through validated URL parameters.
   Server-rendered state is the source of truth after every navigation.
5. Search, catalog, and taxonomy visibility policies come from the shared
   context-aware query service specified in
   `2026-07-30-context-aware-product-visibility.md`.
6. The Product Collection adapter is implemented before the filter and sort
   controls; until those blocks exist, direct canonical URLs exercise the
   same query path.

## 📝 Research

- WooCommerce's Product Collection `Controller` composes an inherited query
  and exposes `query_loop_block_query_vars`; its `Renderer` supplies the
  `woocommerce/product-collection` interactivity namespace,
  `data-wp-router-region`, and pagination directives.
- Product Collection pagination uses `query-{queryId}-page` (or
  `query-page` without an ID), while classic archives may use `paged` or a
  `/page/N/` path. A shared reset utility must cover all three forms.
- WordPress client navigation fetches a normal server response, replaces
  matching router regions, manages assets/history, and announces navigation.
  This lets Shift64 controls stay URL-driven rather than sharing a private
  client store with WooCommerce.
- WooCommerce Product Filters demonstrate the correct canonical-URL/router
  model, but their current shared-state bootstrap is explicitly private and
  their counts come from WooCommerce/Store API semantics rather than the
  Shift64 Redis result set. Shift64 therefore reuses the public URL and router
  conventions, not that internal store.

References:

- [WooCommerce Product Collection extensibility](https://developer.woocommerce.com/docs/block-development/extensible-blocks/product-collection-block/)
- [WordPress Interactivity API client-side navigation](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/core-concepts/client-side-navigation/)
- [WordPress client-navigation compatibility](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/core-concepts/client-side-navigation-compatibility/)

## 📝 Architecture

### Eligibility resolver

Add `Shift64_Woo_Search_Product_Collection_Context`, a pure resolver that
receives the parsed block plus request context and returns either an immutable
integration context or `null`.

An eligible context requires:

- block name `woocommerce/product-collection`;
- `query.isProductCollectionBlock === true`;
- `query.inherit === true`;
- a product search, shop, or enabled product-taxonomy archive;
- an active Shift64 archive/query feature flag; and
- no admin, REST, feed, or unrelated Query Loop request.

The context contains the Product Collection `queryId`, current page,
per-page value, search term, archive taxonomy/term, normalized filter state,
resolved sort, and visibility context. It never contains raw RediSearch
fragments.

Multiple eligible collections are unsupported template configuration, but
must fail safely: each receives the same validated URL state and an isolated
request key based on block/query ID. No global "first render" guard may cause
one collection to leak totals into another. The editor documentation tells
merchants to use one inherited collection.

### Query adapter

Add `Shift64_Woo_Search_Product_Collection_Query` at a late priority on
`query_loop_block_query_vars` after WooCommerce has built its normal query:

1. Resolve eligibility. Return Woo's query unchanged when ineligible.
2. Normalize URL state through the shared request-state service.
3. Execute the shared Redis product query for the requested page and sort.
4. Add a private query marker and constrain membership to returned IDs.
5. Preserve the returned order with `post__in`/`orderby => post__in` for
   Redis-ordered modes; the sorting spec owns Woo pass-through modes.
6. Expose the Redis total through a marker-scoped `found_posts` adjustment so
   Query Pagination calculates the correct number of pages.
7. Store the immutable result envelope in a request-scoped registry for
   server-rendered Filter Pill and Product Sort blocks.

Empty Redis results must become an impossible product query, not an omitted
`post__in` (WordPress treats an empty `post__in` as no restriction).

The adapter does not mutate `$_GET`, the main query, global Woo loop
properties, or parsed block attributes. It does not call a template renderer.

### Result envelope

Add `Shift64_Woo_Search_Product_Collection_Result` with:

- context/request key;
- ordered product IDs for the requested page;
- total matches;
- normalized current page/per-page;
- effective sort slug;
- selected filter state;
- facet buckets keyed by eligible taxonomy; and
- execution status (`redis`, `native-fallback`, `invalid-state`).

The object lives only for the current PHP request. No new cache, option, or
client JSON blob is required. Existing Redis query caching may cache its
serializable payload under the same invalidation rules as search results.

### Canonical URL state

Add `Shift64_Woo_Search_Catalog_State` as the one parser/builder used by the
adapter, Filter Pill, and Product Sort:

- search: WordPress/WooCommerce `s` plus `post_type=product`;
- sorting: `orderby`;
- taxonomy filters: Woo canonical `filter_{taxonomy}` and optional
  `query_type_{taxonomy}`;
- paging: `query-{queryId}-page`, fallback `query-page`, `paged`, and
  `/page/N/`.

Only known sort slugs, enabled/rebuilt facet taxonomies, existing term slugs,
and `and|or` query operators survive normalization. Values are decoded,
deduplicated, sorted deterministically where ordering is not meaningful, and
re-encoded with WordPress URL helpers.

Changing search, sorting, or any facet removes every recognized paging form.
Changing only the Product Collection page preserves search/filter/sort state.
Unrelated safe query parameters are preserved; nonces and editor preview
parameters are never copied into storefront navigation.

### Router ownership

Each Shift64 control parent renders a stable router region and declares
Interactivity API client-navigation compatibility. On an action it computes a
canonical URL, then delegates to the public core router. The resulting server
response refreshes:

- the WooCommerce Product Collection region;
- the Product Filters region;
- the Product Sort region; and
- any search-control region whose server state depends on the URL.

The regions are siblings; Shift64 never reaches into or replaces the
WooCommerce region. When `forcePageReload` is enabled on Product Collection,
controls perform a normal browser navigation rather than attempting enhanced
navigation.

## 📝 Data Model

No persistent schema or option is added. This spec defines internal immutable
value objects and a request-scoped registry only.

The Redis schema changes needed for new sorting modes remain owned by
`2026-07-29-native-woocommerce-catalog-sorting.md`. Facet availability remains
owned by the existing Facets setting and index rebuild status.

## 📝 API Contracts

### Supported block contract

The host is `woocommerce/product-collection` with:

- `query.isProductCollectionBlock: true`;
- `query.inherit: true`;
- a stable numeric `queryId`; and
- Query Pagination inside the collection when pagination is desired.

Product card composition, columns, alignment, no-results content, and
pagination layout are deliberately unconstrained.

### Internal contracts

- `Product_Collection_Context::from_block( array $block, WP_Block $instance ):
  ?Context`
- `Catalog_State::from_request( Context $context ): Catalog_State`
- `Product_Collection_Query::execute( Context $context, Catalog_State $state ):
  Product_Collection_Result`
- `Product_Collection_Results::get( string $request_key ): ?Result`

Names are illustrative class-level contracts; implementation may refine file
names without changing the boundaries.

No REST endpoint, Store API endpoint, shortcode, theme action, or public
JavaScript global is introduced.

## 📝 UI/UX

- The Site Editor inserter/help text tells merchants to place Shift64 controls
  in the same archive template as one inherited Product Collection.
- In editor preview, a control without a compatible Product Collection shows a
  non-destructive setup notice and still exposes its design tools.
- On the storefront, controls and the collection update in one navigation.
  Browser Back restores both results and selected control state.
- Product Collection's native loading, focus, history, no-results, and
  pagination behavior remains visible; Shift64 adds no competing spinner or
  live region.
- With JavaScript disabled, links/forms navigate to the same canonical URLs
  and the server returns the correct collection.

## 📝 Edge Cases & Failure Scenarios

- **Redis unavailable or query fails:** return Woo's original query vars and
  mark the result as native fallback. Product Collection remains usable.
  Redis-only facet counts render unavailable; native sort URLs still work.
- **Malformed URL state:** drop invalid values, render the normalized state,
  and never interpolate raw input into RediSearch.
- **No matching IDs:** inject an impossible query, total `0`, and let the
  Product Collection no-results blocks render.
- **Page beyond the last result:** canonicalize to page 1 after a state change;
  for a directly requested stale page, return no results without redirect
  loops.
- **Missing/duplicate query ID:** use WordPress's `query-page` fallback and a
  render-instance request key; enhanced navigation may degrade to full-page
  navigation, but results remain correct.
- **`forcePageReload` enabled:** submit/link to the canonical URL normally.
- **Multiple eligible collections:** both remain isolated and correct, but the
  configuration is documented as unsupported because global controls cannot
  communicate which grid they target.
- **Third-party query mutation:** the late adapter preserves unrelated query
  vars and only replaces membership/order/total fields it owns.
- **Redis total and returned IDs disagree:** log the request key, clamp
  pagination calculations to non-negative values, and never manufacture IDs.
- **Back/forward navigation:** server-rendered URL state wins; no stale local
  selection cache is retained.

## 📝 Risks & Impact Review

- **Highest risk:** correct `found_posts` scoping. The private query marker and
  request key must prevent totals from affecting unrelated loops.
- **Compatibility:** this is additive until the legacy-removal spec lands.
  Existing classic archive interception remains present but must explicitly
  skip eligible Product Collection queries to avoid double interception.
- **Performance:** Redis pages the native sort modes. The existing sorting
  spec owns the bounded all-candidate fallback for third-party/Woo DB sorts.
- **Router coupling:** only public WordPress router APIs and HTML region
  attributes are used. No WooCommerce private JS state is imported.
- **Rollback:** unregister the adapter and controls fall back to native
  Product Collection results; no stored content or index data is destroyed.
- **Security:** all request values pass closed allowlists and WordPress URL
  encoding. The adapter performs reads only and relies on normal public
  catalog visibility.

## 📋 Phasing

- **Phase 1 — query and URL foundation.** Product Collection can render
  Redis-backed direct search/filter/sort URLs with correct totals and
  pagination. Independently shippable without new controls.
- **Phase 2 — router compatibility and shared state.** Control regions can
  participate in Product Collection navigation and read request-scoped result
  state. Independently testable using a minimal fixture control.

Downstream adoption is a non-blocking handoff: Search, Product Filters, and
Product Sort consume the published contracts in their owning specs. Their
status does not block this foundation from becoming implemented.

## 📋 Implementation Plan

### Phase 1

1. Add pure Product Collection eligibility/context and catalog-state parsers.
   PHPUnit covers eligible/ineligible block contexts, every paging form,
   invalid values, and canonical round trips.
2. Extract the existing Redis result request into a context-driven service
   returning ordered IDs, total, facets, and effective sort without rendering.
   Keep existing callers working through a compatibility adapter.
3. Register the late Product Collection query adapter, private query marker,
   empty-result guard, and marker-scoped total adjustment. PHPUnit proves an
   unrelated Query Loop cannot inherit IDs or totals.
4. Add block-theme E2E fixtures using an inherited Product Collection and its
   real Query Pagination. Cover search membership, no results, direct page
   links, and browser refresh.

### Phase 2

5. Add the request-scoped result registry and read-only accessors for dynamic
   control renderers. Test isolation across query IDs and fallback states.
6. Add a shared Interactivity API URL/navigation utility with progressive
   link/form fallback, page reset, enhanced-navigation detection, and
   full-reload fallback.
7. Extend the existing block-theme Playwright project with one request/history
   assertion, Back/Forward restoration, `forcePageReload`, and the ownership
   matrix already documented in `AGENTS.md`. Remove the current `test.fail()`
   markers only when their intended assertions begin passing.
8. Document the supported Site Editor template contract. Flip this spec and
   its index row to implemented in the implementation PR.
