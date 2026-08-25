<?php
/**
 * Context-agnostic sorting resolution and configuration service.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves catalog orderby parameters to Redis or WooCommerce sort modes.
 */
class Shift64_Woo_Search_Sort {

	/**
	 * Relevance sorting mode using the PHP re-ranking pipeline.
	 *
	 * @var string
	 */
	const MODE_RELEVANCE = 'relevance';

	/**
	 * Single-field numeric sorting in Redis via FT.SEARCH SORTBY.
	 *
	 * @var string
	 */
	const MODE_REDIS = 'redis';

	/**
	 * Composite sorting in Redis via FT.AGGREGATE SORTBY.
	 *
	 * @var string
	 */
	const MODE_REDIS_COMPOSITE = 'redis_composite';

	/**
	 * Candidate-set pass-through mode evaluated by WooCommerce/MySQL.
	 *
	 * @var string
	 */
	const MODE_WC = 'wc';

	/**
	 * Default maximum candidate IDs allowed for WooCommerce pass-through sorts.
	 *
	 * @var int
	 */
	const DEFAULT_CANDIDATE_LIMIT = 10000;

	/**
	 * Canonical WooCommerce sort keys recognized by the plugin.
	 *
	 * @var string[]
	 */
	const CANONICAL_KEYS = array(
		'menu_order',
		'popularity',
		'rating',
		'date',
		'price',
		'price-desc',
		'relevance',
	);

	/**
	 * Resolve an orderby parameter to its execution mode and sort definition.
	 *
	 * @param string    $orderby      Orderby slug (e.g. 'price', 'popularity', 'menu_order').
	 * @param bool|null $date_indexed Whether the `date` index field is populated; null checks option.
	 * @return array{mode:string,sort_by:string|null,sort_fields:array<string,string>|null}
	 */
	public static function resolve_mode( $orderby, $date_indexed = null ) {
		$orderby = sanitize_key( $orderby );

		// B2B Price mode: logged-in users sort by live DB prices when configured.
		if ( in_array( $orderby, array( 'price', 'price-desc' ), true ) ) {
			$price_mode = get_option( 'shift64_woo_search_price_sort_mode', 'redis' );
			if ( 'db' === $price_mode && is_user_logged_in() ) {
				return array(
					'mode'        => self::MODE_WC,
					'sort_by'     => null,
					'sort_fields' => null,
				);
			}
		}

		switch ( $orderby ) {
			case 'relevance':
				return array(
					'mode'        => self::MODE_RELEVANCE,
					'sort_by'     => null,
					'sort_fields' => null,
				);

			case 'price':
				return array(
					'mode'        => self::MODE_REDIS,
					'sort_by'     => 'price ASC',
					'sort_fields' => null,
				);

			case 'price-desc':
				return array(
					'mode'        => self::MODE_REDIS,
					'sort_by'     => 'price DESC',
					'sort_fields' => null,
				);

			case 'popularity':
				return array(
					'mode'        => self::MODE_REDIS,
					'sort_by'     => 'total_sales DESC',
					'sort_fields' => null,
				);

			case 'rating':
				return array(
					'mode'        => self::MODE_REDIS,
					'sort_by'     => 'average_rating DESC',
					'sort_fields' => null,
				);

			case 'date':
				$is_indexed = ( null !== $date_indexed ) ? (bool) $date_indexed : self::is_date_indexed();
				if ( $is_indexed ) {
					return array(
						'mode'        => self::MODE_REDIS,
						'sort_by'     => 'date DESC',
						'sort_fields' => null,
					);
				}
				// Prior to backfill completion, date uses candidate pass-through to prevent silent misordering.
				return array(
					'mode'        => self::MODE_WC,
					'sort_by'     => null,
					'sort_fields' => null,
				);

			case 'menu_order':
				return array(
					'mode'        => self::MODE_REDIS_COMPOSITE,
					'sort_by'     => null,
					'sort_fields' => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				);

			default:
				return array(
					'mode'        => self::MODE_WC,
					'sort_by'     => null,
					'sort_fields' => null,
				);
		}
	}

	/**
	 * Resolve the store's default catalog sort mode.
	 *
	 * Core WooCommerce removes 'Default sorting' on product searches and maps
	 * a default of `menu_order` to `relevance`.
	 *
	 * @param bool $is_search Whether the current query is a product search.
	 * @return string Resolved orderby slug.
	 */
	public static function resolve_default_sort( $is_search = false ) {
		$default = get_option( 'woocommerce_default_catalog_orderby', 'menu_order' );
		if ( ! is_string( $default ) || '' === $default ) {
			$default = 'menu_order';
		}

		if ( $is_search && 'menu_order' === $default ) {
			return 'relevance';
		}

		return $default;
	}

	/**
	 * Determine the effective sort slug for a request.
	 *
	 * @param string|null $requested_sort Explicit orderby parameter from request.
	 * @param bool        $is_search      Whether the current query is a product search.
	 * @return string Effective sort slug.
	 */
	public static function get_effective_sort( $requested_sort, $is_search = false ) {
		if ( is_string( $requested_sort ) && '' !== trim( $requested_sort ) ) {
			$sanitized = sanitize_key( $requested_sort );
			if ( self::is_valid_sort( $sanitized ) ) {
				return $sanitized;
			}
		}

		return self::resolve_default_sort( $is_search );
	}

	/**
	 * Check whether a sort key is recognized by WooCommerce or registered extensions.
	 *
	 * @param string $sort Sort key.
	 * @return bool
	 */
	public static function is_valid_sort( $sort ) {
		if ( in_array( $sort, self::CANONICAL_KEYS, true ) || in_array( $sort, array( 'rand', 'id', 'title', 'modified' ), true ) ) {
			return true;
		}

		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Read core WooCommerce filter.
			$options = apply_filters( 'woocommerce_catalog_orderby', array() );
			if ( is_array( $options ) && isset( $options[ $sort ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the maximum number of candidates allowed for WooCommerce pass-through sorts.
	 *
	 * @return int Maximum candidate IDs.
	 */
	public static function get_candidate_limit() {
		$limit = (int) apply_filters( 'shift64_woo_search_wc_sort_candidate_limit', self::DEFAULT_CANDIDATE_LIMIT );
		return max( 1, $limit );
	}

	/**
	 * Check whether the RediSearch schema has the date field indexed.
	 *
	 * @return bool True if date field is indexed and ready for SORTBY.
	 */
	public static function is_date_indexed() {
		return 'yes' === get_option( 'shift64_woo_search_date_indexed', 'no' );
	}
}
