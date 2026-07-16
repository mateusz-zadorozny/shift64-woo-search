<?php
/**
 * Auto-register newly-stamped attributes into the facet allowlist + schedule
 * a debounced index rebuild.
 *
 * Subscribes to the `shift64_woo_search_product_sync_attribute_ensured` action emitted by
 * the shift64-woo-search-product-sync `Mapper::ensure_attribute()` method. Two effects:
 *
 *   1. Adds the new taxonomy slug to `shift64_woo_search_filter_attributes` so the
 *      schema picks it up on the next FT.CREATE.
 *   2. Schedules a single-event WP-Cron at +60s named `shift64_woo_search_auto_rebuild`.
 *      Multiple events arriving in the same window collapse into one rebuild,
 *      avoiding N rebuilds when a bulk category-parameters sync fires N
 *      ensure_attribute() calls back-to-back.
 *
 * Why this lives in the plugin (not the theme): the producer is plugin-agnostic
 * (shift64-woo-search-product-sync only knows about UUID storage), but the consequence
 * — index rebuild — is owned by this plugin. Coupling lives where ownership
 * does.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for attribute UUID-stamp / creation events and reconciles the
 * Redis facet allowlist with a debounced rebuild.
 */
class Shift64_Woo_Search_Attribute_Auto_Register {

	/**
	 * Single-event cron hook name (debounce target).
	 */
	const REBUILD_HOOK = 'shift64_woo_search_auto_rebuild';

	/**
	 * Transient lock to prevent concurrent rebuilds (e.g. cron + manual CLI).
	 */
	const LOCK_TRANSIENT = 'shift64_woo_search_auto_rebuild_lock';

	/**
	 * Debounce window in seconds — how long after the first ensured-event we
	 * wait before firing the rebuild. Large enough to absorb a full bulk
	 * category-parameters sync (~50-200 categories at ~200 ms HTTP each).
	 */
	const DEBOUNCE_SECONDS = 60;

	/**
	 * Wire the hooks.
	 */
	public function __construct() {
		add_action( 'shift64_woo_search_product_sync_attribute_ensured', array( $this, 'on_attribute_ensured' ), 10, 2 );
		add_action( self::REBUILD_HOOK, array( $this, 'run_rebuild' ) );
	}

	/**
	 * Subscriber for `shift64_woo_search_product_sync_attribute_ensured`.
	 *
	 * Updates the global filter-attributes option (idempotent) and schedules
	 * the debounced rebuild. Both writes are cheap; the rebuild itself runs
	 * later in WP-Cron.
	 *
	 * @param array $hit     Attribute data: attribute_id, taxonomy, name, and label.
	 * @param bool  $created True if the attribute was freshly created.
	 */
	public function on_attribute_ensured( $hit, $created ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook contract includes the creation flag.
		if ( ! is_array( $hit ) || empty( $hit['name'] ) ) {
			return;
		}

		$taxonomy_slug = (string) $hit['taxonomy'];

		$current = get_option( 'shift64_woo_search_filter_attributes', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		if ( in_array( $taxonomy_slug, $current, true ) ) {
			// Already in the allowlist. Even so, the schema may already have
			// the field — no rebuild needed unless the attribute is
			// freshly-created (path 4). When path 2 or 3 ran on an attribute
			// that's already enabled, the stamp landed but the schema was
			// already correct.
			return;
		}

		$current[] = $taxonomy_slug;
		update_option( 'shift64_woo_search_filter_attributes', array_values( array_unique( $current ) ) );

		$this->schedule_debounced_rebuild();
	}

	/**
	 * Schedule a single-event rebuild at +DEBOUNCE_SECONDS, debounced.
	 *
	 * `wp_schedule_single_event` with the same hook + same args is a no-op if
	 * an event is already pending — so multiple ensured-events in quick
	 * succession produce exactly one rebuild.
	 */
	private function schedule_debounced_rebuild() {
		if ( wp_next_scheduled( self::REBUILD_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::REBUILD_HOOK );
	}

	/**
	 * Cron handler: drop + create the index with the updated schema, sync
	 * synonyms / suggestions / category cache, reindex all products,
	 * regenerate the SHORTINIT config.
	 *
	 * Mirrors the body of Shift64_Woo_Search_CLI::rebuild() minus the WP_CLI
	 * progress bar (replaced with error_log on failure) and minus halt-on-error
	 * (we want partial recovery rather than a stuck cron). A transient lock
	 * keeps a long rebuild from overlapping with the next scheduled run.
	 */
	public function run_rebuild() {
		// Lock — bail out if another rebuild is already in flight.
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Shift64 Woo Search Auto Rebuild] Skipped: lock held by concurrent rebuild.' );
			return;
		}
		set_transient( self::LOCK_TRANSIENT, time(), 30 * MINUTE_IN_SECONDS );

		try {
			$redis = Shift64_Woo_Search_Redis::get_instance();
			if ( ! $redis->is_available() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Shift64 Woo Search Auto Rebuild] Skipped: Redis unavailable.' );
				return;
			}

			// Long task — raise limits.
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'cron' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 1800 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			// Drop existing index (preserves product hashes — different prefix).
			if ( Shift64_Woo_Search_Schema::index_exists( $redis ) ) {
				Shift64_Woo_Search_Schema::drop_index( $redis );
			}

			if ( ! Shift64_Woo_Search_Schema::create_index( $redis ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Shift64 Woo Search Auto Rebuild] FT.CREATE failed.' );
				return;
			}

			// Sister-data sync (matches the CLI rebuild's sequence).
			if ( class_exists( 'Shift64_Woo_Search_Synonyms' ) ) {
				Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
			}
			if ( class_exists( 'Shift64_Woo_Search_Suggestions' ) ) {
				Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
			}
			Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis );

			$indexer = new Shift64_Woo_Search_Indexer( $redis );
			$count   = $indexer->reindex_all();

			// Refresh the SHORTINIT config so the autocomplete endpoint sees
			// the new attribute fields. Mirrors CLI rebuild.
			if ( class_exists( 'Shift64_Woo_Search_Plugin' ) && method_exists( 'Shift64_Woo_Search_Plugin', 'get_instance' ) ) {
				$plugin = Shift64_Woo_Search_Plugin::get_instance();
				if ( method_exists( $plugin, 'generate_mu_plugin_config' ) ) {
					$plugin->generate_mu_plugin_config();
				}
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[Shift64 Woo Search Auto Rebuild] OK — %d products reindexed.', (int) $count ) );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Shift64 Woo Search Auto Rebuild] Exception: ' . $e->getMessage() );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}
}
