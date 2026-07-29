<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Shift64_Woo_Search
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 *
 * The main plugin file guards on `woocommerce/woocommerce.php` being in
 * active_plugins and early-returns otherwise — which skips loading every
 * class. In the test environment we don't install WooCommerce, so fake
 * the option before requiring the plugin so class files get included.
 *
 * TODO: Replace this fake with a real WooCommerce test-harness once tests
 * exercise integration paths (WC_Product, taxonomies, cart, etc.). The
 * static array works only for unit tests that don't touch WC runtime.
 */
function _manually_load_plugin() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	add_filter(
		'pre_option_active_plugins',
		function () {
			return array( 'woocommerce/woocommerce.php' );
		}
	);
	require dirname( __DIR__ ) . '/shift64-woo-search.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Stub WooCommerce helpers used by code paths that get exercised in tests.
 *
 * The plugin's sync handlers call `wc_get_products()` to translate category
 * slugs into product IDs. Since WooCommerce isn't installed in the test
 * environment, we provide a no-op stub that returns an empty result. Tests
 * that need to assert real product-to-category mapping should mock the
 * indexer layer directly rather than relying on this stub.
 */
if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * Test stub for `wc_get_products()` — returns empty so post-delete sync handlers
	 * short-circuit cleanly without needing a real WooCommerce data layer.
	 *
	 * @param array $args Query arguments (ignored in the stub).
	 * @return array Empty list of product IDs.
	 */
	function wc_get_products( $args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter
		return array();
	}
}

/**
 * The Facets admin section lists WooCommerce's global product attributes. A store
 * without WooCommerce has none, which is exactly the branch this stub produces —
 * so the section still renders its category and brand controls under test.
 */
if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
	/**
	 * Test stub for `wc_get_attribute_taxonomies()` — no global attributes registered.
	 *
	 * @return array Empty list of attribute taxonomy objects.
	 */
	function wc_get_attribute_taxonomies() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return array();
	}
}

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
