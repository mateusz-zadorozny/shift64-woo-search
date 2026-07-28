<?php
/**
 * Tests for Shift64_Woo_Search_Taxonomy_Archive interceptor.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Taxonomy archive interception tests.
 */
class Taxonomy_Archive_Test extends WP_UnitTestCase {

	/**
	 * Clear scope toggle before each test.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'shift64_woo_search_taxonomy_archive_scopes', array() );
	}

	/**
	 * SCOPE_MAP must list product_cat with the correct Redis field mapping.
	 *
	 * Per plan §11.1: Redis index stores category NAMES in field `categories`,
	 * not slugs — this must be reflected in the map so the interceptor builds
	 * the right filter.
	 */
	public function test_scope_map_includes_product_cat_with_categories_field() {
		$this->assertTrue( class_exists( 'Shift64_Woo_Search_Taxonomy_Archive' ) );

		$map = Shift64_Woo_Search_Taxonomy_Archive::get_scope_map();

		$this->assertArrayHasKey( 'product_cat', $map );
		$this->assertSame( 'category', $map['product_cat']['filter_key'] );
		$this->assertSame( 'categories', $map['product_cat']['redis_field'] );
	}

	/**
	 * The is_scope_enabled check returns false when the taxonomy is not in the scopes option.
	 */
	public function test_scope_disabled_when_not_in_option() {
		update_option( 'shift64_woo_search_taxonomy_archive_scopes', array() );
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();

		$this->assertFalse( $archive->is_scope_enabled( 'product_cat' ) );
	}

	/**
	 * The is_scope_enabled check returns true when the taxonomy is in the scopes option.
	 */
	public function test_scope_enabled_when_in_option() {
		update_option( 'shift64_woo_search_taxonomy_archive_scopes', array( 'product_cat' ) );
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();

		$this->assertTrue( $archive->is_scope_enabled( 'product_cat' ) );
	}

	/**
	 * Unknown taxonomy is never enabled even if listed in the option.
	 */
	public function test_unknown_taxonomy_is_never_enabled() {
		update_option( 'shift64_woo_search_taxonomy_archive_scopes', array( 'random_tax' ) );
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();

		$this->assertFalse( $archive->is_scope_enabled( 'random_tax' ) );
	}

	/**
	 * Facet_Context interface: get_active_filters() returns parsed URL params.
	 */
	public function test_implements_facet_context_interface() {
		$this->assertTrue( interface_exists( 'Shift64_Woo_Search_Facet_Context' ) );
		$this->assertTrue( class_exists( 'Shift64_Woo_Search_Taxonomy_Archive' ) );

		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$this->assertInstanceOf( 'Shift64_Woo_Search_Facet_Context', $archive );
	}

	/**
	 * The get_active_filters helper parses filter_pa_* query params into the shape
	 * expected by build_filter_parts (keyed by 'attr_pa_*') AND converts
	 * URL slugs to term names — Redis index stores names, not slugs, so
	 * a raw slug passthrough would silently miss all multi-word values
	 * (e.g. slug "3-x-165" vs name "3 x 16,5").
	 */
	public function test_get_active_filters_parses_url_params_and_converts_slugs() {
		register_taxonomy( 'pa_kolor', array() );
		$this->factory->term->create(
			array(
				'taxonomy' => 'pa_kolor',
				'name'     => 'Biały',
				'slug'     => 'bialy',
			)
		);
		$this->factory->term->create(
			array(
				'taxonomy' => 'pa_kolor',
				'name'     => 'Czarny',
				'slug'     => 'czarny',
			)
		);

		$_GET['filter_pa_kolor'] = 'bialy,czarny';

		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$active  = $archive->get_active_filters();

		$this->assertArrayHasKey( 'attr_pa_kolor', $active );
		$this->assertContains( 'Biały', $active['attr_pa_kolor'] );
		$this->assertContains( 'Czarny', $active['attr_pa_kolor'] );

		unset( $_GET['filter_pa_kolor'] );
	}

	/**
	 * Unknown slugs (no matching term) are silently dropped — better than
	 * passing a typo slug through to Redis where it would fail noisily or,
	 * worse, become an alternation separator after escaping.
	 */
	public function test_get_active_filters_drops_unknown_slugs() {
		register_taxonomy( 'pa_kolor', array() );

		$_GET['filter_pa_kolor'] = 'nonexistent-slug';

		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$active  = $archive->get_active_filters();

		$this->assertArrayNotHasKey( 'attr_pa_kolor', $active );

		unset( $_GET['filter_pa_kolor'] );
	}

	/**
	 * The get_active_filters helper returns an empty array when no filter_pa_* params are set.
	 *
	 * Downstream consumers (Facets::compute, search_by_filters) must be
	 * null/empty-safe — this guards the contract that intercept doesn't
	 * throw when $_GET is bare.
	 */
	public function test_get_active_filters_empty_when_no_params() {
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();
		$active  = $archive->get_active_filters();

		$this->assertIsArray( $active );
		$this->assertEmpty( $active );
	}

	/**
	 * Filter dimensions: exact name match is kept, non-listed dims are dropped.
	 *
	 * Bug #2: category archives showed "Kategoria" pastylka, but user is
	 * already navigating inside a category — it's noise. SCOPE_MAP declares
	 * facet_dimensions per scope; filter_dimensions enforces that.
	 */
	public function test_filter_dimensions_exact_match_kept_others_dropped() {
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();

		$input = array(
			'categories'    => array(
				array(
					'value' => 'x',
					'count' => 1,
				),
			),
			'attr_pa_kolor' => array(
				array(
					'value' => 'y',
					'count' => 2,
				),
			),
		);

		$out = $archive->filter_dimensions( $input, array( 'attr_pa_kolor' ) );

		$this->assertArrayNotHasKey( 'categories', $out );
		$this->assertArrayHasKey( 'attr_pa_kolor', $out );
	}

	/**
	 * Filter dimensions: wildcard `attr_pa_*` matches any attr_pa_ prefix,
	 * categories is dropped — this is the product_cat scope's default.
	 */
	public function test_filter_dimensions_wildcard_matches_prefix() {
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();

		$input = array(
			'categories'        => array(
				array(
					'value' => 'x',
					'count' => 1,
				),
			),
			'attr_pa_kolor'     => array(
				array(
					'value' => 'y',
					'count' => 2,
				),
			),
			'attr_pa_gramatura' => array(
				array(
					'value' => '26,5',
					'count' => 3,
				),
			),
		);

		$out = $archive->filter_dimensions( $input, array( 'attr_pa_*' ) );

		$this->assertArrayNotHasKey( 'categories', $out );
		$this->assertArrayHasKey( 'attr_pa_kolor', $out );
		$this->assertArrayHasKey( 'attr_pa_gramatura', $out );
	}

	/**
	 * Filter dimensions: an empty allowed list is a no-op (defensive default).
	 */
	public function test_filter_dimensions_empty_allowed_is_noop() {
		$archive = new Shift64_Woo_Search_Taxonomy_Archive();

		$input = array(
			'categories' => array(
				array(
					'value' => 'x',
					'count' => 1,
				),
			),
		);
		$out   = $archive->filter_dimensions( $input, array() );

		$this->assertSame( $input, $out );
	}
}
