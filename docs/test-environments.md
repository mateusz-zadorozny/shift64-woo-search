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
- started an isolated MariaDB/MySQL (native `mysqld` when the host has one,
  otherwise a Docker `mariadb:lts` container) and a RediSearch-capable Redis
  on run-scoped `127.0.0.1` ports;
- installed WordPress + WooCommerce via `bin/e2e-install-wp.sh`, with Twenty
  Twenty-Five active (the supported block-theme baseline) and Storefront
  installed inactive for the classic-theme e2e projection
  and provisioned the plugin state (demo catalog, options, index, the shop
  archive as the front page, and the theme's search header) via
  `bin/e2e-provision.sh`;
- installed `wordpress-tests-lib` and its test database via
  `bin/install-wp-tests.sh`, and generated a worktree-local `phpunit.xml`
  that injects `WP_TESTS_DIR` — so **`vendor/bin/phpunit` just works, with
  no manual exports**;
- printed the QA URL (`/search-e2e/` carries both search blocks) and the
  admin credentials (`admin` / `admin`);
- pointed the front page at the WooCommerce shop archive, so `/` opens on a
  product grid;
- written the environment descriptor to `.ai/qa/test-env.json`;
- started the configured validation gate (`.ai/agentic.config.json` →
  `validation.commands`) in a supervised **background** process, so tests run
  while you QA in the browser.

Expected time-to-ready: ~2–4 minutes cold (network downloads dominate),
seconds on a healthy reuse. The ready environment is owned independently of
the shell or agent command that launched it; no terminal needs to stay open.

Flags: `--allow-degraded` (accept a searchless storefront when phpredis
cannot be installed), `--no-validate` (skip the background gate), `--force` (restart even if
healthy), `--fresh` (wipe and rebuild everything, new database and catalog).

## The storefront shape

Provisioning gives every environment the same shopper-facing shape, so a
screenshot from one run is comparable with the next:

- **Front page = the shop archive.** `show_on_front`/`page_on_front` point at
  the WooCommerce shop page, so `/` opens on a product grid instead of a blog
  roll.
- **Search lives in the site header.** `bin/provision-block-theme-header.php`
  writes the block theme's `header` template part: the modal search trigger
  next to the account and cart icons, and the inline search field on the row
  below. It replaces any existing `header` part for that theme on every run.

Both are live the moment `up` returns: the default environment activates Twenty
Twenty-Five, and the script targets the active theme whenever it is a block
theme — so the same holds on a block-theme dev site (LocalWP) running
`bin/e2e-provision.sh` directly.

Template parts are theme-scoped through the `wp_theme` term, so the header is
simply **absent under Storefront**, including while
`tests/e2e/classic-theme/` has it activated for the length of its spec file.
Nothing needs tearing down around that switch. The suite's own selectors are
already scoped to the search block wrappers (`.shift64-woo-search-block--form`,
`--modal`), so the header's second instance of each block does not collide with
the ones on `/search-e2e/`.

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

`runId` remains stable for a worktree, while the additive `generationId`
changes whenever the environment is rebuilt. The descriptor's
`recoveryMode` is one of `reused`, `restarted-app`, or `rebuilt`. Validation
results also carry `generationId`; `status --json` returns
`validationStatus: null` for a missing or mismatched result. Running `up
--no-validate` stops any owned validation supervisor and clears its status,
so an earlier `passed` result is never presented as part of the current run.

`status` never echoes a stale descriptor: every recorded PID, port, database,
Redis instance, and the storefront HTTP endpoint is re-probed, and a
`running` claim that fails any probe is rewritten to `unhealthy`.

## Recovery

- **Dead application server** — just run `bin/test-env.sh up` again. When
  MySQL, Redis, WordPress files, and the PHPUnit runtime are still healthy,
  it restarts only `wp server`, keeps the same URL and catalog, and records
  `recoveryMode: restarted-app`.
- **Dead service or corrupt provisioning state** — `up` tears down only the
  resources it owns (PIDs are verified against their recorded command lines
  before any kill) and rebuilds the environment with a new `generationId`.
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

- **phpredis missing from the selected PHP** — `up` first tries to fix it
  itself: it prefers an installed PHP that already has the extension, and
  otherwise runs a non-interactive `pecl install redis` (log:
  `.ai/qa/logs/pecl-redis-<runId>.log`). Only when that fails — and only
  with the explicit `--allow-degraded` flag — does it provision a searchless
  storefront (`SKIP_REDIS_WIRING=1` path in `bin/e2e-provision.sh`, native
  WooCommerce search fallback, a loud banner plus descriptor `notes`).
  Without the flag, a failed install stops `up` with exit 2 and the fix
  named. PHPUnit is unaffected either way.
- **No dedicated Redis possible** (no usable binary, no Docker) — the
  launcher attaches to a shared RediSearch-capable instance
  (`TEST_ENV_SHARED_REDIS`, default `127.0.0.1:6379`) with a run-scoped key
  prefix and records `"isolation": "shared-redis"`.
- **Distro redis-server without built-in FT** — the launcher auto-discovers
  `redisearch.so` (e.g. `/usr/lib/redis/modules/`) and passes
  `--loadmodule`; FT capability is verified from reply text, not exit codes.

## Platform support

Linux and macOS are both first-class. On macOS the launcher runs on the stock
`/bin/bash` 3.2 (no arrays-under-`set -u` tricks, no `setsid`, no `/proc`, no
GNU `sed -i` — PID ownership is verified via `ps -o command=` there), hands
the app and validation supervisor to `launchd`, and uses `setsid` on Linux so
both survive the launching shell. Services
without a native binary fall back to Docker (`mariadb:lts`,
`redis/redis-stack-server`), and a host without `redis-cli` is fine: probes go
through `docker exec` into the run's container. The wp-cli shim also pins
`memory_limit=512M` and mutes deprecation noise, so a Homebrew PHP with a
128M default extracts WordPress without OOMing. Native Windows remains out of
scope (`om-prepare-test-env` owns that path).

No GNU coreutils are required on macOS. The per-worktree hash that names the
run directory, the MySQL socket and both databases is a SHA-1 of the worktree
path, computed with the first of `sha1sum`, `shasum` or `openssl` found on
`PATH` — all three produce the same digest, and stock macOS satisfies the
requirement through `shasum`. A host with none of the three stops immediately
naming all three, rather than dying with a bare `command not found` before
preflight can report anything.

When a Docker fallback is required, preflight verifies the daemon rather than
only the CLI. On macOS with `/Applications/Docker.app`, it starts Docker
Desktop and waits up to 90 seconds. Other hosts fail before provisioning with
an actionable `docker info` diagnosis. Image pulls retry three times and keep
their stderr in the run log named by the failure.

## Environment variables

| Variable | Effect |
| --- | --- |
| `TEST_ENV_PHP` | Absolute path of the PHP binary to use (default: every `php`/`php8.x` on `PATH` and in the usual prefixes is probed, preferring one that already has `mysqli` **and** `phpredis`) |
| `TEST_ENV_SHARED_REDIS` | `host:port` of the shared-Redis fallback (default `127.0.0.1:6379`) |
| `TEST_ENV_RUN_ROOT` | Root for the run directory (default `${XDG_CACHE_HOME:-$HOME/.cache}/shift64-test-env`) |

**The run directory deliberately ignores `$TMPDIR`.** It holds the WordPress
docroot, the MySQL datadir and `wordpress-tests-lib` for the whole life of the
environment, and agent harnesses (cezar) point `$TMPDIR` at a per-task scratch
directory they recycle *while the environment is still running*. When that
happened, `wp server` and `mysqld` stayed up on top of a deleted docroot and
every page answered **HTTP 200 carrying a PHP fatal** (`chdir(): No such file
or directory` from the wp-cli router) — an environment that looks healthy to a
status-code check and is entirely dead. Anchoring to a stable per-user cache
directory removes that failure mode; use `TEST_ENV_RUN_ROOT` to relocate it.
The wp-cli shim still lives worktree-side, because tmp roots are often `noexec`.

**The flip side is that nothing reaps the run root for you any more.** Each
worktree gets its own `$RUN_ROOT/<runId>/` holding a WordPress core checkout,
`wordpress-develop`, `wordpress-tests-lib` and a MySQL datadir — hundreds of MB
— and `down` only removes the *current* worktree's directory. A worktree that
is deleted without a `down` first leaves its run directory behind for good.
Reclaim the space with `rm -rf "${XDG_CACHE_HOME:-$HOME/.cache}/shift64-test-env"`
once no environment is running (`bin/test-env.sh status` in each worktree, or
just after a reboot). Upgrading from a pre-`TEST_ENV_RUN_ROOT` checkout: run
`bin/test-env.sh down` **before** updating, or the old `$TMPDIR`-anchored
environment is orphaned — the new code cannot see it to stop it.

PHP discovery enumerates candidates **by directory**, not by command name: on
macOS, Local by Flywheel's phpredis-less `php` commonly shadows a Homebrew
`php` that has the extension, and `command -v php` only ever returns the first
of them — so a name-only search would silently pick the build that cannot talk
to Redis, and then fail on `pecl install redis`.

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

`tests/env/test-env-discovery.sh` is the cheap half: it sources `test-env.sh`
(sourcing loads the helpers without dispatching a command) and asserts the pure
logic — PHP discovery returns a bare executable path even when the candidate
binary is noisy on stdout, `RUN_DIR` is anchored to `TEST_ENV_RUN_ROOT` /
`$XDG_CACHE_HOME` and never to `$TMPDIR`, and `WORKTREE_HASH` is byte-identical
whichever of `sha1sum`/`shasum`/`openssl` the host exposes. It provisions
nothing, needs no PHP
(the candidates are stubs) and runs in seconds, so it *is* part of the per-PR
`Tests (PHP 8.3)` job.
