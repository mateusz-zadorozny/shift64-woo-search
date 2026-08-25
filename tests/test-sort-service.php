<?php
/**
 * Tests for the Shift64_Woo_Search_Sort service.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Sort service test cases.
 */
class Shift64_Woo_Search_Sort_Test extends WP_UnitTestCase {

	/**
	 * Reset options before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'woocommerce_default_catalog_orderby' );
		delete_option( 'shift64_woo_search_price_sort_mode' );
		delete_option( 'shift64_woo_search_date_indexed' );
		wp_set_current_user( 0 );
	}

	/**
	 * Canonical sort keys resolve to their respective Redis sort modes and fields.
	 */
	public function test_resolve_mode_canonical_keys() {
		$relevance = Shift64_Woo_Search_Sort::resolve_mode( 'relevance' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_RELEVANCE, $relevance['mode'] );
		$this->assertNull( $relevance['sort_by'] );

		$price_asc = Shift64_Woo_Search_Sort::resolve_mode( 'price' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_REDIS, $price_asc['mode'] );
		$this->assertSame( 'price ASC', $price_asc['sort_by'] );

		$price_desc = Shift64_Woo_Search_Sort::resolve_mode( 'price-desc' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_REDIS, $price_desc['mode'] );
		$this->assertSame( 'price DESC', $price_desc['sort_by'] );

		$popularity = Shift64_Woo_Search_Sort::resolve_mode( 'popularity' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_REDIS, $popularity['mode'] );
		$this->assertSame( 'total_sales DESC', $popularity['sort_by'] );

		$rating = Shift64_Woo_Search_Sort::resolve_mode( 'rating' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_REDIS, $rating['mode'] );
		$this->assertSame( 'average_rating DESC', $rating['sort_by'] );
	}

	/**
	 * Menu order resolves to composite sort mode with menu_order ASC and title ASC.
	 */
	public function test_resolve_mode_menu_order() {
		$menu_order = Shift64_Woo_Search_Sort::resolve_mode( 'menu_order' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_REDIS_COMPOSITE, $menu_order['mode'] );
		$this->assertSame(
			array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			$menu_order['sort_fields']
		);
	}

	/**
	 * Date sort resolves to Redis SORTBY when date is indexed, and WC pass-through when unindexed.
	 */
	public function test_resolve_mode_date_indexed_flag() {
		// When date is indexed.
		$date_indexed = Shift64_Woo_Search_Sort::resolve_mode( 'date', true );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_REDIS, $date_indexed['mode'] );
		$this->assertSame( 'date DESC', $date_indexed['sort_by'] );

		// When date is unindexed.
		$date_unindexed = Shift64_Woo_Search_Sort::resolve_mode( 'date', false );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_WC, $date_unindexed['mode'] );
		$this->assertNull( $date_unindexed['sort_by'] );

		// Option fallback.
		update_option( 'shift64_woo_search_date_indexed', 'yes' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_REDIS, Shift64_Woo_Search_Sort::resolve_mode( 'date' )['mode'] );

		update_option( 'shift64_woo_search_date_indexed', 'no' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_WC, Shift64_Woo_Search_Sort::resolve_mode( 'date' )['mode'] );
	}

	/**
	 * Unknown third-party orderby parameters route to WC pass-through mode.
	 */
	public function test_resolve_mode_unknown_third_party() {
		$custom = Shift64_Woo_Search_Sort::resolve_mode( 'custom_vendor_score' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_WC, $custom['mode'] );
		$this->assertNull( $custom['sort_by'] );
	}

	/**
	 * B2B DB price mode routes logged-in price requests to WC mode.
	 */
	public function test_resolve_mode_b2b_price_mode() {
		update_option( 'shift64_woo_search_price_sort_mode', 'db' );

		// Guest user: still uses Redis.
		wp_set_current_user( 0 );
		$guest = Shift64_Woo_Search_Sort::resolve_mode( 'price' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_REDIS, $guest['mode'] );

		// Logged in user: routes to WC mode.
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		$logged_in = Shift64_Woo_Search_Sort::resolve_mode( 'price' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_WC, $logged_in['mode'] );

		$logged_in_desc = Shift64_Woo_Search_Sort::resolve_mode( 'price-desc' );
		$this->assertSame( Shift64_Woo_Search_Sort::MODE_WC, $logged_in_desc['mode'] );
	}

	/**
	 * Default catalog sort resolution honors store settings and search remap.
	 */
	public function test_resolve_default_sort() {
		update_option( 'woocommerce_default_catalog_orderby', 'popularity' );
		$this->assertSame( 'popularity', Shift64_Woo_Search_Sort::resolve_default_sort( false ) );
		$this->assertSame( 'popularity', Shift64_Woo_Search_Sort::resolve_default_sort( true ) );

		update_option( 'woocommerce_default_catalog_orderby', 'menu_order' );
		$this->assertSame( 'menu_order', Shift64_Woo_Search_Sort::resolve_default_sort( false ) );
		// menu_order on search remaps to relevance.
		$this->assertSame( 'relevance', Shift64_Woo_Search_Sort::resolve_default_sort( true ) );
	}

	/**
	 * Effective sort resolution falls back to default sort when omitted.
	 */
	public function test_get_effective_sort() {
		update_option( 'woocommerce_default_catalog_orderby', 'price' );

		// Explicit sort requested.
		$this->assertSame( 'rating', Shift64_Woo_Search_Sort::get_effective_sort( 'rating', false ) );

		// Omitted / empty sort requested.
		$this->assertSame( 'price', Shift64_Woo_Search_Sort::get_effective_sort( '', false ) );
		$this->assertSame( 'price', Shift64_Woo_Search_Sort::get_effective_sort( null, false ) );
	}

	/**
	 * Candidate ceiling is filterable and enforces a minimum of 1.
	 */
	public function test_get_candidate_limit() {
		$this->assertSame( 10000, Shift64_Woo_Search_Sort::get_candidate_limit() );

		add_filter(
			'shift64_woo_search_wc_sort_candidate_limit',
			function () {
				return 500;
			}
		);
		$this->assertSame( 500, Shift64_Woo_Search_Sort::get_candidate_limit() );
	}
}
