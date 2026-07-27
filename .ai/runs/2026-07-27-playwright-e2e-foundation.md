# Execution Plan: Playwright E2E Foundation

Source doc: `.ai/specs/2026-07-18-playwright-e2e-foundation.md`

## Overview

### Goal

Add a Playwright end-to-end layer that exercises the plugin's real runtime (WordPress + WooCommerce + Redis Stack) — ~19 tests covering shopper journeys and every degradation path — provisioned by one idempotent script that targets both CI (GitHub Actions, runner-native) and the LocalWP dev site, wired as a blocking `e2e` job that gates `release`.

### Scope

- `bin/e2e-provision.sh` — idempotent: any WP install → known e2e state (CI + LocalWP).
- `bin/e2e-install-wp.sh` — CI-only: WP + WooCommerce from zero on the runner.
- `playwright.config.ts` at repo root; `tests/e2e/` with helpers, specs, and the degraded project chain.
- npm surface: `@playwright/test` devDependency + 4 scripts.
- `.github/workflows/release.yml`: new `e2e` job; `release` gains `needs: [test, e2e]`.
- `.distignore`, `.gitignore`, `AGENTS.md` updates.

### Non-goals

- No shipped plugin code changes (PHP/JS runtime untouched).
- No admin-settings browser flows, no visual regression, no Firefox/WebKit.
- Playwright is NOT added to `.ai/agentic.config.json` `validation.commands` (explicit spec decision — the gate must stay hermetic; the degraded project mutates the target site).

### External References

None (`--skill-url` not provided). Reference material: the spec itself and Playwright/WooCommerce e2e conventions cited therein.

## Risks

- **Blocking-gate flake**: mitigated per spec — `workers: 1`, `retries: 1` in CI, auto-retrying assertions, raised rate limit, loud provisioning, artifacts on failure. Fallbacks documented (`php -S` + committed router; docker-compose escape hatch).
- **`wp server` availability on the runner** — flagged assumption; verified in Phase 4 with documented fallback.
- **Degraded project mutates the LocalWP site** — restore teardown runs real `setup` (PING self-verifies); recovery documented for aborted runs.
- **Branch protection change** (required status check) affects all open PRs — applied only after the `e2e` job is green on this PR; reported explicitly.
- **Reseeding the dev catalog** — `reset` semantics of the demo generator verified against source before first provisioning run on the LocalWP site.

## Implementation Plan

Phases mirror the spec's Implementation Plan (steps 1–10 therein).

### Phase 1 — Provisioning + local smoke

Write the provisioning script, Playwright scaffolding (config, helpers, npm surface), and the first two smoke tests; verify provisioning idempotency and smoke green against LocalWP.

### Phase 2 — Core journey suite

Complete the 14 real-stack tests: dropdown (#1–#7), results page (#8–#11), category archive (#12), modal (#13–#14). Flake-check: green 3× consecutively locally.

### Phase 3 — Failure modes + degraded environment

Route-mocked failure modes (#15–#17) with payloads mirroring `mu-plugins/endpoint.php` contracts; `degrade-env` / `degraded` / `restore-env` project chain (#18–#19) with a self-verifying restore.

### Phase 4 — CI wiring + release gate

`bin/e2e-install-wp.sh`, the `e2e` job in `release.yml` (mysql + redis-stack services, setup-php 8.3 with phpredis, wp-cli install + provision, `wp server`, Playwright run, failure artifacts), `release: needs: [test, e2e]`, `.distignore` entries, `AGENTS.md` note, required status check registration.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Provisioning + local smoke

- [x] 1.1 Write `bin/e2e-provision.sh` (idempotent; env contract; verification tail) and verify twice against LocalWP — a0518bb
- [x] 1.2 Playwright scaffolding: devDependency, npm scripts, `playwright.config.ts`, `.gitignore` entries, `tests/e2e/helpers/{env,search,mocks}.ts` — 3722199
- [x] 1.3 First two smoke tests (#1 dropdown visible, #7 submit journey) green against LocalWP — 0a10789

### Phase 2: Core journey suite

- [ ] 2.1 Complete `search-dropdown.spec.ts` (#2–#6)
- [ ] 2.2 Write `search-results-page.spec.ts` (#8–#11)
- [ ] 2.3 Write `category-archive.spec.ts` (#12) and `modal.spec.ts` (#13–#14)

### Phase 3: Failure modes + degraded environment

- [ ] 3.1 Write `specs/failure-modes.spec.ts` (#15–#17) with verified mock payloads
- [ ] 3.2 Add degraded project chain (`degrade.setup.ts`, `degraded.spec.ts` #18–#19, `restore.teardown.ts`); full local run leaves LocalWP healthy

### Phase 4: CI wiring + release gate

- [ ] 4.1 Write `bin/e2e-install-wp.sh` and add the `e2e` job to `.github/workflows/release.yml`
- [ ] 4.2 Flip `release` to `needs: [test, e2e]`; `.distignore` entries; `AGENTS.md` note; register required status check after `e2e` is green on the PR
