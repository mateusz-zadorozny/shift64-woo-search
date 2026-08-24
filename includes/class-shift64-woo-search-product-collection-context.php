<?php
/**
 * Immutable eligibility and request context for an inherited Product Collection.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves whether a WooCommerce Product Collection belongs to Shift64.
 */
final class Shift64_Woo_Search_Product_Collection_Context {

	/**
	 * Context marker used to route inherited Product Collection children
	 * through their own WP_Query without mutating the main archive query.
	 */
	const SCOPED_INHERIT_MARKER = 'shift64WooSearchInheritedProductCollection';

	/**
	 * Request key for a Redis result prepared before query construction.
	 */
	const SCOPED_RESULT_KEY = 'shift64WooSearchProductCollectionResultKey';

	/**
	 * Stable Product Collection query ID.
	 *
	 * @var int|null
	 */
	private $query_id;

	/**
	 * Request-unique registry key.
	 *
	 * @var string
	 */
	private $request_key;

	/**
	 * Requested page.
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
	 * Search term, when this is a product search.
	 *
	 * @var string
	 */
	private $search_term;

	/**
	 * Archive taxonomy slug.
	 *
	 * @var string
	 */
	private $taxonomy;

	/**
	 * Archive term name as indexed in Redis.
	 *
	 * @var string
	 */
	private $term_name;

	/**
	 * Visibility policy passed to the shared query builder.
	 *
	 * @var string|null
	 */
	private $visibility_policy;

	/**
	 * Constructor.
	 *
	 * @param int|null    $query_id         Product Collection query ID.
	 * @param string      $request_key      Request-unique key.
	 * @param int         $page             Requested page.
	 * @param int         $per_page         Products per page.
	 * @param string      $search_term      Search term.
	 * @param string      $taxonomy         Archive taxonomy.
	 * @param string      $term_name        Archive term name.
	 * @param string|null $visibility_policy Visibility policy.
	 */
	public function __construct( $query_id, $request_key, $page, $per_page, $search_term, $taxonomy, $term_name, $visibility_policy ) {
		$this->query_id          = null === $query_id ? null : max( 0, (int) $query_id );
		$this->request_key       = sanitize_key( $request_key );
		$this->page              = max( 1, (int) $page );
		$this->per_page          = max( 1, (int) $per_page );
		$this->search_term       = trim( (string) $search_term );
		$this->taxonomy          = sanitize_key( $taxonomy );
		$this->term_name         = sanitize_text_field( $term_name );
		$this->visibility_policy = $visibility_policy;
	}

	/**
	 * Resolve an eligible Product Collection from the public Query Loop filter.
	 *
	 * `$request_context` makes the resolver independently testable. Production
	 * callers omit it and the method derives the current frontend archive.
	 *
	 * @param array      $query_vars      WooCommerce-composed query variables.
	 * @param WP_Block   $block           Product Collection block instance.
	 * @param int        $page            Query Loop page.
	 * @param array|null $request_context Optional normalized request facts.
	 * @return self|null
	 */
	public static function from_block( array $query_vars, $block, $page, $request_context = null ) {
		if ( ! $block instanceof WP_Block ) {
			return null;
		}

		$parsed     = is_array( $block->parsed_block ?? null ) ? $block->parsed_block : array();
		$block_name = $parsed['blockName'] ?? '';
		$query      = is_array( $block->context['query'] ?? null ) ? $block->context['query'] : array();

		$product_collection_blocks = array(
			'woocommerce/product-collection',
			'woocommerce/product-template',
			'woocommerce/product-collection-no-results',
			'core/query-pagination-next',
			'core/query-pagination-previous',
			'core/query-pagination-numbers',
			'core/query-total',
		);
		$is_scoped_inherit         = true === ( $query[ self::SCOPED_INHERIT_MARKER ] ?? false );
		if (
			! in_array( $block_name, $product_collection_blocks, true )
			|| true !== ( $query['isProductCollectionBlock'] ?? false )
			|| ( true !== ( $query['inherit'] ?? false ) && ! $is_scoped_inherit )
		) {
			return null;
		}

		$facts       = is_array( $request_context ) ? $request_context : self::current_request_context( $query_vars );
		$request_key = sanitize_key( $query[ self::SCOPED_RESULT_KEY ] ?? '' );
		if ( '' === $request_key ) {
			$query_id    = isset( $block->context['queryId'] ) ? absint( $block->context['queryId'] ) : null;
			$instance    = function_exists( 'spl_object_id' ) ? spl_object_id( $block ) : 0;
			$request_key = sprintf( 'pc-%s-%d', null === $query_id ? 'fallback' : $query_id, $instance );
		}

		return self::from_query_context(
			$query_vars,
			$query,
			$block->context['queryId'] ?? null,
			$page,
			$facts,
			$request_key
		);
	}

	/**
	 * Resolve context early enough to preserve native inherited fallback.
	 *
	 * @param array      $block_context   Product Collection block context.
	 * @param string     $request_key     Request-scoped result key.
	 * @param array|null $request_context Optional normalized request facts.
	 * @return self|null
	 */
	public static function from_render_context( array $block_context, $request_key, $request_context = null ) {
		$query = is_array( $block_context['query'] ?? null ) ? $block_context['query'] : array();
		if (
			true !== ( $query['isProductCollectionBlock'] ?? false )
			|| true !== ( $query['inherit'] ?? false )
		) {
			return null;
		}

		$facts    = is_array( $request_context ) ? $request_context : self::current_request_context( array() );
		$query_id = $block_context['queryId'] ?? null;
		return self::from_query_context(
			array(),
			$query,
			$query_id,
			self::current_query_page( $query_id ),
			$facts,
			$request_key,
			true
		);
	}

	/**
	 * Resolve Product Collection pagination before its child query is built.
	 *
	 * @param mixed $raw_query_id Product Collection query ID.
	 * @return int
	 */
	private static function current_query_page( $raw_query_id ) {
		$query_id = null === $raw_query_id ? null : absint( $raw_query_id );
		return Shift64_Woo_Search_Catalog_State::requested_page( 1, null, null, $query_id );
	}

	/**
	 * Build an eligible immutable context from normalized inputs.
	 *
	 * @param array  $query_vars Query variables.
	 * @param array  $query Block query context.
	 * @param mixed  $raw_query_id Product Collection query ID.
	 * @param int    $page Query page.
	 * @param array  $facts Normalized request facts.
	 * @param string $request_key Request-scoped result key.
	 * @param bool   $archive_enabled Whether the archive gate was already checked.
	 * @return self|null
	 */
	private static function from_query_context( array $query_vars, array $query, $raw_query_id, $page, array $facts, $request_key, $archive_enabled = false ) {
		if (
			! empty( $facts['is_admin'] )
			|| ! empty( $facts['is_rest'] )
			|| ! empty( $facts['is_feed'] )
		) {
			return null;
		}

		$is_search = ! empty( $facts['is_product_search'] );
		$is_shop   = ! empty( $facts['is_shop'] );
		$taxonomy  = sanitize_key( $facts['taxonomy'] ?? '' );
		$is_tax    = '' !== $taxonomy && ! empty( $facts['taxonomy_enabled'] );
		if ( ! $is_search && ! $is_shop && ! $is_tax ) {
			return null;
		}

		if ( ! $archive_enabled && 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return null;
		}

		$query_id    = null === $raw_query_id ? null : absint( $raw_query_id );
		$per_page    = max( 1, (int) ( $query_vars['posts_per_page'] ?? $query['perPage'] ?? get_option( 'posts_per_page', 12 ) ) );
		$search_term = $is_search ? sanitize_text_field( $facts['search_term'] ?? '' ) : '';

		return new self(
			$query_id,
			$request_key,
			$page,
			$per_page,
			$search_term,
			$taxonomy,
			$facts['term_name'] ?? '',
			$is_search ? 'search' : null
		);
	}

	/**
	 * Build an eligible context from the request alone, without any Product
	 * Collection block context.
	 *
	 * Server-rendered controls (Filter Pills) may render before the inherited
	 * Product Collection composes its query; this factory gives them the same
	 * validated search/scope/visibility facts. Paging intentionally stays at
	 * 1 — facet aggregations are page-independent.
	 *
	 * @param string   $request_key Request-scoped result key.
	 * @param int|null $page       Optional current Product Collection page.
	 * @return self|null Null off eligible archives or with the feature disabled.
	 */
	public static function for_current_request( $request_key, $page = null ) {
		return self::from_query_context(
			array(),
			array(),
			null,
			null === $page ? 1 : max( 1, (int) $page ),
			self::current_request_context( array() ),
			$request_key
		);
	}

	/**
	 * Whether the current frontend request is an eligible inherited archive.
	 *
	 * This is used before WooCommerce constructs the child WP_Block instance,
	 * when no query vars have been composed yet.
	 *
	 * @return bool
	 */
	public static function is_current_archive_request() {
		$facts = self::current_request_context( array() );
		if (
			! empty( $facts['is_admin'] )
			|| ! empty( $facts['is_rest'] )
			|| ! empty( $facts['is_feed'] )
		) {
			return false;
		}

		$is_tax = ! empty( $facts['taxonomy'] ) && ! empty( $facts['taxonomy_enabled'] );
		return ! empty( $facts['is_product_search'] ) || ! empty( $facts['is_shop'] ) || $is_tax;
	}

	/**
	 * Derive normalized request facts without mutating global query state.
	 *
	 * @param array $query_vars WooCommerce-composed query variables.
	 * @return array
	 */
	private static function current_request_context( array $query_vars ) {
		$rest_request      = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$post_type         = $query_vars['post_type'] ?? get_query_var( 'post_type' );
		$is_product_search = is_search()
			&& ( 'product' === $post_type || array( 'product' ) === $post_type || 'product' === get_query_var( 'post_type' ) );
		$taxonomy          = '';
		$term_name         = '';
		$taxonomy_enabled  = false;

		$queried = get_queried_object();
		if ( $queried instanceof WP_Term ) {
			$taxonomy         = $queried->taxonomy;
			$term_name        = $queried->name;
			$enabled          = (array) get_option( 'shift64_woo_search_taxonomy_archive_scopes', array() );
			$taxonomy_enabled = in_array( $taxonomy, $enabled, true );
		}

		return array(
			'is_admin'          => is_admin(),
			'is_rest'           => $rest_request,
			'is_feed'           => is_feed(),
			'is_product_search' => $is_product_search,
			'is_shop'           => function_exists( 'is_shop' ) && is_shop(),
			'taxonomy'          => $taxonomy,
			'term_name'         => $term_name,
			'taxonomy_enabled'  => $taxonomy_enabled,
			'search_term'       => $query_vars['s'] ?? get_search_query( false ),
		);
	}

	/**
	 * Get query ID.
	 *
	 * @return int|null
	 */
	public function get_query_id() {
		return $this->query_id;
	}

	/**
	 * Get registry key.
	 *
	 * @return string
	 */
	public function get_request_key() {
		return $this->request_key;
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
	 * Get per-page value.
	 *
	 * @return int
	 */
	public function get_per_page() {
		return $this->per_page;
	}

	/**
	 * Get search term.
	 *
	 * @return string
	 */
	public function get_search_term() {
		return $this->search_term;
	}

	/**
	 * Get archive taxonomy.
	 *
	 * @return string
	 */
	public function get_taxonomy() {
		return $this->taxonomy;
	}

	/**
	 * Get archive term name.
	 *
	 * @return string
	 */
	public function get_term_name() {
		return $this->term_name;
	}

	/**
	 * Get visibility policy.
	 *
	 * @return string|null
	 */
	public function get_visibility_policy() {
		return $this->visibility_policy;
	}
}
