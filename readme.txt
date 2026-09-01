=== Shift64 Woo Search ===
Contributors: mateuszzadorozny
Tags: woocommerce, search, redis, redisearch, autocomplete
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
WC requires at least: 10.9
WC tested up to: 11.0
Requires Plugins: woocommerce
Stable tag: 0.21.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast WooCommerce product search, autocomplete, and relevance tuning powered by RediSearch.

== Description ==

Shift64 Woo Search replaces WooCommerce product retrieval with a RediSearch-backed search engine while leaving product rendering and business logic in WooCommerce.

The storefront is built from Site Editor blocks: search, filters, sorting and the results grid are blocks you place in your block templates around an inherited WooCommerce Product Collection. A block theme is required for the storefront controls. On a classic theme the plugin still indexes, searches and serves its autocomplete endpoint, but it adds no controls to the theme's output.

Features include:

* A fast SHORTINIT autocomplete endpoint.
* Typo-tolerant matching: every word matches as prefix or fuzzy, with an optional strict-first cascade.
* Synonym expansion, including multi-word and one-way rules.
* Configurable field weights and result-ranking controls.
* Product, category, attribute, brand, and SKU search, using WooCommerce's native product brands.
* Configurable autocomplete density, with per-placement tray width on the Search Panel block.
* A settings screen organised into six workspaces, from connection and indexing through relevance tuning and diagnostics.
* Search statistics and diagnostic tools, including an opt-in storefront debug panel — visible only to users who can manage WooCommerce, never to shoppers — that breaks a query down into request-phase and browser timings.
* WP-CLI commands for setup, indexing, tests, and health checks.
* Composable Shift64 Product Search and Modal Product Search blocks with independently styleable Control and Panel children.
* Product Filters, Filter Pill and Product Sort blocks that drive an inherited WooCommerce Product Collection.
* Progressive native search forms plus accessible autocomplete and native dialog behavior through the WordPress Interactivity API.

The plugin requires Redis Stack, or another Redis server with RediSearch, and the PHP Redis extension.

== Installation ==

1. Install and activate WooCommerce.
2. Make a Redis deployment with RediSearch available to WordPress.
3. Install and activate Shift64 Woo Search.
4. Run `wp shift64-woo-search setup` to configure the connection.
5. Run `wp shift64-woo-search rebuild` to create and populate the index.
6. In Appearance > Editor, add the Shift64 blocks to your product templates: Search or Modal Search where the search box belongs, and Product Filters, Filter Pill and Product Sort alongside an inherited WooCommerce Product Collection.

== Frequently Asked Questions ==

= Does this plugin include Redis? =

No. The plugin is Bring Your Own Redis: you point it at a Redis Stack deployment, or any Redis server with the RediSearch module, that you control. A managed connection option is planned separately and would be opt-in.

= Does it replace WooCommerce templates? =

No. Redis retrieves product IDs and applies WooCommerce's product-search visibility contract, excluding hidden and catalog-only products. WooCommerce remains responsible for catalog rendering, pricing, taxes, and direct product access. The plugin never edits or auto-inserts anything into your block templates; you place its blocks yourself in the Site Editor.

= Does it work with a classic theme? =

Indexing, searching, the autocomplete endpoint and the WP-CLI commands all work on any theme. The storefront controls do not: they are Site Editor blocks, and the plugin does not inject markup into a theme it does not own. A block theme is required to place them.

= I upgraded and my search box disappeared. What happened? =

Versions before 0.21.0 could inject a search form, a filter bar and a sort control into a classic theme, and offered `[shift64_woo_search]` and `[shift64_woo_search_modal]` shortcodes. Those were removed in 0.21.0 in favour of one block-based storefront. Replace each shortcode with its matching block and place the controls in your block templates; the migration guide walks through it step by step:
https://github.com/mateusz-zadorozny/shift64-woo-search/blob/main/docs/block-theme-migration.md

== External services ==

This plugin contacts no external service and sends no data off-site. All search
data stays between your WordPress site and the Redis deployment you configure and
control; the plugin makes no other network request of its own.

A managed Redis connection is planned as a future option. It would be opt-in, and
it would ship with its own disclosure of the service used, the data sent, and the
applicable terms, privacy policy, and pricing.

== Changelog ==

The full, per-release changelog lives in the repository:
https://github.com/mateusz-zadorozny/shift64-woo-search/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 0.21.0 =

Breaking storefront change. The classic-theme frontend is removed: the
`[shift64_woo_search]`, `[shift64_woo_search_modal]` and
`[shift64_woo_search_breadcrumbs]` shortcodes, the automatically injected filter
bar, sort control and archive header, and the theme-specific rendering are all
gone. The supported storefront is a block theme with the plugin's Site Editor
blocks placed around an inherited WooCommerce Product Collection.

Before updating: confirm you are on a block theme, find and replace those
shortcodes in your content, and export your block templates so a rollback is
possible. After updating, edit each product template to place the blocks, then
purge your page cache and CDN. Minimum versions are now WordPress 7.0,
WooCommerce 10.9 and PHP 8.3; below them the storefront blocks stop loading while
search, indexing and the CLI keep working. No product data, index or setting is
deleted, and the retired appearance settings keep their stored values so a
rollback finds them. Full steps:
https://github.com/mateusz-zadorozny/shift64-woo-search/blob/main/docs/block-theme-migration.md

= 0.12.3 =

Maintenance release: the plugin's GPLv2-or-later licensing and Shift64 attribution
are now stated consistently across the package. No configuration change is needed,
and no reindex is required.
