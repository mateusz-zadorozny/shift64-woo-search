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
- Minimum runtime: WordPress 6.0 and PHP 7.4

Redis and RediSearch remain the only search backend in this repository. Do not add Elasticsearch or Elastica.
