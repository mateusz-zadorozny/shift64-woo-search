<?php
/**
 * Facet context registry.
 *
 * Singleton registry that holds the current-request facet context. Interceptor
 * classes register themselves here once they decide to handle the query; the
 * shared filter renderer reads from here instead of knowing which specific
 * interceptor is active.
 *
 * ## Lifecycle note
 *
 * The `$current` state is intended to be per-request. Under php-fpm the static
 * is reset on every request automatically. Under long-running runtimes
 * (RoadRunner, Swoole, Amphp, ReactPHP) state carries between requests —
 * consumers in those environments must call `clear()` at the start of each
 * request cycle (typically on `init` or equivalent bootstrap hook).
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static registry for the active facet context.
 */
class Shift64_Woo_Search_Facet_Registry {

	/**
	 * Current request's facet context, or null if none is active.
	 *
	 * @var Shift64_Woo_Search_Facet_Context|null
	 */
	private static $current = null;

	/**
	 * Register the current context. Called by interceptors once their gate passes.
	 *
	 * @param Shift64_Woo_Search_Facet_Context $context Active context provider.
	 */
	public static function set_current( Shift64_Woo_Search_Facet_Context $context ) {
		self::$current = $context;
	}

	/**
	 * Get the active context, if any. Renderer uses this to decide whether to output.
	 *
	 * @return Shift64_Woo_Search_Facet_Context|null
	 */
	public static function get_current() {
		return self::$current;
	}

	/**
	 * Clear the current context. Useful for tests and isolation between requests.
	 */
	public static function clear() {
		self::$current = null;
	}
}
