# Checkpoint 1 — Steps 1.1 … 2.4

**Recorded:** 2026-08-28T06:34:36Z
**Step range:** 1.1 → 2.4 (6 Steps)
**Commit range:** `5edd4d2` → `e9c9d10`

## Areas touched

- `docs/block-theme-migration.md` — the surface inventory and option classification.
- `tests/` — characterization tests for preserved surfaces; the shortcode suite retargeted at the block fallback; category-exclusion coverage moved to the block filter path.
- `frontend/class-shift64-woo-search-frontend.php` — shortcode registrations and the two markup builders removed.
- `frontend/class-shift64-woo-search-filters.php` — deleted.
- `frontend/js/shift64-woo-search-catalog-navigation.js` — the plugin's own breadcrumb selector dropped.
- `includes/class-shift64-woo-search-archive.php` — header, title overrides, breadcrumbs shortcode, Kadence partial and hero integration removed.
- `includes/class-shift64-woo-search-blocks.php` — the markup builders moved in as the childless-parent fallback renderers.
- `includes/class-shift64-woo-search-filter-blocks.php` — category exclusion ported over from the deleted renderer.
- `includes/class-shift64-woo-search-taxonomy-archive.php`, `tests/test-posts-clauses.php` — comments degeneralized off a theme brand name.
- `shift64-woo-search.php` — the legacy filter renderer's require and instantiation removed.

## Checks

| Check | Result | Notes |
|-------|--------|-------|
| `composer validate --strict` | ✅ pass | Run at baseline; no manifest change in this window. |
| `vendor/bin/phpcs` | ✅ pass | 8/8 files clean. |
| `vendor/bin/phpunit` | ✅ pass | 744 tests / 8439 assertions. Baseline was 730 / 8385, so the window added 14 tests and removed none. |
| `npm run validate:block-metadata` | ✅ pass | 7 block metadata files validated. |
| `wp-scripts test-unit-js` | ✅ pass | 9 suites / 119 tests. |
| HTTP smoke, 4 storefront URLs | ✅ pass | `/`, `/?s=shirt&post_type=product`, `/search-e2e/`, `/shop/` all HTTP 200 with no PHP fatal, warning or deprecation in the response body. |
| Browser check, block-native search results | ✅ pass | See artifacts. |

## UI verification

The isolated worktree environment (`bin/test-env.sh up`, WordPress 7.1, dedicated
Redis and MySQL) symlinks this worktree as the active plugin, so the screenshots
below are of the code at `e9c9d10`.

The search results page renders entirely from block-native surfaces after the
classic ones were removed: the Search block's field, the Product Filters block
with its Category and Color pills, the Product Sort block, and the Product
Collection grid. Nothing regressed to an unstyled or empty state, and no
plugin-injected filter bar or archive header appears — which is the intended
outcome of Steps 2.2 and 2.3.

One expected leftover is visible and is *not* a defect: the result count still
reads "Products: 4" rather than WooCommerce's own phrasing, because the
`ngettext_woocommerce` replacement is removed in Step 2.5, which had not landed
at this checkpoint.

Artifacts in `checkpoint-1-artifacts/`:

- `screenshot-search-results.png` — product search results, the primary surface.
- `screenshot-shop-archive.png` — the shop archive, to confirm the removed global enqueue path did not take the catalog with it.
- `screenshot-qa-search-page.png` — the QA page carrying the search block in isolation.

## Decisions recorded in this window

- The childless-parent block fallback keeps the markup builders the shortcodes
  used. Removing them with the shortcodes would have broken a surface the spec
  explicitly preserves.
- `pre_get_document_title` is retained. It sets the browser document title, not
  theme output, and removing it would regress the search results page title.
- "Excluded Categories" was only ever applied by the deleted classic renderer.
  Since the spec retains facet settings, the exclusion was ported into the Filter
  Pill option builder rather than silently lost.

## Follow-ups

None open. The next Step is 2.5.
