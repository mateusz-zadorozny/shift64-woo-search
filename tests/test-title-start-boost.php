<?php
/**
 * Tests for exact title-start score boosting.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Pure-function tests for Shift64_Woo_Search_Query::boost_title_start().
 */
class Title_Start_Boost_Test extends WP_UnitTestCase {

	public function test_boosts_single_exact_first_title_word() {
		$results = Shift64_Woo_Search_Query::boost_title_start(
			array( 'papier' ),
			array(
				array(
					'title'  => 'Papier toaletowy bez gilzy',
					'_score' => 2.0,
				),
			)
		);

		$this->assertSame( 4.0, $results[0]['_score'] );
	}

	public function test_boosts_single_exact_second_title_word() {
		$results = Shift64_Woo_Search_Query::boost_title_start(
			array( 'papier' ),
			array(
				array(
					'title'  => 'Dozownik papier w listkach',
					'_score' => 2.0,
				),
			)
		);

		$this->assertSame( 4.0, $results[0]['_score'] );
	}

	public function test_does_not_boost_single_prefix_match_in_second_title_word() {
		$results = Shift64_Woo_Search_Query::boost_title_start(
			array( 'papier' ),
			array(
				array(
					'title'  => 'Filtr papierowy do odkurzacza',
					'_score' => 2.0,
				),
			)
		);

		$this->assertSame( 2.0, $results[0]['_score'] );
	}

	public function test_does_not_boost_single_prefix_match_in_first_title_word() {
		$results = Shift64_Woo_Search_Query::boost_title_start(
			array( 'papier' ),
			array(
				array(
					'title'  => 'Papierowe podkładki higieniczne',
					'_score' => 2.0,
				),
			)
		);

		$this->assertSame( 2.0, $results[0]['_score'] );
	}

	public function test_boosts_exact_multi_term_phrase_more_strongly() {
		$results = Shift64_Woo_Search_Query::boost_title_start(
			array( 'papier', 'toaletowy' ),
			array(
				array(
					'title'  => 'Papier toaletowy bez gilzy',
					'_score' => 2.0,
				),
			)
		);

		$this->assertSame( 6.0, $results[0]['_score'] );
	}

	public function test_boosts_exact_multi_term_phrase_from_second_title_word() {
		$results = Shift64_Woo_Search_Query::boost_title_start(
			array( 'papier', 'toaletowy' ),
			array(
				array(
					'title'  => 'Dozownik papier toaletowy',
					'_score' => 2.0,
				),
			)
		);

		$this->assertSame( 6.0, $results[0]['_score'] );
	}

	public function test_does_not_boost_multi_term_phrase_from_third_title_word() {
		$results = Shift64_Woo_Search_Query::boost_title_start(
			array( 'papier', 'toaletowy' ),
			array(
				array(
					'title'  => 'Pojemnik na papier toaletowy',
					'_score' => 2.0,
				),
			)
		);

		$this->assertSame( 2.0, $results[0]['_score'] );
	}

	public function test_does_not_boost_multi_term_prefix_phrase() {
		$results = Shift64_Woo_Search_Query::boost_title_start(
			array( 'papier', 'toaletowy' ),
			array(
				array(
					'title'  => 'Papierowy toaletowy filtr',
					'_score' => 2.0,
				),
			)
		);

		$this->assertSame( 2.0, $results[0]['_score'] );
	}
}
