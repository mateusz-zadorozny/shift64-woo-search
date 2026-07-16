# Shift64 Woo Search

Shift64 Woo Search is a Redis Stack–powered product search engine for WooCommerce. It keeps WooCommerce responsible for catalog rendering and business rules while RediSearch handles retrieval, autocomplete, filtering, and relevance.

This repository currently contains the `0.x` development line. The first public, compatibility-stable release is planned as `1.0.0`.

## Requirements

- WordPress 6.0 or newer
- WooCommerce
- PHP 7.4 or newer with the `redis` extension
- Redis Stack or another Redis deployment with the RediSearch module

## Search flow

```text
Search field -> SHORTINIT endpoint -> FT.SEARCH -> ranked product IDs -> WooCommerce rendering
```

The query engine uses a strict-first cascade:

1. AND prefix search.
2. AND prefix search after optional weak-token reduction.
3. OR prefix fallback with term-coverage scoring and a minimum match ratio.
4. Fuzzy fallback.

Results can then be re-ranked by exact title position, SKU match, category or tag rules, promoted-product status, and stock status. See [Search Result Controls](docs/search-result-controls-audit.md) for the complete inventory.

## Installation for development

1. Place this repository in `wp-content/plugins/shift64-woo-search`.
2. Run `composer install` and `npm ci`.
3. Activate WooCommerce and Shift64 Woo Search.
4. Run `wp shift64-woo-search setup`.
5. Run `wp shift64-woo-search rebuild`.

The setup command deploys the source files from `mu-plugins/` to the site's `wp-content/mu-plugins/` directory and generates a site-specific `config.php`. That generated file contains connection details and is never part of a release package.

## WP-CLI

```bash
wp shift64-woo-search setup
wp shift64-woo-search rebuild
wp shift64-woo-search reindex
wp shift64-woo-search status
wp shift64-woo-search test "search phrase"
wp shift64-woo-search health
```

## Development commands

```bash
composer test
composer lint
composer makepot
npm ci
bash build-release.sh 0.1.0
```

## Configuration modes

In a normal WordPress request, settings are read from `wp_options`. The SHORTINIT endpoint reads constants from its generated `config.php`, so regenerate that configuration after changing search settings.

## Distribution direction

The product is designed to support two connection modes:

- Bring Your Own Redis for the initial public plugin and technical validation.
- A managed Shift64 service, activated with a service key, after the BYOR version is proven.

The commercial and hosted-service plans are documented in [Product Roadmap](docs/product-roadmap.md) and [Hosted MVP Plan](docs/hosted-mvp-plan.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
