# Playwright E2E Foundation

## TLDR

Add a Playwright end-to-end layer that exercises the plugin's real runtime for the first time in CI: real WordPress + WooCommerce + Redis Stack provisioned natively on a GitHub Actions runner (no Docker), served by `wp server`, seeded by the existing deterministic demo generator. Nineteen tests cover the core shopper journeys (autocomplete dropdown, keyboard navigation, modal, Redis-backed results page, filters, sort, AJAX pagination, category archive) plus every degradation path — JS failure modes via `page.route()` mocks, real dead-Redis and cleared-config via serialized environment mutation. The e2e job runs on every PR and push to `main`, gates the release job (`release: needs: [test, e2e]`), and is registered as a required status check in branch protection so it blocks merges. The same idempotent provisioning script targets the LocalWP dev site, so the suite runs locally with only a `BASE_URL` switch.

Out of scope by decision: admin-settings browser flows, pixel/visual regression snapshots, Firefox/WebKit projects, and adding Playwright to the agentic validation gate (see Risks).

## Problem Statement

- The shopper-facing surface — autocomplete dropdown, modal search, Redis-backed results page — has **zero automated browser coverage**. Every release relies on manual QA; existing specs even prescribe it ("E2E QA on the seeded store" clauses in `.ai/specs/2026-07-18-native-woocommerce-brands-support.md`).
- The PHPUnit suite never runs real WooCommerce: `tests/bootstrap.php` fakes `active_plugins` and stubs `wc_get_products()`. CI has never executed the plugin against the software it extends.
- The riskiest integration is invisible to unit tests by construction: the autocomplete endpoint runs under `SHORTINIT` and reads constants from the **generated** `wp-content/mu-plugins/shift64-woo-search/config.php`, not `wp_options` (`AGENTS.md`, `mu-plugins/endpoint.php`). A stale or missing config file breaks live search while the admin UI looks healthy. Only a browser test against a real install can catch this class of failure.
- Graceful degradation (Redis down → native WooCommerce search still works) is a headline feature with no regression protection.

## Prior Art

- **WooCommerce core**: Playwright e2e over `wp-env` is the ecosystem standard. This spec adopts the Playwright half but rejects `wp-env` for the environment: the plugin's hard phpredis requirement would need a root `docker exec` + `pecl install` hack on every container start — the one dependency `wp-env` handles poorly is the one this plugin cannot live without.
- **Testcontainers philosophy** (Elasticsearch/OpenSearch ecosystems): search engines are integrations, not functions — teams run the real engine in tests rather than mocking it, because relevance, index schema, and protocol behavior cannot be faithfully stubbed. Adopted: real Redis Stack (`redis/redis-stack-server` service container) for all journey tests.
- **Playwright request interception** (`page.route()`): the standard tool for deterministic failure-mode testing (timeouts, 429s, malformed payloads) that a live stack cannot reliably produce on demand. Adopted for the three JS failure-mode tests.
- **`@wordpress/e2e-test-utils-playwright`**: admin login/editor helpers. Skipped in v1 — no admin flows are in scope, and the frontend journeys need no authentication.

## Proposed Solution

Three deliverables, in dependency order:

1. **An idempotent provisioning script** (`bin/e2e-provision.sh`) that takes *any* WordPress + WooCommerce install to a known e2e state: plugin active, permalinks pretty, deterministic 48-product catalog seeded, Redis wired, index rebuilt, feature gates enabled, a `search-e2e` page containing both search blocks. This is the design center — CI and LocalWP become interchangeable targets, and the script doubles as executable setup documentation.
2. **A Playwright suite** (`tests/e2e/`) of ~19 tests: real-stack journeys, route-mocked JS failure modes, and a serialized "degraded" project that really breaks Redis and really clears the config, then restores.
3. **A blocking CI job** (`e2e` in `.github/workflows/release.yml`) building the environment runner-natively: `shivammathur/setup-php` with `extensions: redis` (phpredis for free), MySQL and Redis Stack as service containers, wp-cli installing WordPress + WooCommerce, `wp server` serving the site. PHP 8.3 only. `release` gains `needs: [test, e2e]`.

Alternatives considered:

- **`wp-env` (Docker)** — ecosystem standard, but phpredis must be pecl-compiled into the container via root exec on every start (+1–2 min, fragile). Rejected.
- **`docker-compose` with a custom image** — most deterministic, but requires Docker locally (LocalWP is not Docker-based) and adds an image-maintenance surface. Rejected for v1; remains the fallback if runner-native proves flaky.
- **Stubs-only (mock the endpoint everywhere)** — erases the SHORTINIT/config.php, phpredis, and RediSearch integrations, i.e. exactly the failure classes this spec exists to catch. Rejected; mocks are used only where they are the *better* tool (deterministic JS failure modes).
- **Non-blocking / main-only e2e** — non-blocking checks get ignored; post-merge discovery means fix-forward on `main`. Rejected: the job blocks PR merges (required status check) and releases (`needs`).

## Architecture

### File layout

```
playwright.config.ts              # repo root — `npx playwright test` just works
bin/e2e-install-wp.sh             # CI-only: WP + WooCommerce from zero on the runner
bin/e2e-provision.sh              # idempotent: ANY WP install → known e2e state (CI + LocalWP)
tests/e2e/
  helpers/
    env.ts                        # BASE_URL resolution, wp-cli exec wrapper (WP_CLI_BIN / WP_ROOT)
    search.ts                     # selector constants + page-object helpers (openDropdown, typeQuery, …)
    mocks.ts                      # canned endpoint payloads mirroring mu-plugins/endpoint.php contracts
  specs/
    search-dropdown.spec.ts       # tests 1–7   (real stack)
    search-results-page.spec.ts   # tests 8–11  (real stack)
    category-archive.spec.ts      # test 12     (real stack)
    modal.spec.ts                 # tests 13–14 (real stack)
    failure-modes.spec.ts         # tests 15–17 (page.route mocks, healthy env)
  degraded/
    degrade.setup.ts              # setup project: flip env to dead Redis
    degraded.spec.ts              # tests 18–19 (real env mutation)
    restore.teardown.ts           # teardown project: restore healthy env
```

`tests/e2e/` sits beside the PHPUnit files deliberately: `.phpcs.xml.dist` already excludes `/tests/` and `/bin/`, PHPUnit's `test-*.php` prefix rule ignores TypeScript, and both `build-release.sh` and `.distignore` already strip `tests/` and `bin/` from the release ZIP. No tooling fights the new files.

### CI data flow

```text
services: mysql:8.0 + redis/redis-stack-server (health-checked)
  → setup-php 8.3 (extensions: mysqli, mbstring, intl, redis; tools: composer, wp-cli)
  → bin/e2e-install-wp.sh   (wp core download/config/install; wp plugin install woocommerce
                             --activate; symlink checkout into wp-content/plugins/)
  → bin/e2e-provision.sh    (seed + wire + rebuild + verify — fails loudly before any test runs)
  → PHP_CLI_SERVER_WORKERS=4 wp server --port=8889   (+ readiness curl loop)
  → setup-node → npm ci → playwright install --with-deps chromium → npx playwright test
  → on failure: upload playwright-report/, test-results/, /tmp/wp-server.log
```

`release` changes from `needs: test` (`.github/workflows/release.yml:97`) to `needs: [test, e2e]`.

### Playwright project graph

```text
main (specs/**, chromium)
  └─▶ degrade-env (setup: option flip + config regen)   [dependencies: main]
        └─▶ degraded (degraded.spec.ts)
              └─▶ restore-env (teardown: wp shift64-woo-search setup — PING doubles as health assert)
```

Decisions:

- **`workers: 1`, `fullyParallel: false`.** The hard reason: the degraded project mutates global server state, so ordering must be a guarantee, not a convention. The supporting reason: with 19 tests at seconds each, wall time is dominated by environment build — parallelism buys almost nothing while adding the flake profile of multiple browsers contending for a `PHP_CLI_SERVER_WORKERS=4` built-in server that also serves them all from one rate-limit bucket (`mu-plugins/endpoint.php:120-135`; the raised limit mitigates this, serialization removes it). Revisit only if the suite grows past ~50 tests.
- **No `webServer` block.** The server lifecycle belongs to CI (a workflow step) or LocalWP (already running). `BASE_URL` env selects the target; default `http://127.0.0.1:8889`.
- **Chromium only**; the mobile modal test uses `test.use({ viewport: { width: 390, height: 844 } })` rather than a device project.
- **`retries: 1` in CI**, trace/video/screenshot `retain-on-failure`, `forbidOnly` in CI.
- **Mocked failure-mode specs run inside `main`** — `page.route()` interception never reaches the network, but the tests need the *healthy* page (assets enqueued, `window.shift64_woo_search_config` present). Only the two true environment-mutation tests live in `degraded`.

### Two flagged assumptions (verified early, not silently trusted)

1. **`wp server` availability**: `setup-php`'s `tools: wp-cli` installs the official bundle phar, which ships `wp-cli/server-command`. If absent in practice: fallback is `wp package install wp-cli/server-command`, or committing wp-cli's router as `tests/e2e/bin/router.php` and running `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8889 -t "$WP_ROOT" tests/e2e/bin/router.php` (identical semantics).
2. **Direct mu-plugin PHP under the router**: wp-cli's router executes existing `.php` files directly, so `GET /wp-content/mu-plugins/shift64-woo-search/endpoint.php?q=…` should work. The provisioning script's final smoke-curl proves both this and pretty permalinks *before* any Playwright test runs — a provisioning failure is loud and diagnosable; a mysterious test failure is not.

## Data Model

No production schema changes. The "data model" here is the **known e2e state** the provisioning script guarantees:

- **Catalog**: `wp eval-file bin/generate-demo-products.php count=48 mode=mixed seed=6464 reset` — deterministic apparel products (names like "Athena T-Shirt Green", SKUs `DEMO640001+`, global color attribute, size variations, category tree Clothing → Tops/Bottoms/Outerwear + Dresses). `reset` makes reprovisioning idempotent.
- **Options** (each with its own ordering constraint, hence all set before `setup`/`rebuild`):
  - `shift64_woo_search_rate_limit` → `1000` — must precede config regeneration: it becomes the generated constant `SHIFT64_WOO_SEARCH_RATE_LIMIT` (`mu-plugins/endpoint.php:121`). Real typing tests must never trip the default 30 req/s limiter; the 429 path is covered by mock instead.
  - `shift64_woo_search_suggestions` → `["t-shirt","hoodie","athena"]` — must precede `rebuild`, which bakes it into the suggestions blob. Focus-on-empty suggestions are admin-curated and default empty; without seeding, test 3 is untestable.
  - `shift64_woo_search_archive_enabled` → `yes` — live option read at request time (results-page takeover defaults off).
  - `shift64_woo_search_taxonomy_archive_scopes` → `["product_cat"]` — live option (category takeover defaults off).
  - `woocommerce_coming_soon` → `no` — WooCommerce's own gate (WC ≥ 9.1 launches storefronts in Coming Soon mode).
- **Generated config**: `wp shift64-woo-search setup --host --port` then `wp shift64-woo-search rebuild` — options saved, `wp-content/mu-plugins/shift64-woo-search/config.php` regenerated, index + synonym/suggestion/category blobs built.
- **Content**: page `search-e2e` containing `<!-- wp:shift64-woo-search/search /--><!-- wp:shift64-woo-search/modal-search /-->` (both blocks are dynamic with render callbacks — attribute-less self-closing comments render fully). Pretty permalinks `/%postname%/`.
- **Verification tail** (the loud-failure guarantee): `wp shift64-woo-search health`, `wp shift64-woo-search test "athena"` expecting results, and when `BASE_URL` is set an HTTP smoke-curl of the endpoint expecting `"success":true`.

## API Contracts

### Provisioning script environment contract

| Variable | Default | Meaning |
|---|---|---|
| `WP_CLI_BIN` | `wp` | wp-cli binary (LocalWP shell provides its own) |
| `WP_ROOT` | *(unset)* | when set, every call gets `--path="$WP_ROOT"` (CI); unset inside a LocalWP shell |
| `REDIS_HOST` / `REDIS_PORT` | `127.0.0.1` / `6379` | passed to `wp shift64-woo-search setup` |
| `BASE_URL` | *(unset)* | when set, enables the final endpoint smoke-curl |

`bin/e2e-install-wp.sh` (CI only) additionally assumes the MySQL service at `127.0.0.1:3306` (`root`/`root`, db `wordpress_e2e`) and installs to `WP_ROOT=/tmp/wordpress-e2e` with site URL `http://127.0.0.1:8889`, then symlinks `$GITHUB_WORKSPACE` into `wp-content/plugins/shift64-woo-search` (safe: `install_mu_plugin()` copies into the real `WP_CONTENT_DIR`).

### Endpoint failure contracts the mocks must mirror (verified)

- Redis unavailable — HTTP 200 (`mu-plugins/endpoint.php:106-118`):
  ```json
  {"success":false,"count":0,"query":"athena","time_ms":0,"results":[],
   "fallback":"/?s=athena&post_type=product"}
  ```
- Rate limited — HTTP 429 + `Retry-After: 1` (`mu-plugins/endpoint.php:120-135`):
  ```json
  {"success":false,"error":"Too many requests."}
  ```
- Client behavior under each (`frontend/js/shift64-woo-search.js`): 5 s hard timeout aborts the fetch, sets the `redisDown` latch, renders "Quick search timed out. Use the search button." (`:386-399`); `success:false` + `fallback` sets `redisDown`, closes the tray, stops all further fetches until reload (`:415-421`); `success:false` + `error` renders the error state (`:423-427`); network error closes the tray (`:432-438`).

### npm surface

```json
"test:e2e": "playwright test",
"test:e2e:ui": "playwright test --ui",
"test:e2e:report": "playwright show-report",
"e2e:provision": "bash bin/e2e-provision.sh"
```

`@playwright/test` joins devDependencies. Composer surface unchanged.

## UI/UX

No product UI changes. The test inventory *is* the UX contract — the journeys that must keep working:

| # | Spec file | Journey | Mode |
|---|---|---|---|
| 1 | search-dropdown | type "athena" → dropdown visible, ≥1 matching product row, Products section header | real |
| 2 | search-dropdown | product row shows SKU (`DEMO64…`) + category; click navigates to that product page | real |
| 3 | search-dropdown | focus on empty input → `mode=suggestions` request; seeded terms rendered | real |
| 4 | search-dropdown | type "shirt" → Categories section shows "T-Shirts"; click → category archive | real |
| 5 | search-dropdown | ArrowDown ×2 → second row active; Enter navigates to it; Escape closes tray | real |
| 6 | search-dropdown | 1-char query (< `minQueryLength` 2) → zero endpoint requests, no dropdown | real |
| 7 | search-dropdown | submit "athena" → lands on `/?s=athena&post_type=product` with results | real |
| 8 | search-results-page | takeover renders relevant results; orderby control shows Relevance | real |
| 9 | search-results-page | color facet checkbox → URL gains `filter_pa_color=green`; results filtered | real |
| 10 | search-results-page | `orderby=price` → rendered prices non-decreasing | real |
| 11 | search-results-page | AJAX pagination → grid changes without full navigation | real |
| 12 | category-archive | `/product-category/t-shirts/` takeover + facet sidebar (also proves permalinks) | real |
| 13 | modal | trigger opens modal (body class, input focused); search works; Escape closes, focus returns | real |
| 14 | modal | mobile viewport (390×844): open, clear button empties input, overlay/close dismisses | real |
| 15 | failure-modes | parked route → after 5 s: timeout message; latch stops further fetches; form submit still reaches real results | mock |
| 16 | failure-modes | fulfilled 429 → dropdown error state; native form submit unaffected | mock |
| 17 | failure-modes | fulfilled `success:false`+`fallback` → tray closed, `redisDown` latch (route counter stays at 1), form submit works | mock |
| 18 | degraded | real dead Redis: no dropdown, no `pageerror`s, endpoint returns fallback JSON, `/?s=` serves native WP search | real (mutated) |
| 19 | degraded | cleared `redis_host`: no script tag, no `window.shift64_woo_search_config`, plain form submit works | real (mutated) |

Selector contract (all existing, stable, non-generated): input `.shift64-woo-search-field__input`; tray `.shift64-woo-search-results` / `--visible`; rows `.shift64-woo-search-result` / `--active`; sections `.shift64-woo-search-section--*`; modal `[data-shift64-woo-search-modal]`, `[data-shift64-woo-search-modal-trigger]`, `[data-shift64-woo-search-clear]`, body class `shift64-woo-search-modal-open`; filters `#shift64-woo-search-filters`, `.shift64-woo-search-filter__checkbox[data-taxonomy][data-slug]`. Helpers in `tests/e2e/helpers/search.ts` centralize these so a markup refactor touches one file.

## Edge Cases & Failure Scenarios

- **`wp shift64-woo-search setup` refuses dead Redis hosts** — it PINGs first and `WP_CLI::error()`s on failure (`cli/class-shift64-woo-search-cli.php:100-145`). The dead-Redis test therefore cannot use `setup`; `degrade.setup.ts` mutates directly:
  ```bash
  wp option update shift64_woo_search_redis_port 6390
  wp eval 'Shift64_Woo_Search_Plugin::get_instance()->generate_mu_plugin_config();'
  ```
  (`generate_mu_plugin_config()` is public — `shift64-woo-search.php:404`.) This is *also* a faithful reproduction of the stale-config failure class: options and generated constants diverge from a healthy install exactly as they would in the field.
- **Restore is mandatory and self-verifying**: `restore.teardown.ts` runs the real `wp shift64-woo-search setup --host --port`, whose built-in PING doubles as a post-suite health assertion. A local run against LocalWP must not leave the dev site broken.
- **Aborted local degraded run** (Ctrl-C between degrade and restore) leaves LocalWP pointing at port 6390. Remedy is documented in the script header and AGENTS.md: re-run `wp shift64-woo-search setup` or `npm run e2e:provision` (both idempotent).
- **`main` failure skips the degraded chain**: Playwright runs `degrade-env` only if its dependency passed, and teardown only if its setup ran — a red `main` leaves the environment untouched.
- **Test 19 mechanics**: the asset-enqueue gate reads live options (`frontend/class-shift64-woo-search-frontend.php`), so clearing `shift64_woo_search_redis_host` needs no config regen; the shared teardown's `setup` call restores the host.
- **Timing discipline**: no `waitForTimeout` polling anywhere. Auto-retrying `expect(locator)` assertions absorb the 150 ms debounce and 150 ms loading-render delay; fetch-count assertions use `page.waitForRequest`/`waitForResponse` and route counters.
- **Provisioning fails loudly, tests don't inherit mystery**: the `health` + `test` + smoke-curl tail means a broken index, stale config, or non-executing endpoint kills the job at the provisioning step with a named cause.

## Risks & Impact Review

- **Zero runtime blast radius**: no shipped plugin code changes. `build-release.sh` already excludes all e2e paths (`tests`, `bin`, `playwright.config.*`, `playwright-report`, `test-results` — its rsync excludes); `.distignore` today covers only `tests` and `bin`, so Step 10 adds the playwright entries there as a real fix for any future `.distignore`-based packaging, not belt-and-braces. Rollback = delete the `e2e` job, revert `release.needs`, unrequire the status check.
- **CI cost**: ≈ 6–9 min wall time per run (WP + WooCommerce install dominates), single PHP version, every PR and push to `main`. Accepted by decision. WP-core tarball caching is a later optimization — cache invalidation of a live install dir is riskier than the minute it saves.
- **Blocking-gate risk**: a flaky e2e job blocks releases. Mitigations: `workers: 1`, auto-retrying assertions, `retries: 1` in CI, raised rate limit, loud provisioning, trace/video/server-log artifacts on failure. If flake persists, the documented `php -S` + committed-router fallback removes the `wp server` variable; the docker-compose alternative remains the escape hatch.
- **`wp server` single-process stalls**: mitigated by `PHP_CLI_SERVER_WORKERS=4`, serialized tests, readiness loop, and `/tmp/wp-server.log` uploaded on failure.
- **Agentic validation gate — explicit decision: do NOT add Playwright to `validation.commands` in `.ai/agentic.config.json`.** The gate must stay hermetic (composer/phpcs/phpunit run anywhere); `playwright test` requires a provisioned live site and — worse — the degraded project *mutates the target site's Redis config*. An agent "helpfully" adding it would break every validation run or corrupt the LocalWP site. Enforcement points are CI (`release` needs `e2e`) and the documented `npm run test:e2e`. Future agents: do not add it.
- **LocalWP coupling**: local runs assume the LocalWP site shell (`wp` targeting the site) and a local Redis Stack (see `docs/local-phpredis-setup.md`, `bin/install-phpredis-local.sh` — both exist). The suite itself never assumes LocalWP; only `BASE_URL` and the provisioning env vars differ.

## Phasing

- **Phase 1 — Provisioning + scaffolding + first smoke** (independently shippable: a provisioned LocalWP site, a runnable-if-tiny suite, tooling wired)
- **Phase 2 — Core journey suite** (ships the 14 real-stack tests)
- **Phase 3 — Failure modes + degraded environment** (ships mocks + the mutation projects with restore guarantees)
- **Phase 4 — CI wiring + release gate** (ships the `e2e` job, flips `release.needs`, docs)

Each phase leaves `main` releasable; Phases 1–3 are fully exercisable against LocalWP with no CI dependency.

## Implementation Plan

### Phase 1 — Provisioning + local smoke

1. Write `bin/e2e-provision.sh`: `set -euo pipefail`, the env contract above, wp-cli wrapper function, steps — activate WooCommerce + `woocommerce_coming_soon=no`; activate plugin; permalinks `/%postname%/` + hard flush; seed catalog (`count=48 mode=mixed seed=6464 reset`, path computed from script dir); set the five options (rate limit, suggestions, archive gate, taxonomy scopes) *before* regen; `wp shift64-woo-search setup --host --port`; `wp shift64-woo-search rebuild`; create `search-e2e` page by slug if missing; verification tail (`health`, `test "athena"` expecting results, `BASE_URL` smoke-curl expecting `"success":true`). *Test: run twice from the LocalWP shell — the second run completes end-to-end without error (reprovision-safe: `reset` reseeds the catalog, page creation is skipped, no duplicate `search-e2e` page exists); `health` green; smoke-curl passes; `/search-e2e/` renders both blocks in a browser.*
2. Add `@playwright/test` to devDependencies, the four npm scripts, `playwright.config.ts` (main project only at this point, `workers: 1`, `BASE_URL` env, artifact settings), `.gitignore` entries (`playwright-report/`, `test-results/`, `blob-report/`), and `tests/e2e/helpers/{env,search,mocks}.ts`. *Test: `npm ci && npx playwright install chromium` clean; `npx playwright test --list` runs without error; existing gate (`composer validate --strict && vendor/bin/phpcs && vendor/bin/phpunit`) still green.*
3. Write the first two smoke tests (#1 dropdown visible, #7 submit journey) in `specs/search-dropdown.spec.ts`. *Test: `BASE_URL=http://<localwp-host> npm run test:e2e` passes; break an assertion deliberately to confirm trace/screenshot artifacts are produced, then revert.*

### Phase 2 — Core journey suite

4. Complete `search-dropdown.spec.ts` (#2–#6), using helper page-objects and `page.waitForRequest` for the fetch-count assertions (#3, #6). *Test: suite green locally 3× consecutively (flake check).*
5. Write `search-results-page.spec.ts` (#8–#11). *Test: green locally; verify the `filter_pa_color=green` URL param and the price ordering once by hand against the seeded catalog before trusting the assertions.*
6. Write `category-archive.spec.ts` (#12) and `modal.spec.ts` (#13–#14, mobile viewport via `test.use`). *Test: green locally including the mobile describe; full main project completes in ≤ ~2 min locally.*

### Phase 3 — Failure modes + degraded environment

7. Write `specs/failure-modes.spec.ts` (#15–#17) with payloads in `helpers/mocks.ts` copied from the verified contracts (`mu-plugins/endpoint.php:106-135`). *Test: green locally; deliberately corrupt one mock body shape to confirm the assertion actually depends on the contract, then revert.*
8. Add the `degrade-env` / `degraded` / `restore-env` projects to `playwright.config.ts`; write `degrade.setup.ts` (option flip + `wp eval` config regen via the `env.ts` wp-cli wrapper), `degraded.spec.ts` (#18–#19), `restore.teardown.ts` (real `setup --host --port`). *Test: full local run passes AND leaves LocalWP healthy (`wp shift64-woo-search health` green afterwards); interrupt a run after degrade, confirm the documented recovery (`npm run e2e:provision`) restores the site.*

### Phase 4 — CI wiring + release gate

9. Write `bin/e2e-install-wp.sh` and add the `e2e` job to `.github/workflows/release.yml`: mysql + redis-stack services with health checks, setup-php 8.3 (`extensions: mysqli, mbstring, intl, redis`, `tools: composer:v2, wp-cli`), install + provision scripts, `PHP_CLI_SERVER_WORKERS=4 wp server` with readiness curl loop, setup-node with npm cache, `npm ci`, `playwright install --with-deps chromium`, `npx playwright test`, failure-only artifact upload (`playwright-report/`, `test-results/`, `/tmp/wp-server.log`). *Test: job green on a draft PR; logs confirm `wp server` exists and the provisioning smoke-curl passed (retiring both flagged assumptions); if `wp server` is missing, land the `php -S` + committed-router fallback in the same PR; total job time within the 6–9 min budget.*
10. Flip `release` to `needs: [test, e2e]`; register `E2E (Playwright)` as a required status check on `main`'s branch protection (repo settings or `gh api repos/{owner}/{repo}/branches/main/protection`); add the `.distignore` entries; add the `npm run test:e2e` command note to `AGENTS.md` (requires a provisioned live site — `bin/e2e-provision.sh`; `BASE_URL` selects target; never part of the agentic validation gate). *Test: `gh api` (or the settings UI) lists `E2E (Playwright)` among required status checks and a test PR cannot merge while it is red; a push to `main` runs `test` + `e2e` before semantic-release; the release ZIP from `build-release.sh` contains no e2e files.*
