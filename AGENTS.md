# AGENTS.md

## Project

Shift64 Woo Search is a WooCommerce plugin that uses RediSearch for product retrieval, autocomplete, and facets while WooCommerce retains rendering and business logic.

The source code, comments, user-facing source strings, and maintained documentation are English. Language-specific fixtures and synonym examples may use their target language.

## Commands

```bash
bin/test-env.sh up      # one-shot isolated QA + PHPUnit env for this worktree (docs/test-environments.md)
bin/test-env.sh status  # truthful health + background-validation state
bin/test-env.sh down    # stop exactly what `up` started

composer install
vendor/bin/phpunit
vendor/bin/phpcs
composer makepot

wp shift64-woo-search setup
wp shift64-woo-search rebuild
wp shift64-woo-search reindex
wp shift64-woo-search status
wp shift64-woo-search test "query"
wp shift64-woo-search health
```

### Spec lifecycle

Specs live in `.ai/specs/` and every file carries a `> **Status:**` line under
its title, mirrored in the `.ai/specs/README.md` index table. A PR that
implements a spec MUST flip that spec's Status header (`draft` →
`implemented — PR #N, date`) and the index row **in the same PR**. Never move,
rename, or delete spec files — their paths are referenced from other specs,
`.ai/runs/` plans, and PR bodies.

### E2E (Playwright)

`npm run test:e2e` requires a provisioned live site (`bin/e2e-provision.sh`);
`BASE_URL` selects the target (defaults to the CI `wp server` at
`http://127.0.0.1:8889`; use `BASE_URL=http://<site>.local` for LocalWP).
The suite's degraded project REALLY mutates the target site's Redis config
and restores it in a teardown — if an aborted run leaves the site broken,
re-run `npm run e2e:provision`. The environment's default theme is the block
theme (`E2E_BLOCK_THEME`, default `twentytwentyfive`); the `classic-theme`
project REALLY switches the site to `E2E_CLASSIC_THEME` (default
`storefront`) for the plugin-owned classic AJAX-swap journeys and restores
the previous theme in the spec's `afterAll`. If a hard-killed run leaves the
wrong theme active, run `wp theme activate twentytwentyfive`, and delete
`wp-content/mu-plugins/shift64-e2e-force-page-reload.php` and
`wp-content/mu-plugins/shift64-e2e-product-filters.php` (scenario fixtures
the `block-theme` project installs and removes around its spec files).

The `block-theme` project encodes the **pagination ownership matrix** decided
in #20: a Product Collection with `data-wp-router-region` belongs to
WooCommerce's Interactivity API, and one with `forcePageReload` belongs to
plain browser navigation. The plugin owns pagination nowhere. The matrix used
to have a third row — classic Woo markup, Kadence and custom pagers owned by
the plugin's AJAX swap — which the block theme-only release removed along with
the swap itself and the `classic-theme` project that covered it. The
`test.fail()` markers these assertions once carried are gone: #20 landed and
they pass. **Do not "fix" a failure here by relaxing an assertion** — a passing
version of the wrong assertion would codify the opposite contract.
`blockified.spec.ts`
keeps its scope to pagination ownership; the Product Filters / Filter Pill
journeys (which go through Woo's router as decided) live in their own
`product-filters.spec.ts` within this project because they need the block
theme active. Never add Playwright to the agentic
validation gate (`.ai/agentic.config.json` `validation.commands`): the gate
must stay hermetic, and the degraded project would corrupt the dev site.
CI enforcement lives in `.github/workflows/release.yml` (`e2e` job;
`release` needs it).

## Architecture

```text
Search field -> SHORTINIT endpoint -> Redis FT.SEARCH -> JSON -> frontend dropdown
```

Full WordPress requests read `wp_options`. The SHORTINIT endpoint reads constants from the generated `wp-content/mu-plugins/shift64-woo-search/config.php`. Regenerate that file after changing search settings.

Source MU-plugin files live in `mu-plugins/` and are deployed on activation, plugin update, or `wp shift64-woo-search setup`. Never commit a generated `config.php`.

## Conventions

- Classes: `Shift64_Woo_Search_*`
- Functions, options, and custom hooks: `shift64_woo_search_*`
- Constants: `SHIFT64_WOO_SEARCH_*`
- Text domain and plugin slug: `shift64-woo-search`
- Redis keys: `{prefix}:product:{id}`; product index: `{prefix}_product_idx`
- Minimum runtime: WordPress 7.0, WooCommerce 10.9, and PHP 8.3

Redis and RediSearch remain the only search backend in this repository. Do not add Elasticsearch or Elastica.
