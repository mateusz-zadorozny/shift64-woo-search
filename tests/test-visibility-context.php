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
	 * Build a query service without connecting to Redis.
	 *
	 * @return Shift64_Woo_Search_Query
	 */
	private function make_query() {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		return new Shift64_Woo_Search_Query( $redis );
	}

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

	/**
	 * Search builders render one escaped TAG exclusion from the resolved set.
	 */
	public function test_search_builder_excludes_hidden_and_catalog() {
		$query = $this->make_query();

		$this->assertStringContainsString(
			'-@visibility:{hidden|catalog}',
			$query->build_strict_query( array( 'fixture' ), array(), null, 'search' )
		);
	}

	/**
	 * Existing callers retain hidden-only behavior, including missing documents.
	 *
	 * RediSearch negated TAG filters do not require the field to exist, so this
	 * clause excludes a matching hidden value without hiding legacy documents
	 * that have no visibility field.
	 */
	public function test_default_builder_keeps_hidden_only_compatibility_clause() {
		$query = $this->make_query();
		$built = $query->build_strict_query( array( 'fixture' ) );

		$this->assertStringContainsString( '-@visibility:{hidden}', $built );
		$this->assertStringNotContainsString( 'hidden|catalog', $built );
	}

	/**
	 * Explicit exclusions cannot inject RediSearch syntax into the rendered query.
	 */
	public function test_invalid_explicit_set_never_reaches_query_text() {
		$query     = $this->make_query();
		$injection = 'catalog}|@price:[0 999999]';
		$built     = $query->build_strict_query(
			array( 'fixture' ),
			array(),
			null,
			array( 'hidden', $injection )
		);

		$this->assertStringContainsString( '-@visibility:{hidden}', $built );
		$this->assertStringNotContainsString( $injection, $built );
	}
}
