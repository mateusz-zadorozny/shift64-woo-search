<?php
/**
 * Tests for the version-gated db_version upgrade path in
 * Shift64_Woo_Search_Plugin::maybe_create_tables().
 *
 * This is the plugin's migration path: a stale RediSearch schema is invisible
 * to ensure_index_healthy() (it checks existence and doc count only), so an
 * upgraded install relies entirely on the version→action map to schedule the
 * rebuild that makes a new indexed field queryable.
 *
 * The blobs-only branch is deliberately not invoked here: it talks to the live
 * Redis singleton, and a developer machine running Redis on the default port
 * would get its blobs overwritten with test-database content. Its classifier
 * is locked by the map assertions instead, and cache_blobs() itself is covered
 * by the indexer-cache and brand-suggest tests.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Upgrade-action map and scheduling tests.
 */
class Db_Upgrade_Actions_Test extends WP_UnitTestCase {

	/**
	 * Clean cron and version state so each test starts from a known baseline.
	 */
	public function set_up() {
		parent::set_up();
		wp_clear_scheduled_hook( Shift64_Woo_Search_Attribute_Auto_Register::REBUILD_HOOK );
		delete_option( 'shift64_woo_search_db_version' );
	}

	/**
	 * Invoke the private maybe_create_tables() on the live plugin instance.
	 */
	private function run_upgrade_check() {
		$plugin = Shift64_Woo_Search_Plugin::get_instance();
		$method = new ReflectionMethod( Shift64_Woo_Search_Plugin::class, 'maybe_create_tables' );
		$method->invoke( $plugin );
	}

	/**
	 * Read the private version→action map via reflection.
	 *
	 * @return array<string,string>
	 */
	private function get_upgrade_actions() {
		$plugin = Shift64_Woo_Search_Plugin::get_instance();
		$method = new ReflectionMethod( Shift64_Woo_Search_Plugin::class, 'get_db_upgrade_actions' );
		return $method->invoke( $plugin );
	}

	/**
	 * Whether the auto-rebuild cron event is scheduled.
	 *
	 * @return bool
	 */
	private function rebuild_scheduled() {
		return (bool) wp_next_scheduled( Shift64_Woo_Search_Attribute_Auto_Register::REBUILD_HOOK );
	}

	/**
	 * The map must classify the brands versions correctly: the schema change
	 * (brands_text) needs a rebuild, the blob addition only a blob refresh —
	 * and no entry may name an action the upgrade runner doesn't implement.
	 */
	public function test_upgrade_map_classifies_known_versions() {
		$actions = $this->get_upgrade_actions();

		$this->assertSame( 'rebuild', $actions['1.1'], 'brands_text is a schema change; without a rebuild the field silently matches nothing.' );
		$this->assertSame( 'blobs', $actions['1.2'], 'The {prefix}:brands blob needs no index drop.' );

		foreach ( $actions as $version => $action ) {
			$this->assertContains( $action, array( 'rebuild', 'blobs' ), "Unknown upgrade action '{$action}' for version {$version}." );
			$this->assertTrue( version_compare( $version, Shift64_Woo_Search_Plugin::DB_VERSION, '<=' ), "Map entry {$version} is above DB_VERSION and would never run." );
		}
	}

	/**
	 * An install upgrading from before the brands feature must get a full
	 * rebuild scheduled (which subsumes the blob refresh), and its stored
	 * version must advance to the current one.
	 */
	public function test_upgrade_from_pre_brands_schedules_rebuild() {
		update_option( 'shift64_woo_search_db_version', '1.0' );

		$this->run_upgrade_check();

		$this->assertTrue( $this->rebuild_scheduled(), 'A schema-changing upgrade must schedule the auto-rebuild cron event.' );
		$this->assertSame( Shift64_Woo_Search_Plugin::DB_VERSION, get_option( 'shift64_woo_search_db_version' ) );
	}

	/**
	 * An install already on the current version must do nothing — no cron
	 * event, no version churn.
	 */
	public function test_current_version_is_a_noop() {
		update_option( 'shift64_woo_search_db_version', Shift64_Woo_Search_Plugin::DB_VERSION );

		$this->run_upgrade_check();

		$this->assertFalse( $this->rebuild_scheduled(), 'An up-to-date install must not schedule a rebuild.' );
		$this->assertSame( Shift64_Woo_Search_Plugin::DB_VERSION, get_option( 'shift64_woo_search_db_version' ) );
	}

	/**
	 * A fresh install (no stored version) is already built from the current
	 * schema by activate(), so it needs no upgrade action — only the version
	 * stamp.
	 */
	public function test_fresh_install_schedules_nothing() {
		$this->run_upgrade_check();

		$this->assertFalse( $this->rebuild_scheduled(), 'A fresh install must not schedule a rebuild.' );
		$this->assertSame( Shift64_Woo_Search_Plugin::DB_VERSION, get_option( 'shift64_woo_search_db_version' ) );
	}

	/**
	 * Scheduling must be idempotent: a second upgrade check while the rebuild
	 * event is still pending must not stack a duplicate.
	 */
	public function test_pending_rebuild_is_not_duplicated() {
		update_option( 'shift64_woo_search_db_version', '1.0' );
		$this->run_upgrade_check();
		$first = wp_next_scheduled( Shift64_Woo_Search_Attribute_Auto_Register::REBUILD_HOOK );

		update_option( 'shift64_woo_search_db_version', '1.0' );
		$this->run_upgrade_check();

		$this->assertSame( $first, wp_next_scheduled( Shift64_Woo_Search_Attribute_Auto_Register::REBUILD_HOOK ) );

		$crons = _get_cron_array();
		$count = 0;
		foreach ( $crons as $events ) {
			if ( isset( $events[ Shift64_Woo_Search_Attribute_Auto_Register::REBUILD_HOOK ] ) ) {
				$count += count( $events[ Shift64_Woo_Search_Attribute_Auto_Register::REBUILD_HOOK ] );
			}
		}
		$this->assertSame( 1, $count, 'Exactly one pending rebuild event, never a stack.' );
	}
}
