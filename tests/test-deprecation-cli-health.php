<?php
/**
 * The headless half of the deprecation report.
 *
 * A store run from CI or WP-CLI never sees an admin notice, so
 * `wp shift64-woo-search health` carries the same information. The message
 * strings live on `Shift64_Woo_Search_Deprecations` rather than in the command
 * because `cli/class-shift64-woo-search-cli.php` returns early unless `WP_CLI`
 * is defined — nothing inside that class is reachable from PHPUnit. These
 * tests therefore cover the payload directly, plus a source-level check that
 * the command still asks for it.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Deprecation reporting for `wp shift64-woo-search health`.
 */
class Deprecation_CLI_Health_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'shift64_woo_search_logic' );
		delete_option( 'shift64_woo_search_fallback_trigger' );
	}

	/**
	 * A clean store produces no warnings — the command logs the "none" line instead.
	 */
	public function test_no_messages_for_a_store_on_the_recommended_values() {
		update_option( 'shift64_woo_search_logic', 'AND' );
		update_option( 'shift64_woo_search_fallback_trigger', 'low_score' );

		$this->assertSame( array(), Shift64_Woo_Search_Deprecations::cli_messages() );
	}

	/**
	 * One message per stored deprecated value, naming the field, the option key,
	 * the stored value, and what to do instead.
	 *
	 * The option key matters here in a way it does not in the admin: an operator
	 * fixing this headlessly needs the key to run `wp option update`.
	 */
	public function test_one_message_per_stored_value_naming_field_option_and_value() {
		update_option( 'shift64_woo_search_logic', 'OR' );
		update_option( 'shift64_woo_search_fallback_trigger', 'no_results' );

		$messages = Shift64_Woo_Search_Deprecations::cli_messages();

		$this->assertCount( 2, $messages );

		$this->assertStringContainsString( 'Search Logic', $messages[0] );
		$this->assertStringContainsString( 'shift64_woo_search_logic', $messages[0] );
		$this->assertStringContainsString( '"OR"', $messages[0] );
		$this->assertStringContainsString( 'Switch to AND', $messages[0] );

		$this->assertStringContainsString( 'Fallback Trigger', $messages[1] );
		$this->assertStringContainsString( 'shift64_woo_search_fallback_trigger', $messages[1] );
		$this->assertStringContainsString( '"no_results"', $messages[1] );
	}

	/**
	 * Only the deprecated option is reported when just one is stored.
	 */
	public function test_only_the_stored_deprecated_value_is_reported() {
		update_option( 'shift64_woo_search_logic', 'OR' );
		update_option( 'shift64_woo_search_fallback_trigger', 'low_score' );

		$messages = Shift64_Woo_Search_Deprecations::cli_messages();

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'shift64_woo_search_logic', $messages[0] );
	}

	/**
	 * The command still wires the reporter into `health()`.
	 *
	 * Source-level, because the class cannot be loaded here. Without it, the
	 * payload above could keep passing while the headless surface silently
	 * stopped reporting anything.
	 */
	public function test_health_command_reports_deprecated_settings() {
		$cli = file_get_contents( dirname( __DIR__ ) . '/cli/class-shift64-woo-search-cli.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a repo source file.

		$this->assertStringContainsString(
			'self::report_deprecated_settings();',
			$cli,
			'health() should call the deprecation reporter.'
		);
		$this->assertStringContainsString(
			'Shift64_Woo_Search_Deprecations::cli_messages()',
			$cli,
			'The reporter should source its lines from the registry.'
		);
		$this->assertStringContainsString(
			"WP_CLI::log( 'Deprecated settings: none' )",
			$cli,
			'A clean store should get an explicit "none" line rather than silence.'
		);
	}

	/**
	 * `health` stays diagnostic: reporting a deprecation must never call
	 * `WP_CLI::error()`, which would halt the command and change its exit
	 * status for a store whose search is working fine.
	 */
	public function test_deprecation_reporting_never_errors_out() {
		$cli = file_get_contents( dirname( __DIR__ ) . '/cli/class-shift64-woo-search-cli.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a repo source file.

		$start = strpos( $cli, 'private static function report_deprecated_settings()' );
		$this->assertNotFalse( $start );

		$body = substr( $cli, $start );
		$this->assertStringNotContainsString( 'WP_CLI::error(', $body );
	}
}
