<?php
/**
 * Regression tests for ASCII-variant expansion in the FT query builders.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Query builders must NOT OR a diacritics-stripped ASCII variant into the query.
 *
 * `(mydło*|mydlo*)` looks like a harmless superset of `mydło*`, but an
 * explicit UNION node collapses RediSearch TFIDF scoring (IDF is computed
 * differently than for a lone prefix): measured on live data, max score fell
 * from 2.21 to 0.57 and the ranking inverted — soap dispensers outranked
 * soaps for "mydło", after which the 0.5 score threshold wiped all but one
 * autocomplete result. ASCII input still matches diacritic content through
 * the indexed *_ascii fields; the fuzzy pass covers the rest.
 */
class Query_Builder_Diacritics_Test extends WP_UnitTestCase {

	/**
	 * Helper: real Shift64_Woo_Search_Query with a mocked Redis connector.
	 *
	 * @param array $config   Config overrides.
	 * @param array $synonyms Synonym groups to serve from the mocked Redis.
	 * @return Shift64_Woo_Search_Query
	 */
	private function make_query( $config = array(), $synonyms = array() ) {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();

		$redis->method( 'get_prefix' )->willReturn( 'shift64_woo_search' );
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturn(
			empty( $synonyms ) ? false : wp_json_encode( $synonyms )
		);

		return new Shift64_Woo_Search_Query( $redis, $config );
	}

	/**
	 * Query punctuation must preserve the token boundaries used by RediSearch.
	 */
	public function test_sanitize_query_replaces_token_separators_with_spaces() {
		$query = $this->make_query();

		$this->assertSame( 'athena t shirt green', $query->sanitize_query( 'Athena T-Shirt Green' ) );
		$this->assertSame( 'demo 640001 xs', $query->sanitize_query( 'DEMO-640001-XS' ) );
		$this->assertSame( 'red blue classic', $query->sanitize_query( 'Red,Blue/Classic' ) );
		$this->assertSame( 'demo_640001_xs', $query->sanitize_query( 'DEMO_640001_XS' ) );
	}

	/**
	 * Strict pass: a diacritic term stays a lone prefix — no ASCII union.
	 */
	public function test_strict_query_emits_bare_prefix_for_diacritic_term() {
		$query = $this->make_query();

		$ft = $query->build_strict_query( array( 'mydło' ) );

		$this->assertStringContainsString( 'mydło*', $ft );
		$this->assertStringNotContainsString( 'mydlo*', $ft, 'ASCII variant must not be OR-ed in — the union collapses TFIDF scoring.' );
		$this->assertStringNotContainsString( 'mydło*|', $ft, 'Diacritic term must not open a union.' );
	}

	/**
	 * Strict pass: plain ASCII input keeps its lone prefix.
	 */
	public function test_strict_query_ascii_term_unchanged() {
		$query = $this->make_query();

		$ft = $query->build_strict_query( array( 'mydlo' ) );

		$this->assertStringContainsString( 'mydlo*', $ft );
		$this->assertStringNotContainsString( '|', explode( ' -@', $ft )[0], 'ASCII term must stay a lone prefix.' );
	}

	/**
	 * Strict AND pass: none of the terms grow variant unions.
	 */
	public function test_strict_query_multi_term_and_logic_has_no_variant_unions() {
		$query = $this->make_query( array( 'logic' => 'AND' ) );

		$ft = $query->build_strict_query( array( 'mydło', 'w', 'płynie' ) );

		$this->assertStringContainsString( 'mydło*', $ft );
		$this->assertStringContainsString( 'płynie*', $ft );
		$this->assertStringNotContainsString( 'mydlo*', $ft );
		$this->assertStringNotContainsString( 'plynie*', $ft );
	}

	/**
	 * Fuzzy pass: a diacritic term stays a lone fuzzy token.
	 */
	public function test_fuzzy_query_emits_bare_fuzzy_for_diacritic_term() {
		$query = $this->make_query();

		$ft = $query->build_fuzzy_query( array( 'mydło' ) );

		$this->assertStringContainsString( '%mydło%', $ft );
		$this->assertStringNotContainsString( '%mydlo%', $ft );
	}

	/**
	 * Facet query mirrors the strict pass — no ASCII union.
	 */
	public function test_facet_query_emits_bare_prefix_for_diacritic_term() {
		$query = $this->make_query();

		$ft = $query->build_facet_query( array( 'mydło' ), array(), 'category' );

		$this->assertStringContainsString( 'mydło*', $ft );
		$this->assertStringNotContainsString( 'mydlo*', $ft );
	}

	/**
	 * Legacy mixed builder: single term, no variant union.
	 */
	public function test_legacy_mixed_query_emits_single_term_without_variant_union() {
		$query = $this->make_query();

		$ft = $query->build_ft_query( 'mydło' );

		$this->assertStringContainsString( 'mydło', $ft );
		$this->assertStringNotContainsString( 'mydlo', $ft );
	}

	/**
	 * Synonym groups still expand into a union of the group members (tracked
	 * separately in issue #36) — but no ASCII variants sneak into that union,
	 * and duplicate members are deduped case-insensitively.
	 */
	public function test_synonym_group_union_has_no_ascii_variants_and_is_deduped() {
		$query = $this->make_query(
			array(),
			array( array( 'ręcznik', 'Ręcznik', 'papier' ) )
		);

		$ft = $query->build_strict_query( array( 'ręcznik' ) );

		$this->assertStringContainsString( '(ręcznik*|papier*)', $ft );
		$this->assertStringNotContainsString( 'recznik*', $ft, 'No ASCII variant inside the synonym union.' );
	}

	/**
	 * One-way synonym with a MULTI-WORD source and target (issue #36).
	 *
	 * "kosz nożny => kosz pedałowy": the source phrase is REPLACED by the target,
	 * which is rendered as a grouped AND-prefix clause. The source is not searched
	 * (one-way `=>` = replace, Solr/ES semantics). Previously the multi-word target
	 * was silently dropped, so nothing surfaced.
	 */
	public function test_strict_query_expands_multiword_synonym_target() {
		$query = $this->make_query(
			array(),
			array(
				array(
					'from' => array( 'kosz nożny' ),
					'to'   => array( 'kosz pedałowy' ),
				),
			)
		);

		$ft = $query->build_strict_query( array( 'kosz', 'nożny' ) );

		$this->assertStringContainsString( '(kosz* pedałowy*)', $ft, 'Multi-word target must expand to a grouped AND-prefix clause.' );
		$this->assertStringNotContainsString( 'nożny*', $ft, 'One-way source must be replaced, not searched.' );
	}

	/**
	 * Fuzzy pass mirrors the strict pass for multi-word synonym targets.
	 */
	public function test_fuzzy_query_expands_multiword_synonym_target() {
		$query = $this->make_query(
			array(),
			array(
				array(
					'from' => array( 'kosz nożny' ),
					'to'   => array( 'kosz pedałowy' ),
				),
			)
		);

		$ft = $query->build_fuzzy_query( array( 'kosz', 'nożny' ) );

		$this->assertStringContainsString( '(%kosz% %pedałowy%)', $ft, 'Multi-word target must expand to a grouped AND-fuzzy clause.' );
		$this->assertStringNotContainsString( '%nożny%', $ft, 'One-way source must be replaced, not searched.' );
	}

	/**
	 * One-way single-word synonym REPLACES the source (issue: "wózki" showed
	 * buckets/worki, never carts). "wózki => wózek" must search wózek*, NOT wózki*
	 * — searching the source token dragged in a broad shared category and, via the
	 * "(wózki*|wózek*)" union, collapsed TFIDF so noise outranked the real carts.
	 */
	public function test_strict_query_oneway_synonym_replaces_source_term() {
		$query = $this->make_query(
			array(),
			array(
				array(
					'from' => array( 'wózki' ),
					'to'   => array( 'wózek' ),
				),
			)
		);

		$ft = $query->build_strict_query( array( 'wózki' ) );

		$this->assertStringContainsString( 'wózek*', $ft, 'Target term must be searched.' );
		$this->assertStringNotContainsString( 'wózki*', $ft, 'Source term must be replaced, not OR-ed in.' );
	}

	/**
	 * One-way source phrase containing a sub-2-char weak token ("z").
	 *
	 * get_search_terms() drops single-letter tokens, so the query "kosz z pedałem"
	 * tokenizes to ['kosz','pedałem']. The synonym key must be normalized through
	 * the SAME pipeline (→ "kosz pedałem"), otherwise the literal key
	 * "kosz z pedałem" never matches and the phrase silently fails to expand —
	 * exactly the "kosz z pedałem returns noise" report.
	 */
	public function test_strict_query_expands_source_with_short_weak_token() {
		$query = $this->make_query(
			array(),
			array(
				array(
					'from' => array( 'kosz z pedałem' ),
					'to'   => array( 'kosz pedałowy' ),
				),
			)
		);

		// Query tokens as get_search_terms() would produce them — "z" already dropped.
		$ft = $query->build_strict_query( array( 'kosz', 'pedałem' ) );

		$this->assertStringContainsString( '(kosz* pedałowy*)', $ft, 'Source phrase with a single-letter weak token must still expand.' );
		$this->assertStringNotContainsString( 'pedałem*', $ft, 'Source must be replaced by the target, not searched literally.' );
	}

	/**
	 * A group mixing single- and multi-word members renders each in its own form:
	 * single word stays a lone prefix, multi-word becomes a grouped clause.
	 */
	public function test_strict_query_mixed_single_and_multiword_synonym_members() {
		$query = $this->make_query(
			array(),
			array( array( 'suszarka', 'suszenie rąk' ) )
		);

		$ft = $query->build_strict_query( array( 'suszarka' ) );

		$this->assertStringContainsString( 'suszarka*', $ft );
		$this->assertStringContainsString( '(suszenie* rąk*)', $ft );
	}
}
