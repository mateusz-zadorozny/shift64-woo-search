<?php
/**
 * RediSearch index schema definition and management.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RediSearch index schema and weight configuration.
 */
class Shift64_Woo_Search_Schema {

	/**
	 * Return default field weights.
	 *
	 * @return array
	 */
	public static function get_default_weights() {
		return array(
			'title'           => 10,
			'sku_text'        => 8,
			'brands_text'     => 5,
			'categories_text' => 4,
			'short_desc'      => 3,
			'attributes'      => 2,
			'description'     => 1,
		);
	}

	/**
	 * Return merged field weights (defaults + custom overrides).
	 *
	 * @return array
	 */
	public static function get_weights() {
		$defaults = self::get_default_weights();
		$custom   = get_option( 'shift64_woo_search_weights', array() );

		if ( ! is_array( $custom ) || empty( $custom ) ) {
			return $defaults;
		}

		return array_merge( $defaults, $custom );
	}


	/**
	 * Return the list of attribute taxonomies selected for faceted filtering.
	 *
	 * @return array Array of taxonomy slugs, e.g. ['pa_kolor', 'pa_rozmiar'].
	 */
	public static function get_filter_attributes() {
		$attrs = get_option( 'shift64_woo_search_filter_attributes', array() );
		if ( ! is_array( $attrs ) ) {
			return array();
		}
		// Normalize: legacy entries from Auto_Register may lack 'pa_' prefix.
		return array_map(
			function ( $a ) {
				$a = (string) $a;
				return ( strpos( $a, 'pa_' ) === 0 || $a === '' ) ? $a : 'pa_' . $a;
			},
			$attrs
		);
	}

	/**
	 * Build the FT.CREATE command arguments for the product index.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return bool
	 */
	public static function create_index( $redis ) {
		$index_name = $redis->get_index_name();
		$prefix     = $redis->get_prefix() . ':product:';
		$weights    = self::get_weights();

		// Build the SCHEMA portion.
		$schema = array(
			'title',
			'TEXT',
			'WEIGHT',
			$weights['title'],
			'SORTABLE',
			'sku',
			'TAG',
			'SORTABLE',
			'sku_text',
			'TEXT',
			'WEIGHT',
			$weights['sku_text'],
			'short_desc',
			'TEXT',
			'WEIGHT',
			$weights['short_desc'],
			'description',
			'TEXT',
			'WEIGHT',
			$weights['description'],
			'categories',
			'TAG',
			'SEPARATOR',
			'|',
			'categories_text',
			'TEXT',
			'WEIGHT',
			$weights['categories_text'],
			'brands',
			'TAG',
			'SEPARATOR',
			'|',
			'brands_text',
			'TEXT',
			'WEIGHT',
			$weights['brands_text'],
			'tags',
			'TAG',
			'SEPARATOR',
			'|',
			'attributes',
			'TEXT',
			'WEIGHT',
			$weights['attributes'],
			'title_ascii',
			'TEXT',
			'WEIGHT',
			max( 1, (int) ( $weights['title'] / 2 ) ),
			'image_url',
			'TEXT',
			'NOINDEX',
			'permalink',
			'TEXT',
			'NOINDEX',
			'price',
			'NUMERIC',
			'SORTABLE',
			'regular_price',
			'NUMERIC',
			'stock_status',
			'TAG',
			'visibility',
			'TAG',
			'product_type',
			'TAG',
			'promoted',
			'TAG',
			'excluded',
			'TAG',
			'menu_order',
			'NUMERIC',
			'SORTABLE',
			'total_sales',
			'NUMERIC',
			'SORTABLE',
			'average_rating',
			'NUMERIC',
			'SORTABLE',
			'post_id',
			'NUMERIC',
			'SORTABLE',
		);

		// Per-attribute TAG fields for faceted filtering.
		$filter_attrs = self::get_filter_attributes();
		foreach ( $filter_attrs as $taxonomy ) {
			$field_name = 'attr_' . $taxonomy;
			$schema[]   = $field_name;
			$schema[]   = 'TAG';
			$schema[]   = 'SEPARATOR';
			$schema[]   = '|';
		}

		$args = array_merge(
			array( 'FT.CREATE', $index_name, 'ON', 'HASH', 'PREFIX', '1', $prefix, 'SCHEMA' ),
			$schema
		);

		$result = $redis->raw_command( ...$args );

		return $result !== false;
	}

	/**
	 * Drop the RediSearch index and its documents.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return bool
	 */
	public static function drop_index( $redis ) {
		$index_name = $redis->get_index_name();
		// DD = Drop Documents. Without DD, only the index definition is removed.
		$result = $redis->raw_command( 'FT.DROPINDEX', $index_name, 'DD' );
		return $result !== false;
	}

	/**
	 * Check whether the RediSearch index exists.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return bool
	 */
	public static function index_exists( $redis ) {
		$index_name = $redis->get_index_name();
		$result     = $redis->raw_command( 'FT.INFO', $index_name );
		return $result !== false && ! empty( $result );
	}

	/**
	 * Ensure the RediSearch index exists and is populated. Called from the search hot path.
	 *
	 * Why: FLUSHDB on any database drops all RediSearch indexes instance-wide. This means
	 * the WP Redis Object Cache plugin's flush button silently kills our index. Recovery
	 * is cheap because product hashes survive (different prefix, FT.CREATE re-indexes them
	 * in ~250ms via PREFIX matching). Only when hashes are also gone do we need a full reindex.
	 *
	 * The `redis_object_cache_flush` action handles the common case proactively. This
	 * defensive hot-path check is a narrow backstop for the cases the hook can't catch:
	 * Redis restart without persistence, manual FT.DROPINDEX, a flush that fired before
	 * our hook was registered, or a fatal inside the hook handler. The cost is one FT.INFO
	 * on the first search of each request (per-request static cache handles the rest).
	 *
	 * Per-request static cache prevents repeated checks within a single request. A short-lived
	 * Redis lock prevents concurrent searches from racing into FT.CREATE / reindex together.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return string One of: 'healthy', 'created', 'reindex_scheduled', 'locked', 'shortinit_skip', 'failed'.
	 */
	public static function ensure_index_healthy( $redis ) {
		static $checked_this_request = null;
		if ( null !== $checked_this_request ) {
			return $checked_this_request;
		}

		$info = self::get_index_info( $redis );

		// Tier 1: index exists and has documents → nothing to do.
		if ( false !== $info && isset( $info['num_docs'] ) && (int) $info['num_docs'] > 0 ) {
			$checked_this_request = 'healthy';
			return $checked_this_request;
		}

		// Heal path needs WordPress option APIs (get_option for weights, filter attrs) and
		// wc_get_products for reindex. Neither exists under SHORTINIT, so bail early and let
		// the next full-WP request heal. Calling create_index here would fatal on get_option.
		if ( defined( 'SHORTINIT' ) && SHORTINIT ) {
			$checked_this_request = 'shortinit_skip';
			return $checked_this_request;
		}

		// Acquire short-lived lock so concurrent searches don't all trigger heal.
		$client = $redis->get_client();
		if ( null === $client ) {
			$checked_this_request = 'failed';
			return $checked_this_request;
		}
		$lock_key = $redis->get_prefix() . ':_heal_lock';
		// 300s lock — generous upper bound for FT.CREATE + a catalog reindex. If a reindex
		// ever exceeds this, two workers could race; bump further if catalog size demands it.
		// The lock is released in `finally` on every return path. Concurrent duplicate
		// scheduling after release is still prevented by `wp_next_scheduled` below.
		$got_lock = false;
		try {
			$got_lock = (bool) $client->set(
				$lock_key,
				'1',
				array(
					'NX',
					'EX' => 300,
				)
			);
		} catch ( Exception $e ) {
			$got_lock = false;
		}
		if ( ! $got_lock ) {
			// Another request is already healing; skip and let this search return whatever it can.
			$checked_this_request = 'locked';
			return $checked_this_request;
		}

		try {
			// Tier 2: index missing → create it. Existing hashes get auto-indexed via PREFIX.
			if ( false === $info ) {
				if ( ! self::create_index( $redis ) ) {
					$checked_this_request = 'failed';
					return $checked_this_request;
				}
				// After FT.CREATE, RediSearch backfills from existing hashes asynchronously.
				// Recheck count — if hashes survived the flush, we're done.
				$info_after = self::get_index_info( $redis );
				if ( false !== $info_after && isset( $info_after['num_docs'] ) && (int) $info_after['num_docs'] > 0 ) {
					$checked_this_request = 'created';
					return $checked_this_request;
				}
				// Index created but empty — fall through to reindex.
			}

			// Tier 3: index empty (hashes also gone) → schedule a full reindex.
			// SHORTINIT was bailed above; we're in full WP context here.
			if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( 'shift64_woo_search_lazy_reindex' ) ) {
				wp_schedule_single_event( time(), 'shift64_woo_search_lazy_reindex' );
				$checked_this_request = 'reindex_scheduled';
				return $checked_this_request;
			}
			$checked_this_request = 'failed';
			return $checked_this_request;
		} finally {
			// Release lock (best-effort; expires automatically anyway).
			try {
				$client->del( $lock_key );
			} catch ( Exception $e ) {
				// The lock expires automatically; deletion is best-effort only.
				unset( $e );
			}
		}
	}

	/**
	 * Get index info from RediSearch.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return array|false Parsed info array or false on failure.
	 */
	public static function get_index_info( $redis ) {
		$index_name = $redis->get_index_name();
		$result     = $redis->raw_command( 'FT.INFO', $index_name );

		if ( $result === false || empty( $result ) ) {
			return false;
		}

		// FT.INFO always replies as a flat [key, value, key, value, ...] list.
		// Depending on the phpredis/RediSearch/RESP combo the simple-string keys
		// (and string values like index_name) may arrive as real strings or be
		// coerced to booleans. Walk it as pairs either way: use the string key
		// when present, otherwise fall back to the stable positional field name.
		$info         = array();
		$result_count = count( $result );

		// FT.INFO field order (stable across RediSearch versions for the fields we read).
		$field_order = array(
			'index_name',
			'index_options',
			'index_definition',
			'attributes',
			'num_docs',
			'max_doc_id',
			'num_terms',
			'num_records',
			'inverted_sz_mb',
			'vector_index_sz_mb',
			'total_inverted_index_blocks',
			'offset_vectors_sz_mb',
			'doc_table_size_mb',
			'sortable_values_size_mb',
			'key_table_size_mb',
			'records_per_doc_avg',
			'bytes_per_record_avg',
			'offsets_per_term_avg',
			'offset_bits_per_record_avg',
			'hash_indexing_failures',
			'total_indexing_time',
			'indexing',
			'percent_indexed',
		);

		for ( $i = 0; $i + 1 < $result_count; $i += 2 ) {
			$key  = $result[ $i ];
			$val  = $result[ $i + 1 ];
			$pair = intval( $i / 2 );

			if ( is_string( $key ) ) {
				$field = $key;
			} elseif ( isset( $field_order[ $pair ] ) ) {
				$field = $field_order[ $pair ];
			} else {
				continue;
			}

			if ( ! is_array( $val ) ) {
				$info[ $field ] = $val;
			}
		}

		// index_name's value can also be coerced to a boolean; the known name is authoritative.
		if ( empty( $info['index_name'] ) || ! is_string( $info['index_name'] ) ) {
			$info['index_name'] = $index_name;
		}

		return ! empty( $info ) ? $info : false;
	}
}
