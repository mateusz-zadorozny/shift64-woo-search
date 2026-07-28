=== Shift64 Woo Search ===
Contributors: mateuszzadorozny
Tags: woocommerce, search, redis, redisearch, autocomplete
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 0.9.1
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
* Product, category, attribute, and SKU search.
* Search statistics and diagnostic tools.
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

No. Version 0.1.0 uses Bring Your Own Redis. A managed service is planned separately.

= Does it replace WooCommerce templates? =

No. Redis retrieves product IDs; WooCommerce remains responsible for catalog rendering, pricing, taxes, and visibility rules.

== Changelog ==

= 0.1.0 =

* Initial Shift64 Woo Search development release.
