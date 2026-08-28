<?php
/**
 * Frontend assets for the childless-parent block fallback.
 *
 * This class used to be the plugin's classic frontend: it hooked
 * `wp_enqueue_scripts` and shipped the autocomplete stylesheet and script to
 * every page of the storefront, bound to whatever CSS selectors an
 * administrator had configured for their theme. The block-theme-only cleanup
 * removed that surface. What is left is the asset pair the childless
 * `shift64-woo-search/search` and `/modal-search` fallback still needs, enqueued
 * only when that fallback renders.
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
	 * Enqueue the fallback autocomplete assets.
	 *
	 * Nothing hooks `wp_enqueue_scripts` any more. The shared stylesheet reaches
	 * a page through block metadata when a Shift64 block renders, and this
	 * method is called only by the childless-parent block fallback, which is the
	 * one surface still driven by the pre-block autocomplete script. A page with
	 * no Shift64 block therefore ships none of these assets.
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

		wp_enqueue_script(
			'shift64-woo-search',
			SHIFT64_WOO_SEARCH_URL . 'frontend/js/shift64-woo-search.js',
			array(),
			self::asset_version( 'frontend/js/shift64-woo-search.js' ),
			true
		);

		$config = array(
			'endpoint'              => content_url( '/mu-plugins/shift64-woo-search/endpoint.php' ),
			// The fallback renders its own field, so the script binds to that
			// class alone. Enhancing arbitrary theme fields by configured
			// selector was a classic-theme surface and is gone.
			'selectors'             => '.shift64-woo-search-field__input',
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
			'noResultsText'         => esc_html__( 'No products found', 'shift64-woo-search' ),
			'seeAllText'            => esc_html__( 'See all results', 'shift64-woo-search' ),
			'suggestionsHeaderText' => esc_html__( 'SEARCH SUGGESTIONS', 'shift64-woo-search' ),
			'categoriesHeaderText'  => esc_html__( 'CATEGORIES', 'shift64-woo-search' ),
			'brandsHeaderText'      => esc_html__( 'BRANDS', 'shift64-woo-search' ),
			'productsHeaderText'    => esc_html__( 'PRODUCTS', 'shift64-woo-search' ),
			'fallbackUrl'           => home_url( '/?s={query}&post_type=product' ),
		);

		wp_localize_script( 'shift64-woo-search', 'shift64_woo_search_config', $config );
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
}
