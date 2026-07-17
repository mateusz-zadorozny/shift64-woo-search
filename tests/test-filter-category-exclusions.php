<?php
/**
 * Tests for frontend category exclusion sources.
 *
 * @package Shift64_Woo_Search
 */

class Filter_Category_Exclusions_Test extends WP_UnitTestCase {

	/**
	 * Plugin-level excluded category IDs should be merged into the frontend exclusion list.
	 */
	public function test_plugin_category_exclusions_are_merged() {
		update_option( 'default_product_cat', 12 );
		update_option( 'shift64_woo_search_filter_categories_excluded', array( 56, 78 ) );

		$filters = new Shift64_Woo_Search_Filters();
		$method  = new ReflectionMethod( Shift64_Woo_Search_Filters::class, 'get_excluded_category_ids' );

		$result = $method->invoke( $filters );
		sort( $result );

		$this->assertSame( array( 12, 56, 78 ), $result );
	}
}
