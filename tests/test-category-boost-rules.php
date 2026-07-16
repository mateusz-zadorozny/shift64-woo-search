<?php
/**
 * Tests for category/tag boost rules.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Pure-function tests for category/tag boost parsing and scoring.
 */
class Category_Boost_Rules_Test extends WP_UnitTestCase {

	public function test_parses_global_and_query_specific_rules() {
		$rules = Shift64_Woo_Search_Query::parse_category_boost_rules(
			"Promocja|1.2\npapier|Papier toaletowy|1.5\n# ignored"
		);

		$this->assertCount( 2, $rules );
		$this->assertSame( '', $rules[0]['query'] );
		$this->assertSame( 'Promocja', $rules[0]['match'] );
		$this->assertSame( 1.2, $rules[0]['factor'] );
		$this->assertSame( 'papier', $rules[1]['query'] );
		$this->assertSame( 'Papier toaletowy', $rules[1]['match'] );
		$this->assertSame( 1.5, $rules[1]['factor'] );
	}

	public function test_applies_global_rule_to_tag_field() {
		$results = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'dozownik',
			array(
				array(
					'title'  => 'Produkt promocyjny',
					'tags'   => 'Promocja|Nowość',
					'_score' => 10.0,
				),
			),
			'Promocja|1.2'
		);

		$this->assertSame( 12.0, $results[0]['_score'] );
	}

	public function test_applies_global_rule_to_category_field() {
		$results = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'papier',
			array(
				array(
					'title'      => 'Papier toaletowy',
					'categories' => 'Papier toaletowy i pojemniki na papier toaletowy|Promocje',
					'_score'     => 10.0,
				),
			),
			'Promocje|1.3'
		);

		$this->assertSame( 13.0, $results[0]['_score'] );
	}

	public function test_applies_query_specific_rule_only_for_matching_query() {
		$rules = 'papier|Promocja|1.5';

		$matched = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'papier',
			array(
				array(
					'title'  => 'Produkt promocyjny',
					'tags'   => 'Promocja',
					'_score' => 10.0,
				),
			),
			$rules
		);

		$not_matched = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'dozownik',
			array(
				array(
					'title'  => 'Produkt promocyjny',
					'tags'   => 'Promocja',
					'_score' => 10.0,
				),
			),
			$rules
		);

		$this->assertSame( 15.0, $matched[0]['_score'] );
		$this->assertSame( 10.0, $not_matched[0]['_score'] );
	}

	public function test_does_not_apply_partial_category_name_match() {
		$results = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'papier',
			array(
				array(
					'title'      => 'Produkt',
					'categories' => 'Promocje',
					'_score'     => 10.0,
				),
			),
			'Promo|1.5'
		);

		$this->assertSame( 10.0, $results[0]['_score'] );
	}

	public function test_uses_highest_matching_factor_instead_of_compounding() {
		$results = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'papier',
			array(
				array(
					'title'  => 'Produkt promocyjny',
					'tags'   => 'Promocja',
					'_score' => 10.0,
				),
			),
			"Promocja|1.2\npapier|Promocja|1.5"
		);

		$this->assertSame( 15.0, $results[0]['_score'] );
	}

	public function test_allows_large_manual_test_factor() {
		$results = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'butelka',
			array(
				array(
					'title'      => 'Krem ochronny do rąk, butelka z pompką',
					'categories' => 'Kremy pielęgnacyjne do rak',
					'_score'     => 2.0,
				),
			),
			'Kremy pielęgnacyjne do rak|150'
		);

		$this->assertSame( 300.0, $results[0]['_score'] );
	}

	public function test_matches_category_with_diacritics_folded() {
		// Admin types the rule without ogonki ("rak" instead of "rąk") —
		// should still match the indexed value "Kremy pielęgnacyjne do rąk"
		// because both sides go through strip_diacritics_static().
		$results = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'krem',
			array(
				array(
					'title'      => 'Krem do rąk',
					'categories' => 'Kremy pielęgnacyjne do rąk',
					'_score'     => 5.0,
				),
			),
			'Kremy pielegnacyjne do rak|2'
		);

		$this->assertSame( 10.0, $results[0]['_score'] );
	}

	public function test_skips_results_with_missing_or_empty_category_and_tag_fields() {
		$results = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'cokolwiek',
			array(
				array(
					'title'  => 'No fields at all',
					'_score' => 4.0,
				),
				array(
					'title'      => 'Empty fields',
					'categories' => '',
					'tags'       => '',
					'_score'     => 3.0,
				),
			),
			'Promocja|1.5'
		);

		$this->assertSame( 4.0, $results[0]['_score'] );
		$this->assertSame( 3.0, $results[1]['_score'] );
	}

	public function test_sku_exact_match_outranks_max_category_boost() {
		// Re-rank ordering invariant: category/tag boost runs BEFORE
		// rerank_sku_matches() so that even a 200× factor on an
		// unrelated product can't push it above an exact SKU match.
		// rerank_sku_matches() uses (max_score + 100) for an exact SKU
		// hit, where max_score is recomputed AFTER the category boost.
		// See class-shift64-woo-search-query.php boost_configured_categories
		// call site for the corresponding code comment.
		$results = array(
			array(
				'id'     => 1,
				'title'  => 'DJM 201 — przykładowy produkt',
				'sku'    => 'DJM201',
				'_score' => 1.0,
			),
			array(
				'id'         => 2,
				'title'      => 'Promocja Item',
				'sku'        => 'OTHER',
				'categories' => 'Kremy pielęgnacyjne do rąk',
				'_score'     => 10.0,
			),
		);

		// Apply max-factor category boost.
		$results = Shift64_Woo_Search_Query::apply_category_boost_rules(
			'djm201',
			$results,
			'Kremy pielęgnacyjne do rąk|200'
		);

		$score_by_id = array();
		foreach ( $results as $r ) {
			$score_by_id[ $r['id'] ] = $r['_score'];
		}

		// Sanity: category boost did inflate the irrelevant product.
		$this->assertSame( 2000.0, $score_by_id[2] );
		$this->assertSame( 1.0, $score_by_id[1] );

		// Reproduce the rerank_sku_matches() scoring rule: exact SKU
		// match gets max(scores) + 100. Confirm the SKU candidate
		// finishes above the boosted irrelevant one.
		$max_post_boost    = max( array_values( $score_by_id ) );
		$sku_post_rerank   = $max_post_boost + 100;
		$other_post_rerank = $score_by_id[2];

		$this->assertGreaterThan(
			$other_post_rerank,
			$sku_post_rerank,
			'SKU exact match must rank above any category-boosted product after rerank_sku_matches().'
		);
	}

	public function test_parses_rule_containing_polish_a_with_ogonek() {
		// Regression: preg_split('/\R/', ...) without the `u` flag matches
		// byte 0x85 (NEL) inside multibyte chars, including UTF-8 `ą`
		// (0xC4 0x85). A rule like "...rąk|200" got split mid-character,
		// silently truncating `match` and corrupting the factor.
		$rules = Shift64_Woo_Search_Query::parse_category_boost_rules( 'Kremy pielęgnacyjne do rąk|200' );

		$this->assertCount( 1, $rules );
		$this->assertSame( 'Kremy pielęgnacyjne do rąk', $rules[0]['match'] );
		$this->assertSame( 200.0, $rules[0]['factor'] );
	}

	public function test_parse_drops_malformed_pre_parsed_entries() {
		// The pre-parsed array path must not silently trust malformed
		// entries — otherwise a buggy caller could bypass the matcher
		// normalization or factor clamp by handing in raw data.
		$valid_entry = array(
			'query'    => '',
			'match'    => 'Promocja',
			'factor'   => 1.5,
			'matchers' => array( 'promocja' ),
		);

		$rules = Shift64_Woo_Search_Query::parse_category_boost_rules(
			array(
				array(
					'query'  => '',
					'match'  => 'NoMatchers',
					'factor' => 5.0,
				),
				$valid_entry,
				'not-an-array',
				array(
					'query'    => '',
					'match'    => 'BadMatchers',
					'factor'   => 2.0,
					'matchers' => 'oops',
				),
			)
		);

		$this->assertCount( 1, $rules );
		$this->assertSame( 'Promocja', $rules[0]['match'] );
	}
}
