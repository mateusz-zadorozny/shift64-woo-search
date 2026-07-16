<?php
/**
 * Facet context contract.
 *
 * Implemented by archive interceptors (search archive, taxonomy archive) to
 * expose the data needed by the shared filter renderer. Keeps the renderer
 * decoupled from which specific interceptor is active.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider of facet data and active filters for the current archive request.
 */
interface Shift64_Woo_Search_Facet_Context {

	/**
	 * Active user-selected filters parsed from URL params.
	 *
	 * @return array Keyed by filter key (e.g. 'category', 'attr_pa_kolor'), value is list of strings.
	 */
	public function get_active_filters();

	/**
	 * Facet counts per dimension, ready for renderer consumption.
	 *
	 * @return array Keyed by field name (e.g. 'categories', 'attr_pa_kolor'), value is list of ['value' => string, 'count' => int].
	 */
	public function get_facet_data();
}
