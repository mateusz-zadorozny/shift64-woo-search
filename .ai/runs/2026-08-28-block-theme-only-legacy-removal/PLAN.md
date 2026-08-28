# Execution plan — Block Theme-Only Legacy Surface Removal

**Slug:** `block-theme-only-legacy-removal`
**Branch:** `feat/block-theme-only-legacy-removal`
**Base:** `main`
**Engine:** om-auto-create-pr-loop (steps: 24, --loop: no)
**Source spec:** `.ai/specs/2026-07-30-block-theme-only-legacy-removal.md`

## Tasks

> Authoritative status table. `Status` is one of `todo` or `done`. On landing a Step, flip `Status` to `done` and fill the `Commit` column with the short SHA. The first row whose `Status` is not `done` is the resume point for `om-auto-continue-pr-loop`. Step ids and `Exec` cells are immutable once the plan is committed — per-Step commits touch only `Status` and `Commit`.

| Phase | Step | Title | Exec | Status | Commit |
|-------|------|-------|------|--------|--------|
| 1 | 1.1 | Publish the legacy-surface inventory and option classification table | inline | done | 5edd4d2 |
| 1 | 1.2 | Add characterization tests for every preserved surface | inline | done | f0fec52 |
| 2 | 2.1 | Remove the search and modal-search shortcodes, keeping the block fallback renderers | inline | done | 2183e3e |
| 2 | 2.2 | Remove the breadcrumbs shortcode and the archive header/title output surfaces | inline | done | 915929c |
| 2 | 2.3 | Remove the automatic filter-bar placement and the legacy filter renderer | inline | done | 7232bcc |
| 2 | 2.4 | Remove the Kadence partial-template takeover and theme-specific integration | inline | done | e9c9d10 |
| 2 | 2.5 | Remove the catalog sort-control takeover and result-count text replacement | inline | done | e92340e |
| 2 | 2.6 | Remove the AJAX fragment/pagination interception and its script | inline | done | 3cea974 |
| 2 | 2.7 | Scope the legacy autocomplete script to the block fallback and drop the global enqueue | inline | done | 648bff8 |
| 2 | 2.8 | Prune the removed archive, filter and theme-specific rules from the stylesheet | inline | done | ffbb008 |
| 2 | 2.9 | Refactor the bootstrap and archive class down to query adaptation and engine hooks | inline | done | 7a5775c |
| 2 | 2.10 | Add the frontend asset manifest test proving no global legacy assets load | inline | done | 310c5d9 |
| 3 | 3.1 | Remove the appearance, selector and placement admin fields, leaving values inert | inline | done | 0496ba7 |
| 3 | 3.2 | Lock the generated SHORTINIT config to engine constants with a regeneration test | inline | done | pending |
| 3 | 3.3 | Raise the WordPress, WooCommerce and PHP baselines everywhere they are declared | inline | todo | — |
| 3 | 3.4 | Add the runtime baseline guard, admin notice and non-zero CLI error | inline | todo | — |
| 3 | 3.5 | Add the admin-only legacy shortcode occurrence detector | inline | todo | — |
| 3 | 3.6 | Add the dismissible per-user upgrade notice linking the migration guide | inline | todo | — |
| 4 | 4.1 | Publish the Site Editor migration guide with the Product Collection pattern | inline | todo | — |
| 4 | 4.2 | Convert BACKWARD_COMPATIBILITY.md promises into a dated migration record | inline | todo | — |
| 4 | 4.3 | Update README.md, readme.txt and the changelog for the breaking release | inline | todo | — |
| 5 | 5.1 | Drop the pagination-ownership `test.fail()` markers now the plugin defers | inline | todo | — |
| 5 | 5.2 | Remove the classic-theme Playwright project and its AJAX-swap journeys | inline | todo | — |
| 5 | 5.3 | Retarget the remaining E2E specs at the block-native surfaces | inline | todo | — |
| 5 | 5.4 | Flip the spec status header and the specs index row | inline | todo | — |

## Goal

Remove the pre-1.0 classic-theme frontend surface — placement hooks, shortcodes,
theme-specific takeovers, bespoke archive fragments and the admin appearance
controls that configured them — so the plugin owns exactly one frontend: block
theme templates driven by an inherited WooCommerce Product Collection plus the
plugin's own Site Editor blocks. Raise the declared runtime baseline to the
versions the block and Interactivity APIs actually require, and ship an explicit
migration guide with the breaking release.

## Scope

- `frontend/` — the shortcode renderers, the legacy filter-bar renderer, the
  global asset enqueue, the AJAX pagination script, and the stylesheet rules that
  only served removed markup.
- `includes/class-shift64-woo-search-archive.php` — the theme output surfaces
  (header, title, sort control, result count, Kadence partial, breadcrumbs
  shortcode, pagination link rewriting); the search execution and facet provider
  stay.
- `shift64-woo-search.php` — bootstrap wiring, runtime declarations, activation
  and upgrade guards.
- `admin/` — appearance, selector and placement fields; the upgrade notice and
  legacy-shortcode detector.
- `docs/`, `README.md`, `readme.txt`, `BACKWARD_COMPATIBILITY.md`, `CHANGELOG.md`
  — migration guide, dated break record, release communication.
- `tests/` — characterization tests for preserved surfaces, an asset manifest
  test, and the Playwright projects that encoded plugin-owned classic journeys.

## Non-goals

- Deleting or renaming any option row. Removed UI leaves its stored value inert
  for one pre-1.0 release so a version rollback still finds its settings.
- Touching the Redis schema, index naming, indexer, CLI commands, SHORTINIT
  endpoint response, relevance tuning, facet configuration, or sorting engine.
- Renaming `shift64-woo-search/search` or `shift64-woo-search/modal-search`, or
  migrating their children — that belongs to the composable-search spec, already
  implemented.
- Editing a merchant's templates. Migration is explicit in the Site Editor; the
  plugin never rewrites template content and never auto-inserts a product loop.
- Removing engine-side WordPress/WooCommerce filters. "No hooks" in the spec
  means no theme placement or markup-takeover hooks, not no hooks at all.

## Implementation Plan

### Phase 1 — Inventory and characterization

**1.1 Publish the legacy-surface inventory and option classification table.**
Generate the inventory the spec requires before any deletion — registered
shortcodes, frontend actions and filters, block names, script and style handles,
settings and options, generated SHORTINIT constants, and theme-named code paths —
and commit it as the migration guide's classification table so no hidden alias
survives the cleanup unnoticed. Every option key is classified as retained and
active, retained but no longer UI-exposed, inert for rollback, or never public.

**1.2 Add characterization tests for every preserved surface.** Lock the
surfaces the spec promises to keep before removing anything around them: the two
parent block names and their childless fallback render, the CLI command
registration, the SHORTINIT endpoint response shape, the Redis key and index
naming, and the active engine option keys. These tests are what prove the
cleanup did not take a preserved surface with it.

### Phase 2 — Stop legacy placement and remove competing runtime code

**2.1 Remove the search and modal-search shortcodes, keeping the block fallback
renderers.** Drop `add_shortcode( 'shift64_woo_search' )` and
`add_shortcode( 'shift64_woo_search_modal' )`. The markup builders survive as the
childless-parent fallback for the preserved block names — they move onto the
blocks class under non-shortcode names, so the block contract is unchanged while
the shortcode tags stop existing.

**2.2 Remove the breadcrumbs shortcode and the archive header/title output
surfaces.** Drop `[shift64_woo_search_breadcrumbs]`, the
`woocommerce_archive_description` header action, the `woocommerce_show_page_title`
override and the `get_the_archive_title` filter that fed Kadence dynamic
headings. `pre_get_document_title` stays: the browser document title is not theme
output takeover, and losing it would regress the search results page title.

**2.3 Remove the automatic filter-bar placement and the legacy filter renderer.**
Delete `frontend/class-shift64-woo-search-filters.php` whole — its
`woocommerce_before_shop_loop` insertion, its pill markup, and the mobile
filter/sort tray it generated. The Product Filters and Filter Pill blocks own
this surface now.

**2.4 Remove the Kadence partial-template takeover and theme-specific
integration.** Drop `maybe_render_partial` and its `template_include` filter,
`disable_kadence_hero_on_search`, and the `.kadence-*` / `.kwt-*` coupling in the
render path. No theme is detected, named, or special-cased anywhere in the
plugin after this Step.

**2.5 Remove the catalog sort-control takeover and result-count text
replacement.** Drop the `woocommerce_catalog_orderby` filter that hid and
replaced the theme's sort control and the `ngettext_woocommerce` filters that
rewrote WooCommerce's result count. The Product Sort block offers sorting, and
Product Collection owns its own count.

**2.6 Remove the AJAX fragment/pagination interception and its script.** Delete
`frontend/js/shift64-woo-search-ajax-pagination.js` and the
`preserve_filter_params_in_pagination` link rewriting. Pagination ownership
returns to Product Collection's router and to plain browser navigation, which is
the matrix decided in #20.

**2.7 Scope the legacy autocomplete script to the block fallback and drop the
global enqueue.** `wp_enqueue_scripts` stops firing on every frontend request.
The stylesheet already loads through block metadata; the fallback script enqueues
only when a childless parent block actually renders, and it targets its own
markup rather than the configured theme selectors.

**2.8 Prune the removed archive, filter and theme-specific rules from the
stylesheet.** Strip the rules that only styled markup deleted in 2.2–2.6 so the
shared block stylesheet stops shipping dead CSS to every page that renders a
search block.

**2.9 Refactor the bootstrap and archive class down to query adaptation and
engine hooks.** Remove the legacy filter renderer from the require list and the
bootstrap wiring, and leave the archive class with interception, search
execution, facet provision and the debug surface. Tests assert no Woo template
renderer and no parallel product loop is reachable from a render callback.

**2.10 Add the frontend asset manifest test proving no global legacy assets
load.** Assert that a product page, a product archive and an unrelated page
enqueue none of the removed handles, and that the shared style arrives only
through a rendered block.

### Phase 3 — Admin, options and runtime baseline

**3.1 Remove the appearance, selector and placement admin fields, leaving values
inert.** Take the theme input/button/additional-selector fields and the
appearance controls replaced by block supports out of WP Admin and out of the
save allowlist. Stored values are left untouched so a rollback finds them, and
the classification table records each one.

**3.2 Lock the generated SHORTINIT config to engine constants with a
regeneration test.** Assert the generated `config.php` exports only constants a
SHORTINIT consumer reads, that no removed appearance or selector key reaches it,
and that setup/update regenerates it through the existing deployment path.

**3.3 Raise the WordPress, WooCommerce and PHP baselines everywhere they are
declared.** WordPress 7.0, WooCommerce 10.9 and PHP 8.3, consistently across the
plugin header, `readme.txt`, `README.md`, `BACKWARD_COMPATIBILITY.md`, the
Composer constraint and platform config, and the CI matrix. The release must not
claim support for a version CI does not exercise.

**3.4 Add the runtime baseline guard, admin notice and non-zero CLI error.**
Below the baseline, block bootstrap stops with an actionable admin notice instead
of fatalling, and the CLI returns a non-zero explanatory error. A classic theme
keeps a working search endpoint and CLI; it simply gets no injected controls.

**3.5 Add the admin-only legacy shortcode occurrence detector.** Report where the
removed shortcode tags still appear in content so merchants can find them, for
one release, without ever rendering them and without touching the public
frontend.

**3.6 Add the dismissible per-user upgrade notice linking the migration guide.**
Shown only to users who can manage the plugin, dismissible per user, pointing at
the migration guide from 4.1.

### Phase 4 — Documentation and release record

**4.1 Publish the Site Editor migration guide with the Product Collection
pattern.** The nine-step template migration, the copyable inherited Product
Collection pattern, rollback steps including template backup, and the page
cache/CDN purge warning.

**4.2 Convert `BACKWARD_COMPATIBILITY.md` promises into a dated migration
record.** The protected-surface entries for shortcodes and removed handles become
a dated record of an intentional pre-1.0 break, rather than silently disappearing.

**4.3 Update `README.md`, `readme.txt` and the changelog for the breaking
release.** The upgrade notice leads with the breaking storefront change and links
the migration guide.

### Phase 5 — Release verification

**5.1 Drop the pagination-ownership `test.fail()` markers now the plugin
defers.** `AGENTS.md` records that these assertions encode the #20 ownership
matrix and start passing exactly when the interception is removed. This Step
removes the markers, never relaxes the assertions.

**5.2 Remove the classic-theme Playwright project and its AJAX-swap journeys.**
The plugin-owned classic swap no longer exists, so the project that switched the
site to a classic theme to exercise it is removed with it.

**5.3 Retarget the remaining E2E specs at the block-native surfaces.** Specs that
drove the shortcode form, the injected filter bar or the replaced sort control
move to the block equivalents or are dropped where the block projects already
cover them.

**5.4 Flip the spec status header and the specs index row.** Per `AGENTS.md`, the
implementing PR flips `.ai/specs/2026-07-30-block-theme-only-legacy-removal.md`
from `draft` to `implemented` and updates the matching `.ai/specs/README.md` row.

## Risks

- **Breaking by design.** Merchants must edit templates; the changelog and
  upgrade notice carry that, and 4.1–4.3 are as load-bearing as the code Steps.
- **Hidden coupling.** A theme-specific selector or asset handle surviving in one
  forgotten place would leave the two-frontend problem half-solved. Step 1.1's
  inventory and Step 2.10's manifest test exist to make that observable rather
  than assumed.
- **Preserved-surface regression.** The childless parent block fallback is the
  subtlest thing in scope: it renders through the same builders the shortcodes
  used. Step 1.2 characterizes it before Step 2.1 moves it.
- **Baseline exclusion.** Raising the declared minimums excludes existing sites.
  Step 3.4's guard makes that a notice rather than a fatal, and Step 3.3 keeps
  the declarations and the CI matrix in agreement.
- **Playwright stays outside the gate.** Per `AGENTS.md` the hermetic validation
  gate must not run Playwright, so Phase 5's suites are verified by review and CI
  rather than locally in this run — called out in the summary rather than
  silently skipped.

## External References

None — no `--skill-url` was passed.
