# Notify — 2026-08-28-block-theme-only-legacy-removal

> Append-only log. Every entry is UTC-timestamped. Never rewrite prior entries.

## 2026-08-28T06:22:13Z — run started

- Brief: implement `.ai/specs/2026-07-30-block-theme-only-legacy-removal.md` —
  remove the classic-theme frontend surface, raise the runtime baseline, and ship
  the migration guide.
- External skill URLs: none.

## 2026-08-28T06:22:13Z — decision: prerequisite gate cleared

The spec is a release gate that forbids deletion while any of its four
prerequisite specs is still `draft`. All four are `implemented`
(`block-theme-product-collection-integration` #51, `composable-search-blocks` #60,
`product-filter-pill-blocks` #72, `native-woocommerce-catalog-sorting` #73), so
the run proceeds.

## 2026-08-28T06:22:13Z — decision: loop engine selected

The drafted plan has 24 Steps, over the repository's configured
`engine.loopStepThreshold` of 20, so `om-auto-create-pr` handed the run to
`om-auto-create-pr-loop`. `--loop` was not passed; the Step count alone decided.

## 2026-08-28T06:22:13Z — decision: baselines verified against reality

The spec names WordPress 7.0 and WooCommerce 10.9 as the new minimums. Checked
against `api.wordpress.org`: WordPress 7.1 and WooCommerce 11.0.1 are current, so
both minimums are one release behind the current stable and are declarable
without stranding the plugin. No deviation from the spec is needed.

## 2026-08-28T06:34:36Z — checkpoint 1 (Steps 1.1 … 2.4)

Full PHP gate green (phpcs 8/8, phpunit 744/8439 — up from the 730/8385 baseline),
JS suites green (7 block metadata files, 9 suites / 119 tests), four storefront
URLs HTTP 200 with no PHP notice in the body, and browser screenshots confirming
the block-native search results page renders with its Search field, Product
Filters pills, Product Sort control and Product Collection grid intact. Details
and artifacts: `checkpoint-1-checks.md`.

## 2026-08-28T06:34:36Z — decision: keep the childless-parent block fallback

`shift64-woo-search/search` and `/modal-search` are preserved surfaces, and their
childless form renders through the same markup builder the removed shortcodes
used. The builders therefore moved onto the blocks class as fallback renderers
instead of being deleted with the shortcode registrations.

## 2026-08-28T06:34:36Z — decision: retain pre_get_document_title

The spec removes archive header and title *output* surfaces. `pre_get_document_title`
sets the browser document title rather than theme output, and the plugin clears the
`s` query var, so dropping it would leave the search results page with an empty
title. Retained, and recorded in the migration guide's retained-hooks table.

## 2026-08-28T06:34:36Z — decision: port "Excluded Categories" to the block filters

The setting was applied only by the deleted classic filter renderer, while the
spec keeps facet settings. Rather than let a retained, still-UI-exposed option
quietly stop working, its resolver moved into the Filter Pill option builder,
with the selected-term escape hatch preserved so an excluded-but-selected
category stays removable.

## 2026-08-28T06:52:00Z — build artifacts rebuilt at Step 2.8

`build/blocks/*` is committed, and `src/interactivity/search/view.js` imports the
catalog-navigation module from `frontend/js/`, so Step 2.2's edit to that module
left the committed bundles stale. Rebuilt with `wp-scripts build` at this Step
and verified the removed breadcrumb selector is gone from all three view bundles.
Note for anyone resuming: `node_modules/.bin/*` loses its exec bit on this server,
which makes `wp-scripts build` exit 1 with no output at all — `chmod +x
node_modules/.bin/*` first.

## 2026-08-28T06:49:12Z — checkpoint 2 (Steps 2.5 … 2.10), Phase 2 closed

Full PHP gate green (phpcs 8/8, phpunit 748/8468), JS suites green, block bundles
rebuilt, five storefront URLs HTTP 200 with no PHP notice. The asset audit is the
substantive result: the legacy autocomplete script and its `shift64_woo_search_config`
payload are on no page of the running site, because every block on the fixture is
composed and the childless fallback never runs. Screenshots also confirm the result
count moved back to WooCommerce's own phrasing. Details: `checkpoint-2-checks.md`.

## 2026-08-28T06:49:12Z — decision: state-based asset enqueue guard

`Shift64_Woo_Search_Frontend` kept a per-instance `$assets_enqueued` flag, but the
plugin holds one long-lived instance, so the flag could not notice the handle being
dequeued, and two fallback blocks on one page would have localized the config twice.
Replaced with a `wp_script_is( ..., 'enqueued' )` check.

## 2026-08-28T06:55:00Z — decision: PR title retyped from refactor to feat

The repository squash-merges with `squash_merge_commit_title: COMMIT_OR_PR_TITLE`,
so a multi-commit PR's title becomes the commit subject semantic-release analyzes.
`.releaserc.json` runs `@semantic-release/commit-analyzer` with no `releaseRules`
override, i.e. the Angular preset: `feat` minor, `fix`/`perf` patch, a
`BREAKING CHANGE:` footer major, and **`refactor` no release at all**.

The original title `refactor(frontend): remove the classic-theme legacy surface for
block-theme-only ownership` would therefore have merged and shipped nothing, while
the spec requires this to land as the next pre-1.0 minor with a changelog and an
upgrade notice. Retitled to
`feat(frontend): make the block theme the only supported storefront`, which bumps
0.20.2 → 0.21.0.

Deliberately **no** `BREAKING CHANGE:` footer, in any commit subject or body:
semantic-release does not special-case 0.x, so that footer would cut 1.0.0 and
declare the pre-1.0 phase over. The spec's Phasing calls this "the planned pre-1.0
minor". The break is communicated through the changelog upgrade notice, the
migration guide and `BACKWARD_COMPATIBILITY.md` (Steps 4.1–4.3) instead. The squash
body is `COMMIT_MESSAGES`, so every commit subject on this branch is analyzed too —
none may introduce that footer.

## 2026-08-28T07:11:34Z — checkpoint 3 (Steps 3.1 … 3.6), Phase 3 closed

Full PHP gate green (phpcs 8/8, phpunit 787/8461). Browser verification in WP
Admin confirms the upgrade notice renders with its migration-guide link and
Dismiss action, that it is the *only* notice on a supported runtime with a block
theme, and that the legacy `?tab=frontend` bookmark still resolves — now to
Search Experience > Autocomplete, with no selector or tray-width field anywhere.
Details and artifacts: `checkpoint-3-checks.md`.

## 2026-08-28T07:11:34Z — decision: retire the Search Field admin section

Removing the three theme-selector fields would have left a section named after a
search field the plugin no longer renders, holding one unrelated setting. The
autocomplete debounce moved into Autocomplete, the section was dropped from the
route registry, and the legacy `frontend` alias was repointed so no existing
bookmark breaks.

## 2026-08-28T07:11:34Z — decision: the requirements guard fails open on an unknown WooCommerce version

The guard disables block bootstrap only on a version it can positively read as
below the floor. The plugin already returns early when WooCommerce is not active,
so an unreadable version means an active installation the guard cannot
introspect; switching every storefront block off there would be worse than
trusting it. A classic theme is likewise treated as supported — it gets an
informational notice, not a disabled plugin.

## 2026-08-28T07:24:06Z — final gate passed

Every Tasks row is `done`. The configured gate is green — `composer validate
--strict` valid, `phpcs` 8/8 clean, `phpunit` 789 tests / 8467 assertions against
a 730 / 8385 baseline — as are the block metadata validator, the JS unit suites,
`lint-js`, and a `wp-scripts build` that produced no diff against the committed
bundles. Browser verification drove search, a filter pill (opening, applying,
and browser Back), and the modal autocomplete end to end. Details, deviations and
artifacts: `final-gate-checks.md`.

Playwright was not run locally: `AGENTS.md` requires the agentic gate to stay
hermetic and the degraded project really mutates a target site's Redis config.
Phase 5's E2E changes are verified by review and by the CI `e2e` job that
`release` depends on. Recorded here rather than left implicit.

## 2026-08-28T07:24:06Z — Step 5.4-ds-fix appended at the final gate

`languages/shift64-woo-search.pot` is a generated artifact committed to the repo
and had gone stale: this release removed a large number of user-facing strings
(the filter bar, the mobile tray, the retired settings fields) and added a few
(the runtime and upgrade notices). Regenerated with `wp i18n make-pot`.
