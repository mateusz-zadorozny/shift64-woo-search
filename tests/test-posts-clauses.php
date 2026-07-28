<?php
/**
 * Tests for Shift64_Woo_Search_Taxonomy_Archive::apply_clauses_for_block_query().
 *
 * Issue #17 — Kadence Woo Template Block creates its own WP_Query with
 * is_main_query()=false, bypassing our pre_get_posts interceptor. These
 * tests drive a separate hook (posts_clauses) that catches block / widget
 * queries on taxonomy archive pages and injects the same Redis-filtered
 * post__in set that the main query interceptor already uses.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Block and widget query posts_clauses tests.
 */
class Posts_Clauses_Test extends WP_UnitTestCase {

	/**
	 * Enable product_cat scope so gating code reaches clause injection.
	 *
	 * Explicit reset of the global $wp_query queried object prevents a term
	 * set by an earlier test in the class from leaking into a later one where
	 * we're asserting "no queried term" behavior.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'shift64_woo_search_taxonomy_archive_scopes', array( 'product_cat' ) );

		global $wp_query;
		if ( isset( $wp_query ) ) {
			$wp_query->queried_object    = null;
			$wp_query->queried_object_id = 0;
		}
	}

	/**
	 * Main queries must be skipped — they're already handled by the
	 * pre_get_posts interceptor which sets `post__in` directly. Double
	 * injection would OR two WHERE clauses and show the wrong product set.
	 */
	public function test_noop_when_main_query() {
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$clauses = array( 'where' => ' AND 1=1' );

		$query = $this->getMockBuilder( 'WP_Query' )->disableOriginalConstructor()->getMock();
		$query->method( 'is_main_query' )->willReturn( true );
		$query->method( 'get' )->willReturn( 'product' );

		$result = $archive->apply_clauses_for_block_query( $clauses, $query, array( 10, 20 ) );

		$this->assertSame( ' AND 1=1', $result['where'] );
	}

	/**
	 * Non-product queries (menus, sidebars, widgets) on a category page
	 * must not receive the product post__in filter.
	 */
	public function test_noop_when_post_type_not_product() {
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$clauses = array( 'where' => ' AND 1=1' );

		$query = $this->getMockBuilder( 'WP_Query' )->disableOriginalConstructor()->getMock();
		$query->method( 'is_main_query' )->willReturn( false );
		$query->method( 'get' )->willReturn( 'page' );

		$result = $archive->apply_clauses_for_block_query( $clauses, $query, array( 10, 20 ) );

		$this->assertSame( ' AND 1=1', $result['where'] );
	}

	/**
	 * Outside a taxonomy archive (no queried WP_Term) we must not touch
	 * clauses — homepage product blocks, shop page, etc.
	 */
	public function test_noop_when_no_queried_term() {
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$clauses = array( 'where' => ' AND 1=1' );

		$query = $this->getMockBuilder( 'WP_Query' )->disableOriginalConstructor()->getMock();
		$query->method( 'is_main_query' )->willReturn( false );
		$query->method( 'get' )->willReturn( 'product' );

		// No go_to() / queried object set.
		$result = $archive->apply_clauses_for_block_query( $clauses, $query, array( 10, 20 ) );

		$this->assertSame( ' AND 1=1', $result['where'] );
	}

	/**
	 * Scope disabled in option → no-op, even on a known scope's archive.
	 * Defensive: a scope present in SCOPE_MAP but absent from the option
	 * must not leak Redis filtering.
	 */
	public function test_noop_when_scope_disabled() {
		update_option( 'shift64_woo_search_taxonomy_archive_scopes', array() );

		register_taxonomy( 'product_cat', 'product' );
		$term = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Test Cat',
				'slug'     => 'test-cat',
			)
		);

		global $wp_query;
		$wp_query->queried_object    = $term;
		$wp_query->queried_object_id = $term->term_id;

		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$clauses = array( 'where' => ' AND 1=1' );

		$query = $this->getMockBuilder( 'WP_Query' )->disableOriginalConstructor()->getMock();
		$query->method( 'is_main_query' )->willReturn( false );
		$query->method( 'get' )->willReturn( 'product' );

		$result = $archive->apply_clauses_for_block_query( $clauses, $query, array( 10, 20 ) );

		$this->assertSame( ' AND 1=1', $result['where'] );
	}

	/**
	 * The positive path: Kadence block query on a scoped category archive
	 * gets `WHERE posts.ID IN (ids...)` appended so the grid matches the
	 * Redis-filtered set.
	 */
	public function test_injects_post_in_for_block_query_on_scoped_archive() {
		global $wpdb;

		register_taxonomy( 'product_cat', 'product' );
		$term = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Papiery',
				'slug'     => 'papiery',
			)
		);

		global $wp_query;
		$wp_query->queried_object    = $term;
		$wp_query->queried_object_id = $term->term_id;

		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$clauses = array( 'where' => ' AND 1=1' );

		$query = $this->getMockBuilder( 'WP_Query' )->disableOriginalConstructor()->getMock();
		$query->method( 'is_main_query' )->willReturn( false );
		$query->method( 'get' )->willReturn( 'product' );

		$result = $archive->apply_clauses_for_block_query( $clauses, $query, array( 101, 202, 303 ) );

		$this->assertStringContainsString( 'AND ' . $wpdb->posts . '.ID IN (101,202,303)', $result['where'] );
	}

	/**
	 * Empty Redis result set → inject `ID IN (0)` to force zero matches.
	 * Without the 0 sentinel, an empty IN () is a SQL error.
	 */
	public function test_empty_ids_forces_no_results() {
		global $wpdb;

		register_taxonomy( 'product_cat', 'product' );
		$term = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Papiery',
				'slug'     => 'papiery',
			)
		);

		global $wp_query;
		$wp_query->queried_object    = $term;
		$wp_query->queried_object_id = $term->term_id;

		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$clauses = array( 'where' => '' );

		$query = $this->getMockBuilder( 'WP_Query' )->disableOriginalConstructor()->getMock();
		$query->method( 'is_main_query' )->willReturn( false );
		$query->method( 'get' )->willReturn( 'product' );

		$result = $archive->apply_clauses_for_block_query( $clauses, $query, array() );

		$this->assertStringContainsString( 'AND ' . $wpdb->posts . '.ID IN (0)', $result['where'] );
	}

	/**
	 * Safety: IDs pass through `(int)` cast, then positive-only filter.
	 * SQL-injection strings, non-numeric text, negatives, and zeros are
	 * all dropped — if nothing survives, the IN (0) sentinel takes over.
	 */
	public function test_non_positive_and_non_integer_ids_are_dropped() {
		global $wpdb;

		register_taxonomy( 'product_cat', 'product' );
		$term = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Papiery',
				'slug'     => 'papiery',
			)
		);

		global $wp_query;
		$wp_query->queried_object    = $term;
		$wp_query->queried_object_id = $term->term_id;

		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$clauses = array( 'where' => '' );

		$query = $this->getMockBuilder( 'WP_Query' )->disableOriginalConstructor()->getMock();
		$query->method( 'is_main_query' )->willReturn( false );
		$query->method( 'get' )->willReturn( 'product' );

		// Mix of: injection string, non-numeric, negative, zero, float, valid.
		$result = $archive->apply_clauses_for_block_query(
			$clauses,
			$query,
			array( '42; DROP TABLE', 'abc', -5, 0, 3.9, 99 )
		);

		// Only 42, 3 (from float), and 99 survive. Zero from 'abc' and -5 are dropped.
		$this->assertStringContainsString( 'AND ' . $wpdb->posts . '.ID IN (42,3,99)', $result['where'] );
	}

	/**
	 * All-invalid input collapses to the IN (0) sentinel so downstream
	 * WP_Query returns zero rows without a malformed SQL error.
	 */
	public function test_all_invalid_ids_fall_back_to_zero_sentinel() {
		global $wpdb;

		register_taxonomy( 'product_cat', 'product' );
		$term = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Papiery',
				'slug'     => 'papiery',
			)
		);

		global $wp_query;
		$wp_query->queried_object    = $term;
		$wp_query->queried_object_id = $term->term_id;

		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$clauses = array( 'where' => '' );

		$query = $this->getMockBuilder( 'WP_Query' )->disableOriginalConstructor()->getMock();
		$query->method( 'is_main_query' )->willReturn( false );
		$query->method( 'get' )->willReturn( 'product' );

		$result = $archive->apply_clauses_for_block_query( $clauses, $query, array( 'abc', -5, 0 ) );

		$this->assertStringContainsString( 'AND ' . $wpdb->posts . '.ID IN (0)', $result['where'] );
	}
}
