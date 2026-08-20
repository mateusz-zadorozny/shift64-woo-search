# Cache Playwright browsers and bound the install step so an apt stall can't consume the E2E job timeout

- Date: 2026-08-19
- Category: refactor
- Priority signal: medium — rare, but each occurrence burns a full 20-minute job timeout plus a CI-monitoring cycle, and normalises red CI
- Risk signal: low — CI-only, single file, self-verifying on the PR's own run
- Routing: Next: om-auto-create-pr "Cache Playwright browsers and bound the install step so an apt stall can't consume the E2E job timeout — brief: .ai/specs/briefs/2026-08-19-e2e-playwright-install-tail.md"

## Problem

The `e2e` job in `.github/workflows/release.yml` runs `npx playwright install --with-deps chromium` with no cache and no bound on its duration. On a healthy run that step takes 23s and the whole job is 3m38s (measured on run 32152350460, PR #66) — there is no meaningful wall-clock to reclaim.

The real defect is the tail. In run 32228732727 (job 95993868960) the step logged `Installing dependencies... / Switching to root user... / Get:1 file:/etc/apt/apt-mirrors.txt Mirrorlist` at 07:40:28 and then emitted nothing for 18 minutes until the job's `timeout-minutes: 20` killed it at 07:58:37. It never reached the Chromium download. The hang is entirely inside the `--with-deps` apt phase, on a wedged Azure apt mirror.

Because the step is unbounded, a transient mirror stall is indistinguishable from a real failure and costs the maximum: the whole job budget, a failed required check on an unrelated PR, and a manual re-run.

## Agreed direction

Remove apt from the hot path and put a ceiling on the step:

1. Cache `~/.cache/ms-playwright`, keyed on `runner.os` + `hashFiles('package-lock.json')`.
2. Replace the install command with `npx playwright install chromium` — drop `--with-deps` entirely, relying on `ubuntu-latest` already shipping Chromium's shared libraries.
3. Wrap the install in a bash `timeout 180` plus a 3-attempt retry loop, following the retry idiom already used by the `Start wp server` step in the same job.

Worst case becomes ~9 minutes and a real error instead of a silent 20-minute wedge; a normal run drops ~20s as a side effect.

**Rejected — "build nothing":** lost because the failure mode is silent and expensive. It presents as a timeout with no error message on a PR that touches no PHP, JS, or storefront code, so every occurrence costs a human or agent a full investigation to conclude "infra, re-run it".

**Rejected — cache only:** a cache miss would still stall unbounded, which is the actual defect.

**Rejected — bound only:** correct but leaves apt in the path, so the retry would keep re-entering the exact operation that hangs.

**Rejected — `mcr.microsoft.com/playwright` container:** browsers and deps preinstalled would remove the step outright, but the job also needs PHP 8.3, wp-cli and a running `wp server`, all currently provided by the runner. Restructuring that is disproportionate to the problem.

**Rejected — `install-deps` only on cache miss:** incoherent, see Resolved unknowns.

## Resolved unknowns

| Question | Answer (from the conversation) |
|----------|--------------------------------|
| Is the E2E job slow, or is it stalling? | Stalling. Healthy baseline is 3m38s total / 23s for the install step. There is no speed problem to solve. |
| Where exactly does it hang? | Confirmed from the job log, not inferred: last output is the first apt mirrorlist fetch, then 18 minutes of silence to the timeout. Inside `--with-deps`, before the browser download. |
| Should `install-deps` run only on a cache miss? | No — incoherent. `actions/cache` restores the browser binary to `~/.cache/ms-playwright`, but apt packages are OS-level and never persist to the next runner. A cache hit would yield Chromium with no system libs. Deps are either always needed or never needed; this change bets on never. |
| Is it safe to drop `--with-deps`? | This is the riskiest assumption. `ubuntu-latest` ships Chromium's libs (libnss3, libatk, libgbm, libasound2) in practice, but Playwright does not contract it. Accepted because failure is loud, immediate and unambiguous — the suite dies within seconds of launch with an explicit missing-shared-library error. |
| What if that assumption is wrong? | Add back a `playwright install-deps chromium` step, itself wrapped in the same timeout+retry. Apt returns to the path but can no longer consume the job budget. |
| How is the assumption tested? | The PR's own CI run is the test. No separate verification step is needed. |
| Retry via third-party action or bash? | Bash. `Start wp server` in the same job already uses a bash readiness loop; a `timeout` + attempt loop matches it and avoids adding a supply-chain dependency for ~6 lines. |
| Cache key? | `runner.os` + `hashFiles('package-lock.json')`. Playwright is pinned as `^1.54.0` in package.json, so the lockfile is the accurate version signal; it changes only on a dependency bump. |

## Non-goals

- The `Install SVN (for WP test suite)` apt call in the `test` job. Same class of unbounded-apt risk, different job — deliberately out of scope for this change.
- Shortening the 78s Playwright suite itself.
- Reducing the 33s `Initialize containers` (mysql + redis) time.
- Changing the job's `timeout-minutes: 20` or `ci.maxWaitMinutes: 40`.
- Containerising the E2E job.

## Affected areas (if known)

- `.github/workflows/release.yml` — the `e2e` job, steps `Install Playwright Chromium` and (new) a preceding `actions/cache` step. Single file; no other area established as affected.
