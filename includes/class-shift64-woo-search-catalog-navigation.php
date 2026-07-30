<?php
/**
 * Shared Interactivity API navigation module registration.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes the URL-driven navigation contract for block-native controls.
 */
final class Shift64_Woo_Search_Catalog_Navigation {

	const MODULE_ID = 'shift64-woo-search/catalog-navigation';

	/**
	 * Register the script module on init.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register a dynamically router-aware module without enqueueing it.
	 *
	 * Downstream Filter, Sort, and Search controls enqueue this shared module.
	 * WordPress 6.0–6.4 keep their progressive links/forms and simply skip
	 * module registration because script modules do not exist there.
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_script_module' ) ) {
			return;
		}

		wp_register_script_module(
			self::MODULE_ID,
			SHIFT64_WOO_SEARCH_URL . 'frontend/js/shift64-woo-search-catalog-navigation.js',
			array(
				array(
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				),
			),
			SHIFT64_WOO_SEARCH_VERSION
		);

		if ( function_exists( 'wp_interactivity' ) ) {
			$interactivity = wp_interactivity();
			if ( is_object( $interactivity ) && method_exists( $interactivity, 'add_client_navigation_support_to_script_module' ) ) {
				$interactivity->add_client_navigation_support_to_script_module( self::MODULE_ID );
			}
		}
	}
}
