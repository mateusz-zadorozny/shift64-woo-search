<?php
/**
 * Tests for Shift64_Woo_Search_Query::detect_sku_concatenation().
 *
 * @package Shift64_Woo_Search
 */

/**
 * Pure-function tests for the SKU concatenation detector used by the
 * fuzzy SKU matching feature ("djm 201" → "djm201").
 *
 * The detect_sku_concatenation method is static and has no Redis dependency,
 * so we call it directly without instantiating Shift64_Woo_Search_Query.
 */
class Detect_Sku_Concatenation_Test extends WP_UnitTestCase {

	/**
	 * Happy path: 3 letters + 3 digits.
	 */
	public function test_detects_typical_sku_pattern() {
		$this->assertSame( 'djm201', Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'djm', '201' ) ) );
	}

	/**
	 * Boundary: minimum (2 letters + 1 digit).
	 */
	public function test_detects_minimum_lengths() {
		$this->assertSame( 'ab1', Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'ab', '1' ) ) );
	}

	/**
	 * Boundary: maximum (4 letters + 4 digits).
	 */
	public function test_detects_maximum_lengths() {
		$this->assertSame( 'abcd1234', Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'abcd', '1234' ) ) );
	}

	/**
	 * Mixed case letters are accepted (caller is responsible for casing).
	 */
	public function test_preserves_case_of_input() {
		$this->assertSame( 'DJM201', Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'DJM', '201' ) ) );
	}

	/**
	 * Zero-prefixed digits are accepted by the regex — document this explicitly.
	 */
	public function test_accepts_zero_prefixed_digits() {
		$this->assertSame( 'abc001', Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'abc', '001' ) ) );
	}

	/**
	 * Letters too short (1 char) — should not match.
	 */
	public function test_rejects_single_letter_prefix() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'a', '1' ) ) );
	}

	/**
	 * Letters too long (5 chars) — should not match.
	 */
	public function test_rejects_long_letter_prefix() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'abcde', '1' ) ) );
	}

	/**
	 * Digits too long (5 chars) — should not match.
	 */
	public function test_rejects_long_digit_suffix() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'abc', '12345' ) ) );
	}

	/**
	 * Reversed order (digits, then letters) — not the documented use case.
	 */
	public function test_rejects_reversed_order() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( '201', 'djm' ) ) );
	}

	/**
	 * Letters mixed with digits in a single term — should not match.
	 */
	public function test_rejects_alphanumeric_terms() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'djm1', '201' ) ) );
	}

	/**
	 * More than 2 terms — pattern requires exactly 2.
	 */
	public function test_rejects_three_terms() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'djm', '201', 'extra' ) ) );
	}

	/**
	 * Single term — pattern requires exactly 2.
	 */
	public function test_rejects_single_term() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'djm201' ) ) );
	}

	/**
	 * Empty array — pattern requires exactly 2.
	 */
	public function test_rejects_empty_terms() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array() ) );
	}

	/**
	 * Non-ASCII letters (Polish diacritics) — regex is ASCII-only.
	 */
	public function test_rejects_non_ascii_letters() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'żół', '201' ) ) );
	}

	/**
	 * Empty string as letter term — regex requires {2,4} chars, not zero.
	 */
	public function test_rejects_empty_letter_term() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( '', '201' ) ) );
	}

	/**
	 * Empty string as digit term — regex requires {1,4} chars, not zero.
	 */
	public function test_rejects_empty_digit_term() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'abc', '' ) ) );
	}

	/**
	 * Internal whitespace in a term — anchored regex rejects it.
	 */
	public function test_rejects_internal_whitespace() {
		$this->assertFalse( Shift64_Woo_Search_Query::detect_sku_concatenation( array( 'a b', '201' ) ) );
	}
}
