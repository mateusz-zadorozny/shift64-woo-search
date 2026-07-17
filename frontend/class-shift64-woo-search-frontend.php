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
	 * Whether the primary search assets were enqueued by this instance.
	 *
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Register frontend hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'shift64_woo_search', array( $this, 'render_search_shortcode' ) );
	}

	/**
	 * Render a product search form compatible with the default autocomplete selector.
	 *
	 * The form remains a regular WooCommerce product search when JavaScript or Redis
	 * is unavailable. The default input class lets the autocomplete script enhance it
	 * without any additional selector configuration.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_search_shortcode( $atts = array() ) {
		static $instance = 0;

		$this->enqueue_assets();

		$atts = shortcode_atts(
			array(
				'placeholder' => __( 'Search products...', 'shift64-woo-search' ),
				'button'      => __( 'Search', 'shift64-woo-search' ),
				'label'       => __( 'Search products', 'shift64-woo-search' ),
			),
			$atts,
			'shift64_woo_search'
		);

		++$instance;
		$input_id = 'shift64-woo-search-input-' . $instance;

		ob_start();
		?>
		<div class="shift64-woo-search-shortcode">
			<form role="search" method="get" class="shift64-woo-search-field" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $atts['label'] ); ?></label>
				<input
					type="search"
					id="<?php echo esc_attr( $input_id ); ?>"
					class="shift64-woo-search-field__input"
					name="s"
					value="<?php echo esc_attr( get_search_query( false ) ); ?>"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
					autocomplete="off"
					enterkeyhint="search"
					aria-autocomplete="list"
					aria-expanded="false"
				>
				<input type="hidden" name="post_type" value="product">
				<button type="submit" class="shift64-woo-search-field__submit"><?php echo esc_html( $atts['button'] ); ?></button>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Enqueue search JS and CSS on the frontend.
	 */
	public function enqueue_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}

		// Only load if Redis is configured.
		$host = get_option( 'shift64_woo_search_redis_host', '' );
		if ( empty( $host ) ) {
			return;
		}

		$this->assets_enqueued = true;

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
