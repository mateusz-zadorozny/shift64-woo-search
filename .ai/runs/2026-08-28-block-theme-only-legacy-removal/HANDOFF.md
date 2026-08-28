# Handoff — 2026-08-28-block-theme-only-legacy-removal

**Last updated:** 2026-08-28T06:34:36Z
**Branch:** `feat/block-theme-only-legacy-removal`
**PR:** https://github.com/mateusz-zadorozny/shift64-woo-search/pull/100
**Current phase/step:** Phase 2 Step 2.5
**Last commit:** `e9c9d10` — refactor(archive): remove the Kadence partial takeover and theme-specific integration

## What just happened

- Checkpoint 1 passed over Steps 1.1 … 2.4: the full PHP gate, the JS suites, an
  HTTP smoke of four storefront URLs, and browser screenshots of the block-native
  search results page.
- The classic surface removed so far: both search shortcodes, the breadcrumbs
  shortcode, the archive header and title overrides, the injected filter bar with
  its mobile tray, and the Kadence partial-template takeover.
- Two behaviors were deliberately carried over rather than dropped: the childless
  parent block's fallback markup, and the "Excluded Categories" facet setting.

## Next concrete action

- Step 2.5: remove the `woocommerce_catalog_orderby` sort-control takeover and the
  `ngettext_woocommerce` result-count replacement from
  `includes/class-shift64-woo-search-archive.php`. Checkpoint 1's screenshot shows
  the count still reading "Products: 4"; after this Step it should read
  WooCommerce's own phrasing.

## Blockers / open questions

- None.

## Environment caveats

- Dev runtime runnable: yes — `http://127.0.0.1:26641`, WordPress 7.1, symlinked
  to this worktree so the running site always reflects HEAD.
- Browser / UI checks: enabled. `agent-browser` needs `TMPDIR=/tmp`;
  `bin/test-env.sh` needs `TMPDIR=/root/.cache`.
- The repo's binaries lose their exec bit on this server: run `php
  vendor/phpunit/phpunit/phpunit`, `php
  vendor/squizlabs/php_codesniffer/bin/phpcs` and `node
  node_modules/@wordpress/scripts/bin/wp-scripts.js` rather than the `.bin`
  shims.
- Playwright: outside the hermetic validation gate per `AGENTS.md`. Phase 5's E2E
  changes are verified by review and CI, not run locally.
- Database/migration state: clean.

## Worktree

- Path: `.ai/cezar/worktrees/d81edb62-b15a-46e2-9f2a-6083590f9c51`
- Created this run: no — reused the linked cezar worktree.
