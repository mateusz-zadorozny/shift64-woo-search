<?php
/**
 * Frontend — enqueues JS/CSS and provides config via wp_localize_script.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend asset loader and search config provider.
 */
class Shift64_Woo_Search_Frontend {

	/**
	 * Register frontend hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue search JS and CSS on the frontend.
	 */
	public function enqueue_assets() {
		// Only load if Redis is configured.
		$host = get_option( 'shift64_woo_search_redis_host', '' );
		if ( empty( $host ) ) {
			return;
		}

		wp_enqueue_style(
			'shift64-woo-search',
			SHIFT64_WOO_SEARCH_URL . 'frontend/css/shift64-woo-search.css',
			array(),
			SHIFT64_WOO_SEARCH_VERSION
		);

		wp_enqueue_script(
			'shift64-woo-search',
			SHIFT64_WOO_SEARCH_URL . 'frontend/js/shift64-woo-search.js',
			array(),
			SHIFT64_WOO_SEARCH_VERSION,
			true
		);

		$config = array(
			'endpoint'              => content_url( '/mu-plugins/shift64-woo-search/endpoint.php' ),
			'selectors'             => $this->get_selectors(),
			'minQueryLength'        => (int) get_option( 'shift64_woo_search_min_query', 2 ),
			'debounce'              => (int) get_option( 'shift64_woo_search_debounce', 150 ),
			'limit'                 => (int) get_option( 'shift64_woo_search_autocomplete_limit', 7 ),
			'showSku'               => true,
			'showImage'             => true,
			'showCategory'          => true,
			'noResultsText'         => esc_html__( 'No products found', 'shift64-woo-search' ),
			'seeAllText'            => esc_html__( 'See all results', 'shift64-woo-search' ),
			'suggestionsHeaderText' => esc_html__( 'SEARCH SUGGESTIONS', 'shift64-woo-search' ),
			'categoriesHeaderText'  => esc_html__( 'CATEGORIES', 'shift64-woo-search' ),
			'productsHeaderText'    => esc_html__( 'PRODUCTS', 'shift64-woo-search' ),
			'fallbackUrl'           => home_url( '/?s={query}&post_type=product' ),
			'searchButtonSelector'  => get_option( 'shift64_woo_search_button_selector', '' ),
		);

		wp_localize_script( 'shift64-woo-search', 'shift64_woo_search_config', $config );

		// AJAX pagination + filter dropdown toggle + clear-filters handler.
		// Load on any archive page where the interceptor may register facets:
		// - product search results (is_search + post_type=product), when enabled.
		// - taxonomy archives listed in the scope map, when that scope is enabled.
		$needs_filters_js = false;

		if ( is_search() && 'product' === get_query_var( 'post_type' )
			&& 'yes' === get_option( 'shift64_woo_search_archive_enabled', 'no' )
		) {
			$needs_filters_js = true;
		}

		if ( ! $needs_filters_js && class_exists( 'Shift64_Woo_Search_Taxonomy_Archive' ) ) {
			$scope_map     = Shift64_Woo_Search_Taxonomy_Archive::get_scope_map();
			$scopes_option = (array) get_option( 'shift64_woo_search_taxonomy_archive_scopes', array() );
			foreach ( array_keys( $scope_map ) as $taxonomy ) {
				if ( is_tax( $taxonomy ) && in_array( $taxonomy, $scopes_option, true ) ) {
					$needs_filters_js = true;
					break;
				}
			}
		}

		if ( $needs_filters_js ) {
			wp_enqueue_script(
				'shift64-woo-search-ajax-pagination',
				SHIFT64_WOO_SEARCH_URL . 'frontend/js/shift64-woo-search-ajax-pagination.js',
				array(),
				SHIFT64_WOO_SEARCH_VERSION,
				true
			);
		}
	}

	/**
	 * Build the combined CSS selector string for search inputs.
	 *
	 * @return string
	 */
	private function get_selectors() {
		$primary    = get_option( 'shift64_woo_search_input_selector', '.shift64-woo-search-field__input' );
		$additional = get_option( 'shift64_woo_search_additional_selectors', '' );

		$selectors = $primary;
		if ( ! empty( $additional ) ) {
			$selectors .= ', ' . $additional;
		}

		return $selectors;
	}
}
