<?php
/**
 * Tests for Shift64_Woo_Search_Facets service.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Facet computation service tests.
 *
 * Facets orchestrates FT.AGGREGATE calls for each configured filter dimension
 * (category + attribute TAG fields). Contract: must work both for search
 * archive (with text terms) and taxonomy archive (scope filters only, no terms).
 */
class Facets_Test extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'shift64_woo_search_filter_categories_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_kolor' ) );
	}

	/**
	 * Compute should produce facet data for a scope-only query (no search terms).
	 *
	 * This is the core requirement for taxonomy archive — no text input,
	 * just "products in category X, what colors are available".
	 */
	public function test_compute_returns_facet_data_without_terms() {
		$mock = $this->createMock( Shift64_Woo_Search_Query::class );

		$mock->expects( $this->once() )
			->method( 'build_facet_query' )
			->with(
				// Contract: NULL $terms is converted to empty array inside compute().
				// Asserting equalTo(array()) protects the null-to-[] conversion.
				$this->equalTo( array() ),
				$this->equalTo( array() ),
				$this->equalTo( 'attr_pa_kolor' ),
				$this->equalTo( null ),
				$this->equalTo( array() ),
				$this->equalTo( array( 'category' => array( 'Shift64 Stella' ) ) )
			)
			->willReturn( '@categories:{Shift64\\ Stella}' );

		$mock->expects( $this->once() )
			->method( 'execute_ft_aggregate' )
			->with( $this->equalTo( '@categories:{Shift64\\ Stella}' ), $this->equalTo( 'attr_pa_kolor' ) )
			->willReturn(
				array(
					array(
						'value' => 'bialy',
						'count' => 3,
					),
				)
			);

		$result = Shift64_Woo_Search_Facets::compute(
			$mock,
			array( 'category' => array( 'Shift64 Stella' ) ),
			array(),
			null
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attr_pa_kolor', $result );
		$this->assertSame( 'bialy', $result['attr_pa_kolor'][0]['value'] );
		$this->assertSame( 3, $result['attr_pa_kolor'][0]['count'] );
	}

	/**
	 * Empty aggregate response for a dimension means that dimension is skipped.
	 */
	public function test_compute_skips_dimension_with_empty_response() {
		$mock = $this->createMock( Shift64_Woo_Search_Query::class );
		$mock->method( 'build_facet_query' )->willReturn( 'dummy' );
		$mock->method( 'execute_ft_aggregate' )->willReturn( array() );

		$result = Shift64_Woo_Search_Facets::compute(
			$mock,
			array( 'category' => array( 'Shift64 Stella' ) ),
			array(),
			null
		);

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Category dimension is computed when the gate option is 'yes'.
	 */
	public function test_compute_includes_category_dimension_when_enabled() {
		update_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_attributes', array() );

		$mock = $this->createMock( Shift64_Woo_Search_Query::class );
		$mock->expects( $this->once() )
			->method( 'build_facet_query' )
			->with(
				$this->equalTo( array( 'koszulka' ) ),
				$this->equalTo( array() ),
				$this->equalTo( 'category' )
			)
			->willReturn( 'dummy' );
		$mock->expects( $this->once() )
			->method( 'execute_category_facet' )
			->with( $this->equalTo( 'dummy' ) )
			->willReturn(
				array(
					array(
						'value' => 'Koszulki',
						'count' => 10,
					),
				)
			);

		$result = Shift64_Woo_Search_Facets::compute(
			$mock,
			array(),
			array(),
			array( 'koszulka' )
		);

		$this->assertArrayHasKey( 'categories', $result );
	}

	/**
	 * Backward compat: search archive passes $terms and gets same behavior as before.
	 */
	public function test_compute_with_terms_is_backward_compatible() {
		update_option( 'shift64_woo_search_filter_categories_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_kolor' ) );

		$mock = $this->createMock( Shift64_Woo_Search_Query::class );
		$mock->expects( $this->once() )
			->method( 'build_facet_query' )
			->with(
				$this->equalTo( array( 'mop' ) ),
				$this->anything(),
				$this->equalTo( 'attr_pa_kolor' )
			)
			->willReturn( 'mop*' );
		$mock->method( 'execute_ft_aggregate' )->willReturn( array() );

		Shift64_Woo_Search_Facets::compute(
			$mock,
			array(),
			array(),
			array( 'mop' )
		);
	}

	/**
	 * Search archives pass their visibility context through to facet queries so
	 * facet counts describe the same result membership as the product grid.
	 */
	public function test_compute_forwards_search_visibility_context() {
		$mock = $this->createMock( Shift64_Woo_Search_Query::class );
		$mock->expects( $this->once() )
			->method( 'build_facet_query' )
			->with(
				$this->equalTo( array( 'fixture' ) ),
				$this->equalTo( array() ),
				$this->equalTo( 'attr_pa_kolor' ),
				$this->equalTo( 'search' )
			)
			->willReturn( 'fixture* -@visibility:{hidden|catalog}' );
		$mock->method( 'execute_ft_aggregate' )->willReturn( array() );

		Shift64_Woo_Search_Facets::compute(
			$mock,
			array(),
			array(),
			array( 'fixture' ),
			'search'
		);
	}

	/**
	 * Active user filters are passed through to the underlying query builder.
	 *
	 * The exclude-self facet semantics live in `Shift64_Woo_Search_Query::build_facet_query()`
	 * (already public, already tested by search archive integration). Facets service
	 * just needs to forward filters correctly.
	 */
	public function test_compute_forwards_active_user_filters() {
		$mock = $this->createMock( Shift64_Woo_Search_Query::class );

		$expected_filters = array( 'attr_pa_kolor' => array( 'bialy' ) );
		$expected_scope   = array( 'category' => array( 'Shift64 Stella' ) );

		$mock->expects( $this->once() )
			->method( 'build_facet_query' )
			->with(
				$this->anything(),
				$this->equalTo( $expected_filters ),
				$this->anything(),
				$this->equalTo( null ),
				$this->equalTo( array() ),
				$this->equalTo( $expected_scope )
			)
			->willReturn( 'dummy' );
		$mock->method( 'execute_ft_aggregate' )->willReturn( array() );

		Shift64_Woo_Search_Facets::compute(
			$mock,
			array( 'category' => array( 'Shift64 Stella' ) ),
			array( 'attr_pa_kolor' => array( 'bialy' ) ),
			null
		);
	}
}
