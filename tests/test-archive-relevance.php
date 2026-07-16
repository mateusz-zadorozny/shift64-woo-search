<?php
/**
 * Tests for Shift64_Woo_Search_Archive relevance re-ranking.
 *
 * @package Shift64_Woo_Search
 */

class Archive_Relevance_Test extends WP_UnitTestCase {

	/**
	 * Archive relevance applies the same out-of-stock demote as quick search
	 * before title-start boost, so unavailable products do not outrank better
	 * available matches only because of the raw Redis score.
	 */
	public function test_relevance_demotes_outofstock_results() {
		$raw = array(
			2,
			'shift64_woo_search:product:101',
			'10',
			array(
				'post_id',
				'101',
				'title',
				'Papier Alpha',
				'stock_status',
				'outofstock',
			),
			'shift64_woo_search:product:202',
			'4',
			array(
				'post_id',
				'202',
				'title',
				'Papier Beta',
				'stock_status',
				'instock',
			),
		);

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'raw_command' )->willReturn( $raw );

		$archive = ( new ReflectionClass( Shift64_Woo_Search_Archive::class ) )->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod( Shift64_Woo_Search_Archive::class, 'ft_search_relevance' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$archive,
			$redis,
			'shift64_woo_search_product_idx',
			'(papier*) -@excluded:{yes} -@visibility:{hidden}',
			array( 'papier' ),
			300,
			false,
			'demote',
			0.3
		);

		$this->assertSame( array( 202, 101 ), $result['ids'] );
		$this->assertSame( 2, $result['total'] );
	}
}
