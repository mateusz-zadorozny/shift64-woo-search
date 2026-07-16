<?php
/**
 * Tests for attribute-unit expansion in the indexer.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Unit-expansion helpers in Shift64_Woo_Search_Indexer.
 */
class IndexerUnitExpansionTest extends WP_UnitTestCase {

	public function test_extracts_unit_from_label() {
		$this->assertSame( 'm', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'długość wstęgi [m]' ) );
		$this->assertSame( 'mm', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'szerokość [mm]' ) );
		$this->assertSame( 'ml', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'pojemność [ml]' ) );
		$this->assertSame( '', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'kolor' ) );
		$this->assertSame( '', Shift64_Woo_Search_Indexer::extract_unit_from_attribute_name( 'rozmiar [123]' ) );
	}

	public function test_expands_single_numeric_value() {
		$this->assertSame( array( '30m' ), Shift64_Woo_Search_Indexer::expand_value_with_unit( '30', 'm' ) );
		$this->assertSame( array( '500ml' ), Shift64_Woo_Search_Indexer::expand_value_with_unit( '500', 'ml' ) );
	}

	public function test_rejects_decimals_to_match_query_sanitizer() {
		// sanitize_query strips "." and ",", so indexing "1.5kg" would be unreachable.
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '1.5', 'kg' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '1,5', 'kg' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '1.5 - 2', 'kg' ) );
	}

	public function test_expands_range_with_spaces() {
		$this->assertSame(
			array( '10-12s', '10s', '12s' ),
			Shift64_Woo_Search_Indexer::expand_value_with_unit( '10 - 12', 's' )
		);
	}

	public function test_expands_compact_range() {
		$this->assertSame(
			array( '10-15cm', '10cm', '15cm' ),
			Shift64_Woo_Search_Indexer::expand_value_with_unit( '10-15', 'cm' )
		);
	}

	public function test_returns_empty_for_non_numeric_or_missing_unit() {
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( 'czerwony', 'm' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '30', '' ) );
		$this->assertSame( array(), Shift64_Woo_Search_Indexer::expand_value_with_unit( '', 'm' ) );
	}
}
