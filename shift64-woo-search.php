<?php
/**
 * Plugin Name: Shift64 Woo Search
 * Description: Custom WooCommerce search engine powered by RediSearch. Ultra-fast autocomplete and full-text search.
 * Version: 0.1.0
 * Author: Mateusz Zadorożny
 * Text Domain: shift64-woo-search
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.3
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants.
define( 'SHIFT64_WOO_SEARCH_VERSION', '0.1.0' );
define( 'SHIFT64_WOO_SEARCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHIFT64_WOO_SEARCH_URL', plugin_dir_url( __FILE__ ) );

// Check WooCommerce is active.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	add_action( 'admin_notices', 'shift64_woo_search_woocommerce_inactive_notice' );
	return;
}

/**
 * Display admin notice when WooCommerce is not active.
 */
function shift64_woo_search_woocommerce_inactive_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Shift64 Woo Search requires WooCommerce to be active.', 'shift64-woo-search' ); ?></p>
	</div>
	<?php
}

// Include files.
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-redis.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-schema.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-stats.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-indexer.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-query.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-category-suggest.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-synonyms.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-suggestions.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-sync.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/interface-shift64-woo-search-facet-context.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-facet-registry.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-facets.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-archive.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-attribute-auto-register.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-taxonomy-archive.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'frontend/class-shift64-woo-search-frontend.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'frontend/class-shift64-woo-search-filters.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'admin/class-shift64-woo-search-admin.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'cli/class-shift64-woo-search-cli.php';

/**
 * Main plugin class.
 */
class Shift64_Woo_Search_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Shift64_Woo_Search_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Shift64_Woo_Search_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
	}

	/**
	 * Load plugin translations from /languages.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'shift64-woo-search', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Re-deploy mu-plugin files after plugin update.
	 *
	 * @param object $upgrader WP_Upgrader instance.
	 * @param array  $options  Update details.
	 */
	public function on_upgrade( $upgrader, $options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( 'update' !== ( $options['action'] ?? '' ) || 'plugin' !== ( $options['type'] ?? '' ) ) {
			return;
		}

		$our_plugin = plugin_basename( __FILE__ );
		$plugins    = $options['plugins'] ?? array();

		if ( is_array( $plugins ) && in_array( $our_plugin, $plugins, true ) ) {
			$this->install_mu_plugin();
			$this->generate_mu_plugin_config();
		}
	}

	/**
	 * Plugin activation callback.
	 */
	public function activate() {
		Shift64_Woo_Search_Stats::create_table();
		update_option( 'shift64_woo_search_db_version', '1.0' );
		$this->set_default_options();
		$this->install_mu_plugin();
		$this->generate_mu_plugin_config();
	}

	/**
	 * Plugin deactivation callback.
	 */
	public function deactivate() {
		// Keep mu-plugin files — they're harmless without the main plugin
		// and removing them could break other sites on multisite.
	}

	/**
	 * Copy mu-plugin files from plugin to wp-content/mu-plugins/.
	 *
	 * @return bool True if all files copied successfully.
	 */
	public function install_mu_plugin() {
		$source_dir = SHIFT64_WOO_SEARCH_PATH . 'mu-plugins/';
		$target_dir = WP_CONTENT_DIR . '/mu-plugins/shift64-woo-search/';

		if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
			return false;
		}

		$files = array(
			'endpoint.php' => $target_dir . 'endpoint.php',
		);

		$ok = true;
		foreach ( $files as $file => $target ) {
			$source = $source_dir . $file;
			if ( ! file_exists( $source ) ) {
				continue;
			}
			if ( ! self::copy_if_changed( $source, $target ) ) {
				$ok = false;
			}
		}

		// Bootstrap file goes to mu-plugins root.
		$bootstrap_source = $source_dir . 'shift64-woo-search-bootstrap.php';
		$bootstrap_target = WP_CONTENT_DIR . '/mu-plugins/shift64-woo-search-bootstrap.php';
		if ( file_exists( $bootstrap_source ) ) {
			if ( ! self::copy_if_changed( $bootstrap_source, $bootstrap_target ) ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Copy a file only if target is missing or has different content.
	 *
	 * Avoids the PHP `copy()` warning that surfaces in Sentry on read-only
	 * filesystems or with mismatched file ownership when the file is already
	 * up to date.
	 *
	 * @param string $source Absolute source path.
	 * @param string $target Absolute target path.
	 * @return bool True if target exists and matches source after the call.
	 */
	private static function copy_if_changed( $source, $target ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- Runtime guard against spammy copy() failures.
		if ( ! is_readable( $source ) ) {
			return false;
		}

		$source_hash = self::md5_file_quietly( $source );
		if ( false === $source_hash ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- Used only to avoid warning-prone hash checks.
		if ( file_exists( $target ) && is_readable( $target ) ) {
			$target_hash = self::md5_file_quietly( $target );
			if ( false !== $target_hash && $source_hash === $target_hash ) {
				return true;
			}
		}

		$target_dir = dirname( $target );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem requires admin credentials; runtime guard against spammy copy() failures.
		if ( ! is_writable( $target_dir ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- ditto.
		if ( file_exists( $target ) && ! is_writable( $target ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- writability checked above; suppress race-condition warning.
		return (bool) @copy( $source, $target );
	}

	/**
	 * Read an md5 hash without surfacing race-condition filesystem warnings.
	 *
	 * @param string $path Absolute file path.
	 * @return string|false File hash, or false when the file cannot be read.
	 */
	private static function md5_file_quietly( $path ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Caller checks readability first; suppress race-condition warning.
		return @md5_file( $path );
	}

	/**
	 * Write a file only if target is missing or has different content.
	 *
	 * @param string $target   Absolute target path.
	 * @param string $contents File contents.
	 * @return bool True if target exists and matches contents after the call.
	 */
	private static function write_file_if_changed( $target, $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- Used only to avoid warning-prone hash checks.
		if ( file_exists( $target ) && is_readable( $target ) ) {
			$target_hash = self::md5_file_quietly( $target );
			if ( false !== $target_hash && md5( $contents ) === $target_hash ) {
				return true;
			}
		}

		$target_dir = dirname( $target );
		if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem requires admin credentials; runtime guard against spammy write failures.
		if ( ! is_writable( $target_dir ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- ditto.
		if ( file_exists( $target ) && ! is_writable( $target ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Writability checked above; suppress race-condition warning.
		return false !== @file_put_contents( $target, $contents );
	}

	/**
	 * Auto-update mu-plugin files when plugin version changes.
	 *
	 * Covers git/SFTP deploys where upgrader_process_complete does not fire.
	 * On persistent failure (for example, an unwritable mu-plugins directory),
	 * back off for one hour to avoid retrying and logging on every request.
	 */
	private function maybe_update_mu_plugin() {
		$mu_version = defined( 'SHIFT64_WOO_SEARCH_MU_VERSION' ) ? SHIFT64_WOO_SEARCH_MU_VERSION : '';
		if ( $mu_version === SHIFT64_WOO_SEARCH_VERSION ) {
			return;
		}

		$backoff_key = 'shift64_woo_search_mu_install_backoff_' . SHIFT64_WOO_SEARCH_VERSION;
		if ( get_transient( $backoff_key ) ) {
			return;
		}

		if ( ! $this->install_mu_plugin() || ! $this->generate_mu_plugin_config() ) {
			set_transient( $backoff_key, 1, HOUR_IN_SECONDS );
			return;
		}
	}

	/**
	 * Initialize plugin on plugins_loaded.
	 */
	public function init() {
		$this->load_textdomain();
		$this->maybe_create_tables();
		$this->maybe_update_mu_plugin();

		// Admin notices for Redis status.
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		// Proactive heal: WP Redis Object Cache flush drops all RediSearch indexes
		// (FLUSHDB on any db is instance-wide for RediSearch). Recreate ours immediately
		// so search isn't broken until the next query triggers lazy heal.
		add_action( 'redis_object_cache_flush', array( $this, 'on_object_cache_flush' ), 10, 0 );

		// Cron handler for the lazy reindex scheduled by Schema::ensure_index_healthy().
		add_action( 'shift64_woo_search_lazy_reindex', array( $this, 'run_lazy_reindex' ) );

		// Auto-sync hooks.
		new Shift64_Woo_Search_Sync();

		// Listen for shift64-woo-search-product-sync attribute UUID stamps / creations and
		// reconcile our facet allowlist + schema. Wired in any context (admin,
		// frontend, cron) because the action can fire anywhere ensure_attribute()
		// runs — most often on cron during the daily category-parameters sync.
		new Shift64_Woo_Search_Attribute_Auto_Register();

		// Admin panel.
		if ( is_admin() ) {
			new Shift64_Woo_Search_Admin();
		}

		// Frontend autocomplete and search.
		if ( ! is_admin() ) {
			new Shift64_Woo_Search_Frontend();
			new Shift64_Woo_Search_Archive();
			new Shift64_Woo_Search_Taxonomy_Archive();
			new Shift64_Woo_Search_Filters();
		}

		// WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Shift64_Woo_Search_CLI::register_commands();
		}
	}

	/**
	 * Create database tables if needed.
	 */
	private function maybe_create_tables() {
		$current_version = '1.0';
		$db_version      = get_option( 'shift64_woo_search_db_version' );

		if ( $db_version !== $current_version ) {
			Shift64_Woo_Search_Stats::create_table();
			update_option( 'shift64_woo_search_db_version', $current_version );
		}
	}

	/**
	 * Set default plugin options.
	 */
	private function set_default_options() {
		// Only search behavior defaults — NOT Redis connection.
		// Redis must be explicitly configured via wp shift64-woo-search setup or admin UI.
		$defaults = array(
			'shift64_woo_search_min_query'                => 2,
			'shift64_woo_search_autocomplete_limit'       => 7,
			'shift64_woo_search_full_limit'               => 20,
			'shift64_woo_search_debounce'                 => 150,
			'shift64_woo_search_outofstock_mode'          => 'exclude',
			'shift64_woo_search_outofstock_demote_factor' => 0.3,
			'shift64_woo_search_fuzzy_level'              => 1,
			'shift64_woo_search_logic'                    => 'OR',
			'shift64_woo_search_input_selector'           => '.shift64-woo-search-field__input',
			// Phase 4: Search strategy defaults.
			'shift64_woo_search_strategy'                 => 'strict_first',
			'shift64_woo_search_fallback_trigger'         => 'low_score',
			'shift64_woo_search_fallback_score_threshold' => 0.5,
			'shift64_woo_search_fallback_fuzzy_level'     => 1,
			'shift64_woo_search_token_reduction_enabled'  => 'yes',
			'shift64_woo_search_weak_tokens'              => 'do,na,z,i,w,od,po,za,ze,we,o,u,a,e',
			'shift64_woo_search_drop_trailing_weak_token_only' => 'yes',
			'shift64_woo_search_diacritics_normalization' => 'yes',
			'shift64_woo_search_fuzzy_synonyms'           => 'no',
			'shift64_woo_search_category_suggest_fuzzy'   => 'no',
			'shift64_woo_search_category_boost_rules'     => '',
			'shift64_woo_search_category_pin_rules'       => '',
			'shift64_woo_search_category_boosts'          => array(),
			'shift64_woo_search_category_suggest_exclude' => array(),
			'shift64_woo_search_filter_categories_excluded' => array(),
		);

		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value );
		}
	}

	/**
	 * Generate mu-plugin config file with current settings.
	 *
	 * @return bool True if config exists and matches current settings.
	 */
	public function generate_mu_plugin_config() {
		$config_path = WP_CONTENT_DIR . '/mu-plugins/shift64-woo-search/config.php';

		$host   = get_option( 'shift64_woo_search_redis_host', '127.0.0.1' );
		$port   = get_option( 'shift64_woo_search_redis_port', 6379 );
		$user   = get_option( 'shift64_woo_search_redis_username', '' );
		$pass   = get_option( 'shift64_woo_search_redis_password', '' );
		$db     = get_option( 'shift64_woo_search_redis_db', 0 );
		$prefix = get_option( 'shift64_woo_search_redis_prefix', 'shift64_woo_search' );

		$plugin_path = SHIFT64_WOO_SEARCH_PATH;

		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$config  = "<?php\n";
		$config .= '// Auto-generated by Shift64 Woo Search v' . SHIFT64_WOO_SEARCH_VERSION . ' at ' . gmdate( 'Y-m-d H:i:s' ) . " UTC. Do not edit manually.\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_REDIS_HOST', " . var_export( $host, true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_REDIS_PORT', " . var_export( (int) $port, true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_REDIS_USERNAME', " . var_export( $user, true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_REDIS_PASSWORD', " . var_export( $pass, true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_REDIS_DB', " . var_export( (int) $db, true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_REDIS_PREFIX', " . var_export( $prefix, true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_PLUGIN_PATH', " . var_export( $plugin_path, true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_MU_VERSION', " . var_export( SHIFT64_WOO_SEARCH_VERSION, true ) . " );\n";

		// Search behavior.
		$config .= "define( 'SHIFT64_WOO_SEARCH_MIN_QUERY', " . var_export( (int) get_option( 'shift64_woo_search_min_query', 2 ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_AUTOCOMPLETE_LIMIT', " . var_export( (int) get_option( 'shift64_woo_search_autocomplete_limit', 7 ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_FULL_LIMIT', " . var_export( (int) get_option( 'shift64_woo_search_full_limit', 20 ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_OUTOFSTOCK_MODE', " . var_export( get_option( 'shift64_woo_search_outofstock_mode', 'exclude' ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_OUTOFSTOCK_DEMOTE_FACTOR', " . var_export( (float) get_option( 'shift64_woo_search_outofstock_demote_factor', 0.3 ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_FUZZY_LEVEL', " . var_export( (int) get_option( 'shift64_woo_search_fuzzy_level', 1 ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_LOGIC', " . var_export( get_option( 'shift64_woo_search_logic', 'OR' ), true ) . " );\n";

		// Phase 4: Search strategy constants.
		$config .= "define( 'SHIFT64_WOO_SEARCH_STRATEGY', " . var_export( get_option( 'shift64_woo_search_strategy', 'strict_first' ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_FALLBACK_TRIGGER', " . var_export( get_option( 'shift64_woo_search_fallback_trigger', 'low_score' ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_FALLBACK_SCORE_THRESHOLD', " . var_export( (float) get_option( 'shift64_woo_search_fallback_score_threshold', 0.5 ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_FALLBACK_FUZZY_LEVEL', " . var_export( (int) get_option( 'shift64_woo_search_fallback_fuzzy_level', 1 ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_TOKEN_REDUCTION_ENABLED', " . var_export( get_option( 'shift64_woo_search_token_reduction_enabled', 'yes' ) === 'yes', true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_WEAK_TOKENS', " . var_export( get_option( 'shift64_woo_search_weak_tokens', 'do,na,z,i,w,od,po,za,ze,we,o,u,a,e' ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_DROP_TRAILING_WEAK_TOKEN_ONLY', " . var_export( get_option( 'shift64_woo_search_drop_trailing_weak_token_only', 'yes' ) === 'yes', true ) . " );\n";

		// Diacritics normalization.
		$config .= "define( 'SHIFT64_WOO_SEARCH_DIACRITICS_NORMALIZATION', " . var_export( get_option( 'shift64_woo_search_diacritics_normalization', 'yes' ) === 'yes', true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_FUZZY_SYNONYMS', " . var_export( get_option( 'shift64_woo_search_fuzzy_synonyms', 'no' ) === 'yes', true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_CATEGORY_SUGGEST_FUZZY', " . var_export( get_option( 'shift64_woo_search_category_suggest_fuzzy', 'no' ) === 'yes', true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_CATEGORY_BOOST_RULES', " . var_export( get_option( 'shift64_woo_search_category_boost_rules', '' ), true ) . " );\n";
		$config .= "define( 'SHIFT64_WOO_SEARCH_CATEGORY_PIN_RULES', " . var_export( get_option( 'shift64_woo_search_category_pin_rules', '' ), true ) . " );\n";

		// Filter attributes for faceted search.
		$config .= "define( 'SHIFT64_WOO_SEARCH_FILTER_ATTRIBUTES', " . var_export( implode( ',', Shift64_Woo_Search_Schema::get_filter_attributes() ), true ) . " );\n";

		// Rate limiting.
		$config .= "define( 'SHIFT64_WOO_SEARCH_RATE_LIMIT', " . var_export( (int) get_option( 'shift64_woo_search_rate_limit', 30 ), true ) . " );\n";
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export

		return self::write_file_if_changed( $config_path, $config );
	}

	/**
	 * Recreate the RediSearch index after WP Redis Object Cache flush.
	 * FLUSHDB is instance-wide for RediSearch — every OC flush kills our index.
	 */
	public function on_object_cache_flush() {
		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			return;
		}
		// Re-create the index. Hashes survive on db 0 with our prefix, so RediSearch
		// auto-backfills via PREFIX matching in ~250ms for medium catalogs.
		Shift64_Woo_Search_Schema::create_index( $redis );
	}

	/**
	 * Cron handler — runs full reindex when both index and product hashes are missing.
	 * Scheduled by Shift64_Woo_Search_Schema::ensure_index_healthy() as a defensive last resort.
	 *
	 * Reindex runs synchronously here. For large catalogs this can exceed PHP
	 * `max_execution_time`; WP-Cron typically runs with relaxed limits, but on hosts
	 * that cap it the index may be left partially populated — the next triggered heal
	 * will pick up where this one stopped (FT.CREATE is a no-op when the index exists,
	 * and reindex_all idempotently rewrites existing hashes).
	 */
	public function run_lazy_reindex() {
		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			return;
		}
		if ( ! Shift64_Woo_Search_Schema::index_exists( $redis ) ) {
			Shift64_Woo_Search_Schema::create_index( $redis );
		}
		$indexer = new Shift64_Woo_Search_Indexer( $redis );
		$indexer->reindex_all();
	}

	/**
	 * Display admin notices for Redis connection status.
	 */
	public function admin_notices() {
		$host = get_option( 'shift64_woo_search_redis_host', '' );

		if ( empty( $host ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Shift64 Woo Search:', 'shift64-woo-search' ); ?></strong>
					<?php esc_html_e( 'Redis connection not configured. Run: wp shift64-woo-search setup', 'shift64-woo-search' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Shift64 Woo Search:', 'shift64-woo-search' ); ?></strong>
					<?php
					printf(
						/* translators: %1$s: Redis host, %2$s: Redis port. */
						esc_html__( 'Cannot connect to Redis at %1$s:%2$s. Search will fall back to default WooCommerce search.', 'shift64-woo-search' ),
						esc_html( $host ),
						esc_html( get_option( 'shift64_woo_search_redis_port', '?' ) )
					);
					?>
				</p>
			</div>
			<?php
		}
	}
}


Shift64_Woo_Search_Plugin::get_instance();
