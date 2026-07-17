# Fix: the Release job has never succeeded — husky hooks run in CI

## Goal

Make the `Release` job in `.github/workflows/release.yml` able to complete, by stopping
git hooks from running in CI. Unblocks the first-ever release of this plugin.

## Context

The `Release` job has **never** succeeded. It failed identically on:

| Commit | Date | What it was |
|---|---|---|
| `021f429` | 2026-07-16 | merge of PR #2 (a `fix:` commit) |
| `169d16e` | 2026-07-17 | merge of PR #4 (a `fix:` commit) |

Evidence that nothing has ever shipped: no git tags on the remote, no GitHub releases,
and the plugin header, `readme.txt`, and `package.json` all still read `0.1.0` — despite
two `fix:` commits that each should have produced `0.1.1`.

The failure was hidden until now. `Release` declares `needs: test`, and the test job was
red (PHP 8.5, fixed in #4), so `Release` reported `skipped` rather than `failure`. Fixing
the tests let the job actually run, and it failed — one bug was masking another.

### Root cause

From the job log, in order:

```
✖ sh -c 'vendor/bin/phpcs "$@"' --:
--: 1: vendor/bin/phpcs: not found
husky - pre-commit script failed (code 1)
  pluginName: '@semantic-release/git'
```

The chain:

1. The `Release` job runs `npm ci` — and **never `composer install`**. So `vendor/` does
   not exist in that job.
2. `npm ci` triggers `package.json`'s `"prepare": "husky"`, which installs the husky
   runtime shim into `.husky/_/` and sets `core.hooksPath=.husky/_`.
3. `semantic-release` runs correctly and decides **patch release** (0.1.1). It generates
   the changelog and builds the ZIP without complaint.
4. `@semantic-release/git` then commits the version bump. That commit fires the
   `pre-commit` hook → `npx lint-staged` → the `"*.php"` rule → `vendor/bin/phpcs`.
5. `vendor/bin/phpcs` does not exist. The hook exits non-zero, the commit fails, and
   `semantic-release` aborts inside `@semantic-release/git`.

The release pipeline is coupled to a PHP dev toolchain it never installs.

## Fix

Set `HUSKY=0` in the `Release` **job's** environment.

Verified in the installed husky 9.1.7 shim rather than assumed — `.husky/_/h` line 14:

```sh
[ "${HUSKY-}" = "0" ] && exit 0
```

The shim exits before running the hook body. (Line 18's `echo "husky - $n script failed
(code $c)"` is also exactly the message in the CI log, which confirms this shim is the
code path that failed.) `HUSKY=0` additionally makes the `prepare` script skip installing
hooks at all, so the fix holds at both install and run time.

Reproduced and verified locally in a worktree with `node_modules` but no `vendor/` — the
same shape as the Release job:

| Attempt | Result |
|---|---|
| commit a `.php` change without `HUSKY=0` | blocked; `vendor/bin/phpcs` FAILED |
| same commit with `HUSKY=0` | succeeded |

### Why disable hooks rather than install Composer

- The commit `@semantic-release/git` makes is **machine-generated** from content that was
  already validated. Linting it proves nothing a human could act on.
- `phpcs` already ran and passed in the `test` job, which `Release` depends on via
  `needs: test`. Re-running it inside the release commit is redundant work guarding an
  already-guarded artifact.
- Adding `composer install` would cost ~30s per release to re-run a check that already
  passed, and would keep the release path coupled to the PHP dev toolchain — the exact
  coupling that broke it.

Git hooks are a local developer convenience. CI is not a developer.

### Job-level, not workflow-level

`HUSKY=0` goes on the `release` job only, not on the workflow. The `test` job must keep
its normal environment; nothing there should change as a side effect of this fix.

## Scope

One file, one addition: an `env:` block on the `release` job in
`.github/workflows/release.yml`.

## Non-goals

- **Raising the declared PHP minimum / touching `composer.lock`** — tracked in issue #5.
- **Changing the CI test matrix** — out of scope; also part of the #5 discussion.
- **Changing `.husky/pre-commit` or the `lint-staged` config** — they are correct for
  local development, which is what they are for.
- **Reconsidering whether `fix(tests):` should have triggered a release at all** —
  a real question (semantic-release keys off type, not scope), but a separate one.

## Risks

- **Medium.** This touches the release pipeline. A wrong change here publishes a bad
  release rather than merely failing a check.
- The release path cannot be fully exercised before merge: `semantic-release` only runs
  on push to `main`. Confidence rests on the local reproduction plus the fact that
  `semantic-release` already got as far as the git commit unaided.
- No partial release state exists to collide with — verified: no tags, no releases,
  versions still `0.1.0`, `CHANGELOG.md` untouched.

### BLOCKER: merging this publishes 1.0.0, not 0.1.1

**An earlier draft of this plan said a `0.1.1` patch was pending. That is wrong, and the
error is the reason this PR must not merge as-is.**

The actual CI log from run `29582568658` — the same log that diagnosed the husky failure:

```
[semantic-release] › ℹ  No git tag version found on branch main
[@semantic-release/commit-analyzer] › ℹ  Analysis of 6 commits complete: patch release
[semantic-release] › ℹ  There is no previous release, the next release version is 1.0.0
...
Command failed with exit code 1: git commit -m chore(release): 1.0.0 [skip ci]
```

The mechanism, in `node_modules/semantic-release/lib/get-next-version.js`:

```js
} else {
  version = branch.type === "prerelease" ? `${FIRST_RELEASE}-…` : FIRST_RELEASE;  // "1.0.0"
  logger.log(`There is no previous release, the next release version is ${version}`);
}
```

When `lastRelease.version` is empty, the computed `patch` type is **discarded**.
`FIRST_RELEASE` is hardcoded to `1.0.0` (`lib/definitions/constants.js:3`). There are no
tags on the remote, so `lastRelease` is empty. `fix:` commits do **not** yield `0.1.1` on
a repository that has never released; the first release is always `1.0.0`.

This collides head-on with the project's own governing documents:

- `CONTRIBUTING.md`: "The `0.x` line is for product validation. `1.0.0` marks the stable
  public contract for configuration, hooks, storage keys, CLI commands, blocks,
  shortcodes, and migrations."
- `BACKWARD_COMPATIBILITY.md`: "The plugin is at `0.1.0`, so breaking changes are
  permitted… From `1.0.0` on, every rule below hardens into a major-version requirement."

So merging would silently promote a deliberately pre-1.0 plugin to a stable public
contract: tag `v1.0.0`, `Stable tag: 1.0.0` in `readme.txt`,
`SHIFT64_WOO_SEARCH_VERSION = 1.0.0`, a `1.0.0` CHANGELOG entry, and a published GitHub
release. Irreversibly, as a side effect of a CI fix.

**This is a decision for the maintainer, not for this PR.** Two coherent paths:

1. **Ship 0.1.1 (keeps the stated 0.x intent).** Before merging, push a `v0.1.0` tag on
   `c821b5b` ("chore: initialize Shift64 Woo Search 0.1.0", reachable from `origin/main`).
   `.releaserc.json` sets no `tagFormat`, so the default `v${version}` matches.
   `semantic-release` then finds `lastRelease` = 0.1.0, applies the `patch` it already
   computes, and ships `0.1.1`.
2. **Accept 1.0.0** — only as a conscious decision to declare the public contract stable,
   which contradicts CONTRIBUTING.md as currently written and would mean updating it.

The `HUSKY: 0` change itself is correct and independently reviewed as sound. It is the
release semantics behind it that are not ready.

## Implementation Plan

### Phase 1: Disable git hooks in the Release job

- 1.1 Add `env: HUSKY: 0` to the `release` job in `.github/workflows/release.yml`.

### Phase 2: Verify

- 2.1 Validate the workflow YAML parses and the `env` sits on the `release` job only,
  leaving `test` untouched. Run the repo validation gate.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Disable git hooks in the Release job

- [x] 1.1 Add HUSKY=0 to the release job environment — 2853e20

### Phase 2: Verify

- [x] 2.1 Workflow YAML valid, env scoped to release job, gate green — 2853e20
