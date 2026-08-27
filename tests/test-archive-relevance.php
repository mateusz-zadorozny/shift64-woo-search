<?php
/**
 * Tests for Shift64_Woo_Search_Archive relevance re-ranking.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Archive relevance re-ranking tests.
 */
class Archive_Relevance_Test extends WP_UnitTestCase {

	/**
	 * The product search archive explicitly requests search visibility.
	 */
	public function test_archive_requests_search_visibility_context() {
		$raw = array(
			1,
			'shift64_woo_search:product:101',
			'10',
			array(
				'post_id',
				'101',
				'title',
				'Fixture Product',
				'stock_status',
				'instock',
			),
		);

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturn( $raw );

		$search_query = $this->getMockBuilder( Shift64_Woo_Search_Query::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'sanitize_query', 'get_search_terms', 'build_strict_query', 'term_coverage_needles' ) )
			->getMock();
		$search_query->method( 'sanitize_query' )->willReturn( 'fixture' );
		$search_query->method( 'get_search_terms' )->willReturn( array( 'fixture' ) );
		$search_query->method( 'term_coverage_needles' )->willReturn( array( array( 'fixture' ) ) );
		$search_query->expects( $this->once() )
			->method( 'build_strict_query' )
			->with(
				$this->equalTo( array( 'fixture' ) ),
				$this->equalTo( array() ),
				$this->isNull(),
				$this->equalTo( 'search' )
			)
			->willReturn( '(fixture*) -@visibility:{hidden|catalog}' );

		$instance = new ReflectionProperty( Shift64_Woo_Search_Redis::class, 'instance' );
		$instance->setValue( null, $redis );

		try {
			$archive = ( new ReflectionClass( Shift64_Woo_Search_Archive::class ) )->newInstanceWithoutConstructor();
			$method  = new ReflectionMethod( Shift64_Woo_Search_Archive::class, 'execute_search' );
			$result  = $method->invoke(
				$archive,
				$search_query,
				'fixture',
				'strict_first',
				array(
					'logic'                   => 'OR',
					'outofstock_mode'         => 'exclude',
					'token_reduction_enabled' => true,
				),
				16,
				1
			);
		} finally {
			$instance->setValue( null, null );
		}

		$this->assertSame( array( 101 ), $result['ids'] );
		$this->assertSame( 1, $result['total'] );
	}

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

		$archive      = ( new ReflectionClass( Shift64_Woo_Search_Archive::class ) )->newInstanceWithoutConstructor();
		$method       = new ReflectionMethod( Shift64_Woo_Search_Archive::class, 'ft_search_relevance' );
		$search_query = new Shift64_Woo_Search_Query(
			$redis,
			array(
				'outofstock_mode'          => 'demote',
				'outofstock_demote_factor' => 0.3,
			)
		);

		$result = $method->invoke(
			$archive,
			$redis,
			'shift64_woo_search_product_idx',
			'(papier*) -@excluded:{yes} -@visibility:{hidden}',
			array( 'papier' ),
			300,
			false,
			'demote',
			0.0,
			array(),
			0.4,
			$search_query,
			'papier'
		);

		$this->assertSame( array( 202, 101 ), $result['ids'] );
		$this->assertSame( 2, $result['total'] );
	}
}
