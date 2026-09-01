<?php
/**
 * Guards the WooCommerce feature-compatibility declaration.
 *
 * WooCommerce sorts every active plugin into compatible / incompatible /
 * uncertain per feature, and a plugin that declares nothing lands in
 * "uncertain" — which the Plugins screen renders under the heading
 * "Incompatible with WooCommerce features", indistinguishable from a plugin
 * HPOS genuinely breaks. This plugin is a catalog search engine that never
 * reads or writes an order, a cart or a checkout, so the declaration is simply
 * the truth, and these tests stop it being dropped by accident.
 *
 * WooCommerce is not installed in the test environment, so the features API is
 * supplied by the `fixtures/class-featuresutil.php` double that `bootstrap.php`
 * loads, and the declaration hook is fired directly.
 *
 * @package Shift64_Woo_Search
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * WooCommerce feature declaration tests.
 */
class Woocommerce_Feature_Compatibility_Test extends WP_UnitTestCase {

	/**
	 * Features the plugin must declare itself compatible with.
	 *
	 * @var string[]
	 */
	const REQUIRED_FEATURES = array( 'custom_order_tables', 'cart_checkout_blocks' );

	public function set_up() {
		parent::set_up();
		FeaturesUtil::$shift64_woo_search_declarations = array();
	}

	/**
	 * Absolute path to the main plugin file, which is what a declaration names.
	 *
	 * @return string
	 */
	private function plugin_file() {
		return dirname( __DIR__ ) . '/shift64-woo-search.php';
	}

	/**
	 * The declaration is hooked where WooCommerce will still accept it.
	 *
	 * `before_woocommerce_init` is the last point at which the features
	 * controller takes declarations; anything later is ignored in silence, which
	 * is exactly the failure mode this test exists to catch.
	 */
	public function test_the_declaration_is_hooked_to_before_woocommerce_init() {
		$this->assertNotFalse(
			has_action( 'before_woocommerce_init' ),
			'The plugin must declare its WooCommerce feature compatibility on before_woocommerce_init.'
		);
	}

	/**
	 * Firing the hook declares compatibility with HPOS and block checkout.
	 */
	public function test_hpos_and_block_checkout_compatibility_is_declared() {
		do_action( 'before_woocommerce_init' );

		$declared = array();
		foreach ( FeaturesUtil::$shift64_woo_search_declarations as $declaration ) {
			if ( $this->plugin_file() !== $declaration['plugin'] ) {
				continue;
			}
			$this->assertTrue(
				$declaration['value'],
				'Compatibility with ' . $declaration['feature'] . ' must be declared positively.'
			);
			$declared[] = $declaration['feature'];
		}

		foreach ( self::REQUIRED_FEATURES as $feature ) {
			$this->assertContains(
				$feature,
				$declared,
				'The plugin must declare compatibility with the "' . $feature . '" WooCommerce feature.'
			);
		}
	}

	/**
	 * A missing features API is tolerated rather than fatal.
	 *
	 * The hook also fires on WooCommerce builds that predate the features
	 * controller. Declaring against a class that is not there must not take the
	 * site down.
	 */
	public function test_the_declaration_is_guarded_against_a_missing_features_api() {
		$source = file_get_contents( $this->plugin_file() );

		$this->assertStringContainsString(
			'class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class )',
			$source,
			'The declaration must be guarded by a class_exists() check on the features API.'
		);
	}
}
