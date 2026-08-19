<?php
/**
 * Tests for the Shift64 Product Sort block.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Product sort block tests.
 */
class Shift64_Woo_Search_Product_Sort_Block_Test extends WP_UnitTestCase {

	/**
	 * Setup before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'woocommerce_default_catalog_orderby' );
		$_GET = array();
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Block is properly registered in the WP_Block_Type_Registry.
	 */
	public function test_block_is_registered() {
		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/product-sort' ) );

		$block_type = $registry->get_registered( 'shift64-woo-search/product-sort' );
		$this->assertSame( 'woocommerce', $block_type->category );
		$this->assertArrayHasKey( 'orderedOptions', $block_type->attributes );
		$this->assertArrayHasKey( 'labels', $block_type->attributes );
		$this->assertFalse( $block_type->supports['html'] );
		$this->assertTrue( $block_type->supports['interactivity'] );
	}

	/**
	 * Rendering on non-search pages includes menu_order and excludes relevance.
	 */
	public function test_render_non_search_catalog() {
		$html = do_blocks( '<!-- wp:shift64-woo-search/product-sort /-->' );

		$this->assertStringContainsString( 'class="shift64-woo-search-product-sort', $html );
		$this->assertStringContainsString( 'data-wp-interactive="shift64/woo-search-product-sort"', $html );
		$this->assertStringContainsString( 'value="menu_order"', $html );
		$this->assertStringContainsString( 'value="popularity"', $html );
		$this->assertStringContainsString( 'value="price"', $html );
		$this->assertStringNotContainsString( 'value="relevance"', $html );
		$this->assertStringContainsString( 'selected', $html );
	}

	/**
	 * Rendering in search context remaps menu_order to relevance and excludes menu_order.
	 */
	public function test_render_search_context_remaps_menu_order_to_relevance() {
		$_GET['s']         = 'hoodie';
		$_GET['post_type'] = 'product';

		$html = do_blocks( '<!-- wp:shift64-woo-search/product-sort /-->' );

		$this->assertStringContainsString( 'value="relevance"', $html );
		$this->assertStringNotContainsString( 'value="menu_order"', $html );
		$this->assertStringContainsString( 'value="price"', $html );
	}

	/**
	 * Custom orderedOptions and custom labels are honored.
	 */
	public function test_render_custom_ordered_options_and_labels() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/product-sort {"orderedOptions":["price","popularity"],"labels":{"price":"Cheapest First","popularity":"Best Sellers"}} /-->'
		);

		$this->assertStringContainsString( 'Cheapest First', $html );
		$this->assertStringContainsString( 'Best Sellers', $html );
		$this->assertStringNotContainsString( 'value="menu_order"', $html );
		$this->assertStringNotContainsString( 'value="rating"', $html );
	}

	/**
	 * Direct URL with unlisted or custom orderby injects temporary selected option.
	 */
	public function test_render_direct_url_with_unlisted_orderby() {
		$_GET['orderby'] = 'price-desc';

		$html = do_blocks(
			'<!-- wp:shift64-woo-search/product-sort {"orderedOptions":["menu_order","price"]} /-->'
		);

		// price-desc was not in orderedOptions, but is injected because ?orderby=price-desc was requested.
		$this->assertStringContainsString( 'value="price-desc"', $html );
		$this->assertStringContainsString( '<option value="price-desc"  selected=\'selected\'>', $html );
	}

	/**
	 * Hidden inputs preserve facet filters and query parameters for non-JS submission.
	 */
	public function test_render_preserves_get_parameters_in_hidden_inputs() {
		$_GET['filter_color'] = 'blue';
		$_GET['min_price']    = '20';
		$_GET['orderby']      = 'price';

		$html = do_blocks( '<!-- wp:shift64-woo-search/product-sort /-->' );

		$this->assertStringContainsString( 'name="filter_color" value="blue"', $html );
		$this->assertStringContainsString( 'name="min_price" value="20"', $html );
		// orderby should not be duplicated as hidden input.
		$this->assertStringNotContainsString( '<input type="hidden" name="orderby"', $html );
	}

	/**
	 * Empty active options returns empty string cleanly.
	 */
	public function test_render_empty_options_returns_empty_string() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/product-sort {"orderedOptions":["non_existent_key"]} /-->'
		);

		$this->assertSame( '', $html );
	}
}
