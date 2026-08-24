<?php
/**
 * Request-scoped Product Collection result registry.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores immutable result envelopes for dynamic sibling block renderers.
 */
final class Shift64_Woo_Search_Product_Collection_Results {

	/**
	 * Request-local results.
	 *
	 * @var array<string,Shift64_Woo_Search_Product_Collection_Result>
	 */
	private static $results = array();

	/**
	 * Store a result envelope.
	 *
	 * @param Shift64_Woo_Search_Product_Collection_Result $result Result.
	 */
	public static function set( Shift64_Woo_Search_Product_Collection_Result $result ) {
		self::$results[ $result->get_request_key() ] = $result;
	}

	/**
	 * The first stored envelope that came from a successful Redis query.
	 *
	 * Sibling controls (Filter Pills) read facet buckets from here when the
	 * Product Collection has already executed this request.
	 *
	 * @return Shift64_Woo_Search_Product_Collection_Result|null
	 */
	public static function first_redis() {
		foreach ( self::$results as $result ) {
			if ( Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS === $result->get_status() ) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * Get a stored result by its request key.
	 *
	 * @param string $request_key Request key.
	 * @return Shift64_Woo_Search_Product_Collection_Result|null
	 */
	public static function get( $request_key ) {
		$key = sanitize_key( $request_key );
		return self::$results[ $key ] ?? null;
	}

	/**
	 * Clear request state. Public for deterministic tests.
	 */
	public static function reset() {
		self::$results = array();
	}
}
