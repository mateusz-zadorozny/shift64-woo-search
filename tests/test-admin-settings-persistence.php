<?php
/**
 * Tests for Shift64_Woo_Search_Admin_Settings.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Allowlisted settings persistence tests.
 *
 * The generic save is shared by several section forms, each submitting only its
 * own fields. These tests pin the properties that makes that sharing safe:
 *
 * 1. Scoped writes — only allowlisted keys present in the payload are written;
 *    sentinel options set beforehand must survive untouched.
 * 2. Sanitization and storage shape — scalars stay strings, textareas keep
 *    newlines, closed-set arrays drop unknown values.
 * 3. Credential safety — Redis credentials are cleared only by a payload that
 *    itself disables auth, never because of stale stored state.
 */
class Shift64_Woo_Search_Admin_Settings_Test extends WP_UnitTestCase {

	/**
	 * Options the sentinel tests pre-seed and then assert are unchanged.
	 *
	 * @var array<string, string>
	 */
	private $sentinels = array(
		'shift64_woo_search_redis_host'      => 'sentinel-host',
		'shift64_woo_search_min_query'       => '4',
		'shift64_woo_search_logic'           => 'or',
		'shift64_woo_search_input_selector'  => '.sentinel-input',
		'shift64_woo_search_price_sort_mode' => 'sentinel-mode',
		'shift64_woo_search_rate_limit'      => '99',
	);

	/**
	 * Seed every sentinel option.
	 */
	private function seed_sentinels() {
		foreach ( $this->sentinels as $option => $value ) {
			update_option( $option, $value );
		}
	}

	/**
	 * Assert every sentinel option except the named ones is unchanged.
	 *
	 * @param string[] $except Option names expected to have changed.
	 */
	private function assert_sentinels_intact( array $except = array() ) {
		foreach ( $this->sentinels as $option => $value ) {
			if ( in_array( $option, $except, true ) ) {
				continue;
			}
			$this->assertSame( $value, get_option( $option ), "Untouched option {$option} was overwritten." );
		}
	}

	// ── Scoped writes ──────────────────────────────────────────

	/**
	 * A partial payload writes its own keys and leaves every other option alone.
	 */
	public function test_subset_save_updates_only_submitted_keys() {
		$this->seed_sentinels();

		$saved = Shift64_Woo_Search_Admin_Settings::persist(
			array(
				'shift64_woo_search_min_query' => '2',
				'shift64_woo_search_logic'     => 'and',
			)
		);

		$this->assertSame( 2, $saved );
		$this->assertSame( '2', get_option( 'shift64_woo_search_min_query' ) );
		$this->assertSame( 'and', get_option( 'shift64_woo_search_logic' ) );
		$this->assert_sentinels_intact( array( 'shift64_woo_search_min_query', 'shift64_woo_search_logic' ) );
	}

	/**
	 * Keys outside the allowlist are ignored — not saved, not counted.
	 */
	public function test_non_allowlisted_keys_are_ignored() {
		delete_option( 'shift64_woo_search_not_a_setting' );
		delete_option( 'admin_email_backup' );

		$saved = Shift64_Woo_Search_Admin_Settings::persist(
			array(
				'shift64_woo_search_not_a_setting' => 'nope',
				'admin_email_backup'               => 'attacker@example.com',
				'shift64_woo_search_debounce'      => '250',
			)
		);

		$this->assertSame( 1, $saved );
		$this->assertFalse( get_option( 'shift64_woo_search_not_a_setting' ) );
		$this->assertFalse( get_option( 'admin_email_backup' ) );
		$this->assertSame( '250', get_option( 'shift64_woo_search_debounce' ) );
	}

	/**
	 * An empty payload writes nothing.
	 */
	public function test_empty_payload_saves_nothing() {
		$this->seed_sentinels();

		$this->assertSame( 0, Shift64_Woo_Search_Admin_Settings::persist( array() ) );
		$this->assert_sentinels_intact();
	}

	// ── Sanitization and storage shape ─────────────────────────

	/**
	 * A checkbox turned off round-trips as the string 'no'.
	 */
	public function test_checkbox_no_value_round_trips() {
		update_option( 'shift64_woo_search_archive_enabled', 'yes' );

		Shift64_Woo_Search_Admin_Settings::persist( array( 'shift64_woo_search_archive_enabled' => 'no' ) );

		$this->assertSame( 'no', get_option( 'shift64_woo_search_archive_enabled' ) );
	}

	/**
	 * The quick-search density controls round-trip through the generic seam.
	 *
	 * These four are what let a merchant thin out an over-full dropdown, so they have
	 * to be reachable by the Autocomplete form's partial save — and only by it. The
	 * sentinels prove the write stayed scoped.
	 */
	public function test_quick_search_density_settings_round_trip() {
		$this->seed_sentinels();

		$saved = Shift64_Woo_Search_Admin_Settings::persist(
			array(
				'shift64_woo_search_show_sku'            => 'no',
				'shift64_woo_search_show_category'       => 'yes',
				'shift64_woo_search_show_brand'          => 'no',
				'shift64_woo_search_dropdown_width_mode' => 'custom',
				'shift64_woo_search_dropdown_width'      => '900',
			)
		);

		$this->assertSame( 5, $saved );
		$this->assertSame( 'no', get_option( 'shift64_woo_search_show_sku' ) );
		$this->assertSame( 'yes', get_option( 'shift64_woo_search_show_category' ) );
		$this->assertSame( 'no', get_option( 'shift64_woo_search_show_brand' ) );
		$this->assertSame( 'custom', get_option( 'shift64_woo_search_dropdown_width_mode' ) );
		$this->assertSame( '900', get_option( 'shift64_woo_search_dropdown_width' ) );
		$this->assert_sentinels_intact();
	}

	/**
	 * The storefront debug panel switch round-trips through the generic seam like
	 * any other yes/no scalar, so the Diagnostics section can save it.
	 */
	public function test_archive_debug_toggle_round_trips() {
		Shift64_Woo_Search_Admin_Settings::persist(
			array( 'shift64_woo_search_archive_debug_enabled' => 'yes' )
		);

		$this->assertSame( 'yes', get_option( 'shift64_woo_search_archive_debug_enabled' ) );

		Shift64_Woo_Search_Admin_Settings::persist(
			array( 'shift64_woo_search_archive_debug_enabled' => 'no' )
		);

		$this->assertSame( 'no', get_option( 'shift64_woo_search_archive_debug_enabled' ) );
	}

	/**
	 * A payload that does not carry the debug key leaves it alone — the panel
	 * cannot be switched on as a side effect of saving an unrelated section.
	 */
	public function test_unrelated_save_never_touches_the_archive_debug_toggle() {
		update_option( 'shift64_woo_search_archive_debug_enabled', 'no' );

		Shift64_Woo_Search_Admin_Settings::persist( array( 'shift64_woo_search_debounce' => '150' ) );

		$this->assertSame( 'no', get_option( 'shift64_woo_search_archive_debug_enabled' ) );
	}

	/**
	 * Markup submitted for the debug toggle is stripped rather than stored, so
	 * the value can never reach the storefront as markup.
	 *
	 * Note what this does *not* claim: sanitization is not the thing that keeps
	 * the panel off. `sanitize_text_field` removes the tags and leaves the text
	 * around them, so a crafted `<b>yes</b>` still stores `yes`. That is
	 * acceptable because reaching this seam at all already requires the nonce and
	 * the settings capability. The real guard is at read time — only the literal
	 * string `yes` is read as consent, which `Archive_Debug_Panel_Test` covers.
	 */
	public function test_archive_debug_toggle_strips_markup() {
		Shift64_Woo_Search_Admin_Settings::persist(
			array( 'shift64_woo_search_archive_debug_enabled' => '<script>alert(1)</script>maybe' )
		);

		$stored = get_option( 'shift64_woo_search_archive_debug_enabled' );

		$this->assertStringNotContainsString( '<', $stored );
		$this->assertStringNotContainsString( 'alert', $stored );
		$this->assertNotSame( 'yes', $stored );
	}

	/**
	 * Scalars are stored as strings — consumers cast on read.
	 */
	public function test_scalar_values_are_stored_as_strings() {
		Shift64_Woo_Search_Admin_Settings::persist( array( 'shift64_woo_search_debounce' => '30' ) );

		$stored = get_option( 'shift64_woo_search_debounce' );

		$this->assertIsString( $stored );
		$this->assertSame( '30', $stored );
	}

	/**
	 * Textareas keep their line breaks (sanitize_textarea_field, not sanitize_text_field).
	 */
	public function test_textarea_option_preserves_newlines_and_strips_markup() {
		Shift64_Woo_Search_Admin_Settings::persist(
			array(
				'shift64_woo_search_category_boost_rules' => "shoes|1.5\n<script>alert(1)</script>boots|2.0",
			)
		);

		$stored = get_option( 'shift64_woo_search_category_boost_rules' );

		$this->assertStringContainsString( "\n", $stored );
		$this->assertStringContainsString( 'shoes|1.5', $stored );
		$this->assertStringNotContainsString( '<script>', $stored );
	}

	/**
	 * Non-scalar values for a textarea option collapse to an empty string.
	 */
	public function test_textarea_option_rejects_array_value() {
		Shift64_Woo_Search_Admin_Settings::persist(
			array( 'shift64_woo_search_category_pin_rules' => array( 'not', 'a', 'textarea' ) )
		);

		$this->assertSame( '', get_option( 'shift64_woo_search_category_pin_rules' ) );
	}

	/**
	 * The scopes array is filtered down to the known scope map — junk is dropped.
	 */
	public function test_array_option_filters_to_valid_scopes() {
		$valid = array_keys( Shift64_Woo_Search_Taxonomy_Archive::get_scope_map() );
		$this->assertNotEmpty( $valid, 'Scope map must expose at least product_cat.' );

		Shift64_Woo_Search_Admin_Settings::persist(
			array(
				'shift64_woo_search_taxonomy_archive_scopes' => array( 'product_cat', 'not_a_taxonomy', '' ),
			)
		);

		$this->assertSame( array( 'product_cat' ), get_option( 'shift64_woo_search_taxonomy_archive_scopes' ) );
	}

	/**
	 * A scalar submitted for an array option stores an empty list rather than junk.
	 */
	public function test_array_option_rejects_scalar_value() {
		update_option( 'shift64_woo_search_taxonomy_archive_scopes', array( 'product_cat' ) );

		Shift64_Woo_Search_Admin_Settings::persist(
			array( 'shift64_woo_search_taxonomy_archive_scopes' => 'product_cat' )
		);

		$this->assertSame( array(), get_option( 'shift64_woo_search_taxonomy_archive_scopes' ) );
	}

	// ── Credential safety ──────────────────────────────────────

	/**
	 * The regression this seam exists for.
	 *
	 * Stored auth state says "off" (the stale-state trap) while credentials are
	 * still on disk. Saving an unrelated section must not touch them — only a
	 * payload that itself disables auth may.
	 */
	public function test_unrelated_save_never_clears_credentials() {
		update_option( 'shift64_woo_search_redis_auth_enabled', 'no' );
		update_option( 'shift64_woo_search_redis_username', 'redis-user' );
		update_option( 'shift64_woo_search_redis_password', 'redis-secret' );

		Shift64_Woo_Search_Admin_Settings::persist( array( 'shift64_woo_search_debounce' => '150' ) );

		$this->assertSame( 'redis-user', get_option( 'shift64_woo_search_redis_username' ) );
		$this->assertSame( 'redis-secret', get_option( 'shift64_woo_search_redis_password' ) );
	}

	/**
	 * Explicitly disabling auth in the payload still wipes the credentials.
	 */
	public function test_payload_disabling_auth_clears_credentials() {
		update_option( 'shift64_woo_search_redis_auth_enabled', 'yes' );
		update_option( 'shift64_woo_search_redis_username', 'redis-user' );
		update_option( 'shift64_woo_search_redis_password', 'redis-secret' );

		Shift64_Woo_Search_Admin_Settings::persist(
			array( 'shift64_woo_search_redis_auth_enabled' => 'no' )
		);

		$this->assertSame( 'no', get_option( 'shift64_woo_search_redis_auth_enabled' ) );
		$this->assertSame( '', get_option( 'shift64_woo_search_redis_username' ) );
		$this->assertSame( '', get_option( 'shift64_woo_search_redis_password' ) );
	}

	/**
	 * Disabling auth wins over credentials submitted in the same payload.
	 */
	public function test_payload_disabling_auth_clears_credentials_submitted_alongside() {
		Shift64_Woo_Search_Admin_Settings::persist(
			array(
				'shift64_woo_search_redis_auth_enabled' => 'no',
				'shift64_woo_search_redis_username'     => 'redis-user',
				'shift64_woo_search_redis_password'     => 'redis-secret',
			)
		);

		$this->assertSame( '', get_option( 'shift64_woo_search_redis_username' ) );
		$this->assertSame( '', get_option( 'shift64_woo_search_redis_password' ) );
	}

	/**
	 * Enabling auth stores the submitted credentials.
	 */
	public function test_payload_enabling_auth_stores_credentials() {
		update_option( 'shift64_woo_search_redis_username', '' );
		update_option( 'shift64_woo_search_redis_password', '' );

		$saved = Shift64_Woo_Search_Admin_Settings::persist(
			array(
				'shift64_woo_search_redis_auth_enabled' => 'yes',
				'shift64_woo_search_redis_username'     => 'redis-user',
				'shift64_woo_search_redis_password'     => 'redis-secret',
			)
		);

		$this->assertSame( 3, $saved );
		$this->assertSame( 'yes', get_option( 'shift64_woo_search_redis_auth_enabled' ) );
		$this->assertSame( 'redis-user', get_option( 'shift64_woo_search_redis_username' ) );
		$this->assertSame( 'redis-secret', get_option( 'shift64_woo_search_redis_password' ) );
	}
}
