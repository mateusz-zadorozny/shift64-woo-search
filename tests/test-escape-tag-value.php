<?php
/**
 * Tests for Shift64_Woo_Search_Query::escape_tag_value().
 *
 * Regression guard for bug #4: Polish decimal separator (",") in attribute
 * values — e.g. gramatura "26,5" — broke filters because the unescaped comma
 * is a TAG alternation separator in RediSearch query syntax. `{26,5}` was
 * parsed as "26 OR 5", both non-existent values → 0 results → archive
 * intercept bailed → filter bar never rendered.
 *
 * @package Shift64_Woo_Search
 */

/**
 * TAG value escaping tests.
 */
class Escape_Tag_Value_Test extends WP_UnitTestCase {

	/**
	 * Plain ASCII values pass through unchanged.
	 */
	public function test_plain_value_unchanged() {
		$this->assertSame( '265', Shift64_Woo_Search_Query::escape_tag_value( '265' ) );
		$this->assertSame( 'bialy', Shift64_Woo_Search_Query::escape_tag_value( 'bialy' ) );
	}

	/**
	 * Spaces, hyphens, dots — already handled by the pre-bug-#4 implementation.
	 */
	public function test_existing_escapes_preserved() {
		$this->assertSame( 'shift64_woo_search\\ stella', Shift64_Woo_Search_Query::escape_tag_value( 'shift64_woo_search stella' ) );
		$this->assertSame( 'a\\-b', Shift64_Woo_Search_Query::escape_tag_value( 'a-b' ) );
		$this->assertSame( '2\\.5', Shift64_Woo_Search_Query::escape_tag_value( '2.5' ) );
	}

	/**
	 * Comma must be escaped — RediSearch treats `,` inside TAG `{}` as an
	 * alternation separator.
	 */
	public function test_comma_escaped() {
		$this->assertSame( '26\\,5', Shift64_Woo_Search_Query::escape_tag_value( '26,5' ) );
	}

	/**
	 * Combinations — comma AND space (e.g. "26,5 g/m²" style values, without the slash).
	 */
	public function test_comma_with_space_escaped() {
		$this->assertSame( '26\\,5\\ g', Shift64_Woo_Search_Query::escape_tag_value( '26,5 g' ) );
	}

	/**
	 * WordPress stores term names entity-encoded ("Beauty &amp; Care"); the
	 * unescaped `&` and `;` raised SEARCH_SYNTAX and dropped the whole
	 * filtered query to native fallback.
	 */
	public function test_entity_encoded_ampersand_escaped() {
		$this->assertSame(
			'Beauty\\ \\&amp\\;\\ Care',
			Shift64_Woo_Search_Query::escape_tag_value( 'Beauty &amp; Care' )
		);
	}

	/**
	 * Multibyte letters (Polish diacritics) pass through unescaped.
	 */
	public function test_multibyte_letters_untouched() {
		$this->assertSame( 'zólty', Shift64_Woo_Search_Query::escape_tag_value( 'zólty' ) );
		$this->assertSame( 'Bielizna\\ damska', Shift64_Woo_Search_Query::escape_tag_value( 'Bielizna damska' ) );
	}

	/**
	 * Other reserved query punctuation is escaped wholesale.
	 */
	public function test_reserved_punctuation_escaped() {
		$this->assertSame( 'a\\|b', Shift64_Woo_Search_Query::escape_tag_value( 'a|b' ) );
		$this->assertSame( 'a\\{b\\}', Shift64_Woo_Search_Query::escape_tag_value( 'a{b}' ) );
		$this->assertSame( 'a\\(b\\)', Shift64_Woo_Search_Query::escape_tag_value( 'a(b)' ) );
	}

	/**
	 * Invalid UTF-8 (legacy latin1 imports) must never yield null — the /u
	 * regex refuses such subjects, and a null poured into the query string
	 * produced empty TAG clauses and a silent native fallback.
	 */
	public function test_invalid_utf8_falls_back_byte_safe() {
		$latin1 = "caf\xE9 bar";

		$escaped = Shift64_Woo_Search_Query::escape_tag_value( $latin1 );

		$this->assertIsString( $escaped );
		$this->assertSame( "caf\xE9\\ bar", $escaped );
	}
}
