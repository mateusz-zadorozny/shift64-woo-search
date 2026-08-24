<?php
/**
 * Tests for Shift64_Woo_Search_Query::execute_category_facet().
 *
 * @package Shift64_Woo_Search
 */

/**
 * Category facet splitting tests.
 */
class Category_Facet_Splitting_Test extends WP_UnitTestCase {

	/**
	 * Category facet should split a pipe-separated TAG payload into individual buckets.
	 */
	public function test_execute_category_facet_splits_pipe_separated_values() {
		$raw = array(
			2,
			'shift64_woo_search:product:10',
			array(
				'categories',
				'Leaf A|Parent A',
			),
			'shift64_woo_search:product:11',
			array(
				'categories',
				'Leaf B|Parent A',
			),
		);

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturn( $raw );

		$query  = new Shift64_Woo_Search_Query( $redis );
		$result = $query->execute_category_facet( '(dozownik*) -@excluded:{yes}', 100 );

		$this->assertSame(
			array(
				array(
					'value' => 'Parent A',
					'count' => 2,
				),
				array(
					'value' => 'Leaf A',
					'count' => 1,
				),
				array(
					'value' => 'Leaf B',
					'count' => 1,
				),
			),
			$result
		);
	}

	/**
	 * Category facet should preserve a valid negative-only base query.
	 */
	public function test_execute_category_facet_preserves_negative_only_query() {
		$captured_args = null;

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )
			->willReturnCallback(
				function () use ( &$captured_args ) {
					$captured_args = func_get_args();
					return array( 0 );
				}
			);

		$query = new Shift64_Woo_Search_Query( $redis );
		$query->execute_category_facet( '-@excluded:{yes} -@visibility:{hidden}', 50 );

		$this->assertNotNull( $captured_args );
		$this->assertSame( '-@excluded:{yes} -@visibility:{hidden}', $captured_args[2] );
	}
}
