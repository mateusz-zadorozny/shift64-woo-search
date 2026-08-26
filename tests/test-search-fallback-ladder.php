<?php
/**
 * Tests for the strict-first fallback ladder and the hybrid query builder.
 *
 * Two defects motivated this cover, both reproduced on a 100k-product index:
 *
 * 1. `should_fallback()` compared a raw RediSearch TFIDF score (routinely 15–25)
 *    against `fallback_score_threshold` (0.5), so it was true only for an empty
 *    result set. A typo in one word of a multi-word query never reached a fuzzy
 *    pass: "aero cedat" matched everything starting with "aero" and stopped.
 * 2. `strategy=mixed` read an undefined `$terms` in Query::search(), raising two
 *    PHP warnings per search and skipping the title-start and SKU re-ranks.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Ladder ordering, term coverage, and the per-token fuzzy query shape.
 */
class Search_Fallback_Ladder_Test extends WP_UnitTestCase {

	/**
	 * Real Query with a mocked Redis connector.
	 *
	 * @param array $config    Config overrides.
	 * @param array $ft_result Payload returned for FT.SEARCH calls.
	 * @return Shift64_Woo_Search_Query
	 */
	private function make_query( $config = array(), $ft_result = null ) {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		$redis->method( 'get_prefix' )->willReturn( 'shift64_woo_search' );
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturnCallback(
			function ( $command = '' ) use ( $ft_result ) {
				return ( 'FT.SEARCH' === $command && null !== $ft_result ) ? $ft_result : false;
			}
		);

		return new Shift64_Woo_Search_Query( $redis, $config );
	}

	/**
	 * One FT.SEARCH reply carrying a single product.
	 *
	 * @param string $title Product title.
	 * @param float  $score Result score.
	 * @return array
	 */
	private function ft_reply( $title, $score = 12.5 ) {
		return array(
			1,
			'shift64_woo_search:product:1',
			(string) $score,
			array( 'post_id', '1', 'title', $title, 'permalink', 'https://example.test/p/1', 'price', '10' ),
		);
	}

	/**
	 * Coverage needles in the shape best_term_coverage() expects.
	 *
	 * @param string ...$terms One accepted literal per term.
	 * @return array<int,string[]>
	 */
	private function needles( ...$terms ) {
		return array_map(
			function ( $term ) {
				return array( $term );
			},
			$terms
		);
	}

	// ── Defaults ────────────────────────────────────────────────

	/**
	 * OR retrieval ranks a two-of-three-token match above a three-of-three one,
	 * which is what put beauty products above "Aero Cedar Side Table" for
	 * "aero cedar table". AND is the shipped default; mixed carries the
	 * per-token fuzzy that repairs typos in the same query.
	 */
	public function test_defaults_are_and_logic_and_mixed_strategy() {
		$query = $this->make_query( array(), $this->ft_reply( 'Nova Athena Polo Shirt Brushed Lilac' ) );

		$this->assertStringContainsString(
			'(nova* athena*)',
			$query->build_strict_query( array( 'nova', 'athena' ) ),
			'Default logic must join terms with AND (a space), not with |.'
		);

		$this->assertSame(
			'mixed',
			$query->search( 'nova athena', 'autocomplete' )['search_pass'],
			'Default strategy must be the single hybrid pass.'
		);
	}

	// ── Hybrid query shape ──────────────────────────────────────

	/**
	 * The pass that fixes "aero cedat": each token is prefix OR fuzzy, and the
	 * tokens are still ANDed, so the correctly spelled words stay required.
	 */
	public function test_hybrid_query_fuzzes_each_token_without_relaxing_logic() {
		$query = $this->make_query( array( 'logic' => 'AND' ) );

		$ft = $query->build_hybrid_query( array( 'aero', 'cedat' ) );

		$this->assertStringContainsString( '(cedat*|%cedat%)', $ft );
		$this->assertStringContainsString( 'aero* (cedat*|%cedat%)', $ft );
		$this->assertStringNotContainsString( 'aero*|', $ft );
	}

	/**
	 * Short tokens stay prefix-only: fuzzing three letters matches noise.
	 */
	public function test_hybrid_query_leaves_short_tokens_prefix_only() {
		$query = $this->make_query( array( 'logic' => 'AND' ) );

		$ft = $query->build_hybrid_query( array( 'zip', 'hodie' ) );

		$this->assertStringContainsString( 'zip*', $ft );
		$this->assertStringNotContainsString( '%zip%', $ft );
		$this->assertStringContainsString( '(hodie*|%hodie%)', $ft );
	}

	/**
	 * A fallback pass may fuzz harder than the first-choice pass.
	 */
	public function test_hybrid_query_honors_an_explicit_fuzzy_level() {
		$query = $this->make_query( array( 'logic' => 'AND' ) );

		$this->assertStringContainsString( '(cedat*|%%cedat%%)', $query->build_hybrid_query( array( 'cedat' ), array(), 2 ) );
	}

	/**
	 * OR logic still applies to the hybrid shape when a merchant asks for it.
	 */
	public function test_hybrid_query_respects_or_logic() {
		$query = $this->make_query( array( 'logic' => 'OR' ) );

		$this->assertStringContainsString( '(aero*|(cedat*|%cedat%))', $query->build_hybrid_query( array( 'aero', 'cedat' ) ) );
	}

	/**
	 * The hybrid builder supersedes build_ft_query() and, unlike it, carries
	 * the SKU-concatenation branch every other builder already had.
	 */
	public function test_hybrid_query_keeps_the_sku_concatenation_branch() {
		$query = $this->make_query( array( 'logic' => 'AND' ) );

		$this->assertStringContainsString( '@sku:{djm201}', $query->build_hybrid_query( array( 'djm', '201' ) ) );
	}

	// ── Term coverage ───────────────────────────────────────────

	/**
	 * A pass that answered the whole query has nothing to hand over.
	 */
	public function test_coverage_is_full_when_a_result_contains_every_term() {
		$results = array( array( 'title' => 'Aero Cedar Side Table Insulated Natural Oak' ) );

		$this->assertSame(
			1.0,
			Shift64_Woo_Search_Query::best_term_coverage( $this->needles( 'aero', 'cedar', 'table' ), $results )
		);
	}

	/**
	 * The exact shape of the reported bug: an OR pass returns products that
	 * match two of the three words, and the ladder has to keep going.
	 */
	public function test_coverage_is_partial_when_no_result_contains_every_term() {
		$results = array(
			array( 'title' => 'Aero Orchid Sheet Mask Lightweight Cedarwood' ),
			array( 'title' => 'Aero Nectar Shampoo Fragrance Free Cedarwood' ),
		);

		$this->assertEqualsWithDelta(
			2 / 3,
			Shift64_Woo_Search_Query::best_term_coverage( $this->needles( 'aero', 'cedar', 'table' ), $results ),
			0.0001
		);
	}

	/**
	 * A term the shopper typed can be matched by a brand or a category, not
	 * only by the title — coverage reads every indexed TEXT field.
	 */
	public function test_coverage_counts_brand_and_category_fields() {
		$results = array(
			array(
				'title'           => 'Iris Bloom Hoodie Relaxed Slate',
				'brands_text'     => 'Aeon Atelier',
				'categories_text' => 'Hoodies Tops Clothing',
			),
		);

		$this->assertSame(
			1.0,
			Shift64_Woo_Search_Query::best_term_coverage( $this->needles( 'aeon', 'hoodie' ), $results )
		);
	}

	/**
	 * Only the head of an over-fetched result set is inspected; a perfect match
	 * buried at position 40 must not veto the fallback the leaders earned.
	 */
	public function test_coverage_inspects_only_the_leading_results() {
		$results   = array_fill( 0, 6, array( 'title' => 'Aero Orchid Sheet Mask Cedarwood' ) );
		$results[] = array( 'title' => 'Aero Cedar Side Table Natural Oak' );

		$needles = $this->needles( 'aero', 'cedar', 'table' );

		$this->assertEqualsWithDelta( 2 / 3, Shift64_Woo_Search_Query::best_term_coverage( $needles, $results, 5 ), 0.0001 );
		$this->assertSame( 1.0, Shift64_Woo_Search_Query::best_term_coverage( $needles, $results, 100 ) );
	}

	/**
	 * Nothing to measure means no coverage, never a division by zero.
	 */
	public function test_coverage_is_zero_without_needles_or_results() {
		$this->assertSame( 0.0, Shift64_Woo_Search_Query::best_term_coverage( array(), array( array( 'title' => 'Anything' ) ) ) );
		$this->assertSame( 0.0, Shift64_Woo_Search_Query::best_term_coverage( $this->needles( 'aero' ), array() ) );
	}

	/**
	 * A synonym hit still covers the term the shopper typed, so a store with
	 * synonyms configured does not fall through the ladder on every search.
	 */
	public function test_coverage_accepts_a_synonym_variant() {
		$results = array( array( 'title' => 'Alto Radiance Moisturizer Lightweight Almond' ) );

		$this->assertSame(
			1.0,
			Shift64_Woo_Search_Query::best_term_coverage(
				array( array( 'moisturiser', 'moisturizer' ), array( 'almond' ) ),
				$results
			)
		);
	}

	// ── Ladder behavior ─────────────────────────────────────────

	/**
	 * The `mixed` strategy used to read an undefined `$terms`, so every search
	 * raised two PHP warnings. phpunit.xml.dist converts warnings to
	 * exceptions, so an unfixed build fails here rather than passing noisily.
	 */
	public function test_mixed_strategy_search_runs_without_php_warnings() {
		$query = $this->make_query(
			array( 'strategy' => 'mixed' ),
			$this->ft_reply( 'Aero Cedar Side Table Insulated Natural Oak' )
		);

		$response = $query->search( 'aero cedar table', 'autocomplete' );

		$this->assertSame( 'mixed', $response['search_pass'] );
		$this->assertCount( 1, $response['results'] );
	}

	/**
	 * With the strict pass returning a result that covers every term there is
	 * nothing to fall back to, so the ladder stops at pass 1.
	 */
	public function test_strict_pass_wins_when_it_covers_every_term() {
		$query = $this->make_query(
			array(
				'strategy' => 'strict_first',
				'logic'    => 'AND',
			),
			$this->ft_reply( 'Aero Cedar Side Table Insulated Natural Oak' )
		);

		$response = $query->search( 'aero cedar table', 'autocomplete' );

		$this->assertSame( 'strict', $response['search_pass'] );
		$this->assertArrayNotHasKey( 'token_fuzzy', $response['debug_queries'] );
	}

	/**
	 * The regression itself. Under OR logic the strict pass returns plenty of
	 * results, none of which contain "table"; the old score-threshold check
	 * called that good enough and stopped. Coverage keeps the ladder moving to
	 * the per-token fuzzy pass.
	 */
	public function test_partial_coverage_drives_the_ladder_to_the_token_fuzzy_pass() {
		$query = $this->make_query(
			array(
				'strategy'                => 'strict_first',
				'logic'                   => 'OR',
				'token_reduction_enabled' => false,
			),
			$this->ft_reply( 'Aero Orchid Sheet Mask Lightweight Cedarwood' )
		);

		$response = $query->search( 'aero cedar table', 'autocomplete' );

		$this->assertSame( 'token_fuzzy', $response['search_pass'] );
		$this->assertArrayHasKey( 'token_fuzzy', $response['debug_queries'] );
		$this->assertStringContainsString( '%cedar%', $response['debug_queries']['token_fuzzy'] );
	}

	/**
	 * The per-token fuzzy pass is terminal: its hits are approximate by design,
	 * so re-testing coverage would always fail and hand a good answer over to
	 * the broader OR and fuzzy passes.
	 */
	public function test_token_fuzzy_pass_is_not_overridden_by_later_passes() {
		$query = $this->make_query(
			array(
				'strategy'                => 'strict_first',
				'logic'                   => 'OR',
				'token_reduction_enabled' => false,
			),
			$this->ft_reply( 'Aero Cedar Kettle Handcrafted Deep Teal' )
		);

		$response = $query->search( 'aero cedat', 'autocomplete' );

		$this->assertSame( 'token_fuzzy', $response['search_pass'] );
		$this->assertArrayNotHasKey( 'or_prefix', $response['debug_queries'] );
		$this->assertArrayNotHasKey( 'fuzzy', $response['debug_queries'] );
	}

	/**
	 * A per-token fuzzy pass whose every hit falls under the score threshold is
	 * not an answer. Declaring it terminal before filtering returned an empty
	 * dropdown while the archive — which filters inside the pass, then re-tests
	 * emptiness — carried on to the broader passes.
	 */
	public function test_token_fuzzy_pass_that_scores_below_the_threshold_keeps_falling_back() {
		$query = $this->make_query(
			array(
				'strategy'                 => 'strict_first',
				'logic'                    => 'OR',
				'token_reduction_enabled'  => false,
				'fallback_score_threshold' => 100.0,
			),
			$this->ft_reply( 'Aero Orchid Sheet Mask Lightweight Cedarwood', 12.5 )
		);

		$response = $query->search( 'aero cedar table', 'autocomplete' );

		$this->assertNotSame( 'token_fuzzy', $response['search_pass'] );
		$this->assertArrayHasKey( 'fuzzy', $response['debug_queries'] );
	}

	/**
	 * `no_results` still means what it says: never leave a pass that returned
	 * anything, however poorly it matched.
	 */
	public function test_no_results_trigger_keeps_the_first_non_empty_pass() {
		$query = $this->make_query(
			array(
				'strategy'         => 'strict_first',
				'logic'            => 'OR',
				'fallback_trigger' => 'no_results',
			),
			$this->ft_reply( 'Aero Orchid Sheet Mask Lightweight Cedarwood' )
		);

		$response = $query->search( 'aero cedar table', 'autocomplete' );

		$this->assertSame( 'strict', $response['search_pass'] );
	}

	// ── Term-match ranking ──────────────────────────────────────

	/**
	 * Ranking reads the identity fields, so a brand match counts as a matched
	 * term instead of being dropped for not appearing in the title.
	 */
	public function test_term_match_boost_counts_identity_fields() {
		$results = Shift64_Woo_Search_Query::boost_term_match_count(
			array( 'aeon', 'hoodie' ),
			array(
				array(
					'title'       => 'Iris Bloom Hoodie Relaxed Slate',
					'brands_text' => 'Aeon Atelier',
					'_score'      => 4.0,
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 4.0, $results[0]['_score'], 'A full-coverage row keeps its score (ratio 1.0).' );
	}

	/**
	 * A term buried in a long description is weak evidence — counting it would
	 * flatten the ordering, so ranking ignores the body fields.
	 */
	public function test_term_match_boost_ignores_description_text() {
		$results = Shift64_Woo_Search_Query::boost_term_match_count(
			array( 'aero', 'cedar', 'table' ),
			array(
				array(
					'title'       => 'Aero Orchid Sheet Mask Cedarwood',
					'description' => 'Pairs well with any side table in the room.',
					'_score'      => 9.0,
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertEqualsWithDelta( 9.0 * ( ( 2 / 3 ) ** 2 ), $results[0]['_score'], 0.0001 );
	}
}
