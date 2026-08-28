# Handoff — 2026-08-28-block-theme-only-legacy-removal

**Last updated:** 2026-08-28T06:49:12Z
**Branch:** `feat/block-theme-only-legacy-removal`
**PR:** https://github.com/mateusz-zadorozny/shift64-woo-search/pull/100
**Current phase/step:** Phase 3 Step 3.1
**Last commit:** `310c5d9` — test(assets): pin the frontend manifest to block-scoped delivery

## What just happened

- Phase 2 is complete and checkpoint 2 passed over Steps 2.5 … 2.10. The plugin no
  longer contains a classic frontend: no shortcodes, no placement hooks, no
  theme-specific code, no archive fragment swap, no injected filter bar or sort
  control, and no global asset enqueue.
- The running site confirms the asset property directly: the legacy autocomplete
  script and its config payload appear on no page, because every block on the
  fixture is composed and the childless fallback never runs.
- `build/blocks/*` was rebuilt — it had gone stale at Step 2.2 and nothing in the
  PHP gate would have caught it.

## Next concrete action

- Step 3.1: remove the appearance, selector and placement fields from WP Admin
  while leaving their stored values untouched. Nothing on the frontend reads them
  any more as of Step 2.7, so this Step is the admin half of the same change. The
  admin test suites (`test-admin-page-render.php`,
  `test-admin-settings-information-architecture.php`,
  `test-admin-settings-persistence.php`) all assert on these fields and will need
  retargeting onto their absence.

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
