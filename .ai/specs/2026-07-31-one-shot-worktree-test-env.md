# One-Shot Isolated Worktree Test Environments

> **Status:** implemented — PR #56, 2026-07-31

## 📝 TLDR

One committed command — `bin/test-env.sh up` — turns any clean checkout or PR worktree of this plugin into a ready, error-free test environment: an isolated WordPress + WooCommerce + MySQL + Redis Stack storefront for manual QA **and** a fully provisioned WordPress PHPUnit runtime, so `vendor/bin/phpunit` and the whole agentic validation gate run immediately with no manual `WP_TESTS_DIR` exports. The launcher is idempotent and self-healing (a `running` descriptor is trusted only after real health checks), runs the validation gate in a supervised background process with machine-readable status, and tears down only what it started. It productizes the recovery sequence proven during PR #51 instead of leaving it as agent folklore.

## Resolved assumptions (autonomous defaults)

This spec was written in an autonomous run; each critical unknown was resolved with the most reversible, smallest-scope default. Override any of these on the spec PR before implementation.

| # | Question | Chosen answer | Rationale |
| --- | --- | --- | --- |
| Q1 | Are the environment launcher and the background validation supervisor one capability or two specs? | One spec. | Issue #53's acceptance criteria define "ready" as *storefront reachable + validation gate running concurrently*, so they ship together. The supervisor is deliberately kept behind a thin seam (`up --validate` invocation + its own status file) — an adversarial review noted it could be extracted into its own spec later without rework, and that seam preserves the option. |
| Q2 | Service provisioning: Docker-first or native-first? | Native-first (spawn `mysqld` / `redis-stack-server` binaries on run-scoped ports), Docker as fallback when a binary is absent, loud failure listing both options when neither exists. | Repo precedent: the Playwright E2E spec explicitly rejected Docker environments (LocalWP is not Docker-based; phpredis pain), and PR #51 proved native isolated MySQL 8 works. Native-first adds no new hard dependency; detection order is documented and each backend is behind one function, so flipping the preference later is cheap. |
| Q3 | Where do the scripts live, and how does `om-prepare-test-env` find them? | Canonical committed orchestrator `bin/test-env.sh` (subcommands `up`, `down`, `status`) plus thin committed wrappers `.ai/scripts/test-env-up.sh` / `.ai/scripts/test-env-down.sh` **without** the generated-entrypoint marker. | The `om-prepare-test-env` contract treats a marker-less script at its standard path as repo-owned tooling: it runs it and never overwrites it. One implementation, discoverable at both the human path (`bin/`) and the agent path (`.ai/scripts/`). |
| Q4 | Redis isolation: dedicated instance per environment, or shared instance with a run-scoped key prefix? | Dedicated `redis-stack-server` per environment on an isolated port (native binary, else Docker `redis/redis-stack-server`). When neither is available but a reachable RediSearch-capable instance exists, degrade to it with a run-scoped key prefix and record `"isolation": "shared-redis"` in the descriptor. | The plugin needs RediSearch (`FT.*`), not plain Redis. A dedicated instance is the only full isolation (FT index names are server-global); the prefix fallback keeps the launcher usable on minimal boxes while stating the compromise truthfully. |
| Q5 | PHP runtime for the isolated site? | Reuse the host PHP ≥ 8.3 via `wp server` (the CI-proven approach). Detect `php -m` for `redis`; a missing phpredis is a loud degradation recorded in the descriptor, never an auto-compile. | `bin/e2e-install-wp.sh` + `wp server` already work in CI; auto-compiling extensions (à la `bin/install-phpredis-local.sh`) is platform-specific and high blast radius. |
| Q6 | Where does the clean-room integration test run? | A committed test script (`tests/env/test-env-launcher.sh`) runnable manually, wired to a `workflow_dispatch`(+weekly `schedule`) CI job — **not** added to the per-PR gate and **never** to `validation.commands`. | The launcher test provisions servers and takes minutes; the agentic gate must stay hermetic (standing AGENTS.md rule). A dispatchable job keeps it honest without taxing every PR. |
| Q7 | Where does the background validation runner get its command list? | Parsed from `.ai/agentic.config.json` → `validation.commands` at run time (fallback to `composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit` when the config is absent). | Keeps the launcher agnostic to gate changes — edit the config once, every consumer follows. This is the issue's "fully agnostic" requirement applied to validation. |

## 📝 Problem Statement

- A fresh PR worktree cannot run the configured validation gate: `tests/bootstrap.php` requires `wordpress-tests-lib/includes/functions.php`, which nothing in the local flow provisions (only CI runs `bin/install-wp-tests.sh`). The failure surfaces *after* PHPUnit starts, deep into a review or QA session (observed in PR #51).
- Browser QA and PHPUnit setup are two disconnected, partially manual paths: `bin/e2e-install-wp.sh` + `bin/e2e-provision.sh` build a storefront; `bin/install-wp-tests.sh` builds a test library; nothing composes them, allocates non-conflicting ports, or installs Composer/npm dependencies first.
- `.ai/qa/test-env.json` can keep claiming `"status": "running"` after its server died. Consumers (`om-auto-qa-pr`, `om-integration-tests`) attach to a corpse.
- The successful recovery in PR #51 — isolated ports, fresh MySQL 8, test DB, `wordpress-tests-lib` into a run-owned tmp directory, PHPUnit from the worktree — exists only as an agent transcript. Every future agent re-derives it.
- Falling back to the developer's LocalWP/dev site is destructive: `bin/e2e-provision.sh` overwrites site options and reseeds the catalog, and the degraded e2e project really breaks Redis config.

## 📝 Proposed Solution

A single committed orchestrator, `bin/test-env.sh`, owning the full worktree contract:

- **`up`** — from a clean worktree: install Composer (and npm, when `package.json` scripts are needed) dependencies; allocate free run-scoped ports; start isolated MySQL and Redis Stack; install WordPress + WooCommerce via the existing `bin/e2e-install-wp.sh`; provision plugin state via the existing `bin/e2e-provision.sh`; install `wordpress-tests-lib` + its test database via the existing `bin/install-wp-tests.sh` into a run-owned tmp directory; generate a worktree-local `phpunit.xml` (already gitignored) that injects `WP_TESTS_DIR`, so bare `vendor/bin/phpunit` works; write the descriptor; print QA URL + admin credentials. With `--validate` (default for the one-shot flow) it then starts the supervised background validation run and returns immediately.
- **`status`** — re-verify every recorded resource (PID/container alive, port listening, MySQL ping, Redis PING + `FT._LIST`-capable, HTTP 200 on the storefront, `wordpress-tests-lib` present) and report truthfully; never echo a stale descriptor.
- **`down`** — stop exactly the PIDs/containers the descriptor records as owned by this run, remove run-owned tmp directories, keep logs, mark the descriptor `stopped`. Safe to run twice.

Reuse over reinvention: the three existing `bin/` scripts remain the single provisioning implementation; `test-env.sh` composes them with environment variables they already honor (`WP_ROOT`, `SITE_URL`, `DB_*`, `WP_TESTS_DIR`, `WP_CORE_DIR`, `REDIS_HOST/PORT`, `BASE_URL`). CI keeps calling them directly, unchanged.

Alternatives considered:

- **Extend `om-prepare-test-env` generation only (no committed script)** — leaves the launcher as agent-generated state, invisible to humans and reviewers, and regenerated per machine. Rejected: the issue explicitly asks to productize; committed scripts are reviewable and version-locked to the code they test.
- **Docker-compose environment** — rejected for v1 per the Playwright-spec precedent (phpredis + LocalWP friction); Docker remains the per-service fallback, not the frame.
- **`wp-env`** — same rejection as the Playwright spec (root `pecl install` hack for phpredis on every start).

## 📝 Architecture

```text
bin/test-env.sh (orchestrator: up | status | down)
  ├─ lib: port allocation, run-id, health probes, descriptor read/write (single file, POSIX bash)
  ├─ services:  mysqld --datadir=$RUN_DIR/mysql --port=$MYSQL_PORT   (native | docker fallback)
  │             redis-stack-server --port=$REDIS_PORT --dir=$RUN_DIR/redis
  ├─ app:       bin/e2e-install-wp.sh  (WP_ROOT=$RUN_DIR/wordpress, SITE_URL=http://127.0.0.1:$HTTP_PORT)
  │             wp server --host=127.0.0.1 --port=$HTTP_PORT  (PID recorded)
  │             bin/e2e-provision.sh   (REDIS_PORT=$REDIS_PORT, BASE_URL smoke check)
  ├─ phpunit:   bin/install-wp-tests.sh  (WP_TESTS_DIR=$RUN_DIR/wordpress-tests-lib, test DB on $MYSQL_PORT)
  │             generate ./phpunit.xml from phpunit.xml.dist + <env WP_TESTS_DIR>
  ├─ validate:  nohup supervisor → runs validation.commands sequentially,
  │             writes .ai/qa/validation-status.json + .ai/qa/logs/validation-<runId>.log
  └─ state:     .ai/qa/test-env.json (descriptor), $RUN_DIR=<tmp>/shift64-test-env/<runId>/
.ai/scripts/test-env-up.sh / test-env-down.sh  →  exec bin/test-env.sh up|down (marker-less wrappers)
tests/env/test-env-launcher.sh                 →  clean-room integration test (dispatch CI job)
```

- **Run identity & isolation.** `runId = <worktree-basename>-<8-char hash of worktree path>`. All mutable state lives under `$RUN_DIR` (platform tmp root); all ports are allocated free at start and bound to `127.0.0.1`. Two worktrees run two fully disjoint environments concurrently.
- **Idempotence & self-healing.** `up` under a PID-checked lock: if a descriptor exists, run the full health probe set; healthy → reuse (report, exit 0); any probe fails → `down` the owned remnants, then provision fresh. `"running"` is written only after every probe passes.
- **Ownership rule.** The launcher never touches a MySQL/Redis/WordPress it did not start (descriptor records `startedByThisRepo`, PIDs, container names, datadirs). The LocalWP path (`bin/install-phpredis-local.sh`, `wp-cli.local.yml`) stays a deliberate, separate, manual flow.
- **What is reused vs. new.** New: `bin/test-env.sh`, the two `.ai/scripts/` wrappers, the supervisor, the clean-room test, docs. Reused unchanged in interface: `bin/e2e-install-wp.sh` (gains only env-var pass-throughs if needed), `bin/e2e-provision.sh`, `bin/install-wp-tests.sh`, `tests/bootstrap.php` (no change — `phpunit.xml` injects the env var it already reads).

## 📝 Data Model

No application/database schema changes. Two JSON working files (both gitignored):

**`.ai/qa/test-env.json`** — the `om-prepare-test-env` descriptor schema, plus launcher extensions:

```json
{
  "version": 1, "runId": "s64-main-3fa9c2d1", "status": "running | stopped | provisioning | unhealthy",
  "mode": "ephemeral", "baseUrl": "http://127.0.0.1:8931", "startedByThisRepo": true,
  "startScript": ".ai/scripts/test-env-up.sh", "stopScript": ".ai/scripts/test-env-down.sh",
  "app": { "startCommand": "wp server", "port": 8931, "healthPath": "/search-e2e/", "pid": 41210 },
  "services": [
    { "type": "mysql", "host": "127.0.0.1", "port": 53306, "pid": 41180, "container": null, "datadir": "<RUN_DIR>/mysql" },
    { "type": "redis-stack", "host": "127.0.0.1", "port": 56379, "pid": 41190, "container": null, "isolation": "dedicated | shared-redis" }
  ],
  "credentials": [ { "role": "admin", "username": "admin", "password": "admin" } ],
  "phpunit": { "wpTestsDir": "<RUN_DIR>/wordpress-tests-lib", "wpCoreDir": "<RUN_DIR>/wordpress-develop",
               "testDb": "wp_tests_s64_3fa9c2d1", "phpunitXml": "./phpunit.xml" },
  "validation": { "statusFile": ".ai/qa/validation-status.json", "pid": 41300 },
  "browser": { "provider": "agent-browser", "…": "unchanged from the om-prepare-test-env contract" },
  "platform": "linux", "startedAt": "<ISO-8601>", "notes": "<degradations, e.g. phpredis missing>"
}
```

**`.ai/qa/validation-status.json`** — supervisor state, updated atomically (write temp + rename) after every transition:

```json
{
  "runId": "s64-main-3fa9c2d1", "status": "running | passed | failed | aborted",
  "startedAt": "<ISO>", "finishedAt": "<ISO or null>", "pid": 41300,
  "commands": [
    { "command": "composer validate --strict", "status": "passed", "exitCode": 0, "startedAt": "…", "finishedAt": "…" },
    { "command": "vendor/bin/phpcs", "status": "running", "exitCode": null, "startedAt": "…", "finishedAt": null },
    { "command": "vendor/bin/phpunit", "status": "pending", "exitCode": null, "startedAt": null, "finishedAt": null }
  ],
  "log": ".ai/qa/logs/validation-s64-main-3fa9c2d1.log"
}
```

## 📝 API Contracts

CLI surface (the public contract; everything else is internal):

| Command | Behavior | Exit code |
| --- | --- | --- |
| `bin/test-env.sh up [--validate\|--no-validate] [--force] [--fresh]` | Healthy env exists → reuse & report. Else provision fully; print `BASE_URL=…`, `WP_ADMIN=admin/admin`, `WP_TESTS_DIR=…`, descriptor path. `--validate` (default) starts the background gate after the URL is printed. `--force` restarts even if healthy; `--fresh` additionally wipes `$RUN_DIR` (new DB, new catalog). | 0 ready; 1 provisioning failed (partial state torn down); 2 preflight missing dependency (named, with install hint) |
| `bin/test-env.sh status [--json]` | Re-probe everything; human summary or the raw descriptor + validation status JSON. Repairs the descriptor's `status` field to match reality. Environment health and validation state are reported (and exit-coded) separately so consumers can distinguish "rebuild the env" from "re-run validation". | 0 healthy (validation absent, running, or passed); 3 env unhealthy/stale; 4 no environment; 5 env healthy but validation `failed`/`aborted` |
| `bin/test-env.sh down [--keep-logs]` (default keeps logs) | Stop owned PIDs/containers, drop run tmp dirs, descriptor → `stopped`. Never fails on already-stopped resources. | 0 |
| `.ai/scripts/test-env-up.sh` / `test-env-down.sh` | `exec` the above (`up` / `down`); honor `--force`/`--force-rebuild` (mapped to `--force`/`--fresh`) per the `om-prepare-test-env` pass-through contract. | as above |

Descriptor and validation-status JSON shapes above are contracts for `om-auto-qa-pr`, `om-integration-tests`, and any agent polling test progress. `wp server` remains the app runtime; the storefront health probe is `GET /search-e2e/` expecting HTTP 200 and the search block markup.

## 📝 UI/UX

None — developer/agent CLI tooling only. No user-facing surfaces change; no mockups apply.

## 📝 Edge Cases & Failure Scenarios

- **Interrupted `up` (Ctrl-C / crash mid-provision).** A trap kills started PIDs and writes the descriptor `status` as `unhealthy` (with the abort noted in `notes`); the next `up` health-probes, finds remnants, tears down owned resources, and provisions fresh. The PID-checked lock prevents two concurrent `up`s in one worktree.
- **Stale descriptor, dead server (the PR #51 case).** `status`/`up` never trust `"running"`: every probe (PID, port, MySQL ping, Redis PING, HTTP 200, tests-lib present) must pass, else the env is `unhealthy` and `up` rebuilds. A descriptor whose recorded PIDs now belong to other processes (PID reuse) is detected by matching the process command line before killing anything.
- **Port stolen between allocation and bind.** Service start fails → one retry with a newly allocated port before declaring failure.
- **Missing host dependency** (`wp`, `composer`, `mysqld`+no Docker, `redis-stack-server`+no Docker, `svn`, PHP < 8.3). Preflight names every missing tool with an install hint and exits 2 *before* mutating anything.
- **phpredis missing from host PHP.** Storefront still provisions: the launcher passes an additive `SKIP_REDIS_WIRING=1` to `bin/e2e-provision.sh` (defaulting to current behavior when unset) so the `setup`/`rebuild` Redis steps are skipped instead of failing hard; search degrades to native WooCommerce search, the descriptor `notes` + `status` output state the degradation loudly, and PHPUnit (which does not need Redis) is unaffected. Never auto-compile the extension.
- **Aborted degraded e2e run broke the site's Redis config.** `up` re-runs `bin/e2e-provision.sh`, whose `setup` call restores healthy config — the documented recovery, now automatic.
- **Validation supervisor dies.** `validation-status.json` holds a PID; `status` detects a dead PID with a non-terminal status and reports `aborted`, pointing at the log's tail.
- **Two worktrees at once.** Disjoint `runId`, ports, datadirs, DB names (`wp_tests_<hash>`), Redis instances — no shared mutable state. The clean-room test asserts this.
- **`down` on someone else's services.** Impossible by construction: `down` only acts on PIDs/containers/datadirs recorded in the descriptor with `startedByThisRepo: true`, after command-line verification.

## 📝 Risks & Impact Review

- **CI compatibility (protected surface).** `release.yml` calls `bin/install-wp-tests.sh` and the e2e scripts directly. Their argument/env interfaces must not change; `test-env.sh` only composes them. Any additive env var must default to current behavior. Covered by existing CI staying green.
- **Gate hermeticity.** The validation gate (`validation.commands`) is *run by* the supervisor, never *extended* by this work; Playwright stays out of the gate per AGENTS.md. The clean-room test lives in a dispatch CI job only.
- **Developer-site safety.** The launcher refuses to operate on an environment it did not create; the destructive `e2e-provision.sh` is only ever pointed at the run-owned `WP_ROOT`. Blast radius of a bug is confined to `$RUN_DIR` and `127.0.0.1` ports.
- **Rollback.** Tooling-only: reverting the PR restores the status quo; no data migrations, no runtime plugin code touched. Descriptor consumers already tolerate a missing descriptor (they regenerate via `om-prepare-test-env`).
- **Platform scope.** v1 targets POSIX (Linux/macOS/WSL2) matching every existing `bin/*.sh`; native Windows keeps the `om-prepare-test-env` generated-PowerShell path. Recorded as a descriptor `platform` field, not a silent assumption.

## 📋 Phasing

- **Phase 1 — Isolated storefront lifecycle**: `bin/test-env.sh` with `up`/`status`/`down`, native/Docker service provisioning, WP install + provision via existing scripts, descriptor with truthful health. Ships alone: one-command manual QA env.
- **Phase 2 — PHPUnit runtime**: tests-lib + test DB provisioning, generated `phpunit.xml`, bare `vendor/bin/phpunit` green in a clean worktree. Ships alone: fixes the PR #51 failure class.
- **Phase 3 — Background validation supervisor**: `--validate`, `validation-status.json`, log capture, `status` integration.
- **Phase 4 — Productization**: `.ai/scripts/` wrappers, clean-room integration test + dispatch workflow, docs (AGENTS.md + docs/test-environments.md).

## 📋 Implementation Plan

**Phase 1 — Isolated storefront lifecycle**
1. Scaffold `bin/test-env.sh`: argument parsing (`up|status|down` + flags), run-id derivation, `$RUN_DIR` layout, PID-checked lock, atomic descriptor read/write helpers. Testable: `status` with no env exits 4; lock blocks a second concurrent `up`.
2. Port allocation + preflight checks (named missing-dependency exit 2). Testable: run preflight under a stripped/shimmed `PATH` (the clean-room test's harness) and assert the report names each missing tool and the fallback chain.
3. MySQL backend: native `mysqld --datadir` initialize/start/stop on a run port, Docker fallback (`mysql:8` container, run-scoped name); health = `mysqladmin ping` + create/drop probe DB. Testable: start, ping, `down`, re-`up` reuses cleanly.
4. Redis backend: `redis-stack-server` native/Docker with the same shape; health = `PING` + `FT._LIST`. Shared-instance degradation path recorded in descriptor. Testable: `redis-cli -p $REDIS_PORT FT._LIST` succeeds.
5. WordPress bring-up: `bin/e2e-install-wp.sh` with run-scoped `WP_ROOT`/`SITE_URL`/`DB_*`, start `wp server` (PID recorded), then `bin/e2e-provision.sh` with `REDIS_PORT` + `BASE_URL` smoke. Descriptor written `running` only after all probes pass; `up` prints URL + credentials. Testable: fresh worktree → `GET /search-e2e/` returns HTTP 200 with the search-block markup; the autocomplete canary (`wp shift64-woo-search test`) is additionally asserted when phpredis is present, and the descriptor records the degradation when it is not.
6. Self-healing reuse: full probe set on existing descriptor; unhealthy → owned-resource teardown → fresh provision. Testable: kill `wp server`, re-run `up`, get a healthy env; descriptor never lies.

**Phase 2 — PHPUnit runtime**
7. Provision `wordpress-tests-lib` via `bin/install-wp-tests.sh` with `WP_TESTS_DIR`/`WP_CORE_DIR` under `$RUN_DIR` and the test DB on the run's MySQL (skip-create=false, non-interactive). Record the `phpunit` block in the descriptor. Testable: `WP_TESTS_DIR=<run> vendor/bin/phpunit` passes.
8. Generate worktree-local `phpunit.xml` from `phpunit.xml.dist` injecting `<env name="WP_TESTS_DIR">`; regenerate on every `up` (file is gitignored). Testable: bare `vendor/bin/phpunit` passes in a clean worktree after `up`.

**Phase 3 — Background validation supervisor**
9. Supervisor process: parse `validation.commands` from `.ai/agentic.config.json` (fallback trio), run sequentially, stream to `.ai/qa/logs/validation-<runId>.log`, update `validation-status.json` atomically per transition; `up --validate` starts it after printing the URL. Testable: status file transitions `running → passed` with real exit codes; a forced phpcs failure yields `failed` + pointed log.
10. `status` integration: report validation state, detect dead-supervisor `aborted` case. Testable: kill the supervisor mid-run; `status` reports `aborted`, exit 3.

**Phase 4 — Productization**
11. Add marker-less `.ai/scripts/test-env-up.sh` / `test-env-down.sh` wrappers (flag mapping per the entrypoint contract). Testable: `sh .ai/scripts/test-env-up.sh` yields the same healthy env; `om-prepare-test-env`'s phase-1 check recognizes and runs them.
12. Clean-room integration test `tests/env/test-env-launcher.sh`: in a fresh detached worktree without `vendor`/`node_modules`/tests-lib/descriptor, assert one `up` → healthy site, bare `phpunit` green, truthful validation status; cover interrupted-startup and stale-descriptor recovery; plus a `workflow_dispatch` + weekly `schedule` job running it. Testable: the job itself.
13. Docs: `docs/test-environments.md` (happy path, status, logs, recovery, expected time-to-ready) + AGENTS.md pointer; flip this spec's Status + index row in the implementing PR. Testable: docs review; no `WP_TESTS_DIR` export appears in the happy path.
