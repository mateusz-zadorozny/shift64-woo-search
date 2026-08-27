<?php
/**
 * The deprecation registry, and what a store's stored options say about it.
 *
 * Issue #85 measured `logic = OR` and `fallback_trigger = no_results` worse
 * than their alternatives on every axis tried, with no case where either wins.
 * They are marked deprecated rather than removed, because the evidence came
 * from one synthetic catalog and removal is a breaking change under
 * `BACKWARD_COMPATIBILITY.md` §6.
 *
 * The load-bearing property these tests protect is that deprecation is
 * *annotation only*: `Shift64_Woo_Search_Deprecations` decides what the admin
 * and WP-CLI say, and nothing else. The runtime assertions live in
 * `test-deprecation-runtime-unchanged.php`.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Registry contents and stored-value resolution.
 */
class Deprecated_Option_Values_Test extends WP_UnitTestCase {

	/**
	 * Reset both options to a known-clean state between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'shift64_woo_search_logic' );
		delete_option( 'shift64_woo_search_fallback_trigger' );
	}

	/**
	 * Step 1.1 — the registry declares exactly the two entries issue #85 asks for.
	 *
	 * Pinned by name and value: a third entry appearing here is a scope change
	 * that should arrive with its own spec, and a missing one means the
	 * deprecation silently stopped being announced.
	 */
	public function test_registry_declares_exactly_the_two_deprecated_values() {
		$registry = Shift64_Woo_Search_Deprecations::registry();

		$this->assertSame(
			array( 'shift64_woo_search_logic', 'shift64_woo_search_fallback_trigger' ),
			array_keys( $registry ),
			'The registry should declare exactly the two options issue #85 deprecates, in that order.'
		);
		$this->assertSame( array( 'OR' ), array_keys( $registry['shift64_woo_search_logic'] ) );
		$this->assertSame( array( 'no_results' ), array_keys( $registry['shift64_woo_search_fallback_trigger'] ) );
	}

	/**
	 * Every entry carries the fields the admin notice and the CLI both read.
	 *
	 * Without this, a half-filled entry would surface as a notice line with a
	 * blank reason or a link pointing nowhere.
	 */
	public function test_every_registry_entry_is_fully_described() {
		foreach ( Shift64_Woo_Search_Deprecations::registry() as $option => $values ) {
			foreach ( $values as $value => $entry ) {
				foreach ( array( 'field', 'value_label', 'reason', 'workspace', 'section' ) as $key ) {
					$this->assertArrayHasKey( $key, $entry, "{$option}={$value} is missing '{$key}'." );
					$this->assertNotSame( '', $entry[ $key ], "{$option}={$value} has an empty '{$key}'." );
				}
			}
		}
	}

	/**
	 * Each entry's route must be one the admin router actually declares.
	 *
	 * A stale workspace/section pair would render a notice whose "go fix it"
	 * link lands on the plugin's fallback route instead of the field.
	 */
	public function test_every_registry_entry_points_at_a_declared_route() {
		$workspaces = Shift64_Woo_Search_Admin_Routes::get_workspaces();

		foreach ( Shift64_Woo_Search_Deprecations::registry() as $option => $values ) {
			foreach ( $values as $value => $entry ) {
				$this->assertArrayHasKey( $entry['workspace'], $workspaces, "{$option}={$value} names an unknown workspace." );
				$this->assertArrayHasKey(
					$entry['section'],
					$workspaces[ $entry['workspace'] ]['sections'],
					"{$option}={$value} names an unknown section."
				);
			}
		}
	}

	/**
	 * Step 1.2 — a store on the recommended values is not nagged.
	 */
	public function test_stored_is_empty_on_the_recommended_values() {
		update_option( 'shift64_woo_search_logic', 'AND' );
		update_option( 'shift64_woo_search_fallback_trigger', 'low_score' );

		$this->assertSame( array(), Shift64_Woo_Search_Deprecations::stored() );
	}

	/**
	 * Step 1.2 — "never saved" behaves exactly like "saved to the recommended value".
	 *
	 * Both option rows are absent here. They resolve to the defaults PR #78
	 * set, which are the recommended values, so an install that never touched
	 * either setting must see nothing.
	 */
	public function test_stored_is_empty_when_no_option_row_exists() {
		$this->assertFalse( get_option( 'shift64_woo_search_logic', false ) );
		$this->assertFalse( get_option( 'shift64_woo_search_fallback_trigger', false ) );

		$this->assertSame( array(), Shift64_Woo_Search_Deprecations::stored() );
	}

	/**
	 * Step 1.2 — one deprecated value stored reports exactly that one.
	 */
	public function test_stored_reports_only_the_deprecated_option() {
		update_option( 'shift64_woo_search_logic', 'OR' );
		update_option( 'shift64_woo_search_fallback_trigger', 'low_score' );

		$stored = Shift64_Woo_Search_Deprecations::stored();

		$this->assertCount( 1, $stored );
		$this->assertSame( 'shift64_woo_search_logic', $stored[0]['option'] );
		$this->assertSame( 'OR', $stored[0]['value'] );
		$this->assertNotSame( '', $stored[0]['reason'] );
	}

	/**
	 * Step 1.2 — both stored reports both, in registry order.
	 */
	public function test_stored_reports_both_in_registry_order() {
		update_option( 'shift64_woo_search_logic', 'OR' );
		update_option( 'shift64_woo_search_fallback_trigger', 'no_results' );

		$stored = Shift64_Woo_Search_Deprecations::stored();

		$this->assertCount( 2, $stored );
		$this->assertSame(
			array( 'shift64_woo_search_logic', 'shift64_woo_search_fallback_trigger' ),
			wp_list_pluck( $stored, 'option' )
		);
		$this->assertSame( array( 'OR', 'no_results' ), wp_list_pluck( $stored, 'value' ) );
	}

	/**
	 * Step 1.2 — a value nobody declared is not reported.
	 *
	 * The registry describes values this plugin shipped, not "anything that is
	 * not the recommended one". A hand-edited option is somebody else's
	 * problem, and guessing about it would produce a notice with no reason to
	 * show.
	 */
	public function test_stored_ignores_an_undeclared_value() {
		update_option( 'shift64_woo_search_logic', 'xor' );

		$this->assertSame( array(), Shift64_Woo_Search_Deprecations::stored() );
	}

	/**
	 * Step 1.3 — drift guard.
	 *
	 * The plugin's option allow-list (`Admin_Settings::scalar_options()`) and
	 * its default map (`Plugin::set_default_options()`) are both private, so
	 * the guard anchors on the one public reader of these options. If an option
	 * is renamed or dropped, it stops appearing in the search config and this
	 * fails — rather than leaving the registry quietly announcing a key that no
	 * longer exists.
	 */
	public function test_every_registry_option_is_exposed_by_the_settings_reader() {
		$config = Shift64_Woo_Search_Settings::search_config();

		foreach ( array_keys( Shift64_Woo_Search_Deprecations::registry() ) as $option ) {
			$config_key = str_replace( 'shift64_woo_search_', '', $option );
			$this->assertArrayHasKey(
				$config_key,
				$config,
				"The registry names {$option}, but Settings::search_config() no longer exposes '{$config_key}'."
			);
		}
	}

	/**
	 * Step 1.3 — the shipped defaults are the safe ones.
	 *
	 * With no option row stored, the value the plugin falls back to must never
	 * be a deprecated one; otherwise a fresh install would be deprecated on
	 * arrival.
	 */
	public function test_shipped_defaults_are_never_a_deprecated_value() {
		$config = Shift64_Woo_Search_Settings::search_config();

		foreach ( Shift64_Woo_Search_Deprecations::registry() as $option => $values ) {
			$config_key = str_replace( 'shift64_woo_search_', '', $option );
			$this->assertArrayNotHasKey(
				$config[ $config_key ],
				$values,
				"The default for {$option} is a deprecated value."
			);
		}
	}

	/**
	 * `for_option()` gives the renderer a value => reason map, and nothing for
	 * an option with no deprecations.
	 */
	public function test_for_option_returns_reasons_keyed_by_value() {
		$this->assertSame(
			array( 'OR' ),
			array_keys( Shift64_Woo_Search_Deprecations::for_option( 'shift64_woo_search_logic' ) )
		);
		$this->assertSame(
			array(),
			Shift64_Woo_Search_Deprecations::for_option( 'shift64_woo_search_strategy' ),
			'strategy = strict_first is explicitly kept by issue #85 and must not be marked.'
		);
	}
}
