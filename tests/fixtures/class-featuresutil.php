<?php
/**
 * Test double for the WooCommerce features API.
 *
 * WooCommerce is not installed in the unit-test environment, so the class the
 * plugin declares its feature compatibility against does not exist. This stand
 * -in records declarations instead of registering them, which is what lets
 * `test-woocommerce-feature-compatibility.php` fire the real hook and assert on
 * the real arguments.
 *
 * It has to live in WooCommerce's own namespace — a test double is only
 * substitutable if it answers to the name the production code calls — and in
 * its own file, because both facts together are what the coding standard
 * expects of a class.
 *
 * @package Shift64_Woo_Search
 */

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( __NAMESPACE__ . '\FeaturesUtil' ) ) {
	/**
	 * Minimal stand-in for `Automattic\WooCommerce\Utilities\FeaturesUtil`.
	 */
	class FeaturesUtil {

		/**
		 * Declarations recorded since the last reset.
		 *
		 * @var array<int,array{feature:string,plugin:string,value:bool}>
		 */
		public static $shift64_woo_search_declarations = array();

		/**
		 * Record a compatibility declaration.
		 *
		 * @param string $feature_id  Feature identifier.
		 * @param string $plugin_file Absolute path to the declaring plugin file.
		 * @param bool   $positive    Whether the plugin declares itself compatible.
		 */
		public static function declare_compatibility( $feature_id, $plugin_file, $positive = true ) {
			self::$shift64_woo_search_declarations[] = array(
				'feature' => $feature_id,
				'plugin'  => $plugin_file,
				'value'   => $positive,
			);
		}
	}
}
