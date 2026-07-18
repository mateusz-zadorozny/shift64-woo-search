<?php
/**
 * Tests for the brand suggestion blob and its ranking.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Brand blob + ranking tests.
 */
class Brand_Suggest_Test extends WP_UnitTestCase {

	/**
	 * Build a blob entry in the shape cache_brands_to_redis() writes.
	 *
	 * @param string $name  Brand name.
	 * @param int    $count Product count.
	 * @return array
	 */
	private function entry( $name, $count ) {
		return array(
			'name'       => $name,
			'name_ascii' => strtolower( $name ),
			'slug'       => sanitize_title( $name ),
			'url'        => 'https://example.com/brand/' . sanitize_title( $name ) . '/',
			'count'      => $count,
		);
	}

	/**
	 * An exact match outranks a prefix match, which outranks a substring match.
	 */
	public function test_rank_orders_exact_before_prefix_before_substring() {
		$brands = array(
			$this->entry( 'Studio Aeon', 1 ),
			$this->entry( 'Aeon Reserve', 1 ),
			$this->entry( 'Aeon', 1 ),
		);

		$result = Shift64_Woo_Search_Brand_Suggest::rank( $brands, 'Aeon' );

		$this->assertSame( 'Aeon', $result[0]['name'] );
		$this->assertSame( 'Aeon Reserve', $result[1]['name'] );
		$this->assertSame( 'Studio Aeon', $result[2]['name'] );
	}

	/**
	 * Equally relevant brands are broken by product count, descending.
	 */
	public function test_rank_breaks_ties_on_product_count() {
		$brands = array(
			$this->entry( 'Aeon Everyday', 2 ),
			$this->entry( 'Aeon Reserve', 9 ),
		);

		$result = Shift64_Woo_Search_Brand_Suggest::rank( $brands, 'Aeon' );

		$this->assertSame( 'Aeon Reserve', $result[0]['name'] );
	}

	/**
	 * The result set is capped at five, and the cap is applied after sorting so
	 * it keeps the best matches rather than the first-stored ones.
	 */
	public function test_rank_caps_results_at_five() {
		$brands = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$brands[] = $this->entry( 'Aeon ' . $i, $i );
		}

		$result = Shift64_Woo_Search_Brand_Suggest::rank( $brands, 'Aeon' );

		$this->assertCount( 5, $result );
		$this->assertSame( 'Aeon 11', $result[0]['name'] );
	}

	/**
	 * Typo tolerance is off by default and honoured when requested.
	 */
	public function test_rank_fuzzy_matching_is_opt_in() {
		$brands = array( $this->entry( 'Orpheus Goods', 3 ) );

		$this->assertSame( array(), Shift64_Woo_Search_Brand_Suggest::rank( $brands, 'Orpheas' ) );
		$this->assertCount( 1, Shift64_Woo_Search_Brand_Suggest::rank( $brands, 'Orpheas', true ) );
	}

	/**
	 * An empty or malformed blob ranks to an empty list rather than erroring —
	 * this is the default state of every store without brands.
	 */
	public function test_rank_handles_empty_blob() {
		$this->assertSame( array(), Shift64_Woo_Search_Brand_Suggest::rank( array(), 'Aeon' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Brand_Suggest::rank( null, 'Aeon' ) );
	}

	/**
	 * Extracting the shared scoring core must not have changed category ranking:
	 * both entry points produce identical output for identical input.
	 */
	public function test_shared_core_keeps_category_and_brand_ranking_identical() {
		$entries = array(
			$this->entry( 'Aeon Reserve', 4 ),
			$this->entry( 'Nyx Workshop', 7 ),
		);

		$this->assertSame(
			Shift64_Woo_Search_Category_Suggest::rank( $entries, 'Aeon' ),
			Shift64_Woo_Search_Brand_Suggest::rank( $entries, 'Aeon' )
		);
	}

	/**
	 * The blob is written even when the store has no brands, so the endpoint
	 * sees a definitive empty list instead of stale data.
	 */
	public function test_cache_brands_writes_empty_blob_without_taxonomy() {
		if ( taxonomy_exists( 'product_brand' ) ) {
			unregister_taxonomy( 'product_brand' );
		}

		$written = null;

		$redis = $this->getMockBuilder( 'Shift64_Woo_Search_Redis' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_prefix', 'raw_command' ) )
			->getMock();
		$redis->method( 'get_prefix' )->willReturn( 'shift64_woo_search' );
		$redis->method( 'raw_command' )->willReturnCallback(
			function ( ...$args ) use ( &$written ) {
				$written = $args;
				return 'OK';
			}
		);

		$count = Shift64_Woo_Search_Indexer::cache_brands_to_redis( $redis );

		$this->assertSame( 0, $count );
		$this->assertSame( 'SET', $written[0] );
		$this->assertSame( 'shift64_woo_search:brands', $written[1] );
		$this->assertSame( array(), json_decode( $written[2], true ) );
	}
}
