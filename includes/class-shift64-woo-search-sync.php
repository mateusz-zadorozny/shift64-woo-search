<?php
/**
 * Auto-sync hooks — keeps Redis index in sync with WooCommerce product changes.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-sync handler for WooCommerce product changes.
 */
class Shift64_Woo_Search_Sync {

	/**
	 * Product IDs queued for deferred reindexing at shutdown.
	 *
	 * @var array
	 */
	private static $pending_ids = array();

	/**
	 * Whether the shutdown handler has been registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Descendants of categories about to be deleted, captured pre-delete and consumed post-delete.
	 *
	 * Keyed by parent term ID. Needed because once `wp_delete_term()` runs, descendants are
	 * reparented (parent → 0) and there is no way to ask "who used to be a child of $term_id".
	 *
	 * @var array<int, int[]>
	 */
	private static $deleted_category_descendants = array();

	/**
	 * Register WooCommerce sync hooks.
	 */
	public function __construct() {
		// Product save/update.
		add_action( 'save_post_product', array( $this, 'on_product_save' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_update' ), 20, 1 );

		// Product deletion.
		add_action( 'before_delete_post', array( $this, 'on_product_delete' ), 10, 1 );

		// Stock status changes.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_change' ), 10, 3 );

		// Category edit — reindex affected products.
		add_action( 'edited_product_cat', array( $this, 'on_category_edit' ), 10, 2 );

		// Category deletion — capture descendants before deletion (their ancestor chain
		// changes when the parent disappears), then reindex affected products after deletion.
		add_action( 'pre_delete_term', array( $this, 'on_pre_category_delete' ), 10, 2 );
		add_action( 'delete_product_cat', array( $this, 'on_category_delete' ), 10, 4 );

		// CSV import.
		add_action( 'woocommerce_product_import_inserted_product_object', array( $this, 'on_product_import' ), 10, 2 );
	}

	/**
	 * Handle product save.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_save( $product_id ) {
		$this->upsert_product( $product_id );
	}

	/**
	 * Handle WooCommerce product update.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_update( $product_id ) {
		$this->upsert_product( $product_id );
	}

	/**
	 * Handle product deletion.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_product_delete( $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			return;
		}

		$indexer = new Shift64_Woo_Search_Indexer( $redis );
		$indexer->remove_product( $post_id );
	}

	/**
	 * Handle stock status change.
	 *
	 * @param int        $product_id   Product ID.
	 * @param string     $stock_status New stock status (unused, required by hook).
	 * @param WC_Product $product      Product object (unused, required by hook).
	 */
	public function on_stock_change( $product_id, $stock_status, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->upsert_product( $product_id );
	}

	/**
	 * Handle category edit — reindex all products in that category.
	 *
	 * @param int $term_id Term ID.
	 * @param int $tt_id   Term taxonomy ID (unused, required by hook).
	 */
	public function on_category_edit( $term_id, $tt_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			return;
		}

		$product_ids = wc_get_products(
			array(
				'category' => array( get_term( $term_id )->slug ),
				'return'   => 'ids',
				'limit'    => -1,
			)
		);

		if ( empty( $product_ids ) ) {
			return;
		}

		$indexer = new Shift64_Woo_Search_Indexer( $redis );
		foreach ( $product_ids as $product_id ) {
			$indexer->index_product( $product_id );
		}

		// Refresh cached category list (name/URL may have changed).
		Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis );
	}

	/**
	 * Snapshot descendants of a category about to be deleted.
	 *
	 * Runs on `pre_delete_term`. Once `wp_delete_term()` finishes, descendants have already
	 * been reparented to 0, so we cannot ask WordPress "who were the children of $term_id"
	 * after the fact. We capture the recursive list of descendant term IDs here and consume
	 * it in `on_category_delete()`.
	 *
	 * @param int    $term_id  Term ID about to be deleted.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function on_pre_category_delete( $term_id, $taxonomy ) {
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}

		$children = get_term_children( $term_id, $taxonomy );
		if ( is_wp_error( $children ) || empty( $children ) ) {
			return;
		}

		self::$deleted_category_descendants[ $term_id ] = array_map( 'intval', $children );
	}

	/**
	 * Reindex products affected by a deleted category.
	 *
	 * Two groups of products need refreshing:
	 *   1. Products directly attached to the deleted category — WP gives us those IDs as
	 *      `$object_ids`. Their TAG field still references the deleted term ID.
	 *   2. Products in descendant categories — their TAG field includes ancestor IDs and
	 *      one of those ancestors (the deleted parent) no longer exists. We reuse the
	 *      descendants snapshot taken in `on_pre_category_delete()`.
	 *
	 * @param int     $term_id      Deleted term ID.
	 * @param int     $tt_id        Term taxonomy ID (unused, required by hook).
	 * @param WP_Term $deleted_term Already-deleted term object (unused, required by hook).
	 * @param array   $object_ids   Product IDs that were directly attached to the deleted term.
	 */
	public function on_category_delete( $term_id, $tt_id, $deleted_term, $object_ids ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$product_ids = array();

		if ( is_array( $object_ids ) && ! empty( $object_ids ) ) {
			$product_ids = array_map( 'intval', $object_ids );
		}

		if ( ! empty( self::$deleted_category_descendants[ $term_id ] ) ) {
			$descendant_ids = self::$deleted_category_descendants[ $term_id ];
			unset( self::$deleted_category_descendants[ $term_id ] );

			$descendant_slugs = array();
			foreach ( $descendant_ids as $descendant_id ) {
				$descendant_term = get_term( $descendant_id, 'product_cat' );
				if ( $descendant_term && ! is_wp_error( $descendant_term ) ) {
					$descendant_slugs[] = $descendant_term->slug;
				}
			}

			if ( ! empty( $descendant_slugs ) ) {
				$descendant_products = wc_get_products(
					array(
						'category' => $descendant_slugs,
						'return'   => 'ids',
						'limit'    => -1,
					)
				);

				if ( ! empty( $descendant_products ) ) {
					$product_ids = array_merge( $product_ids, array_map( 'intval', $descendant_products ) );
				}
			}
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			return;
		}

		if ( ! empty( $product_ids ) ) {
			$indexer = new Shift64_Woo_Search_Indexer( $redis );
			foreach ( array_unique( $product_ids ) as $product_id ) {
				$indexer->index_product( $product_id );
			}
		}

		// Always refresh the cached category list, even when no products were
		// affected (e.g. an empty category was deleted) — otherwise the deleted
		// term keeps appearing in the SHORTINIT category dropdown.
		Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis );
	}

	/**
	 * Handle CSV import product.
	 *
	 * @param WC_Product $product Imported product object.
	 * @param array      $data    Raw import data (unused, required by hook).
	 */
	public function on_product_import( $product, $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->upsert_product( $product->get_id() );
	}

	/**
	 * Queue a product for deferred reindexing at the end of the request.
	 *
	 * Multiple saves() per product (e.g. from ERP sync field processors) are
	 * collapsed into a single reindex that runs after ALL processors finish,
	 * guaranteeing the index captures the complete product state.
	 *
	 * @param int $product_id Product ID.
	 */
	private function upsert_product( $product_id ) {
		// Ignore autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $product_id ) ) {
			return;
		}

		// Queue for deferred indexing (duplicates are handled by array key).
		self::$pending_ids[ $product_id ] = true;

		// Register shutdown handler once.
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_pending_reindex' ) );
		}
	}

	/**
	 * Flush all pending product reindexes.
	 *
	 * Runs at shutdown so every field processor has finished saving before
	 * we read the product data for the index.
	 */
	public static function flush_pending_reindex() {
		if ( empty( self::$pending_ids ) ) {
			return;
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			return;
		}

		$indexer = new Shift64_Woo_Search_Indexer( $redis );

		foreach ( array_keys( self::$pending_ids ) as $product_id ) {
			$indexer->index_product( $product_id );
		}

		self::$pending_ids = array();
	}
}
