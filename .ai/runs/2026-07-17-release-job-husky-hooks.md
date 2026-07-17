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
- **Merging this fires a real release.** A `0.1.1` patch is pending from the two `fix:`
  commits already on `main`. The first successful run will tag `v0.1.1`, commit version
  bumps, build the ZIP, and publish a GitHub release. That is the intended outcome, but
  it is not reversible in the way a normal merge is — the reviewer must expect it.
- The release path cannot be fully exercised before merge: `semantic-release` only runs
  on push to `main`. Confidence rests on the local reproduction plus the fact that
  `semantic-release` already got as far as the git commit unaided.
- No partial release state exists to collide with — verified: no tags, no releases,
  versions still `0.1.0`, `CHANGELOG.md` untouched.

## Implementation Plan

### Phase 1: Disable git hooks in the Release job

- 1.1 Add `env: HUSKY: 0` to the `release` job in `.github/workflows/release.yml`.

### Phase 2: Verify

- 2.1 Validate the workflow YAML parses and the `env` sits on the `release` job only,
  leaving `test` untouched. Run the repo validation gate.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Disable git hooks in the Release job

- [ ] 1.1 Add HUSKY=0 to the release job environment

### Phase 2: Verify

- [ ] 2.1 Workflow YAML valid, env scoped to release job, gate green
