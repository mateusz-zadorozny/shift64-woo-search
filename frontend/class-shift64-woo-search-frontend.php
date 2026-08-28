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
	 * Stock dropdown width in pixels — the value the stylesheet ships with.
	 */
	const DROPDOWN_WIDTH_DEFAULT = 645;

	/**
	 * Narrowest configurable dropdown width in pixels.
	 */
	const DROPDOWN_WIDTH_MIN = 320;

	/**
	 * Widest configurable dropdown width in pixels.
	 */
	const DROPDOWN_WIDTH_MAX = 1200;

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
			self::asset_version( 'frontend/css/shift64-woo-search.css' )
		);

		// Only emitted for a custom width. Left unset, the stylesheet's own fallbacks
		// keep the tray matching the search field, which is the default.
		$dropdown_width = self::get_dropdown_width();
		if ( $dropdown_width > 0 ) {
			wp_add_inline_style(
				'shift64-woo-search',
				':root{--s64ws-dropdown-width:' . $dropdown_width . 'px;}'
			);
		}

		wp_enqueue_script(
			'shift64-woo-search',
			SHIFT64_WOO_SEARCH_URL . 'frontend/js/shift64-woo-search.js',
			array(),
			self::asset_version( 'frontend/js/shift64-woo-search.js' ),
			true
		);

		$config = array(
			'endpoint'              => content_url( '/mu-plugins/shift64-woo-search/endpoint.php' ),
			'selectors'             => $this->get_selectors(),
			'minQueryLength'        => (int) get_option( 'shift64_woo_search_min_query', 2 ),
			'debounce'              => (int) get_option( 'shift64_woo_search_debounce', 150 ),
			'limit'                 => (int) get_option( 'shift64_woo_search_autocomplete_limit', 7 ),
			// Bools, as documented — wp_localize_script stringifies them on the wire
			// ('1' / ''), which is why the script reads them through isEnabled() rather
			// than comparing against a literal false.
			'showSku'               => self::display_switch( 'shift64_woo_search_show_sku' ),
			'showImage'             => true,
			'showCategory'          => self::display_switch( 'shift64_woo_search_show_category' ),
			'showBrand'             => self::display_switch( 'shift64_woo_search_show_brand' ),
			// 0 means "match the search field"; the script only corrects for viewport
			// overflow when the tray has a width of its own.
			'dropdownWidth'         => $dropdown_width,
			'noResultsText'         => esc_html__( 'No products found', 'shift64-woo-search' ),
			'seeAllText'            => esc_html__( 'See all results', 'shift64-woo-search' ),
			'suggestionsHeaderText' => esc_html__( 'SEARCH SUGGESTIONS', 'shift64-woo-search' ),
			'categoriesHeaderText'  => esc_html__( 'CATEGORIES', 'shift64-woo-search' ),
			'brandsHeaderText'      => esc_html__( 'BRANDS', 'shift64-woo-search' ),
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
				self::asset_version( 'frontend/js/shift64-woo-search-ajax-pagination.js' ),
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

	/**
	 * Cache-busting version for a bundled asset.
	 *
	 * The plugin version only moves on release, so during development every edit to a
	 * stylesheet or script kept the same `?ver=` and browsers served their cached copy
	 * — the file changes, the URL does not. Fall back to the plugin version when the
	 * file cannot be stat'd, and mirror the admin enqueue, which already does this.
	 *
	 * Public because the block registration claims the shared `shift64-woo-search`
	 * style handle on `init`; whichever call registers a handle first owns its version,
	 * so both call sites have to agree or the enqueue's argument is silently discarded.
	 *
	 * @param string $relative_path Path below the plugin root.
	 * @return string Version string for wp_enqueue_*.
	 */
	public static function asset_version( $relative_path ) {
		$path = SHIFT64_WOO_SEARCH_PATH . $relative_path;

		return file_exists( $path ) ? (string) filemtime( $path ) : SHIFT64_WOO_SEARCH_VERSION;
	}

	/**
	 * Resolve a yes/no display switch into the bool the frontend config declares.
	 *
	 * Anything other than a stored 'yes' reads as off, so a hand-edited or partially
	 * migrated option row cannot leave a switch stuck on. Options that were never saved
	 * default to 'yes', which is the rendering every site had before these settings
	 * existed.
	 *
	 * @param string $option Option name.
	 * @return bool Whether the part is shown.
	 */
	private static function display_switch( $option ) {
		return 'yes' === get_option( $option, 'yes' );
	}

	/**
	 * Resolve the configured dropdown width, clamped to a usable range.
	 *
	 * Returns 0 unless the width mode is explicitly set to 'custom'. Zero means "match
	 * the search field", which is the default and the behaviour the plugin has always
	 * had: the stylesheet leaves the tray anchored to both edges of the field, and no
	 * custom property is emitted at all.
	 *
	 * When a custom width is in force the value is interpolated into a stylesheet, so it
	 * is cast to an integer and bounded here rather than trusted from storage — a
	 * non-numeric option must never reach the CSS. Out-of-range values clamp instead of
	 * falling back to the default, so a merchant who types 5000 gets the widest
	 * supported tray rather than the stock one.
	 *
	 * @return int Width in pixels between 320 and 1200, or 0 to match the search field.
	 */
	public static function get_dropdown_width() {
		if ( 'custom' !== get_option( 'shift64_woo_search_dropdown_width_mode', 'field' ) ) {
			return 0;
		}

		$width = (int) get_option( 'shift64_woo_search_dropdown_width', self::DROPDOWN_WIDTH_DEFAULT );

		if ( $width < self::DROPDOWN_WIDTH_MIN ) {
			return $width < 1 ? self::DROPDOWN_WIDTH_DEFAULT : self::DROPDOWN_WIDTH_MIN;
		}

		return min( $width, self::DROPDOWN_WIDTH_MAX );
	}
}
