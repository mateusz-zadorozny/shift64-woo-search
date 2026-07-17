<?php
/**
 * WP-CLI commands for Shift64 Woo Search.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * WP-CLI commands for Shift64 Woo Search.
 */
class Shift64_Woo_Search_CLI {

	/**
	 * Register all CLI commands.
	 */
	public static function register_commands() {
		WP_CLI::add_command( 'shift64-woo-search setup', array( __CLASS__, 'setup' ) );
		WP_CLI::add_command( 'shift64-woo-search reindex', array( __CLASS__, 'reindex' ) );
		WP_CLI::add_command( 'shift64-woo-search status', array( __CLASS__, 'status' ) );
		WP_CLI::add_command( 'shift64-woo-search rebuild', array( __CLASS__, 'rebuild' ) );
		WP_CLI::add_command( 'shift64-woo-search test', array( __CLASS__, 'test' ) );
		WP_CLI::add_command( 'shift64-woo-search health', array( __CLASS__, 'health' ) );
	}

	/**
	 * Configure Redis connection for Shift64 Woo Search.
	 *
	 * Tests the connection, saves to wp_options, and regenerates the SHORTINIT
	 * config file. Settings are saved immediately after a successful connection test.
	 *
	 * ## OPTIONS
	 *
	 * [--host=<host>]
	 * : Redis host. Default: 127.0.0.1
	 *
	 * [--port=<port>]
	 * : Redis port. Default: 6379
	 *
	 * [--username=<username>]
	 * : Redis ACL username (Redis 6+). Default: (empty — uses default user)
	 *
	 * [--password=<password>]
	 * : Redis password. Default: (empty)
	 *
	 * [--db=<db>]
	 * : Redis database number. Default: 0
	 *
	 * [--prefix=<prefix>]
	 * : Key prefix for this site. Default: shift64_woo_search
	 *
	 * ## EXAMPLES
	 *
	 *     wp shift64-woo-search setup
	 *     wp shift64-woo-search setup --host=127.0.0.1 --port=6380 --prefix=shift64_woo_search
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function setup( $args, $assoc_args ) {
		WP_CLI::log( '' );
		WP_CLI::log( '== Shift64 Woo Search — Redis Setup ==' );
		WP_CLI::log( '' );

		// Current values (if any).
		$current_host   = get_option( 'shift64_woo_search_redis_host', '' );
		$current_port   = get_option( 'shift64_woo_search_redis_port', '' );
		$current_user   = get_option( 'shift64_woo_search_redis_username', '' );
		$current_pass   = get_option( 'shift64_woo_search_redis_password', '' );
		$current_db     = get_option( 'shift64_woo_search_redis_db', '' );
		$current_prefix = get_option( 'shift64_woo_search_redis_prefix', '' );

		// Resolve values: flag > current > suggested default.
		$host   = isset( $assoc_args['host'] ) ? $assoc_args['host'] : ( $current_host ? $current_host : '127.0.0.1' );
		$port   = isset( $assoc_args['port'] ) ? (int) $assoc_args['port'] : ( $current_port ? $current_port : 6379 );
		$user   = isset( $assoc_args['username'] ) ? $assoc_args['username'] : $current_user;
		$pass   = isset( $assoc_args['password'] ) ? $assoc_args['password'] : $current_pass;
		$db     = isset( $assoc_args['db'] ) ? (int) $assoc_args['db'] : ( $current_db !== '' ? (int) $current_db : 0 );
		$prefix = isset( $assoc_args['prefix'] ) ? $assoc_args['prefix'] : ( $current_prefix ? $current_prefix : 'shift64_woo_search' );

		WP_CLI::log( "  Host:     {$host}" );
		WP_CLI::log( "  Port:     {$port}" );
		WP_CLI::log( '  Username: ' . ( $user ? $user : '(default)' ) );
		WP_CLI::log( '  Password: ' . ( $pass ? '****' : '(none)' ) );
		WP_CLI::log( "  DB:       {$db}" );
		WP_CLI::log( "  Prefix:   {$prefix}" );
		WP_CLI::log( '' );

		// Test connection before saving.
		WP_CLI::log( 'Testing connection...' );

		if ( ! class_exists( 'Redis' ) ) {
			WP_CLI::error( 'phpredis extension is not installed.' );
		}

		try {
			$test = new Redis();
			$test->connect( $host, (int) $port, 2 );
			if ( ! empty( $pass ) ) {
				if ( '' !== $user ) {
					$test->auth( array( $user, $pass ) );
				} else {
					$test->auth( $pass );
				}
			}
			if ( $db > 0 ) {
				$test->select( $db );
			}
			$pong = $test->ping( '+PONG' );
			if ( '+PONG' !== $pong ) {
				WP_CLI::error( 'Redis responded but PING failed.' );
			}

			// Check for RediSearch module.
			$has_search = false;
			$modules    = $test->rawCommand( 'MODULE', 'LIST' );
			if ( is_array( $modules ) ) {
				foreach ( $modules as $module ) {
					if ( is_array( $module ) ) {
						foreach ( $module as $val ) {
							if ( is_string( $val ) && ( stripos( $val, 'search' ) !== false || stripos( $val, 'ft' ) !== false ) ) {
								$has_search = true;
								break 2;
							}
						}
					}
				}
			}
			$test->close();

			WP_CLI::log( 'Redis connection: OK' );
			if ( $has_search ) {
				WP_CLI::log( 'RediSearch module: OK' );
			} else {
				WP_CLI::warning( 'RediSearch module not detected. FT.* commands may not work.' );
			}
		} catch ( RedisException $e ) {
			WP_CLI::error( 'Connection failed: ' . $e->getMessage() );
		}

		WP_CLI::log( '' );

		// Save to wp_options immediately after successful connection test.
		update_option( 'shift64_woo_search_redis_host', $host );
		update_option( 'shift64_woo_search_redis_port', (int) $port );
		update_option( 'shift64_woo_search_redis_username', $user );
		update_option( 'shift64_woo_search_redis_password', $pass );
		update_option( 'shift64_woo_search_redis_db', (int) $db );
		update_option( 'shift64_woo_search_redis_prefix', $prefix );

		WP_CLI::log( 'Settings saved to wp_options.' );

		// Reset Redis singleton so it picks up new config.
		Shift64_Woo_Search_Redis::reset_instance();

		// Install/update mu-plugin files and regenerate config.
		$plugin = Shift64_Woo_Search_Plugin::get_instance();
		$plugin->install_mu_plugin();
		$plugin->generate_mu_plugin_config();
		WP_CLI::log( 'mu-plugin installed + SHORTINIT config regenerated.' );

		WP_CLI::log( '' );
		WP_CLI::success( 'Redis configured. Next step: wp shift64-woo-search reindex --all' );
	}

	/**
	 * Reindex products into RediSearch.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Reindex all products.
	 *
	 * [--id=<product_id>]
	 * : Reindex a specific product by ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shift64-woo-search reindex --all
	 *     wp shift64-woo-search reindex --id=123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function reindex( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		$redis = Shift64_Woo_Search_Redis::get_instance();

		if ( ! $redis->is_available() ) {
			WP_CLI::error( 'Redis is not available. Check your connection settings.' );
		}

		$indexer = new Shift64_Woo_Search_Indexer( $redis );

		// Single product reindex.
		if ( ! empty( $assoc_args['id'] ) ) {
			$product_id = (int) $assoc_args['id'];
			WP_CLI::log( "Reindexing product #{$product_id}..." );

			if ( $indexer->index_product( $product_id ) ) {
				WP_CLI::success( "Product #{$product_id} indexed." );
			} else {
				WP_CLI::error( "Failed to index product #{$product_id}. Product may not exist or be non-published." );
			}
			return;
		}

		// Ensure index exists.
		if ( ! Shift64_Woo_Search_Schema::index_exists( $redis ) ) {
			WP_CLI::log( 'Index does not exist. Creating...' );
			if ( ! Shift64_Woo_Search_Schema::create_index( $redis ) ) {
				WP_CLI::error( 'Failed to create index.' );
			}
			WP_CLI::log( 'Index created.' );

			// Sync synonyms + suggestions + categories after index creation.
			$synced = Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
			if ( $synced > 0 ) {
				WP_CLI::log( "Synced {$synced} synonym groups." );
			}
			Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
			Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis );
		}

		// Full reindex.
		WP_CLI::log( 'Starting full reindex...' );

		$progress = null;
		$count    = $indexer->reindex_all(
			function ( $indexed, $total ) use ( &$progress ) {
				if ( null === $progress ) {
						$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing products', $total );
				}
				// Update progress bar to current position.
				static $last = 0;
				$diff        = $indexed - $last;
				for ( $i = 0; $i < $diff; $i++ ) {
					$progress->tick();
				}
				$last = $indexed;
			}
		);

		if ( $progress ) {
			$progress->finish();
		}

		WP_CLI::success( "{$count} products indexed." );
	}

	/**
	 * Show index status and Redis info.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shift64-woo-search status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$redis = Shift64_Woo_Search_Redis::get_instance();

		if ( ! $redis->is_available() ) {
			WP_CLI::warning( 'Redis is not available.' );
			return;
		}

		$info = Shift64_Woo_Search_Schema::get_index_info( $redis );

		if ( ! $info ) {
			WP_CLI::warning( 'Index does not exist. Run: wp shift64-woo-search reindex --all' );
			return;
		}

		$data = array();

		$fields = array(
			'index_name'          => 'Index Name',
			'num_docs'            => 'Documents',
			'num_terms'           => 'Terms',
			'num_records'         => 'Records',
			'inverted_sz_mb'      => 'Index Size (MB)',
			'total_indexing_time' => 'Indexing Time (s)',
		);

		foreach ( $fields as $key => $label ) {
			if ( isset( $info[ $key ] ) ) {
				$data[] = array(
					'Field' => $label,
					'Value' => $info[ $key ],
				);
			}
		}

		// WooCommerce product count for comparison.
		$wc_count  = wp_count_posts( 'product' );
		$published = isset( $wc_count->publish ) ? $wc_count->publish : 0;
		$data[]    = array(
			'Field' => 'WC Published Products',
			'Value' => $published,
		);

		WP_CLI\Utils\format_items( 'table', $data, array( 'Field', 'Value' ) );
	}

	/**
	 * Drop and recreate the index, then reindex all products.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shift64-woo-search rebuild
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function rebuild( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$redis = Shift64_Woo_Search_Redis::get_instance();

		if ( ! $redis->is_available() ) {
			WP_CLI::error( 'Redis is not available.' );
		}

		// Drop existing index.
		if ( Shift64_Woo_Search_Schema::index_exists( $redis ) ) {
			WP_CLI::log( 'Dropping existing index...' );
			Shift64_Woo_Search_Schema::drop_index( $redis );
		}

		// Create new index.
		WP_CLI::log( 'Creating index with current schema...' );
		if ( ! Shift64_Woo_Search_Schema::create_index( $redis ) ) {
			WP_CLI::error( 'Failed to create index.' );
		}
		WP_CLI::log( 'Index created.' );

		// Sync synonyms + suggestions + categories.
		$synced = Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
		if ( $synced > 0 ) {
			WP_CLI::log( "Synced {$synced} synonym groups." );
		}
		$sug_count = Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
		WP_CLI::log( "Synced {$sug_count} suggestions." );
		$cat_count = Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis );
		WP_CLI::log( "Cached {$cat_count} product categories." );

		// Reindex.
		WP_CLI::log( 'Starting full reindex...' );
		$indexer = new Shift64_Woo_Search_Indexer( $redis );

		$progress = null;
		$count    = $indexer->reindex_all(
			function ( $indexed, $total ) use ( &$progress ) {
				if ( null === $progress ) {
						$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing products', $total );
				}
				static $last = 0;
				$diff        = $indexed - $last;
				for ( $i = 0; $i < $diff; $i++ ) {
					$progress->tick();
				}
				$last = $indexed;
			}
		);

		if ( $progress ) {
			$progress->finish();
		}

		// Regenerate SHORTINIT config to reflect any schema changes.
		Shift64_Woo_Search_Plugin::get_instance()->generate_mu_plugin_config();

		WP_CLI::success( "Rebuild complete. {$count} products indexed." );
	}

	/**
	 * Test a search query.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : The search query to test.
	 *
	 * [--mode=<mode>]
	 * : Search mode: autocomplete or full.
	 * ---
	 * default: autocomplete
	 * options:
	 *   - autocomplete
	 *   - full
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of results.
	 * ---
	 * default: 7
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp shift64-woo-search test "Athena T-Shirt Green"
	 *     wp shift64-woo-search test "DEMO640001" --mode=full
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function test( $args, $assoc_args ) {
		$redis = Shift64_Woo_Search_Redis::get_instance();

		if ( ! $redis->is_available() ) {
			WP_CLI::error( 'Redis is not available.' );
		}

		$query_text = $args[0];
		$mode       = isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : 'autocomplete';
		$limit      = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 7;

		// Load config from options.
		$config = array(
			'min_query_length'         => (int) get_option( 'shift64_woo_search_min_query', 2 ),
			'autocomplete_limit'       => (int) get_option( 'shift64_woo_search_autocomplete_limit', 7 ),
			'full_limit'               => (int) get_option( 'shift64_woo_search_full_limit', 20 ),
			'outofstock_mode'          => get_option( 'shift64_woo_search_outofstock_mode', 'exclude' ),
			'outofstock_demote_factor' => (float) get_option( 'shift64_woo_search_outofstock_demote_factor', 0.3 ),
			'fuzzy_level'              => (int) get_option( 'shift64_woo_search_fuzzy_level', 1 ),
			'logic'                    => get_option( 'shift64_woo_search_logic', 'OR' ),
			'category_boost_rules'     => get_option( 'shift64_woo_search_category_boost_rules', '' ),
		);

		$search  = new Shift64_Woo_Search_Query( $redis, $config );
		$results = $search->search( $query_text, $mode, $limit );

		WP_CLI::log( '' );
		WP_CLI::log( "Query: \"{$query_text}\"" );
		WP_CLI::log( "Mode: {$mode}" );
		WP_CLI::log( "Time: {$results['time_ms']}ms" );
		WP_CLI::log( "Results: {$results['count']}" );
		WP_CLI::log( '' );

		if ( 'autocomplete' === $mode && ! empty( $results['results'] ) ) {
			$table_data = array();
			foreach ( $results['results'] as $i => $r ) {
				$table_data[] = array(
					'#'        => $i + 1,
					'ID'       => $r['id'],
					'Title'    => mb_substr( $r['title'], 0, 50 ),
					'SKU'      => $r['sku'],
					'Category' => mb_substr( $r['category'], 0, 30 ),
					'Score'    => round( $r['score'], 2 ),
				);
			}
			WP_CLI\Utils\format_items( 'table', $table_data, array( '#', 'ID', 'Title', 'SKU', 'Category', 'Score' ) );
		} elseif ( 'full' === $mode && ! empty( $results['post_ids'] ) ) {
			WP_CLI::log( 'Post IDs: ' . implode( ', ', $results['post_ids'] ) );
			WP_CLI::log( 'Redirect: ' . $results['redirect'] );
		} else {
			WP_CLI::warning( 'No results found.' );
		}

		// Show the raw FT.SEARCH query for debugging.
		$sanitized = $search->sanitize_query( $query_text );
		$ft_query  = $search->build_ft_query( $sanitized );
		WP_CLI::log( '' );
		WP_CLI::log( "FT.SEARCH query: {$ft_query}" );
	}

	/**
	 * Run a health check on the Redis connection and RediSearch module.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shift64-woo-search health
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function health( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Check phpredis extension.
		if ( ! class_exists( 'Redis' ) ) {
			WP_CLI::error( 'phpredis extension is not installed.' );
		}
		WP_CLI::log( 'phpredis extension: OK' );

		$redis = Shift64_Woo_Search_Redis::get_instance();

		// Check if configured.
		if ( ! $redis->is_configured() ) {
			WP_CLI::error( 'Redis not configured. Run: wp shift64-woo-search setup' );
		}
		WP_CLI::log( 'Redis configured: OK' );

		// Check connection.
		if ( ! $redis->ping() ) {
			$error = $redis->get_last_error();
			WP_CLI::error( 'Redis unavailable' . ( $error ? ": {$error}" : '.' ) );
		}
		WP_CLI::log( 'Redis connection: OK' );

		// Check RediSearch module.
		$client = $redis->get_client();
		if ( $client ) {
			try {
				$modules    = $client->rawCommand( 'MODULE', 'LIST' );
				$has_search = false;
				if ( is_array( $modules ) ) {
					foreach ( $modules as $module ) {
						if ( is_array( $module ) ) {
							foreach ( $module as $val ) {
								if ( is_string( $val ) && ( stripos( $val, 'search' ) !== false || stripos( $val, 'ft' ) !== false ) ) {
									$has_search = true;
									break 2;
								}
							}
						}
					}
				}

				if ( $has_search ) {
					WP_CLI::log( 'RediSearch module: OK' );
				} else {
					WP_CLI::warning( 'RediSearch module not detected. FT.* commands may not work.' );
				}
			} catch ( \Exception $e ) {
				WP_CLI::warning( 'Could not check modules: ' . $e->getMessage() );
			}
		}

		// Check index.
		if ( Shift64_Woo_Search_Schema::index_exists( $redis ) ) {
			WP_CLI::log( 'Index: OK' );
		} else {
			WP_CLI::warning( 'Index does not exist. Run: wp shift64-woo-search reindex --all' );
		}

		// Memory usage.
		if ( $client ) {
			try {
				$mem_info = $client->info( 'memory' );
				$used     = isset( $mem_info['used_memory_human'] ) ? $mem_info['used_memory_human'] : '?';
				$peak     = isset( $mem_info['used_memory_peak_human'] ) ? $mem_info['used_memory_peak_human'] : '?';
				WP_CLI::log( "Redis memory: {$used} (peak: {$peak})" );
			} catch ( \Exception $e ) {
				WP_CLI::warning( 'Could not read memory info: ' . $e->getMessage() );
			}
		}

		// WooCommerce product count.
		$wc_count  = wp_count_posts( 'product' );
		$published = isset( $wc_count->publish ) ? $wc_count->publish : 0;
		WP_CLI::log( "WC published products: {$published}" );

		WP_CLI::success( 'Health check complete.' );
	}
}
