<?php
/**
 * Tests for attribute-unit expansion in the indexer.
 *
 * @package Shift64_Woo_Search
 */

if ( ! function_exists( 'wc_placeholder_img_src' ) ) {
	/**
	 * Return an empty placeholder URL in the WooCommerce-free unit test harness.
	 *
	 * @return string
	 */
	function wc_placeholder_img_src() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return '';
	}
}

/**
 * Unit-expansion helpers in Shift64_Woo_Search_Indexer.
 */
class IndexerUnitExpansionTest extends WP_UnitTestCase {

	public function test_product_data_ignores_variation_skus() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$product = $this->getMockBuilder( stdClass::class )
			->addMethods(
				array(
					'get_id',
					'get_attributes',
					'get_sku',
					'get_children',
					'is_type',
					'get_image_id',
					'get_name',
					'get_short_description',
					'get_description',
					'get_price',
					'get_regular_price',
					'get_stock_status',
					'get_catalog_visibility',
					'get_type',
					'get_menu_order',
					'get_total_sales',
					'get_average_rating',
				)
			)
			->getMock();

		$product->method( 'get_id' )->willReturn( $post_id );
		$product->method( 'get_attributes' )->willReturn( array() );
		$product->method( 'get_sku' )->willReturn( 'PARENT-SKU' );
		$product->expects( $this->never() )->method( 'get_children' );
		$product->expects( $this->never() )->method( 'is_type' );
		$product->method( 'get_image_id' )->willReturn( 0 );
		$product->method( 'get_name' )->willReturn( 'Variable product' );
		$product->method( 'get_short_description' )->willReturn( '' );
		$product->method( 'get_description' )->willReturn( '' );
		$product->method( 'get_price' )->willReturn( '10.00' );
		$product->method( 'get_regular_price' )->willReturn( '10.00' );
		$product->method( 'get_stock_status' )->willReturn( 'instock' );
		$product->method( 'get_catalog_visibility' )->willReturn( 'visible' );
		$product->method( 'get_type' )->willReturn( 'variable' );
		$product->method( 'get_menu_order' )->willReturn( 0 );
		$product->method( 'get_total_sales' )->willReturn( 0 );
		$product->method( 'get_average_rating' )->willReturn( 0 );

		$indexer = new Shift64_Woo_Search_Indexer( null );
		$data    = $indexer->build_product_data( $product );

		$this->assertSame( 'PARENT-SKU', $data['sku'] );
		$this->assertSame( 'PARENT-SKU', $data['sku_text'] );
	}

	public function test_extracts_unit_from_label() {
		$this->assertSame( 'm', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'długość wstęgi [m]' ) );
		$this->assertSame( 'mm', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'szerokość [mm]' ) );
		$this->assertSame( 'ml', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'pojemność [ml]' ) );
		$this->assertSame( '', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'kolor' ) );
		$this->assertSame( '', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'rozmiar [123]' ) );
	}

	public function test_expands_single_numeric_value() {
		$this->assertSame( array( '30m' ), Shift64_Woo_Search_Indexer::expand_value_with_unit( '30', 'm' ) );
		$this->assertSame( array( '500ml' ), Shift64_Woo_Search_Indexer::expand_value_with_unit( '500', 'ml' ) );
	}

	public function test_rejects_decimals_to_match_query_sanitizer() {
		// sanitize_query strips "." and ",", so indexing "1.5kg" would be unreachable.
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '1.5', 'kg' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '1,5', 'kg' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '1.5 - 2', 'kg' ) );
	}

	public function test_expands_range_with_spaces() {
		$this->assertSame(
			array( '10-12s', '10s', '12s' ),
			Shift64_Woo_Search_Indexer::expand_value_with_unit( '10 - 12', 's' )
		);
	}

	public function test_expands_compact_range() {
		$this->assertSame(
			array( '10-15cm', '10cm', '15cm' ),
			Shift64_Woo_Search_Indexer::expand_value_with_unit( '10-15', 'cm' )
		);
	}

	public function test_returns_empty_for_non_numeric_or_missing_unit() {
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( 'czerwony', 'm' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '30', '' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '', 'm' ) );
	}
}
