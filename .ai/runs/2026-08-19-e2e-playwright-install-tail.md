# Execution plan — Cache Playwright browsers and bound the E2E install step

- Date: 2026-08-19
- Slug: `e2e-playwright-install-tail`
- Branch: `fix/e2e-playwright-install-tail`
- Brief: `.ai/specs/briefs/2026-08-19-e2e-playwright-install-tail.md`
- Engine: om-auto-create-pr (steps: 3, --loop: no)

## Goal

Stop a transient Ubuntu apt-mirror stall inside `npx playwright install --with-deps chromium`
from consuming the entire 20-minute `e2e` job budget in `.github/workflows/release.yml`.

## Scope

One file: `.github/workflows/release.yml`, `e2e` job, the `Install Playwright Chromium` step
and a new `actions/cache` step directly before it.

### Evidence this is worth doing

Measured from the E2E job of run `32152350460` (PR #66, all green) — the healthy baseline:

| Step | Duration |
|------|----------|
| Initialize containers (mysql + redis) | 33s |
| Install WordPress + WooCommerce + provision | 34s |
| npm ci | 24s |
| Install Playwright Chromium | 23s |
| Run Playwright suite | 78s |
| **Whole job** | **3m38s** |

There is no wall-clock problem to solve. The defect is the tail. In run `32228732727`
(job `95993868960`) the same step logged:

```text
07:40:27  ##[group]Run npx playwright install --with-deps chromium
07:40:28  Installing dependencies...
07:40:28  Switching to root user to install dependencies...
07:40:28  Get:1 file:/etc/apt/apt-mirrors.txt Mirrorlist [144 B]
          ← 18 minutes of no output whatsoever →
07:58:37  Terminate orphan process: pid (4187) (npm exec playwright install --with-deps chromium)
```

The step never reached the Chromium download; it wedged on the first apt fetch of the
`--with-deps` phase and was killed by `timeout-minutes: 20`. Because the step is unbounded,
a transient mirror stall is indistinguishable from a real failure and costs the maximum:
the whole job budget, a red required check on an unrelated PR, and a manual re-run.

## Implementation Plan

### Phase 1 — Bound and cache the Playwright browser install

1. **Cache the browser binary.** Add an `actions/cache@v5` step (matching the version the
   `test` job already uses for the WordPress test suite) for `~/.cache/ms-playwright`, keyed
   on `${{ runner.os }}-playwright-${{ hashFiles('package-lock.json') }}`. `@playwright/test`
   is declared as `^1.54.0`, so the lockfile — not `package.json` — carries the resolved
   version and is the accurate cache-key signal.
2. **Remove apt from the hot path and bound the step.** Replace
   `npx playwright install --with-deps chromium` with `npx playwright install chromium`
   wrapped in a `timeout 180` + 3-attempt bash retry loop, following the readiness-loop idiom
   the same job already uses in `Start wp server` rather than adding a third-party retry
   action. Carry an explanatory comment in the repo's established style (see the
   `Ensure wp server command` step and the `bin/e2e-install-wp.sh` header) recording *why*
   `--with-deps` is gone, so a future contributor does not "restore" it.

### Phase 2 — Validation

3. **Run the full validation gate** (`composer validate --strict`, `vendor/bin/phpcs`,
   `vendor/bin/phpunit`) plus a YAML parse check of the edited workflow, and re-read the diff
   for scope creep.

## Risks

- **Dropping `--with-deps` assumes `ubuntu-latest` already ships Chromium's shared libraries**
  (`libnss3`, `libatk*`, `libgbm1`, `libasound2`). True in practice on GitHub's Ubuntu images,
  but Playwright does not contract it. Accepted because the failure mode is loud, immediate and
  unambiguous: the suite dies within seconds of browser launch with an explicit missing-shared-
  library error, on this PR's own `e2e` run. **Fallback if it fires:** add back a
  `npx playwright install-deps chromium` step wrapped in the same `timeout` + retry, so apt
  returns to the path but can never again consume the job budget.
- **A cache hit does not restore apt packages.** Deliberate, and the reason the deps install is
  removed outright rather than made conditional on a cache miss: `actions/cache` restores the
  browser binary to `~/.cache/ms-playwright`, but apt packages are OS-level and never survive to
  the next runner. A "deps only on cache miss" design would hand a cache hit a browser with no
  system libraries. Deps are either always needed or never needed; this change bets on never.
- **Worst case after the change** is 3 attempts × 180s ≈ 9 minutes ending in a real, readable
  error, instead of a silent 20-minute wedge — still inside the job's `timeout-minutes: 20`.
- **`.ai/specs/2026-07-18-playwright-e2e-foundation.md` mentions the old command** in its
  historical pipeline sketch and Phase 4 implementation step. It is a completed spec
  (`Status: implemented — PR #14`) describing what that PR shipped, so it is deliberately left
  unedited: rewriting a landed spec's implementation record would falsify history, and the spec
  lifecycle rule in `AGENTS.md` governs Status flips, not retroactive edits to shipped plans.

## Non-goals

- The `Install SVN (for WP test suite)` apt call in the `test` job — same class of unbounded-apt
  risk, different job, deliberately out of scope.
- Shortening the 78s Playwright suite itself.
- Reducing the 33s `Initialize containers` time.
- Changing the `e2e` job's `timeout-minutes: 20` or the config's `ci.maxWaitMinutes: 40`.
- Containerising the `e2e` job (`mcr.microsoft.com/playwright`) — considered in the brief and
  rejected: the job also needs PHP 8.3, wp-cli and a live `wp server`, all runner-provided today.
- Adding Playwright to the agentic validation gate — forbidden by `AGENTS.md`.

## Validation results

Ran against the worktree's real isolated environment provisioned by `bin/test-env.sh up`
(dedicated MariaDB on `127.0.0.1:63119`, dedicated RediSearch-capable Redis on `:63120`, and the
real WordPress PHPUnit library) — not a stub or scratchpad harness:

| Command | Result |
|---------|--------|
| `composer validate --strict` | ✅ `./composer.json is valid` |
| `vendor/bin/phpcs` | ✅ 8/8 files clean |
| `vendor/bin/phpunit` | ✅ OK — 560 tests, 7903 assertions |

The PHP gate cannot exercise this change (the diff is GitHub Actions YAML and contains no PHP);
it was run because the gate is unconditional, and it confirms the change breaks nothing else.
The change itself is verified by the workflow it edits, on this PR's own `e2e` job, plus:

| Check | Result |
|-------|--------|
| Workflow YAML parses; `e2e` step order correct | ✅ `Cache Playwright browsers` sits directly before `Install Playwright Chromium` |
| `bash -n` on the extracted `run:` block | ✅ syntactically valid |
| Retry-loop success path simulated under `bash -e` | ✅ exits 0 on first attempt, no further attempts |
| Retry-loop exhaustion path simulated under `bash -e` | ✅ three attempts, then exit 1 with the diagnostic on stderr |

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Bound and cache the Playwright browser install

- [x] 1.1 Cache the browser binary — 6175e7a
- [x] 1.2 Remove apt from the hot path and bound the step — 6175e7a

### Phase 2: Validation

- [x] 2.1 Run the full validation gate — see "Validation results" below
