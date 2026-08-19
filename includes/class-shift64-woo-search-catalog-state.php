<?php
/**
 * Canonical URL-backed state for product archive controls.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses, validates, and rebuilds Product Collection request state.
 */
final class Shift64_Woo_Search_Catalog_State {

	/**
	 * Allowed sort slugs until the dedicated sorting spec expands the engine.
	 *
	 * @var string[]
	 */
	private const SORT_SLUGS = array( 'relevance', 'menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc' );

	/**
	 * Query parameters that must not be copied into storefront navigation.
	 *
	 * @var string[]
	 */
	private const PRIVATE_PARAMS = array( '_wpnonce', '_wp_http_referer', 'preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid' );

	/**
	 * Current page.
	 *
	 * @var int
	 */
	private $page;

	/**
	 * Effective sort slug.
	 *
	 * @var string
	 */
	private $sort;

	/**
	 * Search term.
	 *
	 * @var string
	 */
	private $search;

	/**
	 * Canonical selected filter slugs, keyed by URL parameter.
	 *
	 * @var array
	 */
	private $selected_filters;

	/**
	 * Redis filter values, keyed by query-builder field.
	 *
	 * @var array
	 */
	private $redis_filters;

	/**
	 * Canonical query operators.
	 *
	 * @var array
	 */
	private $operators;

	/**
	 * Constructor.
	 *
	 * @param int    $page             Current page.
	 * @param string $sort             Sort slug.
	 * @param string $search           Search term.
	 * @param array  $selected_filters Selected term slugs.
	 * @param array  $redis_filters    Redis term names.
	 * @param array  $operators        Query operators.
	 */
	public function __construct( $page, $sort, $search, array $selected_filters, array $redis_filters, array $operators ) {
		$this->page             = max( 1, (int) $page );
		$this->sort             = sanitize_key( $sort );
		$this->search           = sanitize_text_field( $search );
		$this->selected_filters = $selected_filters;
		$this->redis_filters    = $redis_filters;
		$this->operators        = $operators;
	}

	/**
	 * Parse canonical state from a request.
	 *
	 * @param Shift64_Woo_Search_Product_Collection_Context $context Product Collection context.
	 * @param array|null                                    $request Query parameters; defaults to $_GET.
	 * @param string|null                                   $request_uri Request URI; defaults to server value.
	 * @return self
	 */
	public static function from_request( Shift64_Woo_Search_Product_Collection_Context $context, $request = null, $request_uri = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public storefront state.
		$request = is_array( $request ) ? $request : wp_unslash( $_GET );
		if ( null === $request_uri ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed only for an integer /page/N/ segment.
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		}

		$page = self::requested_page( $context->get_page(), $request, $request_uri, $context->get_query_id() );

		$is_search      = ( 'search' === $context->get_visibility_policy() || '' !== $context->get_search_term() || ( 'product' === ( $request['post_type'] ?? '' ) && isset( $request['s'] ) ) );
		$requested_sort = isset( $request['orderby'] ) ? sanitize_key( $request['orderby'] ) : null;
		$sort           = Shift64_Woo_Search_Sort::get_effective_sort( $requested_sort, $is_search );

		$search = '';
		if ( 'product' === ( $request['post_type'] ?? '' ) && isset( $request['s'] ) ) {
			$search = sanitize_text_field( $request['s'] );
		} elseif ( '' !== $context->get_search_term() ) {
			$search = $context->get_search_term();
		}

		$selected  = array();
		$redis     = array();
		$operators = array();
		foreach ( self::filter_taxonomies() as $taxonomy => $filter_key ) {
			$param = 'filter_' . $taxonomy;
			if ( empty( $request[ $param ] ) || ! is_scalar( $request[ $param ] ) ) {
				continue;
			}

			$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $request[ $param ] ) ) );
			$slugs = array_values( array_unique( $slugs ) );
			sort( $slugs, SORT_STRING );
			$names = array();
			$valid = array();
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term instanceof WP_Term ) {
					$valid[] = $term->slug;
					$names[] = $term->name;
				}
			}
			if ( empty( $valid ) ) {
				continue;
			}

			$selected[ $param ]   = $valid;
			$redis[ $filter_key ] = array_values( array_unique( $names ) );

			$operator_key = 'query_type_' . $taxonomy;
			$operator     = isset( $request[ $operator_key ] ) ? sanitize_key( $request[ $operator_key ] ) : '';
			if ( in_array( $operator, array( 'and', 'or' ), true ) ) {
				$operators[ $operator_key ] = $operator;
			}
		}

		return new self( $page, $sort, $search, $selected, $redis, $operators );
	}

	/**
	 * Resolve a page from every storefront paging form.
	 *
	 * With a query ID, its dedicated Product Collection parameter wins. Without
	 * one, the first Product Collection page parameter bridges child-block state
	 * back to archive presentation such as WooCommerce breadcrumbs.
	 *
	 * @param int      $fallback    Page to use when the request has no paging.
	 * @param array    $request     Query parameters.
	 * @param string   $request_uri Request URI for pretty pagination.
	 * @param int|null $query_id    Product Collection query ID.
	 * @return int
	 */
	public static function requested_page( $fallback = 1, $request = null, $request_uri = null, $query_id = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public storefront state.
		$request = is_array( $request ) ? $request : wp_unslash( $_GET );
		if ( null === $request_uri ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed only for an integer /page/N/ segment.
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		}

		$page_keys = array();
		if ( null !== $query_id ) {
			$page_keys[] = 'query-' . absint( $query_id ) . '-page';
		} else {
			foreach ( array_keys( $request ) as $key ) {
				if ( preg_match( '/^query-\d+-page$/', (string) $key ) ) {
					$page_keys[] = $key;
				}
			}
		}
		$page_keys[] = 'query-page';
		$page_keys[] = 'paged';

		foreach ( array_unique( $page_keys ) as $key ) {
			if ( isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) ) {
				return max( 1, absint( $request[ $key ] ) );
			}
		}

		$page = max( 1, absint( $fallback ) );
		if ( 1 === $page && preg_match( '#/page/(\d+)(?:/|$)#', (string) $request_uri, $matches ) ) {
			return max( 1, absint( $matches[1] ) );
		}

		return $page;
	}

	/**
	 * Taxonomies enabled for canonical facet state.
	 *
	 * @return array<string,string> Taxonomy => Redis filter key.
	 */
	private static function filter_taxonomies() {
		$taxonomies = array();
		if ( 'yes' === get_option( 'shift64_woo_search_filter_categories_enabled', 'yes' ) ) {
			$taxonomies['product_cat'] = 'category';
		}
		if ( 'yes' === get_option( 'shift64_woo_search_filter_brands_enabled', 'no' ) && taxonomy_exists( 'product_brand' ) ) {
			$taxonomies['product_brand'] = 'brand';
		}
		foreach ( Shift64_Woo_Search_Schema::get_filter_attributes() as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$taxonomies[ $taxonomy ] = 'attr_' . $taxonomy;
			}
		}
		return $taxonomies;
	}

	/**
	 * Build a safe canonical URL.
	 *
	 * Changes to search, sort, or facets reset all recognized paging forms.
	 * Passing only `page` preserves the remaining state.
	 *
	 * @param string   $base_url Base storefront URL.
	 * @param array    $changes  Canonical parameter changes.
	 * @param int|null $query_id Product Collection query ID.
	 * @return string
	 */
	public static function build_url( $base_url, array $changes, $query_id = null ) {
		$parts = wp_parse_url( $base_url );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		foreach ( self::PRIVATE_PARAMS as $private ) {
			unset( $query[ $private ] );
		}

		$resets_page = false;
		foreach ( $changes as $key => $value ) {
			$key = sanitize_key( $key );
			if ( 'page' !== $key ) {
				$resets_page = true;
			}
			if ( null === $value || '' === $value || array() === $value ) {
				unset( $query[ $key ] );
				continue;
			}
			$query[ $key ] = is_array( $value ) ? implode( ',', array_map( 'sanitize_title', $value ) ) : sanitize_text_field( $value );
		}

		$page_keys = array( 'query-page', 'paged' );
		if ( null !== $query_id ) {
			$page_keys[] = 'query-' . absint( $query_id ) . '-page';
		}
		if ( $resets_page ) {
			foreach ( array_keys( $query ) as $query_key ) {
				if ( in_array( $query_key, $page_keys, true ) || preg_match( '/^query-\d+-page$/', $query_key ) ) {
					unset( $query[ $query_key ] );
				}
			}
			if ( ! empty( $parts['path'] ) ) {
				$parts['path'] = preg_replace( '#/page/\d+/?$#', '/', $parts['path'] );
			}
		} elseif ( isset( $changes['page'] ) ) {
			unset( $query['page'] );
			$page_key           = null !== $query_id ? 'query-' . absint( $query_id ) . '-page' : 'query-page';
			$query[ $page_key ] = max( 1, absint( $changes['page'] ) );
		}

		ksort( $query );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = $parts['host'] ?? '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = $parts['path'] ?? '/';
		$url    = $scheme . $host . $port . $path;
		$url    = empty( $query ) ? $url : add_query_arg( $query, $url );
		return isset( $parts['fragment'] ) ? $url . '#' . $parts['fragment'] : $url;
	}

	/**
	 * Get page.
	 *
	 * @return int
	 */
	public function get_page() {
		return $this->page;
	}

	/**
	 * Get sort slug.
	 *
	 * @return string
	 */
	public function get_sort() {
		return $this->sort;
	}

	/**
	 * Get search term.
	 *
	 * @return string
	 */
	public function get_search() {
		return $this->search;
	}

	/**
	 * Get selected canonical filter slugs.
	 *
	 * @return array
	 */
	public function get_selected_filters() {
		return $this->selected_filters;
	}

	/**
	 * Get Redis filter values.
	 *
	 * @return array
	 */
	public function get_redis_filters() {
		return $this->redis_filters;
	}

	/**
	 * Get query operators.
	 *
	 * @return array
	 */
	public function get_operators() {
		return $this->operators;
	}

	/**
	 * Get validated operators keyed by the Redis filter dimension.
	 *
	 * @return array<string,string>
	 */
	public function get_redis_operators() {
		$operators = array();
		foreach ( $this->selected_filters as $param => $values ) {
			$taxonomy     = substr( $param, strlen( 'filter_' ) );
			$operator_key = 'query_type_' . $taxonomy;
			if ( ! isset( $this->operators[ $operator_key ] ) ) {
				continue;
			}

			if ( 'product_cat' === $taxonomy ) {
				$filter_key = 'category';
			} elseif ( 'product_brand' === $taxonomy ) {
				$filter_key = 'brand';
			} else {
				$filter_key = 'attr_' . $taxonomy;
			}

			if ( isset( $this->redis_filters[ $filter_key ] ) ) {
				$operators[ $filter_key ] = $this->operators[ $operator_key ];
			}
		}
		return $operators;
	}
}
