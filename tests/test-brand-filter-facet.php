<?php
/**
 * Tests for the brand filter dimension: query clause, facet counting, and the
 * option gate in Shift64_Woo_Search_Facets::compute().
 *
 * @package Shift64_Woo_Search
 */

/**
 * Brand filter and facet tests.
 */
class Brand_Filter_Facet_Test extends WP_UnitTestCase {

	/**
	 * A brand filter becomes an @brands TAG clause.
	 */
	public function test_build_filter_parts_emits_brands_tag_clause() {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		$query = new Shift64_Woo_Search_Query( $redis );
		$parts = $query->build_filter_parts( array( 'brand' => array( 'Nyx Workshop' ) ) );

		$this->assertContains( '@brands:{Nyx\\ Workshop}', $parts );
	}

	/**
	 * Several selected brands OR together inside one clause, and values needing
	 * escaping are escaped rather than reaching Redis raw.
	 */
	public function test_build_filter_parts_escapes_and_ors_multiple_brands() {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		$query = new Shift64_Woo_Search_Query( $redis );
		$parts = $query->build_filter_parts( array( 'brand' => array( 'Aeon-Atelier', 'Nyx Workshop' ) ) );

		$clause = '';
		foreach ( $parts as $part ) {
			if ( 0 === strpos( $part, '@brands:' ) ) {
				$clause = $part;
			}
		}

		$this->assertNotSame( '', $clause );
		$this->assertStringContainsString( '|', $clause );
		$this->assertStringNotContainsString( 'Aeon-Atelier', $clause, 'The hyphen must be escaped for DIALECT 2.' );
	}

	/**
	 * No brand filter means no brand clause — the dimension must stay inert
	 * until a brand is actually selected.
	 */
	public function test_build_filter_parts_omits_brand_clause_when_unfiltered() {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		$query = new Shift64_Woo_Search_Query( $redis );

		foreach ( $query->build_filter_parts( array() ) as $part ) {
			$this->assertStringNotContainsString( '@brands:', $part );
		}
	}

	/**
	 * The brand facet splits the pipe-separated TAG value, counting each brand
	 * once per product — including inherited ancestors.
	 */
	public function test_execute_brand_facet_splits_pipe_separated_values() {
		$raw = array(
			2,
			'shift64_woo_search:product:10',
			array( 'brands', 'Aeon Reserve|Aeon Atelier' ),
			'shift64_woo_search:product:11',
			array( 'brands', 'Aeon Everyday|Aeon Atelier' ),
		);

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturn( $raw );

		$query  = new Shift64_Woo_Search_Query( $redis );
		$result = $query->execute_brand_facet( '(shirt*) -@excluded:{yes}', 100 );

		$this->assertSame(
			array(
				array(
					'value' => 'Aeon Atelier',
					'count' => 2,
				),
				array(
					'value' => 'Aeon Reserve',
					'count' => 1,
				),
				array(
					'value' => 'Aeon Everyday',
					'count' => 1,
				),
			),
			$result
		);
	}

	/**
	 * Brands are opt-in: with the option off, compute() must not even ask Redis
	 * for brand counts.
	 */
	public function test_compute_skips_brand_facet_when_option_disabled() {
		update_option( 'shift64_woo_search_filter_categories_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array() );
		delete_option( 'shift64_woo_search_filter_brands_enabled' );

		$mock = $this->createMock( Shift64_Woo_Search_Query::class );
		$mock->expects( $this->never() )->method( 'execute_brand_facet' );

		$result = Shift64_Woo_Search_Facets::compute( $mock, array(), array(), null );

		$this->assertArrayNotHasKey( 'brands', $result );
	}

	/**
	 * With the option on, brand counts land under the 'brands' key, and the
	 * facet query excludes the brand dimension's own filter so selecting a brand
	 * does not zero its sibling counts.
	 */
	public function test_compute_returns_brand_facet_with_exclude_self() {
		update_option( 'shift64_woo_search_filter_categories_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array() );
		update_option( 'shift64_woo_search_filter_brands_enabled', 'yes' );

		$mock = $this->createMock( Shift64_Woo_Search_Query::class );

		$mock->expects( $this->once() )
			->method( 'build_facet_query' )
			->with(
				$this->equalTo( array() ),
				$this->equalTo( array( 'brand' => array( 'Nyx Workshop' ) ) ),
				// Third argument is the dimension to EXCLUDE from the base query.
				$this->equalTo( 'brand' )
			)
			->willReturn( '*' );

		$mock->expects( $this->once() )
			->method( 'execute_brand_facet' )
			->willReturn(
				array(
					array(
						'value' => 'Nyx Workshop',
						'count' => 4,
					),
				)
			);

		$result = Shift64_Woo_Search_Facets::compute(
			$mock,
			array(),
			array( 'brand' => array( 'Nyx Workshop' ) ),
			null
		);

		$this->assertArrayHasKey( 'brands', $result );
		$this->assertSame( 'Nyx Workshop', $result['brands'][0]['value'] );
		$this->assertSame( 4, $result['brands'][0]['count'] );
	}
}
