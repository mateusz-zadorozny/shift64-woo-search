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
		add_shortcode( 'shift64_woo_search_modal', array( $this, 'render_search_modal_shortcode' ) );
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

		$html = (string) ob_get_clean();
		$html = preg_replace( '/>\s+</', '><', $html );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Render a compact search trigger that opens the product search in a modal.
	 *
	 * This variant is intended for headers and other narrow layouts. The form uses
	 * the same input selector and native WooCommerce fallback as the regular search
	 * shortcode, so the existing autocomplete script enhances it automatically.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_search_modal_shortcode( $atts = array() ) {
		static $instance = 0;

		$this->enqueue_assets();

		$atts = shortcode_atts(
			array(
				'placeholder'   => __( 'Search products...', 'shift64-woo-search' ),
				'button'        => __( 'Search', 'shift64-woo-search' ),
				'label'         => __( 'Search products', 'shift64-woo-search' ),
				'trigger_label' => __( 'Open product search', 'shift64-woo-search' ),
				'close_label'   => __( 'Close search', 'shift64-woo-search' ),
				'clear_label'   => __( 'Clear search', 'shift64-woo-search' ),
				'icon'          => 'default',
			),
			$atts,
			'shift64_woo_search_modal'
		);

		++$instance;
		$modal_id = 'shift64-woo-search-modal-' . $instance;
		$input_id = 'shift64-woo-search-modal-input-' . $instance;
		$title_id = 'shift64-woo-search-modal-title-' . $instance;

		$icon_variant     = in_array( $atts['icon'], array( 'default', 'alternative' ), true ) ? $atts['icon'] : 'default';
		$search_icon_path = 'alternative' === $icon_variant
			? 'M544 513L397.2 364.2C417.2 336.3 429.1 302 429.1 265C429.1 171.9 354.4 96.1 262.6 96.1C170.7 96 96 171.8 96 264.9C96 358 170.7 433.8 262.5 433.8C302.3 433.8 338.8 419.6 367.5 395.9L513.5 544L544 513zM262.5 394.8C191.9 394.8 134.4 336.5 134.4 264.9C134.4 193.3 191.9 135 262.5 135C333.1 135 390.6 193.3 390.6 264.9C390.6 336.5 333.2 394.8 262.5 394.8z'
			: 'M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM272 416C351.5 416 416 351.5 416 272C416 192.5 351.5 128 272 128C192.5 128 128 192.5 128 272C128 351.5 192.5 416 272 416z';

		ob_start();
		?>
		<div class="shift64-woo-search-modal-shortcode">
			<button
				type="button"
				class="shift64-woo-search-modal__trigger"
				aria-label="<?php echo esc_attr( $atts['trigger_label'] ); ?>"
				aria-controls="<?php echo esc_attr( $modal_id ); ?>"
				aria-expanded="false"
				aria-haspopup="dialog"
				data-shift64-woo-search-modal-trigger
			>
				<svg class="shift64-woo-search-icon shift64-woo-search-icon--search" aria-hidden="true" focusable="false" viewBox="0 0 640 640" width="24" height="24" fill="currentColor">
					<path d="<?php echo esc_attr( $search_icon_path ); ?>"></path>
				</svg>
			</button>

			<div id="<?php echo esc_attr( $modal_id ); ?>" class="shift64-woo-search-modal" data-shift64-woo-search-modal hidden>
				<div class="shift64-woo-search-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
					<h2 id="<?php echo esc_attr( $title_id ); ?>" class="screen-reader-text"><?php echo esc_html( $atts['label'] ); ?></h2>
					<button type="button" class="shift64-woo-search-modal__close" aria-label="<?php echo esc_attr( $atts['close_label'] ); ?>" data-shift64-woo-search-modal-close>
						<svg class="shift64-woo-search-icon shift64-woo-search-icon--close" aria-hidden="true" focusable="false" viewBox="0 0 640 640" width="24" height="24" fill="currentColor">
							<path d="M183.1 137.4C170.6 124.9 150.3 124.9 137.8 137.4C125.3 149.9 125.3 170.2 137.8 182.7L275.2 320L137.9 457.4C125.4 469.9 125.4 490.2 137.9 502.7C150.4 515.2 170.7 515.2 183.2 502.7L320.5 365.3L457.9 502.6C470.4 515.1 490.7 515.1 503.2 502.6C515.7 490.1 515.7 469.8 503.2 457.3L365.8 320L503.1 182.6C515.6 170.1 515.6 149.8 503.1 137.3C490.6 124.8 470.3 124.8 457.8 137.3L320.5 274.7L183.1 137.4z"></path>
						</svg>
					</button>

					<div class="shift64-woo-search-shortcode shift64-woo-search-modal__search">
						<form role="search" method="get" class="shift64-woo-search-field" action="<?php echo esc_url( home_url( '/' ) ); ?>">
							<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $atts['label'] ); ?></label>
							<div class="shift64-woo-search-field__controls">
								<div class="shift64-woo-search-field__input-wrap">
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
									<button type="button" class="shift64-woo-search-field__clear" aria-label="<?php echo esc_attr( $atts['clear_label'] ); ?>" data-shift64-woo-search-clear hidden>
										<svg class="shift64-woo-search-icon shift64-woo-search-icon--clear" aria-hidden="true" focusable="false" viewBox="0 0 640 640" width="18" height="18" fill="currentColor">
											<path d="M183.1 137.4C170.6 124.9 150.3 124.9 137.8 137.4C125.3 149.9 125.3 170.2 137.8 182.7L275.2 320L137.9 457.4C125.4 469.9 125.4 490.2 137.9 502.7C150.4 515.2 170.7 515.2 183.2 502.7L320.5 365.3L457.9 502.6C470.4 515.1 490.7 515.1 503.2 502.6C515.7 490.1 515.7 469.8 503.2 457.3L365.8 320L503.1 182.6C515.6 170.1 515.6 149.8 503.1 137.3C490.6 124.8 470.3 124.8 457.8 137.3L320.5 274.7L183.1 137.4z"></path>
										</svg>
									</button>
								</div>
								<input type="hidden" name="post_type" value="product">
								<button type="submit" class="shift64-woo-search-field__submit" aria-label="<?php echo esc_attr( $atts['button'] ); ?>">
									<svg class="shift64-woo-search-icon shift64-woo-search-icon--search" aria-hidden="true" focusable="false" viewBox="0 0 640 640" width="22" height="22" fill="currentColor">
										<path d="<?php echo esc_attr( $search_icon_path ); ?>"></path>
									</svg>
									<span class="screen-reader-text"><?php echo esc_html( $atts['button'] ); ?></span>
								</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php

		$html = (string) ob_get_clean();
		$html = preg_replace( '/>\s+</', '><', $html );

		return is_string( $html ) ? $html : '';
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
