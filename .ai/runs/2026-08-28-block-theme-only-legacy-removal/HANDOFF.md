# Handoff — 2026-08-28-block-theme-only-legacy-removal

**Last updated:** 2026-08-28T07:11:34Z
**Branch:** `feat/block-theme-only-legacy-removal`
**PR:** https://github.com/mateusz-zadorozny/shift64-woo-search/pull/100
**Current phase/step:** Phase 4 Step 4.1
**Last commit:** `b52ac23` — feat(admin): announce the block-only storefront with a dismissible upgrade notice

## What just happened

- Phase 3 is complete and checkpoint 3 passed over Steps 3.1 … 3.6. WP Admin no
  longer offers a setting the frontend does not read; the generated SHORTINIT
  config is pinned to engine constants; WordPress 7.0 and WooCommerce 10.9 are
  declared everywhere and one CI job runs against the floor; a runtime guard,
  a leftover-shortcode detector and a dismissible upgrade notice are in place.
- The PR title was retyped from `refactor(...)` to `feat(...)`: the repo
  squash-merges with the PR title as the commit subject, and the Angular preset
  semantic-release uses treats `refactor` as no release. No commit carries a
  `BREAKING CHANGE:` footer, which would cut 1.0.0 rather than the intended
  pre-1.0 minor.

## Next concrete action

- Step 4.1: finish `docs/block-theme-migration.md`. The surface inventory and
  option classification landed in Step 1.1; what is still missing is the
  merchant-facing half — the nine-step Site Editor migration, the copyable
  inherited Product Collection pattern, rollback steps including template backup,
  and the page cache/CDN purge warning. The file already ends with a link to an
  "Upgrading a store to the block-only frontend" anchor that does not exist yet.

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
