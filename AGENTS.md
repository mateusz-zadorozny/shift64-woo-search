# AGENTS.md

## Project

Shift64 Woo Search is a WooCommerce plugin that uses RediSearch for product retrieval, autocomplete, and facets while WooCommerce retains rendering and business logic.

The source code, comments, user-facing source strings, and maintained documentation are English. Language-specific fixtures and synonym examples may use their target language.

## Commands

```bash
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
re-run `npm run e2e:provision`. Never add Playwright to the agentic
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
- Minimum runtime: WordPress 6.0 and PHP 8.3

Redis and RediSearch remain the only search backend in this repository. Do not add Elasticsearch or Elastica.
