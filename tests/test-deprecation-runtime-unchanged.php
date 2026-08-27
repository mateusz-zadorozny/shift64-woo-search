<?php
/**
 * Deprecating a value must not change what it does.
 *
 * This is the load-bearing invariant of issue #85's deprecation window: the
 * two values are marked as going away, and everything that reads them keeps
 * reading them exactly as before. A merchant on `OR` who ignores the notice
 * gets identical search results the day after upgrading.
 *
 * The failure this guards against is subtle and one-directional: the
 * deprecation touches shared admin code, and a careless "helpful" edit —
 * normalizing the stored value, defaulting away from it, skipping it when
 * building the config — would silently change retrieval for exactly the stores
 * being asked to migrate, which is the worst possible moment to move their
 * results out from under them.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Deprecated values still flow through every reader untouched.
 */
class Deprecation_Runtime_Unchanged_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		update_option( 'shift64_woo_search_logic', 'OR' );
		update_option( 'shift64_woo_search_fallback_trigger', 'no_results' );
	}

	/**
	 * The single search-config reader returns the deprecated values verbatim.
	 *
	 * `Shift64_Woo_Search_Settings::search_config()` is what the dropdown, the
	 * archive interceptor, the Product Collection block, and `wp … test` all
	 * answer on. If deprecation leaked into it, every one of those surfaces
	 * would change at once.
	 */
	public function test_search_config_returns_the_deprecated_values_verbatim() {
		$config = Shift64_Woo_Search_Settings::search_config();

		$this->assertSame( 'OR', $config['logic'] );
		$this->assertSame( 'no_results', $config['fallback_trigger'] );
	}

	/**
	 * Reading the registry does not rewrite the options it describes.
	 *
	 * `stored()` is called on every admin page view; it must stay a pure read.
	 */
	public function test_reading_the_registry_does_not_rewrite_the_options() {
		Shift64_Woo_Search_Deprecations::stored();
		Shift64_Woo_Search_Deprecations::for_option( 'shift64_woo_search_logic' );

		$this->assertSame( 'OR', get_option( 'shift64_woo_search_logic' ) );
		$this->assertSame( 'no_results', get_option( 'shift64_woo_search_fallback_trigger' ) );
	}

	/**
	 * A deprecated value is still writable.
	 *
	 * Deprecation is annotation, not a validation rule — the settings form must
	 * keep accepting the value, or a merchant could be locked out of the
	 * configuration they are already running.
	 */
	public function test_a_deprecated_value_is_still_writable_through_the_settings_persister() {
		update_option( 'shift64_woo_search_logic', 'AND' );

		Shift64_Woo_Search_Admin_Settings::persist( array( 'shift64_woo_search_logic' => 'OR' ) );

		$this->assertSame( 'OR', get_option( 'shift64_woo_search_logic' ) );
	}

	/**
	 * The generated SHORTINIT config still carries the stored values.
	 *
	 * The endpoint never boots the options API and reads these constants
	 * instead, so this is the only place the deprecation could desynchronize
	 * the fast path from the admin without any test noticing.
	 */
	public function test_generated_mu_plugin_config_still_emits_the_stored_values() {
		$plugin = Shift64_Woo_Search_Plugin::get_instance();
		$plugin->generate_mu_plugin_config();

		$config_path = WP_CONTENT_DIR . '/mu-plugins/shift64-woo-search/config.php';

		if ( ! file_exists( $config_path ) ) {
			$this->markTestSkipped( 'The mu-plugin config could not be written in this environment.' );
		}

		$config = file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a generated local file.

		$this->assertStringContainsString( "define( 'SHIFT64_WOO_SEARCH_LOGIC', 'OR' );", $config );
		$this->assertStringContainsString( "define( 'SHIFT64_WOO_SEARCH_FALLBACK_TRIGGER', 'no_results' );", $config );
	}
}
