<?php
/**
 * Tests for the category suggestion-exclusion core extracted from the AJAX
 * handlers (Shift64_Woo_Search_Admin::resolve_category_term_id / add_category_exclusion
 * / remove_category_exclusion).
 *
 * These cover the meaningful logic — token resolution by ID/slug/name, dedup,
 * and per-term removal — in the normal test suite. The AJAX handlers themselves
 * are a thin nonce/capability/JSON shell (identical to the existing, untested
 * boost handlers) and WP tags AJAX tests `@group ajax`, which this project's CI
 * (`vendor/bin/phpunit`, no `--group ajax`) does not run.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Suggestion-exclusion core tests.
 */
class CategoryExcludeTest extends WP_UnitTestCase {

	/**
	 * The option under test.
	 */
	const OPTION = 'shift64_woo_search_category_suggest_exclude';

	/**
	 * Register product_cat fresh per test so factory terms attach to it.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );
		}
		delete_option( self::OPTION );
	}

	/**
	 * Create a product_cat term with an explicit name, slug and optional parent.
	 *
	 * @param string $name   Display name.
	 * @param string $slug   Slug.
	 * @param int    $parent_id Parent term ID (0 for a root category).
	 * @return int Term ID.
	 */
	private function make_category( $name, $slug, $parent_id = 0 ) {
		return self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => $name,
				'slug'     => $slug,
				'parent'   => $parent_id,
			)
		);
	}

	/**
	 * A numeric token resolves straight to that term ID.
	 */
	public function test_resolve_by_id() {
		$id = $this->make_category( 'Meble', 'meble' );
		$this->assertSame( $id, Shift64_Woo_Search_Admin::resolve_category_term_id( (string) $id ) );
	}

	/**
	 * A slug token resolves to the matching term.
	 */
	public function test_resolve_by_slug() {
		$id = $this->make_category( 'Meble', 'meble' );
		$this->assertSame( $id, Shift64_Woo_Search_Admin::resolve_category_term_id( 'meble' ) );
	}

	/**
	 * A display-name token resolves to the matching term.
	 */
	public function test_resolve_by_name() {
		$id = $this->make_category( 'Meble', 'meble' );
		$this->assertSame( $id, Shift64_Woo_Search_Admin::resolve_category_term_id( 'Meble' ) );
	}

	/**
	 * Empty and unknown tokens resolve to 0 rather than a stray term.
	 */
	public function test_resolve_empty_or_unknown_returns_zero() {
		$this->make_category( 'Meble', 'meble' );
		$this->assertSame( 0, Shift64_Woo_Search_Admin::resolve_category_term_id( '' ) );
		$this->assertSame( 0, Shift64_Woo_Search_Admin::resolve_category_term_id( '   ' ) );
		$this->assertSame( 0, Shift64_Woo_Search_Admin::resolve_category_term_id( 'nieistnieje' ) );
	}

	/**
	 * The core value proposition: two categories that share a display name but
	 * live in different tree branches (distinct slugs) resolve to distinct IDs,
	 * so an admin can hide exactly one of them.
	 */
	public function test_resolve_distinguishes_same_named_categories_by_slug() {
		// Two real branches: meble/krzesła and biuro/meble/krzesła.
		$furniture    = $this->make_category( 'Meble', 'meble' );
		$office       = $this->make_category( 'Biuro', 'biuro' );
		$office_meble = $this->make_category( 'Meble', 'meble-biuro', $office );

		$furniture_chairs = $this->make_category( 'Krzesła', 'krzesla', $furniture );
		$office_chairs    = $this->make_category( 'Krzesła', 'krzesla-2', $office_meble );

		// Same display name, different branches → distinct IDs resolvable by slug.
		$this->assertSame( $furniture_chairs, Shift64_Woo_Search_Admin::resolve_category_term_id( 'krzesla' ) );
		$this->assertSame( $office_chairs, Shift64_Woo_Search_Admin::resolve_category_term_id( 'krzesla-2' ) );
		$this->assertNotSame( $furniture_chairs, $office_chairs );
	}

	/**
	 * Adding persists the term ID to the option.
	 */
	public function test_add_persists_to_option() {
		$updated = Shift64_Woo_Search_Admin::add_category_exclusion( 42 );
		$this->assertSame( array( 42 ), $updated );
		$this->assertSame( array( 42 ), get_option( self::OPTION ) );
	}

	/**
	 * Adding the same term ID twice keeps a single, deduped entry.
	 */
	public function test_add_is_deduped_and_idempotent() {
		Shift64_Woo_Search_Admin::add_category_exclusion( 42 );
		$updated = Shift64_Woo_Search_Admin::add_category_exclusion( 42 );
		$this->assertSame( array( 42 ), $updated );
	}

	/**
	 * Removing drops only the target ID and leaves the rest intact.
	 */
	public function test_remove_drops_only_target() {
		update_option( self::OPTION, array( 10, 20, 30 ) );
		$updated = Shift64_Woo_Search_Admin::remove_category_exclusion( 20 );
		$this->assertSame( array( 10, 30 ), $updated );
		$this->assertSame( array( 10, 30 ), get_option( self::OPTION ) );
	}

	/**
	 * Removing an ID that isn't excluded is a harmless no-op.
	 */
	public function test_remove_absent_is_noop() {
		update_option( self::OPTION, array( 10, 20 ) );
		$updated = Shift64_Woo_Search_Admin::remove_category_exclusion( 999 );
		$this->assertSame( array( 10, 20 ), $updated );
	}

	/**
	 * A corrupt (non-array) option value is tolerated as an empty list.
	 */
	public function test_corrupt_option_treated_as_empty() {
		update_option( self::OPTION, 'not-an-array' );
		$updated = Shift64_Woo_Search_Admin::add_category_exclusion( 7 );
		$this->assertSame( array( 7 ), $updated );
	}
}
