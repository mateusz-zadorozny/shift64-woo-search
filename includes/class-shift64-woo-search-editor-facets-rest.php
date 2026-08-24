<?php
/**
 * Editor-only facets REST route.
 *
 * Exposes facet eligibility to the block editor inspector
 * (spec: .ai/specs/2026-07-30-product-filter-pill-blocks.md). The response
 * carries only fixed configuration fields — facet keys, labels, readiness,
 * and an admin settings URL. No index content, product data, or customer
 * data is ever returned. Storefront rendering never calls this route; it
 * reads the PHP eligibility service directly.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves GET /shift64-woo-search/v1/editor/facets.
 */
class Shift64_Woo_Search_Editor_Facets_Rest {

	const ROUTE_NAMESPACE = 'shift64-woo-search/v1';

	/**
	 * Hook route registration.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the editor facets route.
	 */
	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/editor/facets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_facets' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Editing Shift64 search settings or Site Editor templates grants access.
	 *
	 * `manage_woocommerce` is the capability every Shift64 settings screen and
	 * AJAX handler already requires; `edit_theme_options` is what placing
	 * blocks in Site Editor templates requires. Either context legitimately
	 * needs the eligibility list, and the response holds no sensitive data.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_theme_options' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_woocommerce is a WooCommerce capability.
			return true;
		}
		return new WP_Error(
			'shift64_woo_search_rest_forbidden',
			__( 'Sorry, you are not allowed to read facet configuration.', 'shift64-woo-search' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Serve the fixed-shape eligibility payload.
	 *
	 * @return WP_REST_Response
	 */
	public function get_facets() {
		$entries          = Shift64_Woo_Search_Facet_Eligibility::get_entries();
		$facets           = array();
		$rebuild_required = false;

		foreach ( $entries as $entry ) {
			if ( Shift64_Woo_Search_Facet_Eligibility::STATUS_REBUILD_REQUIRED === $entry['status'] ) {
				$rebuild_required = true;
			}
			// Only fixed fields — the internal redis_field never leaves PHP.
			$facets[] = array(
				'key'       => $entry['key'],
				'taxonomy'  => $entry['taxonomy'],
				'type'      => $entry['type'],
				'label'     => $entry['label'],
				'operators' => array_values( $entry['operators'] ),
				'status'    => $entry['status'],
			);
		}

		return rest_ensure_response(
			array(
				'facets'          => $facets,
				'settingsUrl'     => admin_url( 'admin.php?page=shift64-woo-search&tab=results&section=facets' ),
				'rebuildRequired' => $rebuild_required,
			)
		);
	}
}
