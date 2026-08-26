<?php
/**
 * Tests for block-theme test-environment product template provisioning.
 *
 * @package Shift64_Woo_Search
 * @subpackage Test_Environment
 */

/** Load the pure template transformer under test. */
require_once dirname( __DIR__ ) . '/bin/test-env-template-controls.php';

/**
 * Pure contract tests for the archive template transformer.
 */
class Shift64_Test_Env_Template_Controls_Test extends WP_UnitTestCase {

	/**
	 * The target list covers every WooCommerce blockified product archive.
	 */
	public function test_product_template_slugs_cover_product_archives() {
		$this->assertSame(
			array(
				'archive-product',
				'product-search-results',
				'taxonomy-product_attribute',
			),
			shift64_woo_search_test_env_product_template_slugs()
		);
	}

	/**
	 * Current checkouts replace core sorting and add both Shift64 controls.
	 */
	public function test_current_blocks_are_seeded_next_to_the_results_count() {
		$source = "<!-- wp:woocommerce/product-results-count /-->\n<!-- wp:woocommerce/catalog-sorting /-->";
		$actual = shift64_woo_search_test_env_transform_product_template(
			$source,
			array( 'shift64-woo-search/product-filters', 'shift64-woo-search/product-sort' ),
			'archive-product'
		);

		$this->assertStringContainsString( 'shift64-woo-search/product-filters', $actual );
		$this->assertStringContainsString( 'shift64-woo-search/filter-pill {"facet":"product_cat"}', $actual );
		$this->assertStringContainsString( 'shift64-woo-search/filter-pill {"facet":"pa_color"}', $actual );
		$this->assertStringContainsString( 'shift64-woo-search/product-sort', $actual );
		$this->assertStringNotContainsString( 'woocommerce/catalog-sorting', $actual );
		$this->assertLessThan(
			strpos( $actual, 'woocommerce/product-results-count' ),
			strpos( $actual, 'shift64-woo-search/product-filters' )
		);
	}

	/**
	 * A checkout without Product Sort keeps WooCommerce's native sorter alive.
	 */
	public function test_missing_product_sort_uses_the_woocommerce_fallback() {
		$source = "<!-- wp:woocommerce/product-results-count /-->\n<!-- wp:woocommerce/catalog-sorting /-->";
		$actual = shift64_woo_search_test_env_transform_product_template(
			$source,
			array( 'shift64-woo-search/product-filters' ),
			'product-search-results'
		);

		$this->assertStringContainsString( 'woocommerce/catalog-sorting', $actual );
		$this->assertStringContainsString( 'shift64-woo-search/product-filters', $actual );
		$this->assertStringNotContainsString( 'shift64-woo-search/product-sort', $actual );
	}

	/**
	 * Rerunning provisioning does not duplicate the filter parent.
	 */
	public function test_transform_is_idempotent() {
		$source = '<!-- wp:woocommerce/product-results-count /--><!-- wp:woocommerce/catalog-sorting /-->';
		$once   = shift64_woo_search_test_env_transform_product_template(
			$source,
			array( 'shift64-woo-search/product-filters', 'shift64-woo-search/product-sort' ),
			'taxonomy-product_attribute'
		);

		$this->assertSame(
			$once,
			shift64_woo_search_test_env_transform_product_template(
				$once,
				array( 'shift64-woo-search/product-filters', 'shift64-woo-search/product-sort' ),
				'taxonomy-product_attribute'
			)
		);
	}
}
