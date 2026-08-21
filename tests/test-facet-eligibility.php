<?php
/**
 * Tests for Shift64_Woo_Search_Facet_Eligibility and the schema field helper.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Facet eligibility service tests.
 *
 * Contract under test: eligibility combines only the Facets settings, the
 * live index schema, and taxonomy existence — never an incoming `filter_*`
 * parameter — into the closed status set
 * ready|disabled|rebuild-required|taxonomy-missing.
 */
class Facet_Eligibility_Test extends WP_UnitTestCase {

	/**
	 * Set up: deterministic settings and taxonomies for each test.
	 */
	public function set_up() {
		parent::set_up();
		Shift64_Woo_Search_Facet_Eligibility::reset();
		update_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_brands_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array() );
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'product' );
		}
	}

	/**
	 * Tear down: unregister taxonomies individual tests registered.
	 */
	public function tear_down() {
		foreach ( array( 'product_brand', 'pa_material' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		Shift64_Woo_Search_Facet_Eligibility::reset();
		parent::tear_down();
	}

	/**
	 * Build a Redis mock advertising a live index containing the given fields.
	 *
	 * Field existence is answered the way the schema service asks for it: a
	 * GROUPBY probe on a known field returns an (empty) aggregation; an
	 * unknown field errors (false).
	 *
	 * @param array $fields    Field names in the live index.
	 * @param bool  $available Whether the connection reports available.
	 * @return Shift64_Woo_Search_Redis
	 */
	private function mock_redis( array $fields, $available = true ) {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'is_available' )->willReturn( $available );
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturnCallback(
			function ( ...$args ) use ( $fields ) {
				if ( 'FT.INFO' === ( $args[0] ?? '' ) ) {
					return array( 'index_name', 'shift64_woo_search_product_idx' );
				}
				if ( 'FT.AGGREGATE' === ( $args[0] ?? '' ) ) {
					$field = ltrim( (string) ( $args[5] ?? '' ), '@' );
					return in_array( $field, $fields, true ) ? array( 0 ) : false;
				}
				return false;
			}
		);

		return $redis;
	}

	/**
	 * A Redis mock whose FT.INFO fails (missing index / connection error).
	 *
	 * @param bool $available Whether the connection reports available.
	 * @return Shift64_Woo_Search_Redis
	 */
	private function mock_redis_without_index( $available = true ) {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'is_available' )->willReturn( $available );
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturn( false );
		return $redis;
	}

	/**
	 * Category is ready by default: enabled setting, existing taxonomy, live index.
	 */
	public function test_category_ready_by_default() {
		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'product_cat', $this->mock_redis( array( 'categories' ) ) );

		$this->assertNotNull( $entry );
		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_READY, $entry['status'] );
		$this->assertSame( 'category', $entry['type'] );
		$this->assertSame( 'categories', $entry['redis_field'] );
		$this->assertSame( array( 'or' ), $entry['operators'] );
	}

	/**
	 * Disabling the category setting turns the facet off regardless of the index.
	 */
	public function test_category_disabled_by_setting() {
		update_option( 'shift64_woo_search_filter_categories_enabled', 'no' );

		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'product_cat', $this->mock_redis( array( 'categories' ) ) );

		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_DISABLED, $entry['status'] );
	}

	/**
	 * Brand is disabled by default (opt-in setting).
	 */
	public function test_brand_disabled_by_default() {
		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'product_brand', $this->mock_redis( array( 'brands' ) ) );

		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_DISABLED, $entry['status'] );
		$this->assertSame( 'brand', $entry['type'] );
	}

	/**
	 * Enabled brand without the product_brand taxonomy reports taxonomy-missing.
	 */
	public function test_brand_taxonomy_missing() {
		update_option( 'shift64_woo_search_filter_brands_enabled', 'yes' );

		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'product_brand', $this->mock_redis( array( 'brands' ) ) );

		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_TAXONOMY_MISSING, $entry['status'] );
	}

	/**
	 * Enabled brand with a registered taxonomy and live index is ready.
	 */
	public function test_brand_ready_when_enabled_and_registered() {
		update_option( 'shift64_woo_search_filter_brands_enabled', 'yes' );
		register_taxonomy( 'product_brand', 'product' );

		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'product_brand', $this->mock_redis( array( 'brands' ) ) );

		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_READY, $entry['status'] );
	}

	/**
	 * A selected attribute whose TAG field exists in the live index is ready.
	 */
	public function test_attribute_ready_when_field_in_live_schema() {
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_material' ) );
		register_taxonomy( 'pa_material', 'product' );

		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'pa_material', $this->mock_redis( array( 'categories', 'attr_pa_material' ) ) );

		$this->assertNotNull( $entry );
		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_READY, $entry['status'] );
		$this->assertSame( 'attribute', $entry['type'] );
		$this->assertSame( 'attr_pa_material', $entry['redis_field'] );
		$this->assertSame( array( 'or', 'and' ), $entry['operators'] );
	}

	/**
	 * A selected attribute missing from the live index needs a rebuild: the
	 * setting alone never makes a facet ready.
	 */
	public function test_attribute_rebuild_required_when_field_not_indexed() {
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_material' ) );
		register_taxonomy( 'pa_material', 'product' );

		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'pa_material', $this->mock_redis( array( 'categories' ) ) );

		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_REBUILD_REQUIRED, $entry['status'] );
	}

	/**
	 * A selected attribute whose taxonomy no longer exists reports taxonomy-missing.
	 */
	public function test_attribute_taxonomy_missing() {
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_material' ) );

		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'pa_material', $this->mock_redis( array( 'attr_pa_material' ) ) );

		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_TAXONOMY_MISSING, $entry['status'] );
	}

	/**
	 * An index that cannot be described makes enabled facets rebuild-required,
	 * never ready — stale or unknown schemas are not exposed as usable.
	 */
	public function test_missing_index_reports_rebuild_required() {
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_material' ) );
		register_taxonomy( 'pa_material', 'product' );

		$entries = Shift64_Woo_Search_Facet_Eligibility::get_entries( $this->mock_redis_without_index() );

		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_REBUILD_REQUIRED, $entries['product_cat']['status'] );
		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_REBUILD_REQUIRED, $entries['pa_material']['status'] );
		// Disabled wins over rebuild-required: the merchant must enable first.
		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_DISABLED, $entries['product_brand']['status'] );
	}

	/**
	 * An unavailable Redis connection behaves like a missing index.
	 */
	public function test_unavailable_redis_reports_rebuild_required() {
		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( 'product_cat', $this->mock_redis_without_index( false ) );

		$this->assertSame( Shift64_Woo_Search_Facet_Eligibility::STATUS_REBUILD_REQUIRED, $entry['status'] );
	}

	/**
	 * The ready subset and the closed key set: get_ready() returns only ready
	 * entries, and get_entry() outside the closed set returns null — an
	 * arriving filter_* parameter can never mint one.
	 */
	public function test_ready_subset_and_closed_key_set() {
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_material' ) );
		register_taxonomy( 'pa_material', 'product' );
		$redis = $this->mock_redis( array( 'categories', 'brands', 'attr_pa_material' ) );

		$ready = Shift64_Woo_Search_Facet_Eligibility::get_ready( $redis );

		$this->assertSame( array( 'product_cat', 'pa_material' ), array_keys( $ready ) );
		$this->assertNull( Shift64_Woo_Search_Facet_Eligibility::get_entry( 'pa_smuggled', $redis ) );
	}

	/**
	 * The schema field probe distinguishes known fields from unknown ones.
	 */
	public function test_schema_field_probe_distinguishes_fields() {
		$redis = $this->mock_redis( array( 'attr_pa_kolor' ) );

		$this->assertTrue( Shift64_Woo_Search_Schema::index_field_exists( $redis, 'attr_pa_kolor' ) );
		$this->assertFalse( Shift64_Woo_Search_Schema::index_field_exists( $redis, 'attr_pa_missing' ) );
	}

	/**
	 * A missing index means no field exists.
	 */
	public function test_schema_field_probe_false_without_index() {
		$this->assertFalse( Shift64_Woo_Search_Schema::index_field_exists( $this->mock_redis_without_index(), 'attr_pa_kolor' ) );
	}
}
