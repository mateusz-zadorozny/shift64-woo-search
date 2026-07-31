# One-shot isolated test environments

One command turns any clean checkout or PR worktree into a ready test
environment: an isolated WordPress + WooCommerce + MySQL + Redis storefront
for manual QA, plus a fully provisioned WordPress PHPUnit runtime so the
whole validation gate runs immediately.

Design doc: `.ai/specs/2026-07-31-one-shot-worktree-test-env.md` (issue #53).

## The happy path

```bash
bin/test-env.sh up
```

That is the whole setup. When it returns, the launcher has:

- installed Composer dependencies (when `vendor/` is missing);
- started an isolated MariaDB/MySQL and a RediSearch-capable Redis on
  run-scoped `127.0.0.1` ports;
- installed WordPress + WooCommerce + Storefront via `bin/e2e-install-wp.sh`
  and provisioned the plugin state (demo catalog, options, index) via
  `bin/e2e-provision.sh`;
- installed `wordpress-tests-lib` and its test database via
  `bin/install-wp-tests.sh`, and generated a worktree-local `phpunit.xml`
  that injects `WP_TESTS_DIR` — so **`vendor/bin/phpunit` just works, with
  no manual exports**;
- printed the QA URL (`/search-e2e/` carries both search blocks) and the
  admin credentials (`admin` / `admin`);
- written the environment descriptor to `.ai/qa/test-env.json`;
- started the configured validation gate (`.ai/agentic.config.json` →
  `validation.commands`) in a supervised **background** process, so tests run
  while you QA in the browser.

Expected time-to-ready: ~2–4 minutes cold (network downloads dominate),
seconds on a healthy reuse.

Flags: `--no-validate` (skip the background gate), `--force` (restart even if
healthy), `--fresh` (wipe and rebuild everything, new database and catalog).

## Status, logs, and validation progress

```bash
bin/test-env.sh status          # human summary; repairs a lying descriptor
bin/test-env.sh status --json   # descriptor + validation status, machine-readable
```

Exit codes: `0` healthy (validation absent/running/passed), `3` environment
unhealthy, `4` no environment, `5` environment healthy but validation
`failed`/`aborted` — so tooling can distinguish "rebuild the env" from
"re-run the tests".

Machine-readable validation state lives in `.ai/qa/validation-status.json`
(per-command status, exit codes, timestamps); full output streams to
`.ai/qa/logs/validation-<runId>.log`.

`status` never echoes a stale descriptor: every recorded PID, port, database,
Redis instance, and the storefront HTTP endpoint is re-probed, and a
`running` claim that fails any probe is rewritten to `unhealthy`.

## Recovery

- **Dead or half-provisioned environment** — just run `bin/test-env.sh up`
  again. It health-probes the recorded state, tears down only the resources
  it owns (PIDs are verified against their recorded command lines before any
  kill), and rebuilds what is missing.
- **Aborted degraded e2e run left Redis config broken** — `up` re-runs the
  idempotent provisioning, which restores a healthy config.
- **Everything weird** — `bin/test-env.sh down && bin/test-env.sh up --fresh`.

## Teardown

```bash
bin/test-env.sh down
```

Stops exactly the processes the descriptor records as started by this
worktree's run (each PID is verified against a run-scoped command-line
fingerprint first, so a recycled PID is never killed), removes the run's
temporary directory, keeps logs under `.ai/qa/logs/`, and marks the
descriptor `stopped`. Safe to run twice; it exits non-zero only when a live
`up` currently holds the worktree's lock. It never touches a developer's own
LocalWP site, system services, or another worktree's environment — two
worktrees run fully disjoint environments concurrently.

## Degradations (recorded, never silent)

- **phpredis missing from the selected PHP** — the storefront still
  provisions (`SKIP_REDIS_WIRING=1` path in `bin/e2e-provision.sh`); search
  falls back to native WooCommerce search; the descriptor `notes` say so.
  PHPUnit is unaffected.
- **No dedicated Redis possible** (no usable binary, no Docker) — the
  launcher attaches to a shared RediSearch-capable instance
  (`TEST_ENV_SHARED_REDIS`, default `127.0.0.1:6379`) with a run-scoped key
  prefix and records `"isolation": "shared-redis"`.
- **Distro redis-server without built-in FT** — the launcher auto-discovers
  `redisearch.so` (e.g. `/usr/lib/redis/modules/`) and passes
  `--loadmodule`; FT capability is verified from reply text, not exit codes.

## Environment variables

| Variable | Effect |
| --- | --- |
| `TEST_ENV_PHP` | Absolute path of the PHP binary to use (default: newest `php`/`php8.x` ≥ 8.3 with `mysqli`) |
| `TEST_ENV_SHARED_REDIS` | `host:port` of the shared-Redis fallback (default `127.0.0.1:6379`) |
| `TMPDIR` | Root for the run directory (default `/tmp`); the wp-cli shim lives worktree-side because tmp roots are often `noexec` |

Managed servers that periodically strip exec bits under the web root (this
repo's dev server does) are handled: invoke the launcher as
`bash bin/test-env.sh …` when in doubt, and both `up` and the validation
supervisor repair `vendor/bin/*` exec bits before relying on them.

## CI

`.github/workflows/test-env.yml` runs the clean-room integration test
(`tests/env/test-env-launcher.sh`) on demand (`workflow_dispatch`) and weekly.
It is deliberately not part of the per-PR gate, and the launcher test — like
Playwright — must never be added to `.ai/agentic.config.json`
`validation.commands`.
