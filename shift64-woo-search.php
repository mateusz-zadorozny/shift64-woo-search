<?php
/**
 * Plugin Name: Shift64 Woo Search
 * Description: Custom WooCommerce search engine powered by RediSearch. Ultra-fast autocomplete and full-text search.
 * Plugin URI: https://shift64.com
 * Version: 0.21.1
 * Author: Mateusz Zadorożny
 * Author URI: https://shift64.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shift64-woo-search
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * WC requires at least: 10.9
 * WC tested up to: 11.0
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants.
define( 'SHIFT64_WOO_SEARCH_VERSION', '0.21.1' );
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

/**
 * Declare compatibility with the WooCommerce features this plugin coexists with.
 *
 * WooCommerce treats a plugin that says nothing as *uncertain*, and lists it on
 * the Plugins screen under "Incompatible with WooCommerce features" — the same
 * bucket as a plugin that is genuinely broken by HPOS. Silence is the only
 * reason this plugin appears there: it is a catalog search engine that never
 * reads or writes an order, a cart or a checkout, so nothing in it can be
 * affected by the order-storage or block-checkout switches.
 *
 * Declared on `before_woocommerce_init` because that is the only point at which
 * the features controller accepts a declaration.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $feature, __FILE__, true );
		}
	}
);

// Include files.
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-requirements.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-legacy-shortcodes.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-redis.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-schema.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-stats.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-indexer.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-rebuild.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-settings.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-deprecations.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-query.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-sort.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-product-collection-context.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-catalog-state.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-product-collection-result.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-product-collection-results.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-product-collection-query-service.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-product-collection-query.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-catalog-navigation.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-category-suggest.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-brand-suggest.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-synonyms.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-suggestions.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-sync.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/interface-shift64-woo-search-facet-context.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-facet-registry.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-facets.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-facet-eligibility.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-facet-count-provider.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-editor-facets-rest.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-archive.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-attribute-auto-register.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-taxonomy-archive.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'frontend/class-shift64-woo-search-frontend.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-pill-style.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-filter-blocks.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'includes/class-shift64-woo-search-blocks.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'admin/class-shift64-woo-search-admin-routes.php';
require_once SHIFT64_WOO_SEARCH_PATH . 'admin/class-shift64-woo-search-admin-settings.php';
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
		update_option( 'shift64_woo_search_db_version', self::DB_VERSION );
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

		Shift64_Woo_Search_Legacy_Shortcodes::forget();

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

		// Admin notices for Redis status, the runtime baseline, leftover
		// shortcodes, and the block-theme-only upgrade announcement.
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_upgrade_notice' ) );

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

		// Blocks register in the editor as well as on the storefront. The frontend
		// object is no longer a classic renderer of its own — it is the asset
		// loader the childless-parent block fallback calls, and it hooks nothing.
		//
		// Below the declared WordPress/WooCommerce floor the block half of the
		// plugin does not boot at all: registering blocks against a block or
		// Interactivity API that is not there is how a version mismatch becomes a
		// fatal. Search, indexing, the SHORTINIT endpoint, the admin screens and
		// the CLI are unaffected, and admin_notices() explains what to upgrade.
		$requirements_met = Shift64_Woo_Search_Requirements::are_met();

		if ( $requirements_met ) {
			$frontend = new Shift64_Woo_Search_Frontend();
			new Shift64_Woo_Search_Blocks( $frontend );
			new Shift64_Woo_Search_Catalog_Navigation();
			new Shift64_Woo_Search_Editor_Facets_Rest();
		}

		// Storefront query integrations. Every one of these adapts a query or
		// publishes data to a block; none of them places markup in a theme.
		if ( ! is_admin() ) {
			new Shift64_Woo_Search_Archive();
			new Shift64_Woo_Search_Taxonomy_Archive();

			if ( $requirements_met ) {
				new Shift64_Woo_Search_Product_Collection_Query();
			}
		}

		// WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Shift64_Woo_Search_CLI::register_commands();
		}
	}

	/**
	 * Identifier for the block-theme-only upgrade notice.
	 *
	 * An identifier rather than a version: a patch release must not resurrect a
	 * notice somebody already dismissed, and a later breaking release should be
	 * able to raise its own without disturbing this one.
	 */
	const UPGRADE_NOTICE_ID = 'block-theme-only';

	/**
	 * User meta key recording which upgrade notice a user dismissed.
	 */
	const UPGRADE_NOTICE_META = 'shift64_woo_search_upgrade_notice_dismissed';

	/**
	 * Query argument that dismisses the upgrade notice.
	 */
	const UPGRADE_NOTICE_QUERY_ARG = 'shift64_woo_search_dismiss_upgrade_notice';

	/**
	 * Where the upgrade notice sends a merchant for the full migration steps.
	 */
	const MIGRATION_GUIDE_URL = 'https://github.com/mateusz-zadorozny/shift64-woo-search/blob/main/docs/block-theme-migration.md';

	/**
	 * Current data version. Bump whenever an upgrade needs to do work beyond
	 * creating the stats table, and add the matching entry to
	 * get_db_upgrade_actions().
	 */
	const DB_VERSION = '1.2';

	/**
	 * Map a data version to the action an install must run to reach it.
	 *
	 * Two actions exist:
	 *
	 * - 'rebuild' — the RediSearch schema changed, so the live index is stale
	 *   and must be dropped and recreated. ensure_index_healthy() cannot detect
	 *   field-level drift (it only checks existence and doc count), so without
	 *   this an upgraded install would silently return nothing for queries
	 *   against the new field.
	 * - 'blobs'   — only a Redis blob the endpoint reads changed. Cheap, and no
	 *   reindex is needed.
	 *
	 * @return array<string,string> Version => action.
	 */
	private function get_db_upgrade_actions() {
		return array(
			// brands_text TEXT field added to the product index.
			'1.1' => 'rebuild',
			// {prefix}:brands suggestion blob added.
			'1.2' => 'blobs',
		);
	}

	/**
	 * Create database tables and run version-gated upgrade actions if needed.
	 */
	private function maybe_create_tables() {
		$db_version = get_option( 'shift64_woo_search_db_version' );

		if ( $db_version === self::DB_VERSION ) {
			return;
		}

		Shift64_Woo_Search_Stats::create_table();

		// Collect every action between the stored version and the current one.
		// A fresh install (no stored version) is already built from the current
		// schema by activate(), so it needs none of them.
		$actions = array();
		if ( ! empty( $db_version ) ) {
			foreach ( $this->get_db_upgrade_actions() as $version => $action ) {
				if ( version_compare( $db_version, $version, '<' ) ) {
					$actions[ $action ] = true;
				}
			}
		}

		// A full rebuild also refreshes the blobs, so it subsumes 'blobs'.
		if ( isset( $actions['rebuild'] ) ) {
			// Deferred to WP-Cron rather than run inline: a rebuild reindexes the
			// whole catalog and must not block the request that triggered it. The
			// auto-rebuild handler already carries the concurrency lock.
			if ( ! wp_next_scheduled( Shift64_Woo_Search_Attribute_Auto_Register::REBUILD_HOOK ) ) {
				wp_schedule_single_event( time() + 60, Shift64_Woo_Search_Attribute_Auto_Register::REBUILD_HOOK );
			}
		} elseif ( isset( $actions['blobs'] ) ) {
			$redis = Shift64_Woo_Search_Redis::get_instance();
			if ( $redis && $redis->is_available() ) {
				Shift64_Woo_Search_Rebuild::cache_blobs( $redis );
			}
		}

		update_option( 'shift64_woo_search_db_version', self::DB_VERSION );
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
			'shift64_woo_search_logic'                    => 'AND',
			'shift64_woo_search_input_selector'           => '.shift64-woo-search-field__input',
			// Phase 4: Search strategy defaults.
			'shift64_woo_search_strategy'                 => 'mixed',
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
		$config .= "define( 'SHIFT64_WOO_SEARCH_LOGIC', " . var_export( get_option( 'shift64_woo_search_logic', 'AND' ), true ) . " );\n";

		// Phase 4: Search strategy constants.
		$config .= "define( 'SHIFT64_WOO_SEARCH_STRATEGY', " . var_export( get_option( 'shift64_woo_search_strategy', 'mixed' ), true ) . " );\n";
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

		// Brand suggestions. Defaults to on: harmless on a store with no brands,
		// where the blob is empty and the dropdown section stays hidden.
		$config .= "define( 'SHIFT64_WOO_SEARCH_BRAND_SUGGEST', " . var_export( get_option( 'shift64_woo_search_brand_suggest_enabled', 'yes' ) === 'yes', true ) . " );\n";

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
	 * Explain an unsupported runtime, without ever breaking one.
	 *
	 * Two distinct situations, deliberately worded differently. Below the version
	 * floor the block half of the plugin is switched off and the notice is an
	 * error, because storefront controls really are missing. On a supported
	 * version with a classic theme nothing is broken at all — the plugin simply
	 * renders no storefront controls, which is the intended outcome of the
	 * block-theme-only release — so that notice is informational and points at
	 * the migration guide.
	 *
	 * Both are shown only to users who can manage the plugin, since neither is
	 * actionable by anyone else.
	 */
	private function render_runtime_requirement_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$unmet = Shift64_Woo_Search_Requirements::unmet();

		if ( ! empty( $unmet ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Shift64 Woo Search:', 'shift64-woo-search' ); ?></strong>
					<?php esc_html_e( 'Storefront blocks are switched off until this site meets the plugin\'s runtime requirements. Search, indexing and the CLI keep working.', 'shift64-woo-search' ); ?>
				</p>
				<ul>
					<?php foreach ( $unmet as $requirement ) : ?>
						<li><?php echo esc_html( $requirement ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
			return;
		}

		if ( ! Shift64_Woo_Search_Requirements::block_theme_active() ) {
			?>
			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( 'Shift64 Woo Search:', 'shift64-woo-search' ); ?></strong>
					<?php esc_html_e( 'This site runs a classic theme. Search, indexing and the CLI work normally, but the storefront controls are Site Editor blocks and are not injected into a classic theme. Switch to a block theme and place the blocks to use them.', 'shift64-woo-search' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Announce the block-theme-only storefront and link the migration guide.
	 *
	 * This release changes what a storefront looks like, and it does so on
	 * update rather than on any action the merchant took. A changelog entry is
	 * not enough: most merchants never read one. So the plugin says it in WP
	 * Admin, once, to the people who can act on it.
	 *
	 * Dismissal is per user and permanent, keyed by an identifier rather than a
	 * version number so that a later release can raise a different notice without
	 * un-dismissing this one, and so that a patch release does not resurrect it.
	 */
	private function render_upgrade_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::UPGRADE_NOTICE_ID === get_user_meta( get_current_user_id(), self::UPGRADE_NOTICE_META, true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::UPGRADE_NOTICE_QUERY_ARG, '1' ),
			self::UPGRADE_NOTICE_QUERY_ARG
		);

		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Shift64 Woo Search: the storefront is now block-only.', 'shift64-woo-search' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Search, filters, sorting and the results grid are Site Editor blocks placed in your block templates. The classic-theme shortcodes and the controls this plugin used to inject into a theme have been removed, so a storefront that relied on them needs its templates edited once.', 'shift64-woo-search' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( self::MIGRATION_GUIDE_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Read the migration guide', 'shift64-woo-search' ); ?>
				</a>
				<span aria-hidden="true"> | </span>
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'shift64-woo-search' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Record a per-user dismissal of the upgrade notice.
	 *
	 * Hooked to `admin_init` so the redirect happens before any output. The nonce
	 * is what stops the dismissal being triggered for someone else by a crafted
	 * link, and the capability check is repeated here because a nonce proves who
	 * sent the request, not what they are allowed to do.
	 */
	public function maybe_dismiss_upgrade_notice() {
		if ( ! isset( $_GET[ self::UPGRADE_NOTICE_QUERY_ARG ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::UPGRADE_NOTICE_QUERY_ARG ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::UPGRADE_NOTICE_META, self::UPGRADE_NOTICE_ID );

		$redirect = remove_query_arg( array( self::UPGRADE_NOTICE_QUERY_ARG, '_wpnonce' ) );

		if ( wp_safe_redirect( $redirect ) ) {
			exit;
		}
	}

	/**
	 * Report removed shortcode tags still sitting in content.
	 *
	 * A tag that is no longer registered prints as literal text on the
	 * storefront, with nothing anywhere to say why. This notice is the one
	 * release of grace period: it names the posts so they can be fixed, and it
	 * never renders the tag. Shown only to users who can manage the plugin, and
	 * only when there is something to report — the scan behind it is cached, so
	 * an ordinary admin page load costs one transient read.
	 */
	private function render_legacy_shortcode_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$occurrences = Shift64_Woo_Search_Legacy_Shortcodes::find();

		if ( empty( $occurrences ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Shift64 Woo Search:', 'shift64-woo-search' ); ?></strong>
				<?php esc_html_e( 'These entries still contain search shortcodes that this version removed. A removed shortcode is printed as plain text on the storefront, so replace each one with the matching Site Editor block.', 'shift64-woo-search' ); ?>
			</p>
			<ul>
				<?php foreach ( $occurrences as $occurrence ) : ?>
					<li>
						<?php
						$edit_link = get_edit_post_link( $occurrence['id'] );
						$title     = '' !== $occurrence['title']
							? $occurrence['title']
							/* translators: %d: post ID. */
							: sprintf( __( 'Untitled (#%d)', 'shift64-woo-search' ), $occurrence['id'] );

						if ( $edit_link ) {
							printf(
								'<a href="%1$s">%2$s</a>',
								esc_url( $edit_link ),
								esc_html( $title )
							);
						} else {
							echo esc_html( $title );
						}

						echo ' — ';
						echo esc_html( '[' . implode( '] [', $occurrence['tags'] ) . ']' );
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Display admin notices for Redis connection status.
	 */
	public function admin_notices() {
		$this->render_upgrade_notice();
		$this->render_runtime_requirement_notices();
		$this->render_legacy_shortcode_notice();

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
