=== Shift64 Woo Search ===
Contributors: mateuszzadorozny
Tags: woocommerce, search, redis, redisearch, autocomplete
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.3
Requires Plugins: woocommerce
Stable tag: 0.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast WooCommerce product search, autocomplete, and relevance tuning powered by RediSearch.

== Description ==

Shift64 Woo Search replaces WooCommerce product retrieval with a RediSearch-backed search engine while leaving product rendering and business logic in WooCommerce.

Features include:

* A fast SHORTINIT autocomplete endpoint.
* Strict-first search with token reduction, OR fallback, and fuzzy fallback.
* Synonym expansion, including multi-word and one-way rules.
* Configurable field weights and result-ranking controls.
* Product, category, attribute, brand, and SKU search, using WooCommerce's native product brands.
* Configurable autocomplete density and result-tray width.
* A settings screen organised into six workspaces, from connection and indexing through relevance tuning and diagnostics.
* Search statistics and diagnostic tools, including an opt-in storefront debug panel — visible only to users who can manage WooCommerce, never to shoppers — that breaks a query down into request-phase and browser timings.
* WP-CLI commands for setup, indexing, tests, and health checks.
* Shift64 Product Search and Shift64 Modal Product Search blocks on WordPress 7.0+.
* A `[shift64_woo_search]` product search form shortcode for classic themes.
* A `[shift64_woo_search_modal]` compact modal search shortcode for classic themes.

The plugin requires Redis Stack, or another Redis server with RediSearch, and the PHP Redis extension.

== Installation ==

1. Install and activate WooCommerce.
2. Make a Redis deployment with RediSearch available to WordPress.
3. Install and activate Shift64 Woo Search.
4. Run `wp shift64-woo-search setup` to configure the connection.
5. Run `wp shift64-woo-search rebuild` to create and populate the index.

== Frequently Asked Questions ==

= Does this plugin include Redis? =

No. The plugin is Bring Your Own Redis: you point it at a Redis Stack deployment, or any Redis server with the RediSearch module, that you control. A managed connection option is planned separately and would be opt-in.

= Does it replace WooCommerce templates? =

No. Redis retrieves product IDs and applies WooCommerce's product-search visibility contract, excluding hidden and catalog-only products. WooCommerce remains responsible for catalog rendering, pricing, taxes, and direct product access.

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

= 0.12.3 =

Maintenance release: the plugin's GPLv2-or-later licensing and Shift64 attribution
are now stated consistently across the package. No configuration change is needed,
and no reindex is required.
