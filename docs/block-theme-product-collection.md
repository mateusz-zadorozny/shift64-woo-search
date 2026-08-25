# Block Theme Product Collection Integration

Shift64 supports one inherited WooCommerce Product Collection as the result grid
for a product search, Shop template, or enabled product-taxonomy archive.
RediSearch supplies product membership, totals, facets, and Redis-owned ordering;
WooCommerce renders the Product Template and WordPress/WooCommerce own
pagination, history, loading feedback, and accessibility.

## Supported Site Editor template

Use this structure in each supported archive template:

```text
Archive template
├── Shift64 controls (added by their owning feature specs)
└── WooCommerce Product Collection
    ├── Product Template
    ├── Product Collection No Results
    └── Query Pagination
```

The Product Collection must use its inherited query. Its query metadata must
retain `isProductCollectionBlock: true`, `inherit: true`, and a numeric
`queryId`. Product cards, columns, layout, no-results content, and pagination
composition remain merchant-controlled.

Exactly one inherited Product Collection is supported per archive template.
Multiple eligible collections fail safely and keep request-scoped results
isolated, but global controls cannot identify which collection they should
target.

Standalone Product Collections are deliberately not adapted. Admin, REST,
feed, and unrelated Query Loop requests also retain native WordPress and
WooCommerce queries.

## Query ownership

WooCommerce normally clones the already-executed main query for an inherited
Product Collection. A late `render_block_context` bridge changes only the
collection's query-consuming child contexts to scoped queries. That makes
WooCommerce's public query builder run without changing the saved block or main
archive query. The late `query_loop_block_query_vars` adapter then receives
WooCommerce's completed query variables and changes only fields Shift64 owns:

- `post__in` is constrained to the Redis page of IDs.
- `orderby=post__in` preserves Redis order.
- an empty Redis page becomes `post__in=[0]`.
- a private request marker scopes the Redis total to the exact `WP_Query`.
- `paged` and `offset` reset so WordPress does not paginate a Redis page twice.

Unrelated query variables stay intact. Redis failure returns the original query
variables unchanged, so the Product Collection remains usable through native
WooCommerce retrieval.

The bridge and adapter never render a template, product card, grid, pager,
spinner, or live region. They do not mutate parsed block attributes, the main
query, or WooCommerce loop globals.

They touch `$_GET` in exactly one place, and only on a paged archive URL.
Scoping the collection turns `inherit` off, and a non-inherited Query Loop
reads its current page from `$_GET['query-{queryId}-page']` alone — core offers
no filter for it — so on `/shop/page/2/` the pager would sit under page two's
products still showing page one as current. The page the archive already
resolved is therefore published under that key when it is absent. Every other
reader of it resolves the same number, so the request stays consistent with
itself.

## Canonical URL state

`Shift64_Woo_Search_Catalog_State` is the shared parser and URL builder for
Product Collection and future block-native controls. It validates:

- product searches through `s` plus `post_type=product`;
- `orderby` against known WooCommerce/Shift64 sort slugs;
- `filter_{taxonomy}` values against enabled taxonomies and existing term slugs;
- `query_type_{taxonomy}` as only `and` or `or`;
- `query-{queryId}-page`, `query-page`, `paged`, and `/page/N/`.

All four paging forms are read, but only one is written: pagination links are
emitted as the archive's own permalink (`/shop/page/2/`, carrying the live sort
and facet parameters), the same URL an unscoped collection would produce. A
bookmarked `?query-0-page=2` keeps resolving to the same page.

Changing search, sort, or facets removes every paging form. Page-only navigation
preserves the remaining state. Safe unrelated parameters remain, while nonces
and editor-preview parameters are never copied into storefront navigation.

## Router ownership and progressive fallback

The shared `shift64-woo-search/catalog-navigation` script module publishes
canonical URL helpers for downstream blocks. Controls remain real links or
forms first:

1. If a WooCommerce `wc-product-collection-*` router region exists, the control
   delegates to `@wordpress/interactivity-router`.
2. If `forcePageReload` removes that region, the browser follows the canonical
   URL normally.
3. If the router import or navigation fails, the helper falls back to
   `window.location.assign()`.

Shift64 never replaces the Product Collection router region. Browser Back and
Forward restore server-rendered URL state; there is no parallel client cache.

## Request-scoped result contract

Each successful or fallback execution stores an immutable
`Shift64_Woo_Search_Product_Collection_Result` under a request key derived from
the block/query ID and render instance. Downstream dynamic controls can read:

- page IDs and total matches;
- current page and page size;
- effective sort and selected filters;
- Redis facet buckets;
- `redis`, `native-fallback`, or `invalid-state` status.

The registry is PHP request memory only. It adds no option, database table,
cache namespace, REST route, Store API route, or public JavaScript global.

## Recovery and rollback

If Redis is unavailable, leave the adapter registered: it automatically returns
WooCommerce's native query. To roll the integration back completely, unregister
the adapter and shared control module; stored content and Redis index data need
no migration or cleanup.

For local E2E recovery after a hard-killed theme-projection run, reactivate
the block theme (`wp theme activate twentytwentyfive`) and remove the
force-page-reload MU fixture as documented in `AGENTS.md`.
