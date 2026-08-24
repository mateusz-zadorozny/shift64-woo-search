<?php
/**
 * Tests for the editor facets REST route.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Editor facets REST route tests.
 *
 * Contract under test: the route is capability-gated (Shift64 settings or
 * Site Editor template editing), returns only the fixed configuration
 * fields, and leaks nothing to unauthorized requests.
 */
class Editor_Facets_Rest_Test extends WP_UnitTestCase {

	const ROUTE = '/shift64-woo-search/v1/editor/facets';

	/**
	 * Set up: deterministic facet settings.
	 */
	public function set_up() {
		parent::set_up();
		Shift64_Woo_Search_Facet_Eligibility::reset();
		update_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_brands_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_material' ) );
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'product' );
		}
	}

	/**
	 * Tear down: drop per-test taxonomies and the schema memo.
	 */
	public function tear_down() {
		if ( taxonomy_exists( 'pa_material' ) ) {
			unregister_taxonomy( 'pa_material' );
		}
		Shift64_Woo_Search_Facet_Eligibility::reset();
		parent::tear_down();
	}

	/**
	 * Dispatch a GET against the route through the REST server.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch() {
		return rest_do_request( new WP_REST_Request( 'GET', self::ROUTE ) );
	}

	/**
	 * Anonymous requests are rejected and receive no facet data.
	 */
	public function test_anonymous_request_is_rejected_without_leakage() {
		wp_set_current_user( 0 );

		$response = $this->dispatch();
		$data     = $response->get_data();

		$this->assertSame( 401, $response->get_status() );
		$this->assertArrayNotHasKey( 'facets', (array) $data );
		$this->assertArrayNotHasKey( 'settingsUrl', (array) $data );
	}

	/**
	 * A logged-in user without either capability is rejected with 403.
	 */
	public function test_subscriber_is_rejected_without_leakage() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->dispatch();
		$data     = $response->get_data();

		$this->assertSame( 403, $response->get_status() );
		$this->assertArrayNotHasKey( 'facets', (array) $data );
	}

	/**
	 * An administrator (edit_theme_options + manage_options) can read the payload.
	 */
	public function test_administrator_receives_fixed_shape_payload() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		register_taxonomy( 'pa_material', 'product' );

		$response = $this->dispatch();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data['facets'] );
		$this->assertIsString( $data['settingsUrl'] );
		$this->assertStringContainsString( 'page=shift64-woo-search', $data['settingsUrl'] );
		$this->assertStringContainsString( 'section=facets', $data['settingsUrl'] );
		$this->assertIsBool( $data['rebuildRequired'] );

		$statuses = array( 'ready', 'disabled', 'rebuild-required', 'taxonomy-missing' );
		$keys     = array();
		foreach ( $data['facets'] as $facet ) {
			// Only the fixed fields — internals like redis_field never leave PHP.
			$this->assertSame( array( 'key', 'taxonomy', 'type', 'label', 'operators', 'status' ), array_keys( $facet ) );
			$this->assertContains( $facet['status'], $statuses );
			$this->assertContains( $facet['type'], array( 'category', 'brand', 'attribute' ) );
			$keys[] = $facet['key'];
		}

		$this->assertContains( 'product_cat', $keys );
		$this->assertContains( 'product_brand', $keys );
		$this->assertContains( 'pa_material', $keys );
	}

	/**
	 * A user whose only relevant capability is manage_woocommerce is allowed.
	 */
	public function test_manage_woocommerce_capability_is_sufficient() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		$response = $this->dispatch();

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * No index in the test environment: every enabled facet reports
	 * rebuild-required and the aggregate flag is true — settings alone never
	 * produce a ready facet.
	 */
	public function test_rebuild_required_reflects_missing_index() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		register_taxonomy( 'pa_material', 'product' );

		$data = $this->dispatch()->get_data();

		$by_key = array();
		foreach ( $data['facets'] as $facet ) {
			$by_key[ $facet['key'] ] = $facet;
		}

		$this->assertTrue( $data['rebuildRequired'] );
		$this->assertSame( 'rebuild-required', $by_key['product_cat']['status'] );
		$this->assertSame( 'rebuild-required', $by_key['pa_material']['status'] );
		$this->assertSame( 'disabled', $by_key['product_brand']['status'] );
	}
}
