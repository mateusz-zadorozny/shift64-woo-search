<?php
/**
 * Tests for Shift64_Woo_Search_Query::search_by_filters().
 *
 * Public query API for taxonomy archive — returns post IDs + total
 * matching the merged scope+user filters, without running search-archive's
 * relevance boosting or re-ranking pipeline.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Filter-driven search query tests.
 */
class Search_By_Filters_Test extends WP_UnitTestCase {

	/**
	 * Register pa_kolor as a filterable attribute so build_filter_parts()
	 * emits `@attr_pa_kolor:{...}` for our test inputs.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_kolor' ) );
	}

	/**
	 * Helper: build a real Shift64_Woo_Search_Query with a mocked Redis connector.
	 * We want the filter-part building to run for real (so we assert on the
	 * actual FT query string) but the Redis call itself is mocked.
	 *
	 * @param mixed $raw_response Raw response Redis raw_command returns.
	 * @return array [Shift64_Woo_Search_Query, mock Redis]
	 */
	private function make_query_with_redis_mock( $raw_response ) {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturn( $raw_response );

		$query = new Shift64_Woo_Search_Query( $redis );
		return array( $query, $redis );
	}

	/**
	 * The search_by_filters helper returns a structured array with 'ids' (int list) and 'total'.
	 */
	public function test_returns_ids_and_total() {
		// Simulated FT.SEARCH raw response without WITHSCORES: [total, key1, fields1, key2, fields2, ...].
		// Taxonomy archive doesn't re-rank by relevance, so no score is requested — lighter payload.
		$raw = array(
			2,
			'shift64_woo_search:product:42',
			array( '_key', 'shift64_woo_search:product:42' ),
			'shift64_woo_search:product:43',
			array( '_key', 'shift64_woo_search:product:43' ),
		);

		list( $query ) = $this->make_query_with_redis_mock( $raw );

		$result = $query->search_by_filters(
			array( 'category' => array( 'Shift64 Stella' ) ),
			array(),
			12,
			1
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ids', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( array( 42, 43 ), $result['ids'] );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * Scope and user filters are merged — both end up in the FT query.
	 */
	public function test_merges_scope_and_user_filters_in_query() {
		$captured_query = null;

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )
			->willReturnCallback(
				function () use ( &$captured_query ) {
					$args = func_get_args();
					// FT.SEARCH args layout: ['FT.SEARCH', index, query, ...].
					$captured_query = $args[2] ?? null;
					return array( 0 ); // empty response.
				}
			);

		$query = new Shift64_Woo_Search_Query( $redis );

		$query->search_by_filters(
			array( 'category' => array( 'Shift64 Stella' ) ),
			array( 'attr_pa_kolor' => array( 'bialy' ) ),
			12,
			1
		);

		$this->assertNotNull( $captured_query );
		$this->assertStringContainsString( '@categories:{Shift64\\ Stella}', $captured_query );
		$this->assertStringContainsString( 'attr_pa_kolor', $captured_query );
		$this->assertStringContainsString( 'bialy', $captured_query );
	}

	/**
	 * Empty filters still produce a valid query — static exclusions only (excluded, outofstock, visibility).
	 */
	public function test_empty_filters_produces_valid_query_with_static_exclusions() {
		$captured_query = null;

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )
			->willReturnCallback(
				function () use ( &$captured_query ) {
					$args           = func_get_args();
					$captured_query = $args[2] ?? null;
					return array( 0 );
				}
			);

		$query = new Shift64_Woo_Search_Query( $redis );

		$query->search_by_filters( array(), array(), 12, 1 );

		$this->assertNotNull( $captured_query );
		// Must contain at least the static exclusions.
		$this->assertStringContainsString( '-@excluded:{yes}', $captured_query );
		$this->assertStringContainsString( '-@visibility:{hidden}', $captured_query );
		// Dialect 2 rejects `*` next to any other clause ("Syntax error at
		// offset 2"), and accepts a query built purely from negations — so an
		// unfiltered archive must not be padded with a wildcard.
		$this->assertStringNotContainsString( '*', $captured_query );
	}

	/**
	 * Pagination: page 2 with per_page=12 means OFFSET=12, LIMIT=12.
	 */
	public function test_pagination_offset_calculated_correctly() {
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
		$query->search_by_filters( array(), array(), 12, 2 );

		// Find LIMIT in args — format: ['FT.SEARCH', index, query, ..., 'LIMIT', offset, count, ...].
		$limit_pos = array_search( 'LIMIT', $captured_args, true );
		$this->assertNotFalse( $limit_pos, 'LIMIT keyword should be in FT.SEARCH args' );
		$this->assertSame( '12', (string) $captured_args[ $limit_pos + 1 ], 'offset for page 2 with per_page 12 should be 12' );
		$this->assertSame( '12', (string) $captured_args[ $limit_pos + 2 ], 'count should equal per_page' );
	}

	/**
	 * Product IDs are extracted from Redis keys (shift64_woo_search:product:N → N).
	 */
	public function test_extracts_integer_ids_from_redis_keys() {
		$raw           = array(
			1,
			'shift64_woo_search:product:999',
			array( '_key', 'shift64_woo_search:product:999' ),
		);
		list( $query ) = $this->make_query_with_redis_mock( $raw );

		$result = $query->search_by_filters( array(), array(), 12, 1 );

		$this->assertSame( array( 999 ), $result['ids'] );
	}

	/**
	 * The composite sort runs FT.AGGREGATE on dialect 2, like every FT.SEARCH.
	 *
	 * Under the default dialect the scope and exclusion clauses are not applied,
	 * so a category archive sorted by `menu_order` — WooCommerce's default —
	 * would silently list the whole catalog.
	 */
	public function test_composite_sort_keeps_scope_and_requests_dialect_two() {
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
		$query->search_by_filters(
			array( 'category' => array( 'Shift64 Stella' ) ),
			array(),
			12,
			1,
			array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			)
		);

		$this->assertSame( 'FT.AGGREGATE', $captured_args[0] ?? null );
		$this->assertStringContainsString( '@categories:{Shift64\\ Stella}', (string) ( $captured_args[2] ?? '' ) );

		$dialect_pos = array_search( 'DIALECT', $captured_args, true );
		$this->assertNotFalse( $dialect_pos, 'FT.AGGREGATE must request an explicit dialect' );
		$this->assertSame( '2', (string) $captured_args[ $dialect_pos + 1 ] );
	}
}
