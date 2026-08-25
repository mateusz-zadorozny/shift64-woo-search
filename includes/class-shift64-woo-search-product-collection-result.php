<?php
/**
 * Immutable Redis result envelope for Product Collection renderers.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries request-scoped Product Collection query state.
 */
final class Shift64_Woo_Search_Product_Collection_Result {

	const STATUS_REDIS           = 'redis';
	const STATUS_WC_PASS_THROUGH = 'wc-pass-through';
	const STATUS_NATIVE_FALLBACK = 'native-fallback';
	const STATUS_INVALID_STATE   = 'invalid-state';

	/**
	 * Request key.
	 *
	 * @var string
	 */
	private $request_key;

	/**
	 * Ordered product IDs.
	 *
	 * @var int[]
	 */
	private $product_ids;

	/**
	 * Total matches.
	 *
	 * @var int
	 */
	private $total;

	/**
	 * Current page.
	 *
	 * @var int
	 */
	private $page;

	/**
	 * Products per page.
	 *
	 * @var int
	 */
	private $per_page;

	/**
	 * Effective sort slug.
	 *
	 * @var string
	 */
	private $sort;

	/**
	 * Selected canonical filters.
	 *
	 * @var array
	 */
	private $selected_filters;

	/**
	 * Facet buckets.
	 *
	 * @var array
	 */
	private $facets;

	/**
	 * Execution status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Constructor.
	 *
	 * @param string $request_key Request key.
	 * @param int[]  $product_ids Product IDs.
	 * @param int    $total Total matches.
	 * @param int    $page Current page.
	 * @param int    $per_page Products per page.
	 * @param string $sort Effective sort.
	 * @param array  $selected_filters Selected filters.
	 * @param array  $facets Facet buckets.
	 * @param string $status Execution status.
	 */
	public function __construct( $request_key, array $product_ids, $total, $page, $per_page, $sort, array $selected_filters, array $facets, $status ) {
		$this->request_key      = sanitize_key( $request_key );
		$this->product_ids      = array_values( array_filter( array_map( 'absint', $product_ids ) ) );
		$this->total            = max( 0, (int) $total );
		$this->page             = max( 1, (int) $page );
		$this->per_page         = max( 1, (int) $per_page );
		$this->sort             = sanitize_key( $sort );
		$this->selected_filters = $selected_filters;
		$this->facets           = $facets;
		$this->status           = in_array( $status, array( self::STATUS_REDIS, self::STATUS_WC_PASS_THROUGH, self::STATUS_NATIVE_FALLBACK, self::STATUS_INVALID_STATE ), true )
			? $status
			: self::STATUS_INVALID_STATE;
	}

	/**
	 * Get request key.
	 *
	 * @return string
	 */
	public function get_request_key() {
		return $this->request_key;
	}

	/**
	 * Get ordered product IDs.
	 *
	 * @return int[]
	 */
	public function get_product_ids() {
		return $this->product_ids;
	}

	/**
	 * Get total matches.
	 *
	 * @return int
	 */
	public function get_total() {
		return $this->total;
	}

	/**
	 * Get current page.
	 *
	 * @return int
	 */
	public function get_page() {
		return $this->page;
	}

	/**
	 * Get products per page.
	 *
	 * @return int
	 */
	public function get_per_page() {
		return $this->per_page;
	}

	/**
	 * Get effective sort slug.
	 *
	 * @return string
	 */
	public function get_sort() {
		return $this->sort;
	}

	/**
	 * Get selected canonical filters.
	 *
	 * @return array
	 */
	public function get_selected_filters() {
		return $this->selected_filters;
	}

	/**
	 * Get facet buckets.
	 *
	 * @return array
	 */
	public function get_facets() {
		return $this->facets;
	}

	/**
	 * Get execution status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}
}
