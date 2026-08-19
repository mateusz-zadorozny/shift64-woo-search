<?php
/**
 * Tests for the request-scoped facet count provider.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Facet count provider tests.
 *
 * Contract under test: buckets come from an existing Redis result envelope
 * when the Product Collection already executed, are computed once on demand
 * otherwise, are memoized so no canonical state aggregates twice, and
 * degrade to null (options render without counts) when nothing is available.
 */
class Facet_Count_Provider_Test extends WP_UnitTestCase {

	/**
	 * Reset all request-scoped registries.
	 */
	public function set_up() {
		parent::set_up();
		Shift64_Woo_Search_Product_Collection_Results::reset();
		Shift64_Woo_Search_Facet_Count_Provider::reset();
		Shift64_Woo_Search_Facet_Eligibility::reset();
		Shift64_Woo_Search_Filter_Blocks::reset();
	}

	/**
	 * Reset again so later suites see clean statics.
	 */
	public function tear_down() {
		Shift64_Woo_Search_Product_Collection_Results::reset();
		Shift64_Woo_Search_Facet_Count_Provider::reset();
		Shift64_Woo_Search_Facet_Eligibility::reset();
		Shift64_Woo_Search_Filter_Blocks::reset();
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Store a Redis-status envelope carrying the given facet buckets.
	 *
	 * @param array $facets Buckets keyed by Redis field.
	 */
	private function store_envelope( array $facets ) {
		Shift64_Woo_Search_Product_Collection_Results::set(
			new Shift64_Woo_Search_Product_Collection_Result(
				'pc-7-scope-1',
				array( 11, 12 ),
				2,
				1,
				12,
				'relevance',
				array(),
				$facets,
				Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS
			)
		);
	}

	/**
	 * An executed collection's envelope is reused as the bucket source.
	 */
	public function test_envelope_buckets_are_reused() {
		$this->store_envelope(
			array(
				'categories' => array(
					array(
						'value' => 'Lamps',
						'count' => 3,
					),
				),
			)
		);

		$buckets = Shift64_Woo_Search_Facet_Count_Provider::get_buckets( 'categories' );

		$this->assertSame( 'Lamps', $buckets[0]['value'] );
		$this->assertSame( 3, $buckets[0]['count'] );
		$this->assertSame(
			Shift64_Woo_Search_Facet_Count_Provider::STATUS_ENVELOPE,
			Shift64_Woo_Search_Facet_Count_Provider::get_status()
		);
	}

	/**
	 * A native-fallback envelope is never a bucket source.
	 */
	public function test_native_fallback_envelope_is_ignored() {
		Shift64_Woo_Search_Product_Collection_Results::set(
			new Shift64_Woo_Search_Product_Collection_Result(
				'pc-7-scope-1',
				array(),
				0,
				1,
				12,
				'relevance',
				array(),
				array(),
				Shift64_Woo_Search_Product_Collection_Result::STATUS_NATIVE_FALLBACK
			)
		);

		$this->assertNull( Shift64_Woo_Search_Facet_Count_Provider::get_buckets( 'categories' ) );
		$this->assertSame(
			Shift64_Woo_Search_Facet_Count_Provider::STATUS_UNAVAILABLE,
			Shift64_Woo_Search_Facet_Count_Provider::get_status()
		);
	}

	/**
	 * Off eligible archives (or without Redis) counts are simply unavailable.
	 */
	public function test_ineligible_request_degrades_to_unavailable() {
		$this->assertNull( Shift64_Woo_Search_Facet_Count_Provider::get_buckets( 'categories' ) );
		$this->assertSame(
			Shift64_Woo_Search_Facet_Count_Provider::STATUS_UNAVAILABLE,
			Shift64_Woo_Search_Facet_Count_Provider::get_status()
		);
	}

	/**
	 * A degraded dimension (missing from the computed set) yields null for
	 * that facet only.
	 */
	public function test_missing_dimension_yields_null() {
		$this->store_envelope( array( 'categories' => array() ) );

		$this->assertNull( Shift64_Woo_Search_Facet_Count_Provider::get_buckets( 'attr_pa_material' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Facet_Count_Provider::get_buckets( 'categories' ) );
	}

	/**
	 * The same canonical state aggregates exactly once; a different state
	 * aggregates again.
	 */
	public function test_compute_is_memoized_per_canonical_state() {
		update_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_brands_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array() );

		$query = $this->createMock( Shift64_Woo_Search_Query::class );
		$query->expects( $this->exactly( 2 ) )
			->method( 'build_facet_query' )
			->willReturn( 'q' );
		$query->expects( $this->exactly( 2 ) )
			->method( 'execute_category_facet' )
			->willReturn(
				array(
					array(
						'value' => 'Lamps',
						'count' => 2,
					),
				)
			);

		$first  = Shift64_Woo_Search_Facet_Count_Provider::compute_memoized( $query, array(), array(), null, null, array() );
		$second = Shift64_Woo_Search_Facet_Count_Provider::compute_memoized( $query, array(), array(), null, null, array() );
		$this->assertSame( $first, $second );

		Shift64_Woo_Search_Facet_Count_Provider::compute_memoized(
			$query,
			array(),
			array( 'categories' => array( 'Lamps' ) ),
			null,
			null,
			array()
		);
	}

	/**
	 * Pill-first render order: with no envelope, an eligible product search
	 * computes buckets on demand through the shared machinery.
	 */
	public function test_on_demand_compute_on_eligible_search_request() {
		update_option( 'shift64_woo_search_archive_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_brands_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array() );

		$query = $this->createMock( Shift64_Woo_Search_Query::class );
		$query->method( 'sanitize_query' )->willReturnArgument( 0 );
		$query->method( 'get_search_terms' )->willReturn( array( 'lamp' ) );
		$query->method( 'build_facet_query' )->willReturn( 'q' );
		$query->method( 'execute_category_facet' )
			->willReturn(
				array(
					array(
						'value' => 'Lamps',
						'count' => 5,
					),
				)
			);
		Shift64_Woo_Search_Facet_Count_Provider::set_search_query( $query );

		$this->go_to( '/?s=lamp&post_type=product' );

		$buckets = Shift64_Woo_Search_Facet_Count_Provider::get_buckets( 'categories' );

		$this->assertSame( 5, $buckets[0]['count'] );
		$this->assertSame(
			Shift64_Woo_Search_Facet_Count_Provider::STATUS_COMPUTED,
			Shift64_Woo_Search_Facet_Count_Provider::get_status()
		);
	}

	/**
	 * Rendered pills consume envelope counts: shown when configured, empty
	 * unselected options hidden, selected zero-count options retained.
	 */
	public function test_pill_rendering_consumes_counts() {
		foreach ( array( 'product_cat' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, 'product' );
			}
		}
		update_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		foreach ( array(
			array( 'Lamps', 'lamps' ),
			array( 'Chairs', 'chairs' ),
			array( 'Desks', 'desks' ),
		) as $fixture ) {
			if ( ! get_term_by( 'slug', $fixture[1], 'product_cat' ) ) {
				wp_insert_term( $fixture[0], 'product_cat', array( 'slug' => $fixture[1] ) );
			}
		}
		add_filter(
			'shift64_woo_search_facet_entries',
			static function ( $entries ) {
				$entries['product_cat']['status'] = 'ready';
				return $entries;
			}
		);
		$this->store_envelope(
			array(
				'categories' => array(
					array(
						'value' => 'Lamps',
						'count' => 4,
					),
					array(
						'value' => 'Chairs',
						'count' => 0,
					),
					array(
						'value' => 'Desks',
						'count' => 0,
					),
				),
			)
		);
		$_GET['filter_product_cat'] = 'chairs';
		$_SERVER['REQUEST_URI']     = '/shop/?filter_product_cat=chairs';

		$html = do_blocks(
			'<!-- wp:shift64-woo-search/product-filters --><!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /--><!-- /wp:shift64-woo-search/product-filters -->'
		);

		// Lamps shows its count; selected zero-count Chairs stays; unselected
		// zero-count Desks is hidden by hideEmpty's live-count semantics.
		$this->assertStringContainsString( 'value="lamps"', $html );
		$this->assertStringContainsString( 'shift64-woo-search-pill__count">4<', $html );
		$this->assertStringContainsString( 'value="chairs" checked=\'checked\'', $html );
		$this->assertStringNotContainsString( 'value="desks"', $html );
	}
}
