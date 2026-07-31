# Shift64 Woo Search

<!-- The CI badge targets .github/workflows/release.yml, whose workflow `name:` is
     "CI". Renaming that workflow file or its name breaks this badge. -->
[![Shift64](https://img.shields.io/badge/Shift64-search-000000?style=flat-square)](https://shift64.com)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue?style=flat-square)](LICENSE)
[![Release](https://img.shields.io/github/v/release/mateusz-zadorozny/shift64-woo-search?style=flat-square)](https://github.com/mateusz-zadorozny/shift64-woo-search/releases)
[![CI](https://img.shields.io/github/actions/workflow/status/mateusz-zadorozny/shift64-woo-search/release.yml?branch=main&style=flat-square&label=CI)](https://github.com/mateusz-zadorozny/shift64-woo-search/actions/workflows/release.yml)

Shift64 Woo Search is a Redis Stack–powered product search engine for WooCommerce. It keeps WooCommerce responsible for catalog rendering and business rules while RediSearch handles retrieval, autocomplete, filtering, and relevance.

This repository currently contains the `0.x` development line. The first public, compatibility-stable release is planned as `1.0.0`.

## Requirements

- WordPress 6.0 or newer
- WooCommerce
- PHP 8.3 or newer with the `redis` extension
- Redis Stack or another Redis deployment with the RediSearch module

## Installation

1. Place this repository in `wp-content/plugins/shift64-woo-search`.
2. Run `composer install` and `npm ci`.
3. Activate WooCommerce and Shift64 Woo Search.
4. Run `wp shift64-woo-search setup`.
5. Run `wp shift64-woo-search rebuild`.

The setup command deploys the source files from `mu-plugins/` to the site's `wp-content/mu-plugins/` directory and generates a site-specific `config.php`. That generated file contains connection details and is never part of a release package.

### Configuration modes

In a normal WordPress request, settings are read from `wp_options`. The SHORTINIT endpoint reads constants from its generated `config.php`, so regenerate that configuration after changing search settings.

### WP-CLI

```bash
wp shift64-woo-search setup
wp shift64-woo-search rebuild
wp shift64-woo-search reindex
wp shift64-woo-search status
wp shift64-woo-search test "search phrase"
wp shift64-woo-search health
```

## Search blocks

On WordPress 7.0 or newer, the editor exposes two server-rendered blocks without
a plugin-specific editor bundle:

- **Shift64 Product Search** — the full search field and submit button.
- **Shift64 Modal Product Search** — a compact magnifier that opens the full-screen search.

Both blocks provide inspector controls for their text and accessibility labels.
The modal block also provides a choice of bundled search icons. Native block
controls cover colors, typography, spacing, borders, alignment, and style
variations. The editor uses the same PHP renderers as the frontend, including a
contained open-modal preview.

The blocks remain registered for server-side rendering on WordPress 6.x, but the
automatic inserter and inspector UI require WordPress 7.0.

For block-theme archive grids, Shift64 integrates with one inherited
WooCommerce Product Collection and leaves Product Template rendering,
pagination, history, and accessibility to WooCommerce and WordPress. See
[Block Theme Product Collection Integration](docs/block-theme-product-collection.md)
for the supported Site Editor template and canonical URL contract.

## Search shortcodes

Both renderers remain available as permanent shortcodes for classic themes,
widgets, existing content, and page builders:

```text
[shift64_woo_search]
[shift64_woo_search_modal]
```

It renders a native WooCommerce product search form and uses the plugin's default
`.shift64-woo-search-field__input` selector for autocomplete. The visible copy can
be customized per instance:

```text
[shift64_woo_search placeholder="Find products..." button="Go" label="Search the catalog"]
[shift64_woo_search_modal placeholder="Find products..." button="Go" label="Search the catalog" trigger_label="Open product search" close_label="Close search" clear_label="Clear search" icon="alternative"]
```

## Development

Install the plugin as described in [Installation](#installation), then use these
commands:

```bash
composer test
composer lint
composer makepot
npm ci
bash build-release.sh 0.1.0
```

How to propose a change — branching, commit format, the review gate, the agent
pipeline, and the repository's spec and E2E rules — is documented in
[CONTRIBUTING.md](CONTRIBUTING.md). Naming, architecture, and agent-facing
conventions live in [AGENTS.md](AGENTS.md).

## Architecture

```text
Search field -> SHORTINIT endpoint -> FT.SEARCH -> ranked product IDs -> WooCommerce rendering
```

The query engine uses a strict-first cascade:

1. AND prefix search.
2. AND prefix search after optional weak-token reduction.
3. OR prefix fallback with term-coverage scoring and a minimum match ratio.
4. Fuzzy fallback.

Results can then be re-ranked by exact title position, SKU match, category or tag rules, promoted-product status, and stock status.

For the full picture see [Search Architecture](docs/search-architecture.md) and the
[Search Result Controls](docs/search-result-controls-audit.md) inventory.

## Distribution direction

The product is designed to support two connection modes:

- Bring Your Own Redis for the initial public plugin and technical validation.
- A managed Shift64 service, activated with a service key, after the BYOR version is proven.

The plugin is GPL and fully useful on its own; the commercial direction is
documented in [Distribution and Commercial Plan](docs/distribution-and-commercial-plan.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Built by [Shift64](https://shift64.com).
