<?php
/**
 * Shared full-rebuild sequence.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that knows how to rebuild the index from scratch.
 *
 * The sequence — drop, create, sync sister data, cache blobs, reindex,
 * regenerate the SHORTINIT config — used to be duplicated in the WP-CLI
 * rebuild command and the attribute auto-register cron handler. Both now
 * delegate here, so a new indexed field or a new Redis blob is wired in
 * exactly once.
 *
 * Callers differ only in how they report and how they react to failure, so
 * this class reports through optional callbacks and returns a result array
 * rather than halting: WP-CLI turns a failure into WP_CLI::error(), cron
 * logs it and moves on.
 */
class Shift64_Woo_Search_Rebuild {

	/**
	 * Run the full rebuild sequence.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @param array                    $args  Optional callbacks: 'log' receives a
	 *                                        human-readable progress string, and
	 *                                        'progress' receives ( $indexed, $total )
	 *                                        during the reindex.
	 * @return array {
	 *     @type bool   $success   Whether the rebuild completed.
	 *     @type string $error     Failure reason; empty on success.
	 *     @type int    $indexed   Number of products indexed.
	 *     @type int    $synonyms  Number of synonym groups synced.
	 *     @type int    $suggestions Number of suggestions synced.
	 *     @type int    $categories Number of categories cached.
	 *     @type int    $brands    Number of brands cached.
	 * }
	 */
	public static function run( $redis, $args = array() ) {
		$log      = isset( $args['log'] ) && is_callable( $args['log'] ) ? $args['log'] : null;
		$progress = isset( $args['progress'] ) && is_callable( $args['progress'] ) ? $args['progress'] : null;

		$result = array(
			'success'     => false,
			'error'       => '',
			'indexed'     => 0,
			'synonyms'    => 0,
			'suggestions' => 0,
			'categories'  => 0,
			'brands'      => 0,
		);

		$say = static function ( $message ) use ( $log ) {
			if ( $log ) {
				call_user_func( $log, $message );
			}
		};

		if ( ! $redis || ! $redis->is_available() ) {
			$result['error'] = 'Redis is not available.';
			return $result;
		}

		// Drop the existing index (DD also removes the product hashes, which the
		// reindex below recreates).
		if ( Shift64_Woo_Search_Schema::index_exists( $redis ) ) {
			$say( 'Dropping existing index...' );
			Shift64_Woo_Search_Schema::drop_index( $redis );
		}

		$say( 'Creating index with current schema...' );
		if ( ! Shift64_Woo_Search_Schema::create_index( $redis ) ) {
			$result['error'] = 'FT.CREATE failed.';
			return $result;
		}
		$say( 'Index created.' );

		// Sister data: synonyms, suggestions, and the taxonomy blobs the
		// SHORTINIT endpoint reads.
		if ( class_exists( 'Shift64_Woo_Search_Synonyms' ) ) {
			$result['synonyms'] = (int) Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
			if ( $result['synonyms'] > 0 ) {
				$say( sprintf( 'Synced %d synonym groups.', $result['synonyms'] ) );
			}
		}
		if ( class_exists( 'Shift64_Woo_Search_Suggestions' ) ) {
			$result['suggestions'] = (int) Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
			$say( sprintf( 'Synced %d suggestions.', $result['suggestions'] ) );
		}

		$blobs                = self::cache_blobs( $redis );
		$result['categories'] = $blobs['categories'];
		$result['brands']     = $blobs['brands'];
		$say( sprintf( 'Cached %d product categories.', $result['categories'] ) );
		$say( sprintf( 'Cached %d product brands.', $result['brands'] ) );

		$say( 'Starting full reindex...' );
		$indexer           = new Shift64_Woo_Search_Indexer( $redis );
		$result['indexed'] = (int) $indexer->reindex_all( $progress );

		// Mark date field as indexed so SORTBY date DESC is active.
		update_option( 'shift64_woo_search_date_indexed', 'yes' );

		// Regenerate the SHORTINIT config so the endpoint sees any schema or
		// setting change this rebuild introduced.
		if ( class_exists( 'Shift64_Woo_Search_Plugin' ) && method_exists( 'Shift64_Woo_Search_Plugin', 'get_instance' ) ) {
			$plugin = Shift64_Woo_Search_Plugin::get_instance();
			if ( method_exists( $plugin, 'generate_mu_plugin_config' ) ) {
				$plugin->generate_mu_plugin_config();
			}
		}

		$result['success'] = true;

		return $result;
	}

	/**
	 * Refresh the taxonomy blobs the SHORTINIT endpoint reads.
	 *
	 * Separated from run() because refreshing a blob is cheap and does not
	 * require dropping the index — the db_version upgrade map uses this for
	 * versions that add a blob without changing the schema.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return array{categories:int,brands:int} Number of entries cached per blob.
	 */
	public static function cache_blobs( $redis ) {
		return array(
			'categories' => (int) Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis ),
			'brands'     => (int) Shift64_Woo_Search_Indexer::cache_brands_to_redis( $redis ),
		);
	}
}
