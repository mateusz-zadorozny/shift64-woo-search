# Execution plan: one-shot isolated worktree test environments (issue #53)

Source doc: .ai/specs/2026-07-31-one-shot-worktree-test-env.md (on spec PR #54, design-only; not committed here)

## Goal

One committed command — `bin/test-env.sh up` — turns a clean worktree into an isolated WordPress + WooCommerce + MySQL + Redis Stack storefront for manual QA **and** a fully provisioned WordPress PHPUnit runtime, with truthful health status, a supervised background validation run, and safe teardown (issue #53, spec PR #54).

## Scope

- New: `bin/test-env.sh` (orchestrator: `up | status | down`), `.ai/scripts/test-env-up.sh` + `.ai/scripts/test-env-down.sh` marker-less wrappers, background validation supervisor + `.ai/qa/validation-status.json`, generated worktree-local `phpunit.xml`, clean-room test `tests/env/test-env-launcher.sh`, dispatch/weekly CI job, `docs/test-environments.md`, AGENTS.md pointer.
- Touched additively only: `bin/e2e-provision.sh` (`SKIP_REDIS_WIRING=1` opt-in), `.gitignore` if needed for run state.
- Untouched: plugin runtime code, `bin/install-wp-tests.sh` / `bin/e2e-install-wp.sh` interfaces (CI keeps calling them directly), the validation gate contents.

## Non-goals

- No native-Windows (PowerShell) flavor — `om-prepare-test-env` generation keeps owning that path.
- No Playwright in the validation gate; no changes to the e2e suite.
- No auto-compilation of phpredis; no changes to the LocalWP flow (`bin/install-phpredis-local.sh`).
- The spec file itself merges via PR #54, never on this branch.

## Risks

- Service provisioning is host-dependent (native `mysqld`/`redis-stack-server` vs Docker); every backend sits behind one function and preflight names what is missing.
- The clean-room test spawns real servers — it runs in a dispatch/scheduled CI job, never in the per-PR gate.
- `vendor/bin/phpunit` in this very worktree needs the environment this PR builds; the launcher itself is used to provision the gate run, and the summary documents exactly what ran.

## Implementation Plan

Phases and steps mirror the spec's `## 📋 Implementation Plan` (4 phases, 13 steps).

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Isolated storefront lifecycle

- [x] 1.1 Scaffold bin/test-env.sh: args, run-id, RUN_DIR layout, lock, atomic descriptor helpers — ec1b10c
- [x] 1.2 Port allocation + preflight checks with named missing-dependency exit 2 — ec1b10c
- [x] 1.3 MySQL backend (native mysqld datadir/port, Docker fallback, ping health) — ec1b10c
- [x] 1.4 Redis Stack backend (native/Docker, PING + FT._LIST health, shared-instance degradation) — ec1b10c
- [x] 1.5 WordPress bring-up via e2e-install-wp.sh + wp server + e2e-provision.sh; descriptor written running only after all probes pass — ec1b10c
- [x] 1.6 Self-healing reuse: full probe set, owned-resource teardown, fresh provision on unhealthy — ec1b10c

### Phase 2: PHPUnit runtime

- [x] 2.1 Provision wordpress-tests-lib + test DB on the run MySQL via install-wp-tests.sh; descriptor phpunit block — ec1b10c
- [x] 2.2 Generate worktree-local phpunit.xml injecting WP_TESTS_DIR; bare vendor/bin/phpunit green — ec1b10c

### Phase 3: Background validation supervisor

- [x] 3.1 Supervisor: validation.commands from agentic config, sequential run, log stream, atomic validation-status.json; up --validate — ec1b10c
- [x] 3.2 status integration: validation state reporting, dead-supervisor aborted detection, exit codes 0/3/4/5 — ec1b10c

### Phase 4: Productization

- [x] 4.1 Marker-less .ai/scripts/test-env-up.sh / test-env-down.sh wrappers with flag mapping — 5963c58
- [x] 4.2 Clean-room integration test tests/env/test-env-launcher.sh + workflow_dispatch/weekly CI job — 5963c58
- [x] 4.3 docs/test-environments.md + AGENTS.md pointer — 5963c58
