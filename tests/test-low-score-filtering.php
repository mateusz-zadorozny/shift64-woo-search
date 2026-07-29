<?php
/**
 * Tests for score-threshold filtering and its pass gating.
 *
 * Regression cover for #26: autocomplete and the archive disagreed on which
 * products matched, because Query::search() score-filtered every pass while
 * Archive::execute_search() filtered none.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Pure-function tests for Shift64_Woo_Search_Query::filter_low_scores()
 * and Shift64_Woo_Search_Query::pass_is_fuzzy().
 */
class Low_Score_Filtering_Test extends WP_UnitTestCase {

	/**
	 * Build a result row.
	 *
	 * @param float  $score     Score value.
	 * @param string $score_key Key to store the score under.
	 * @return array
	 */
	private function row( $score, $score_key = '_score' ) {
		return array(
			'id'       => 1,
			'title'    => 'Pulse Zest Shampoo',
			$score_key => $score,
		);
	}

	public function test_drops_results_below_the_threshold() {
		$results = Shift64_Woo_Search_Query::filter_low_scores(
			array( $this->row( 0.2 ), $this->row( 0.9 ) ),
			0.5
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 0.9, $results[0]['_score'] );
	}

	public function test_keeps_results_exactly_at_the_threshold() {
		$results = Shift64_Woo_Search_Query::filter_low_scores(
			array( $this->row( 0.5 ) ),
			0.5
		);

		$this->assertCount( 1, $results );
	}

	public function test_zero_threshold_disables_filtering() {
		$results = Shift64_Woo_Search_Query::filter_low_scores(
			array( $this->row( 0.0 ), $this->row( 0.1 ) ),
			0.0
		);

		$this->assertCount( 2, $results );
	}

	/**
	 * The archive stores scores under 'score', not '_score'. Sharing one
	 * helper across both paths is what keeps the two modes in agreement.
	 */
	public function test_honors_an_alternate_score_key() {
		$results = Shift64_Woo_Search_Query::filter_low_scores(
			array( $this->row( 0.2, 'score' ), $this->row( 0.9, 'score' ) ),
			0.5,
			'score'
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 0.9, $results[0]['score'] );
	}

	/**
	 * A row missing the score key is treated as unscored and dropped rather
	 * than silently passed through as if it had cleared the bar.
	 */
	public function test_drops_rows_missing_the_score_key() {
		$results = Shift64_Woo_Search_Query::filter_low_scores(
			array(
				array(
					'id'    => 7,
					'title' => 'No score',
				),
			),
			0.5
		);

		$this->assertSame( array(), $results );
	}

	/**
	 * The core of #26. Prefix passes return exact lexical matches whose TFIDF
	 * score tracks term rarity, so a common term scores low while matching
	 * perfectly. Only fuzzy passes may be score-filtered.
	 */
	public function test_only_fuzzy_passes_are_score_filtered() {
		$this->assertTrue( Shift64_Woo_Search_Query::pass_is_fuzzy( 'fuzzy' ) );
		$this->assertTrue( Shift64_Woo_Search_Query::pass_is_fuzzy( 'mixed' ) );

		$this->assertFalse( Shift64_Woo_Search_Query::pass_is_fuzzy( 'strict' ) );
		$this->assertFalse( Shift64_Woo_Search_Query::pass_is_fuzzy( 'token_reduced' ) );
		$this->assertFalse( Shift64_Woo_Search_Query::pass_is_fuzzy( 'or_prefix' ) );
	}

	/**
	 * A frequent term produces a low TFIDF score on an exact prefix match.
	 * Before #26 that result survived on the archive and was filtered out of
	 * autocomplete — the user-visible disagreement. Gating on the pass keeps
	 * both modes on the same set.
	 */
	public function test_common_prefix_match_survives_despite_a_low_score() {
		$common_term_hit = array( $this->row( 0.12 ) );

		$this->assertFalse( Shift64_Woo_Search_Query::pass_is_fuzzy( 'strict' ) );

		// Gated: the strict pass never reaches the filter, so the match stays.
		$kept = Shift64_Woo_Search_Query::pass_is_fuzzy( 'strict' )
			? Shift64_Woo_Search_Query::filter_low_scores( $common_term_hit, 0.5 )
			: $common_term_hit;

		$this->assertCount( 1, $kept );

		// Ungated (the old behavior) would have dropped it.
		$this->assertSame(
			array(),
			Shift64_Woo_Search_Query::filter_low_scores( $common_term_hit, 0.5 )
		);
	}
}
