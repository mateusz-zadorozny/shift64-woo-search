<?php
/**
 * Tests for context-aware WooCommerce catalog visibility exclusions.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Visibility context resolver tests.
 */
class Visibility_Context_Test extends WP_UnitTestCase {

	/**
	 * Search excludes products hidden from search by either WooCommerce value.
	 */
	public function test_search_context_excludes_hidden_and_catalog() {
		$this->assertSame(
			array( 'hidden', 'catalog' ),
			Shift64_Woo_Search_Query::resolve_visibility_exclusions( 'search' )
		);
	}

	/**
	 * Missing policy preserves the historical hidden-only behavior.
	 */
	public function test_missing_policy_uses_compatibility_fallback() {
		$this->assertSame(
			array( 'hidden' ),
			Shift64_Woo_Search_Query::resolve_visibility_exclusions()
		);
		$this->assertSame(
			array( 'hidden' ),
			Shift64_Woo_Search_Query::resolve_visibility_exclusions( '' )
		);
	}

	/**
	 * Explicit exclusions are accepted only from the closed WooCommerce set.
	 */
	public function test_explicit_exclusions_are_validated_and_deduplicated() {
		$this->assertSame(
			array( 'catalog', 'hidden' ),
			Shift64_Woo_Search_Query::resolve_visibility_exclusions(
				array( 'catalog', 'hidden', 'catalog' )
			)
		);
	}

	/**
	 * Unknown contexts and malformed explicit sets fail closed to compatibility.
	 */
	public function test_invalid_policy_uses_compatibility_fallback() {
		$this->assertSame(
			array( 'hidden' ),
			Shift64_Woo_Search_Query::resolve_visibility_exclusions( 'catalog-archive' )
		);
		$this->assertSame(
			array( 'hidden' ),
			Shift64_Woo_Search_Query::resolve_visibility_exclusions(
				array( 'hidden', 'catalog}|@price:[0 999999]' )
			)
		);
		$this->assertSame(
			array( 'hidden' ),
			Shift64_Woo_Search_Query::resolve_visibility_exclusions( array() )
		);
	}
}
