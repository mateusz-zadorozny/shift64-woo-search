# Checkpoint 2 — Steps 2.5 … 2.10 (Phase 2 closed)

**Recorded:** 2026-08-28T06:49:12Z
**Step range:** 2.5 → 2.10 (6 Steps)
**Commit range:** `e92340e` → `310c5d9`

Phase 2 is complete: the classic frontend no longer exists in the plugin.

## Areas touched

- `includes/class-shift64-woo-search-archive.php` — the sort-control takeover, the
  result-count replacement and the pagination-link rewriting removed; the class
  is now interception, Redis search, facet provision, the debug panel and the
  document title, and 352 lines shorter than at the start of the run.
- `frontend/js/shift64-woo-search-ajax-pagination.js` — deleted.
- `frontend/class-shift64-woo-search-frontend.php` — the `wp_enqueue_scripts`
  hook, the configured-selector plumbing and the custom tray width removed; the
  enqueue guard is now state-based rather than a flag on a long-lived instance.
- `frontend/js/shift64-woo-search.js` — theme-wrapper selectors, the configurable
  search-button binding and the custom-width overflow correction removed.
- `frontend/css/shift64-woo-search.css` — 800 lines pruned: the injected filter
  bar, the archive header, the mobile trigger bar, the filter modal, the sort
  bottom sheet, the theme-specific wrappers and the retired
  `--s64ws-dropdown-width` plumbing. The administrator debug bar's mobile hide
  was re-added, having lived inside a deleted media query.
- `build/blocks/*` — rebuilt, because Step 2.2's edit to the catalog-navigation
  module left the committed bundles stale.
- `shift64-woo-search.php` — bootstrap comments corrected to describe what the
  wiring now does.
- `tests/` — `test-removed-frontend-surfaces.php` and
  `test-frontend-asset-manifest.php` added; the display-settings suite retargeted
  from the retired width and selector settings onto their inertness.

## Checks

| Check | Result | Notes |
|-------|--------|-------|
| `composer validate --strict` | ✅ pass | Unchanged manifest. |
| `vendor/bin/phpcs` | ✅ pass | 8/8 files clean. |
| `vendor/bin/phpunit` | ✅ pass | 748 tests / 8468 assertions (baseline 730 / 8385). |
| `npm run validate:block-metadata` | ✅ pass | 7 block metadata files. |
| `wp-scripts test-unit-js` | ✅ pass | 9 suites / 119 tests. |
| `wp-scripts build` | ✅ pass | Committed bundles regenerated and verified free of the removed selector. |
| HTTP smoke, 5 storefront URLs | ✅ pass | `/`, `/?s=shirt&post_type=product`, `/search-e2e/`, `/shop/`, `/product-category/clothing/` — all HTTP 200, no PHP notice in the body. |
| Frontend asset audit | ✅ pass | See below. |

## Asset audit

The point of Steps 2.7 and 2.10 is that assets stop arriving globally, so the
running site was audited rather than only the unit tests.

Every page of the fixture serves `shift64-woo-search.css`,
`shift64-woo-search-catalog-navigation.js` and
`shift64-woo-search-product-sort.js` — and that is correct, not a leftover global
enqueue: this environment's header template carries a Shift64 Search and Modal
Search block, and its archive templates carry Product Filters, Filter Pill and
Product Sort, so each page genuinely renders blocks that declare those assets.
The homepage markup confirms it, carrying eight `wp-block-shift64-woo-search-*`
wrappers.

The decisive evidence is what is now **absent**: `frontend/js/shift64-woo-search.js`
and its `shift64_woo_search_config` payload appear on no page of the site. Every
block on this fixture is composed, so the childless-parent fallback never runs and
its script is never enqueued — which is exactly the "assets enqueue through block
metadata only when their blocks render" property the design record asks for. Under
the old code that script and its config shipped with every request.

## UI verification

Artifacts in `checkpoint-2-artifacts/`:

- `screenshot-search-results.png` — product search results.
- `screenshot-home-header-search.png` — the header search on a non-archive page.
- `screenshot-category-archive.png` — a scoped product-taxonomy archive.

The search results page confirms the change Step 2.5 predicted: the result count
now reads WooCommerce's own "Showing all 4 results" instead of the plugin's
"Products: 4" that checkpoint 1 captured. The sort control still offers "Search
relevance" — that comes from the Product Sort block's own option list, not from
the removed `woocommerce_catalog_orderby` filter, which is what made the removal
safe. Filters, grid and title are unchanged.

## Decisions recorded in this window

- `Shift64_Woo_Search_Frontend::$assets_enqueued` was replaced with a
  `wp_script_is()` check. The plugin keeps one long-lived instance, so an
  instance flag could not notice the handle being dequeued, and two fallback
  blocks on one page would have localized the config twice.
- The administrator debug bar keeps its mobile hide. It lived inside the 768px
  media query that the filter-bar prune deleted, and losing it would have left a
  fixed strip across the bottom of a phone screen.

## Follow-ups

None open. The next Step is 3.1.
