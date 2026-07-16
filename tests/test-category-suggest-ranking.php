<?php
/**
 * Tests for category autocomplete suggestion ranking.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Pure-function tests for Shift64_Woo_Search_Category_Suggest.
 */
class Category_Suggest_Ranking_Test extends WP_UnitTestCase {

	/**
	 * Build one blob entry, mirroring Shift64_Woo_Search_Indexer::cache_categories_to_redis().
	 *
	 * @param string     $name  Category name.
	 * @param int        $count Product count.
	 * @param float|null $boost Optional per-category boost (omitted when null).
	 * @return array
	 */
	private function cat( $name, $count, $boost = null ) {
		$ascii = Shift64_Woo_Search_Category_Suggest::normalize( $name );
		$entry = array(
			'name'       => $name,
			'name_ascii' => $ascii,
			'slug'       => str_replace( ' ', '-', $ascii ),
			'url'        => 'https://example.test/' . rawurlencode( $ascii ),
			'count'      => $count,
		);
		if ( null !== $boost ) {
			$entry['boost'] = $boost;
		}
		return $entry;
	}

	/**
	 * The "mydła" fixture from the reported bug, in stored (name-ascending) order.
	 *
	 * @return array
	 */
	private function soap_fixture() {
		return array(
			$this->cat( 'Automatyczne dozowniki mydła', 12 ),
			$this->cat( 'Dozowniki mydła', 40 ),
			$this->cat( 'Dozowniki mydła w pianie', 30 ),
			$this->cat( 'Dozowniki mydła w płynie', 25 ),
			$this->cat( 'Mydła w pianie SHIFT64 BALI', 5 ),
			$this->cat( 'Mydła w płynie', 8 ),
			$this->cat( 'Stalowe dozowniki mydła', 15 ),
		);
	}

	/**
	 * Extract just the names from a ranked result, in order.
	 *
	 * @param array $ranked Result of ::rank().
	 * @return array
	 */
	private function names( $ranked ) {
		return array_map(
			function ( $row ) {
				return $row['name'];
			},
			$ranked
		);
	}

	/**
	 * Prefix matches outrank substring matches.
	 */
	public function test_prefix_match_outranks_substring_match() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'mydła' );
		$names  = $this->names( $ranked );

		// "Mydła w płynie" (prefix match) must beat every "Dozowniki mydła"
		// (substring match) — the whole point of the reported fix.
		$this->assertSame( 'Mydła w płynie', $names[0] );
		$this->assertSame( 'Mydła w pianie SHIFT64 BALI', $names[1] );
	}

	/**
	 * Categories ranked highly after sorting survive the limit cap.
	 */
	public function test_relevant_category_survives_the_cap() {
		// With cap-before-sort, five "Dozowniki…" matched first alphabetically and
		// "Mydła w płynie" never entered the top 5. Assert it now does.
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'mydła' );
		$this->assertContains( 'Mydła w płynie', $this->names( $ranked ) );
	}

	/**
	 * Ranking is capped to the public autocomplete limit.
	 */
	public function test_result_is_capped_to_limit() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'mydła' );
		$this->assertLessThanOrEqual( Shift64_Woo_Search_Category_Suggest::LIMIT, count( $ranked ) );
		$this->assertCount( 5, $ranked );
	}

	/**
	 * Exact category-name matches outrank prefix matches.
	 */
	public function test_exact_name_match_outranks_prefix_match() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'dozowniki mydła' );
		$this->assertSame( 'Dozowniki mydła', $this->names( $ranked )[0] );
	}

	/**
	 * Category matching ignores Polish diacritics.
	 */
	public function test_diacritics_insensitive_matching() {
		// Query without the Polish diacritic still matches.
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'mydla' );
		$this->assertSame( 'Mydła w płynie', $this->names( $ranked )[0] );
	}

	/**
	 * Product count breaks ties within the same relevance tier.
	 */
	public function test_count_breaks_ties_within_same_relevance_tier() {
		// Two prefix matches, no boost: higher product count wins.
		$cats   = array(
			$this->cat( 'Mydła w pianie SHIFT64 BALI', 5 ),
			$this->cat( 'Mydła w płynie', 8 ),
		);
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $cats, 'mydła' );
		$this->assertSame( 'Mydła w płynie', $this->names( $ranked )[0] );
	}

	/**
	 * Category boost breaks ties before product count.
	 */
	public function test_boost_breaks_ties_above_count() {
		// Boost the lower-count prefix match; it must now outrank the higher-count one.
		$cats   = array(
			$this->cat( 'Mydła w pianie SHIFT64 BALI', 5, 3.0 ),
			$this->cat( 'Mydła w płynie', 8 ),
		);
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $cats, 'mydła' );
		$this->assertSame( 'Mydła w pianie SHIFT64 BALI', $this->names( $ranked )[0] );
	}

	/**
	 * Category boost does not outrank stronger textual relevance.
	 */
	public function test_boost_does_not_cross_relevance_tiers() {
		// A boosted substring match must NOT jump over an un-boosted prefix match:
		// relevance is more significant than boost in the sort tuple.
		$cats   = array(
			$this->cat( 'Dozowniki mydła', 40, 10.0 ), // Substring, heavily boosted.
			$this->cat( 'Mydła w płynie', 8 ),         // Prefix, no boost.
		);
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $cats, 'mydła' );
		$this->assertSame( 'Mydła w płynie', $this->names( $ranked )[0] );
	}

	/**
	 * Old category blobs without boost fields default to neutral boost.
	 */
	public function test_entries_without_boost_field_default_to_one() {
		// Old cached blobs have no 'boost' key at all — must not error and must
		// rank identically to an explicit boost of 1.0.
		$without = array(
			$this->cat( 'Mydła w płynie', 8 ),          // No boost key.
			$this->cat( 'Mydła w pianie SHIFT64 BALI', 5 ),
		);
		$with    = array(
			$this->cat( 'Mydła w płynie', 8, 1.0 ),
			$this->cat( 'Mydła w pianie SHIFT64 BALI', 5, 1.0 ),
		);
		$this->assertSame(
			$this->names( Shift64_Woo_Search_Category_Suggest::rank( $without, 'mydła' ) ),
			$this->names( Shift64_Woo_Search_Category_Suggest::rank( $with, 'mydła' ) )
		);
	}

	/**
	 * Typo-tolerant category matching is disabled by default.
	 */
	public function test_fuzzy_matching_is_disabled_by_default() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'mydlo' );
		$this->assertEmpty( $ranked );
	}

	/**
	 * Fuzzy matching catches a one-character typo in a category token.
	 */
	public function test_fuzzy_matching_catches_single_token_typo_when_enabled() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'mydlo', '', true );
		$this->assertSame( 'Mydła w płynie', $this->names( $ranked )[0] );
	}

	/**
	 * Fuzzy matching requires every query token to match a category-name token.
	 */
	public function test_fuzzy_matching_handles_multi_token_typo_when_enabled() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'dozownki mydla', '', true );
		$this->assertSame( 'Dozowniki mydła', $this->names( $ranked )[0] );
	}

	/**
	 * Fuzzy matches stay below normal substring matches.
	 */
	public function test_fuzzy_matching_does_not_outrank_substring_match() {
		$cats = array(
			$this->cat( 'Mydła w płynie', 100 ),
			$this->cat( 'Dozowniki mydlo', 1 ),
		);

		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $cats, 'mydlo', '', true );
		$this->assertSame( 'Dozowniki mydlo', $this->names( $ranked )[0] );
	}

	/**
	 * Pin rules can force substring matches to the top.
	 */
	public function test_pin_forces_substring_match_to_top() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank(
			$this->soap_fixture(),
			'mydła',
			'mydla|Dozowniki mydła w płynie|5'
		);
		$this->assertSame( 'Dozowniki mydła w płynie', $this->names( $ranked )[0] );
	}

	/**
	 * Pin rules can inject a category that does not textually match the query.
	 */
	public function test_pin_injects_category_without_textual_match() {
		// "Ręczniki papierowe" does not contain "mydła" — a pin must still inject it.
		$cats   = $this->soap_fixture();
		$cats[] = $this->cat( 'Ręczniki papierowe', 99 );
		$ranked = Shift64_Woo_Search_Category_Suggest::rank(
			$cats,
			'mydła',
			'mydla|Ręczniki papierowe|3'
		);
		$this->assertSame( 'Ręczniki papierowe', $this->names( $ranked )[0] );
	}

	/**
	 * Pin rules fire while the shopper is still typing.
	 */
	public function test_pin_fires_on_partial_typing() {
		// Rule query "mydla", shopper has only typed "myd" — bidirectional prefix.
		$ranked = Shift64_Woo_Search_Category_Suggest::rank(
			$this->soap_fixture(),
			'myd',
			'mydla|Stalowe dozowniki mydła'
		);
		$this->assertSame( 'Stalowe dozowniki mydła', $this->names( $ranked )[0] );
	}

	/**
	 * Pin rules stay inactive for unrelated queries.
	 */
	public function test_pin_does_not_fire_for_unrelated_query() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank(
			$this->soap_fixture(),
			'papier',
			'mydla|Dozowniki mydła w płynie|5'
		);
		// No "mydła" categories should surface for "papier"; pin is inactive.
		$this->assertEmpty( $ranked );
	}

	/**
	 * Higher pin priority wins over lower pin priority.
	 */
	public function test_higher_priority_pin_wins() {
		$cats   = array(
			$this->cat( 'Mydła w płynie', 8 ),
			$this->cat( 'Dozowniki mydła', 40 ),
		);
		$ranked = Shift64_Woo_Search_Category_Suggest::rank(
			$cats,
			'mydła',
			"mydla|Mydła w płynie|1\nmydla|Dozowniki mydła|9"
		);
		$this->assertSame( 'Dozowniki mydła', $this->names( $ranked )[0] );
	}

	/**
	 * Pin targets can reference categories by slug.
	 */
	public function test_pin_matches_by_slug() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank(
			$this->soap_fixture(),
			'mydła',
			'mydla|stalowe-dozowniki-mydla|4'
		);
		$this->assertSame( 'Stalowe dozowniki mydła', $this->names( $ranked )[0] );
	}

	/**
	 * Pin parsing skips comments and malformed lines.
	 */
	public function test_parse_pins_skips_comments_and_malformed_lines() {
		$rules = Shift64_Woo_Search_Category_Suggest::parse_pins(
			"# comment\nmydla|Mydła w płynie|2\n\nonlyonefield\nquery|cat\n|missing-query|1"
		);
		$this->assertCount( 2, $rules );
		$this->assertSame( 'mydla', $rules[0]['query'] );
		$this->assertSame( 'mydla w plynie', $rules[0]['category'] );
		$this->assertSame( 2, $rules[0]['priority'] );
		$this->assertSame( 1, $rules[1]['priority'] ); // Default when omitted.
	}

	/**
	 * Pin priority is clamped to one at minimum.
	 */
	public function test_parse_pins_clamps_priority_to_minimum_one() {
		$rules = Shift64_Woo_Search_Category_Suggest::parse_pins( 'mydla|Mydła w płynie|0' );
		$this->assertSame( 1, $rules[0]['priority'] );
	}

	/**
	 * Empty pin rule input returns no rules.
	 */
	public function test_parse_pins_empty_input_returns_empty() {
		$this->assertSame( array(), Shift64_Woo_Search_Category_Suggest::parse_pins( '' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Category_Suggest::parse_pins( "   \n  " ) );
	}

	/**
	 * Empty category blobs return no suggestions.
	 */
	public function test_empty_blob_returns_empty() {
		$this->assertSame( array(), Shift64_Woo_Search_Category_Suggest::rank( array(), 'mydła' ) );
	}

	/**
	 * Non-array category blobs return no suggestions.
	 */
	public function test_non_array_blob_returns_empty() {
		// json_decode of a corrupt/missing blob can yield null.
		$this->assertSame( array(), Shift64_Woo_Search_Category_Suggest::rank( null, 'mydła' ) );
	}

	/**
	 * Unmatched queries return no suggestions.
	 */
	public function test_no_match_returns_empty() {
		$ranked = Shift64_Woo_Search_Category_Suggest::rank( $this->soap_fixture(), 'xyznonexistent' );
		$this->assertEmpty( $ranked );
	}
}
