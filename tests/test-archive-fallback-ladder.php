<?php
/**
 * Tests for the retrieval ladders that serve the results page.
 *
 * `Query::search()` feeds the dropdown; `Archive::execute_search()` and
 * `Query::search_catalog()` feed the full results page and the Product
 * Collection block. All three have to agree about which pass answers a query,
 * or a shopper sees a product in the dropdown and not on the page they land on.
 *
 * Regression cover for two shapes this PR introduced: the `mixed` short-circuit
 * (before it, `strategy=mixed` gave the archive a prefix-only pass with no fuzzy
 * fallback at all) and the per-token fuzzy pass ordering ahead of OR-prefix.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Pass ordering in the archive and catalog ladders.
 */
class Archive_Fallback_Ladder_Test extends WP_UnitTestCase {

	/**
	 * Builder calls recorded in order, as "method:detail" strings.
	 *
	 * @var string[]
	 */
	private $calls = array();

	/**
	 * FT.SEARCH replies to hand back, consumed in order.
	 *
	 * @var array
	 */
	private $replies = array();

	/**
	 * Raw Redis calls recorded for request-shape assertions.
	 *
	 * @var array<int,array>
	 */
	private $redis_args = array();

	/**
	 * Shared Redis mock for the current test.
	 *
	 * @var Shift64_Woo_Search_Redis|null
	 */
	private $redis = null;

	/**
	 * Reset the per-test recording state.
	 */
	public function set_up() {
		parent::set_up();
		$this->calls      = array();
		$this->replies    = array();
		$this->redis_args = array();
		$this->redis      = null;
	}

	/**
	 * An FT.SEARCH reply with no documents.
	 *
	 * @return array
	 */
	private function empty_reply() {
		return array( 0 );
	}

	/**
	 * An FT.SEARCH reply carrying one product.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function hit_reply( $post_id = 101 ) {
		return array(
			1,
			'shift64_woo_search:product:' . $post_id,
			'10',
			array( 'post_id', (string) $post_id, 'title', 'Aero Cedar Side Table', 'stock_status', 'instock' ),
		);
	}

	/**
	 * Redis mock that serves `$this->replies` in order, then empty replies.
	 *
	 * @return Shift64_Woo_Search_Redis
	 */
	private function redis_serving_replies() {
		if ( null !== $this->redis ) {
			return $this->redis;
		}

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_prefix' )->willReturn( 'shift64_woo_search' );
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturnCallback(
			function () {
				$this->redis_args[] = func_get_args();
				return empty( $this->replies ) ? $this->empty_reply() : array_shift( $this->replies );
			}
		);

		$this->redis = $redis;

		return $redis;
	}

	/**
	 * Query mock whose builders record the order they were called in.
	 *
	 * Built through the real constructor: search_catalog() reads `config` and
	 * talks to Redis itself, so a constructor-less mock cannot exercise it.
	 *
	 * @param array $terms  Search terms the query splits into.
	 * @param array $config Config overrides.
	 * @return Shift64_Woo_Search_Query
	 */
	private function recording_query( $terms = array( 'aero', 'cedat' ), $config = array() ) {
		$query = $this->getMockBuilder( Shift64_Woo_Search_Query::class )
			->setConstructorArgs( array( $this->redis_serving_replies(), $config ) )
			->onlyMethods(
				array(
					'sanitize_query',
					'get_search_terms',
					'term_coverage_needles',
					'reduce_tokens',
					'build_strict_query',
					'build_hybrid_query',
					'build_fuzzy_query',
				)
			)
			->getMock();

		$query->method( 'sanitize_query' )->willReturn( implode( ' ', $terms ) );
		$query->method( 'get_search_terms' )->willReturn( $terms );
		$query->method( 'term_coverage_needles' )->willReturn(
			array_map(
				function ( $term ) {
					return array( $term );
				},
				$terms
			)
		);
		$query->method( 'reduce_tokens' )->willReturn( $terms );

		$query->method( 'build_strict_query' )->willReturnCallback(
			function ( $terms, $filters = array(), $logic = null ) {
				$this->calls[] = 'strict:' . ( null === $logic ? 'default' : $logic );
				return '(strict)';
			}
		);
		$query->method( 'build_hybrid_query' )->willReturnCallback(
			function ( $terms, $filters = array(), $level = null ) {
				$this->calls[] = 'hybrid:' . ( null === $level ? 'default' : $level );
				return '(hybrid)';
			}
		);
		$query->method( 'build_fuzzy_query' )->willReturnCallback(
			function () {
				$this->calls[] = 'fuzzy';
				return '(fuzzy)';
			}
		);

		return $query;
	}

	/**
	 * Drive the private Archive::execute_search() with the Redis singleton swapped.
	 *
	 * @param Shift64_Woo_Search_Query $query    Recording query mock.
	 * @param string                   $strategy Retrieval strategy.
	 * @param array                    $config   Search configuration.
	 * @return array|false
	 */
	private function run_archive_search( $query, $strategy, $config ) {
		$instance = new ReflectionProperty( Shift64_Woo_Search_Redis::class, 'instance' );
		$instance->setValue( null, $this->redis_serving_replies() );

		try {
			$archive = ( new ReflectionClass( Shift64_Woo_Search_Archive::class ) )->newInstanceWithoutConstructor();
			$method  = new ReflectionMethod( Shift64_Woo_Search_Archive::class, 'execute_search' );

			return $method->invoke( $archive, $query, 'aero cedat', $strategy, $config, 16, 1 );
		} finally {
			$instance->setValue( null, null );
		}
	}

	// ── Archive ─────────────────────────────────────────────────

	/**
	 * Under `mixed` the archive runs the one hybrid query and stops. Before this
	 * change it ran `build_strict_query()` and gated the fuzzy pass on
	 * `'strict_first' === $strategy`, so a `mixed` store had a prefix-only
	 * results page: the dropdown repaired a typo and "See all results →" did not.
	 */
	public function test_mixed_strategy_runs_one_hybrid_pass_on_the_archive() {
		$this->replies = array( $this->hit_reply() );

		$result = $this->run_archive_search(
			$this->recording_query(),
			'mixed',
			array(
				'logic'           => 'AND',
				'outofstock_mode' => 'exclude',
			)
		);

		$this->assertSame( array( 'hybrid:default' ), $this->calls );
		$this->assertSame( array( 101 ), $result['ids'] );
	}

	/**
	 * The typo path on the results page: an empty strict pass advances to the
	 * per-token fuzzy pass, which runs at the configured fallback fuzzy level and
	 * comes before the ladder relaxes term logic.
	 */
	public function test_strict_first_reaches_token_fuzzy_before_or_prefix() {
		$this->replies = array( $this->empty_reply(), $this->hit_reply() );

		$result = $this->run_archive_search(
			$this->recording_query(),
			'strict_first',
			array(
				'logic'                   => 'AND',
				'outofstock_mode'         => 'exclude',
				'token_reduction_enabled' => false,
				'fallback_fuzzy_level'    => 2,
			)
		);

		$this->assertSame( array( 'strict:default', 'hybrid:2' ), $this->calls );
		$this->assertNotContains( 'strict:OR', $this->calls );
		$this->assertNotContains( 'fuzzy', $this->calls );
		$this->assertSame( array( 101 ), $result['ids'] );
	}

	/**
	 * With every pass empty the archive still walks the whole ladder, in order.
	 *
	 * Only the call order is asserted: a zero-hit FT.SEARCH reply is `[0]`, which
	 * ft_search_relevance() has always reported as a failed search so the archive
	 * hands the query back to MySQL. That predates this PR and is untouched here.
	 */
	public function test_strict_first_walks_the_full_ladder_when_nothing_matches() {
		$this->run_archive_search(
			$this->recording_query(),
			'strict_first',
			array(
				'logic'                   => 'AND',
				'outofstock_mode'         => 'exclude',
				'token_reduction_enabled' => false,
			)
		);

		$this->assertSame( array( 'strict:default', 'hybrid:default', 'strict:OR', 'fuzzy' ), $this->calls );
	}

	/**
	 * The coverage hand-over the dropdown uses now also drives the archive: a
	 * non-empty OR pass whose leading result misses a term is not the answer.
	 */
	public function test_partial_coverage_advances_the_archive_ladder() {
		$this->replies = array( $this->hit_reply() );

		$this->run_archive_search(
			$this->recording_query( array( 'aero', 'cedar', 'kettle' ) ),
			'strict_first',
			array(
				'logic'                   => 'OR',
				'outofstock_mode'         => 'exclude',
				'token_reduction_enabled' => false,
			)
		);

		// "Aero Cedar Side Table" covers 2 of 3 terms, so pass 1 hands over.
		$this->assertContains( 'hybrid:default', $this->calls );
	}

	/**
	 * `no_results` keeps the archive on the first non-empty pass, matching
	 * Query::should_fallback() under the same setting.
	 */
	public function test_no_results_trigger_stops_the_archive_at_the_first_hit() {
		$this->replies = array( $this->hit_reply() );

		$this->run_archive_search(
			$this->recording_query( array( 'aero', 'cedar', 'kettle' ) ),
			'strict_first',
			array(
				'logic'                   => 'OR',
				'outofstock_mode'         => 'exclude',
				'token_reduction_enabled' => false,
				'fallback_trigger'        => 'no_results',
			)
		);

		$this->assertSame( array( 'strict:default' ), $this->calls );
	}

	/**
	 * The regression the block-theme pagination suite caught.
	 *
	 * Score-filtering the archive's `mixed` pass dropped exact matches on a
	 * frequent term — TFIDF tracks term rarity, so "series" scores near zero on
	 * a catalog where most titles carry it — and `ft_search_relevance()` then
	 * replaces the RediSearch total with the surviving row count. The results
	 * page lost its products, its pagination, and its filter pills at once.
	 */
	public function test_mixed_pass_on_the_archive_is_not_score_filtered() {
		$this->replies = array(
			array(
				30,
				'shift64_woo_search:product:101',
				'0.12',
				array( 'post_id', '101', 'title', 'Athena Series Hoodie', 'stock_status', 'instock' ),
			),
		);

		$result = $this->run_archive_search(
			$this->recording_query( array( 'series' ) ),
			'mixed',
			array(
				'logic'                    => 'AND',
				'outofstock_mode'          => 'exclude',
				'fallback_score_threshold' => 0.5,
			)
		);

		$this->assertSame( array( 101 ), $result['ids'], 'A common-term exact match must survive its low TFIDF score.' );
		$this->assertSame( 30, $result['total'], 'The RediSearch total drives pagination and must not follow a filtered count.' );
	}

	/**
	 * Strategies whose first pass runs in OR mode when the logic is OR.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function or_mode_strategies() {
		return array(
			'mixed'        => array( 'mixed' ),
			'strict_first' => array( 'strict_first' ),
		);
	}

	/**
	 * Under OR the first pass runs in or_mode, and ft_search_relevance() used
	 * to replace the RediSearch total with the surviving row count there.
	 * Relevance mode fetches a candidate window from offset 0, so a 5 364-hit
	 * query reported and paginated as "of 2 results" — for `mixed` too, where
	 * `min_ratio 0` drops nothing. Only what a pass actually removed may come
	 * off the total.
	 *
	 * @dataProvider or_mode_strategies
	 *
	 * @param string $strategy Retrieval strategy.
	 */
	public function test_or_logic_keeps_the_redis_total_for_pagination( $strategy ) {
		$this->replies = array(
			array(
				5364,
				'shift64_woo_search:product:101',
				'9.5',
				array( 'post_id', '101', 'title', 'Aero Cedar Side Table', 'stock_status', 'instock' ),
				'shift64_woo_search:product:102',
				'8.0',
				array( 'post_id', '102', 'title', 'Aero Orchid Sheet Mask Cedarwood', 'stock_status', 'instock' ),
			),
		);

		$result = $this->run_archive_search(
			$this->recording_query( array( 'aero', 'cedar', 'table' ) ),
			$strategy,
			array(
				'logic'                   => 'OR',
				'outofstock_mode'         => 'exclude',
				'token_reduction_enabled' => false,
			)
		);

		$this->assertSame( 5364, $result['total'], 'The RediSearch total must survive an OR pass that dropped nothing.' );
		$this->assertEqualsCanonicalizing( array( 101, 102 ), $result['ids'] );
	}

	/**
	 * When the match ratio does drop a row, the total comes down by exactly
	 * that row — never to the size of the fetched window.
	 */
	public function test_or_logic_subtracts_only_the_rows_it_dropped() {
		$this->replies = array(
			array(
				5364,
				'shift64_woo_search:product:101',
				'9.5',
				array( 'post_id', '101', 'title', 'Aero Cedar Side Table', 'stock_status', 'instock' ),
				'shift64_woo_search:product:103',
				'8.0',
				array( 'post_id', '103', 'title', 'Iris Bloom Kettle Compact Slate', 'stock_status', 'instock' ),
			),
		);

		$result = $this->run_archive_search(
			$this->recording_query( array( 'aero', 'cedar', 'table' ) ),
			'strict_first',
			array(
				'logic'                   => 'OR',
				'outofstock_mode'         => 'exclude',
				'token_reduction_enabled' => false,
			)
		);

		$this->assertSame( array( 101 ), $result['ids'], 'A row matching no term falls under the 40% match ratio.' );
		$this->assertSame( 5363, $result['total'], 'One dropped row takes exactly one off the RediSearch total.' );
	}

	// ── Product Collection catalog query ────────────────────────

	/**
	 * `search_catalog()` hands the Product Collection adapter its query list in
	 * ladder order, with the per-token fuzzy pass ahead of the OR relaxation.
	 */
	public function test_search_catalog_orders_its_queries_like_the_ladder() {
		$query = $this->recording_query(
			array( 'aero', 'cedat' ),
			array(
				'strategy'                => 'strict_first',
				'logic'                   => 'AND',
				'token_reduction_enabled' => false,
			)
		);
		$this->assertSame(
			array(),
			$query->search_catalog( 'aero cedat', array(), 16, 1 )['ids'],
			'No reply is served, so every pass comes back empty.'
		);

		$this->assertSame( array( 'strict:default', 'hybrid:1', 'strict:OR', 'fuzzy' ), $this->calls );
	}

	/**
	 * Under `mixed` the adapter gets exactly one query, as on every other path.
	 */
	public function test_search_catalog_runs_one_hybrid_query_under_mixed() {
		$query = $this->recording_query( array( 'aero', 'cedat' ), array( 'strategy' => 'mixed' ) );
		$query->search_catalog( 'aero cedat', array(), 16, 1 );

		$this->assertSame( array( 'hybrid:default' ), $this->calls );
	}

	/**
	 * Product Collection relevance ranks the candidate window before slicing the
	 * requested page, just like the dropdown's relevance path.
	 */
	public function test_search_catalog_reranks_before_pagination() {
		$this->replies = array(
			array(
				2,
				'shift64_woo_search:product:202',
				'10',
				array( 'post_id', '202', 'title', 'Dining Table Aero Cedar', 'stock_status', 'instock' ),
				'shift64_woo_search:product:201',
				'8',
				array( 'post_id', '201', 'title', 'Aero Cedar Office Chair', 'stock_status', 'instock' ),
			),
		);

		$query  = $this->recording_query( array( 'aero', 'cedar' ), array( 'strategy' => 'mixed' ) );
		$result = $query->search_catalog( 'aero cedar', array(), 1, 1 );

		$this->assertSame( array( 201 ), $result['ids'] );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * In OR mode, complete term coverage wins before title-prefix boosting can
	 * make a partial match look more relevant than the full answer.
	 */
	public function test_search_catalog_prioritizes_complete_or_coverage() {
		$this->replies = array(
			array(
				2,
				'shift64_woo_search:product:301',
				'10',
				array( 'post_id', '301', 'title', 'Aero Cedar Office Chair', 'stock_status', 'instock' ),
				'shift64_woo_search:product:302',
				'8',
				array( 'post_id', '302', 'title', 'Aero Cedar Dining Table', 'stock_status', 'instock' ),
			),
		);

		$query  = $this->recording_query(
			array( 'aero', 'cedar', 'table' ),
			array(
				'strategy' => 'mixed',
				'logic'    => 'OR',
			)
		);
		$result = $query->search_catalog( 'aero cedar table', array(), 1, 1 );

		$this->assertSame( array( 302 ), $result['ids'] );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * A later Product Collection page is sliced only after the complete ranked
	 * candidate window has been ordered.
	 */
	public function test_search_catalog_reranks_candidates_before_a_deep_page_slice() {
		$this->replies = array(
			array(
				4,
				'shift64_woo_search:product:301',
				'10',
				array( 'post_id', '301', 'title', 'Dining Table Aero Cedar', 'stock_status', 'instock' ),
				'shift64_woo_search:product:302',
				'9',
				array( 'post_id', '302', 'title', 'Side Chair Aero Cedar', 'stock_status', 'instock' ),
				'shift64_woo_search:product:303',
				'8',
				array( 'post_id', '303', 'title', 'Aero Cedar Office Chair', 'stock_status', 'instock' ),
				'shift64_woo_search:product:304',
				'7',
				array( 'post_id', '304', 'title', 'Aero Cedar Table', 'stock_status', 'instock' ),
			),
		);

		$query  = $this->recording_query( array( 'aero', 'cedar' ), array( 'strategy' => 'mixed' ) );
		$result = $query->search_catalog( 'aero cedar', array(), 2, 2 );

		$this->assertSame( array( 301, 302 ), $result['ids'] );
		$this->assertSame( 4, $result['total'] );
	}

	/**
	 * Deep pages beyond the bounded relevance window use Redis pagination
	 * instead of turning the request page number into an unbounded fetch.
	 */
	public function test_search_catalog_caps_deep_page_candidate_fetches() {
		$this->replies = array(
			array(
				1,
				'shift64_woo_search:product:401',
				array( 'post_id', '401' ),
			),
		);

		$query  = $this->recording_query( array( 'series' ), array( 'strategy' => 'mixed' ) );
		$result = $query->search_catalog( 'series', array(), 12, 5000 );
		$args   = $this->redis_args[0];
		$limit  = array_search( 'LIMIT', $args, true );

		$this->assertSame( array( 401 ), $result['ids'] );
		$this->assertSame( '59988', (string) $args[ $limit + 1 ] );
		$this->assertSame( '12', (string) $args[ $limit + 2 ] );
		$this->assertNotContains( 'WITHSCORES', $args );
	}

	/**
	 * A scored pass whose rows are all removed by OR coverage must not claim a
	 * positive result and stop the ladder with an empty Product Collection page.
	 */
	public function test_search_catalog_advances_when_a_ranked_pass_filters_every_row() {
		$this->replies = array(
			array(
				1000,
				'shift64_woo_search:product:501',
				'10',
				array( 'post_id', '501', 'title', 'Aero Office Chair', 'stock_status', 'instock' ),
				'shift64_woo_search:product:502',
				'9',
				array( 'post_id', '502', 'title', 'Cedar Office Chair', 'stock_status', 'instock' ),
			),
			array(
				1,
				'shift64_woo_search:product:503',
				'8',
				array( 'post_id', '503', 'title', 'Aero Cedar Dining Table', 'stock_status', 'instock' ),
			),
		);

		$query  = $this->recording_query(
			array( 'aero', 'cedar', 'table' ),
			array(
				'strategy'                => 'strict_first',
				'logic'                   => 'OR',
				'token_reduction_enabled' => false,
			)
		);
		$result = $query->search_catalog( 'aero cedar table', array(), 1, 1 );

		$this->assertSame( array( 503 ), $result['ids'], wp_json_encode( $result ) );
		$this->assertSame( array( 'strict:default', 'hybrid:1', 'fuzzy' ), $this->calls );
		$this->assertCount( 2, $this->redis_args, 'The fallback pass should answer without executing the later fuzzy query.' );
		$this->assertSame( 1, $result['total'] );
	}
}
