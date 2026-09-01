# Final gate — all 25 Steps complete

**Recorded:** 2026-08-28T07:24:06Z
**Commit range:** `5edd4d2` → `afa835c` (30 commits on the branch)
**Diff:** 77 files, +4489 / −3773

## Validation gate (`validation.commands`, in order)

| Command | Result | Notes |
|---------|--------|-------|
| `composer validate --strict` | ✅ pass | `./composer.json is valid`. |
| `vendor/bin/phpcs` | ✅ pass | 8/8 files clean, no errors or warnings. |
| `vendor/bin/phpunit` | ✅ pass | **789 tests / 8467 assertions**, from a 730 / 8385 baseline — 59 tests added, none removed or skipped. |

## Beyond the configured gate

| Check | Result | Notes |
|-------|--------|-------|
| `npm run validate:block-metadata` | ✅ pass | 7 block metadata files. |
| `wp-scripts test-unit-js` | ✅ pass | 9 suites / 119 tests. |
| `wp-scripts lint-js` | ✅ pass | `src/blocks`, `src/interactivity`, the metadata validator. |
| `wp-scripts build` | ✅ pass | Rebuilt; `git status` shows no diff, so the committed bundles match source. |
| `wp i18n make-pot` | ✅ pass | Regenerated as Step 5.4-ds-fix; the committed template had gone stale against the removed and added strings. |
| HTTP smoke, 6 storefront URLs | ✅ pass | All HTTP 200 with no PHP fatal, warning or deprecation in the body. |
| Frontend asset audit | ✅ pass | The legacy autocomplete script and its `shift64_woo_search_config` payload appear on no page. |

## Integration suite

Playwright is deliberately **not** run here. `AGENTS.md` states it outright: the
agentic validation gate must stay hermetic, and the suite's degraded project
really mutates a target site's Redis configuration. `npm run test:e2e` also needs
a provisioned live site, and CI enforces it in the `e2e` job that `release`
depends on, so the suite runs against this branch there rather than locally.

The E2E changes in Phase 5 are consequently **verified by review and CI, not by a
local run**. They are: the `classic-theme` project and its spec file removed, the
project graph's dependency edge repointed from `classic-theme` to `block-theme`,
the shared selector contract stripped of the legacy filter-bar entries, and
comments corrected across four spec files. `playwright.config.ts` parses and its
project graph was inspected directly — `main → block-theme → degrade-env →
degraded`, with `restore-env` as the teardown.

## UI verification

Driven against the worktree's isolated WordPress 7.1 environment, which symlinks
this branch as the active plugin. Artifacts in `final-gate-artifacts/`.

1. **Search results** (`screenshot-search-results.png`) — the page renders
   entirely from block surfaces: Search field, Product Filters pills, Product
   Sort, Product Collection grid, and WooCommerce's own "Showing all 4 results"
   count in place of the removed replacement text.
2. **Filter pill** (`screenshot-filter-pill-open.png`) — the Category pill opens
   with live facet counts. "Uncategorized" is absent, which is the category
   exclusion ported out of the deleted classic renderer in Step 2.3 doing its job.
3. **Filter applied** (`screenshot-filter-applied.png`) — selecting T-Shirts
   narrows 4 results to 2 and produces the canonical URL
   `?filter_product_cat=t-shirts&post_type=product&s=shirt`.
4. **Browser Back** — returns to `?s=shirt&post_type=product` and to "Showing all
   4 results". One click, one history entry, server state restored: the #20
   ownership contract holding with no plugin-owned pagination anywhere.
5. **Modal search** (`screenshot-modal-autocomplete.png`) — opens, queries the
   SHORTINIT endpoint, and renders a product with its SKU, category and brand
   meta line. Entirely through the Interactivity API block path; the legacy
   autocomplete script is not on the page.

## Style pass

The repository's style tooling is `phpcs` (WordPress standard plus
`PHPCompatibilityWP` at `testVersion 8.3-`) and `wp-scripts lint-js`. Both are
green above. There is no separate design-system linter, and no design-token or
component-library layer this change touches — the removed CSS was plain
hand-written rules, and the surviving styling is block supports.

## Deviations from the spec, and why

- **`pre_get_document_title` retained.** The spec removes archive header and
  title *output* surfaces. This filter sets the browser document title, not theme
  output, and the plugin clears the `s` query var — removing it would leave the
  search results page with an empty title.
- **The childless-parent block fallback retained.** The spec preserves
  `shift64-woo-search/search` and `/modal-search`. Their childless form renders
  through the same markup builder the removed shortcodes used, so the builders
  moved onto the blocks class rather than being deleted with the tags.
- **"Excluded Categories" ported rather than dropped.** The spec retains facet
  settings; this one was applied only by the deleted classic renderer, so its
  resolver moved into the Filter Pill option builder.
- **The requirements guard fails open on an unreadable WooCommerce version.** The
  spec asks for an activation/runtime guard; it does not ask for one that
  disables the storefront when it cannot introspect an active installation.
- **`shift64_woo_search_debounce` stays active and UI-exposed.** The spec groups
  it loosely with the frontend settings, but the Search block reads it for its
  Interactivity context, so it is a live autocomplete setting rather than a
  retired appearance one.

Each deviation is recorded in `NOTIFY.md` with its reasoning at the point it was
made.

## Outstanding

Nothing blocking. The `om-auto-review-pr` pass runs next, before the summary
comment and the draft→ready flip.
