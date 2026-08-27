<?php
/**
 * How the admin screens announce a deprecated setting value.
 *
 * Two surfaces, both driven from `Shift64_Woo_Search_Deprecations`: the select
 * field marks the deprecated choice in its own label (and explains itself only
 * to the store that has it stored), and a notice on every plugin workspace
 * names each stored deprecated value and links to the field that owns it.
 *
 * The property worth guarding hardest is the negative one: a store on the
 * recommended values must see no notice at all, and rendering must stay a pure
 * read — `render_page()` is bookmarked, refreshed, and crawled, and the
 * relocation notice's docblock already establishes that "rendering a route
 * never writes" is a contract, not a preference.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Admin marking and notice for deprecated option values.
 */
class Deprecated_Settings_Admin_Test extends WP_UnitTestCase {

	/**
	 * Admin page controller under test.
	 *
	 * @var Shift64_Woo_Search_Admin
	 */
	private $admin;

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		delete_option( 'shift64_woo_search_logic' );
		delete_option( 'shift64_woo_search_fallback_trigger' );

		$this->admin = new Shift64_Woo_Search_Admin();
	}

	public function tear_down() {
		unset( $_GET['tab'], $_GET['section'] );

		parent::tear_down();
	}

	/**
	 * Render a route and return its markup.
	 *
	 * @param string|null $tab     `tab` request value, or null to omit it.
	 * @param string|null $section `section` request value, or null to omit it.
	 * @return string Rendered markup.
	 */
	private function render( $tab = null, $section = null ) {
		unset( $_GET['tab'], $_GET['section'] );

		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}
		if ( null !== $section ) {
			$_GET['section'] = $section;
		}

		ob_start();
		$this->admin->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Every `shift64_woo_search_`-prefixed option row, straight from the database.
	 *
	 * @return array<string,string>
	 */
	private function option_snapshot() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading raw option rows is the point of the assertion.
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$wpdb->esc_like( 'shift64_woo_search_' ) . '%'
			),
			ARRAY_A
		);

		$snapshot = array();
		foreach ( (array) $rows as $row ) {
			$snapshot[ $row['option_name'] ] = $row['option_value'];
		}

		return $snapshot;
	}

	// ── Step 1.4 / 1.5: the select field marking ───────────────

	/**
	 * The deprecated choice keeps its place in the dropdown but says so.
	 *
	 * Keeping it selectable is deliberate (spec Q1): a merchant comparing
	 * settings must be able to switch back during the deprecation window.
	 */
	public function test_or_option_is_labelled_deprecated_and_still_selectable() {
		$html = $this->render( 'relevance', 'basic' );

		$this->assertMatchesRegularExpression(
			'/<option value="OR"[^>]*>[^<]*deprecated[^<]*<\/option>/',
			$html,
			'The OR choice should be present and labelled deprecated.'
		);
	}

	/**
	 * The recommended choice is never marked.
	 */
	public function test_and_option_is_not_labelled_deprecated() {
		$html = $this->render( 'relevance', 'basic' );

		$this->assertMatchesRegularExpression(
			'/<option value="AND"[^>]*>(?:(?!deprecated)[^<])*<\/option>/',
			$html,
			'AND is the recommended value and must not be marked deprecated.'
		);
	}

	/**
	 * `no_results` is marked on Matching & Fallback, `low_score` is not.
	 */
	public function test_no_results_option_is_labelled_deprecated_and_low_score_is_not() {
		$html = $this->render( 'relevance', 'matching' );

		$this->assertMatchesRegularExpression(
			'/<option value="no_results"[^>]*>[^<]*deprecated[^<]*<\/option>/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<option value="low_score"[^>]*>(?:(?!deprecated)[^<])*<\/option>/',
			$html
		);
	}

	/**
	 * `strategy = strict_first` is explicitly kept by issue #85, so Search Mode
	 * must come through the same renderer completely unmarked.
	 */
	public function test_search_mode_choices_are_never_marked() {
		$html = $this->render( 'relevance', 'basic' );

		$this->assertMatchesRegularExpression(
			'/<option value="strict_first"[^>]*>(?:(?!deprecated)[^<])*<\/option>/',
			$html,
			'strict_first is a defensible trade-off, not a deprecation.'
		);
	}

	/**
	 * The per-field reason line appears only for the store that has the value stored.
	 */
	public function test_reason_line_renders_only_when_the_deprecated_value_is_stored() {
		update_option( 'shift64_woo_search_logic', 'AND' );
		$this->assertStringNotContainsString(
			'shift64-woo-search-admin__deprecated-note',
			$this->render( 'relevance', 'basic' ),
			'A store on AND should get the label marker and nothing more.'
		);

		update_option( 'shift64_woo_search_logic', 'OR' );
		$this->assertStringContainsString(
			'shift64-woo-search-admin__deprecated-note',
			$this->render( 'relevance', 'basic' ),
			'A store on OR should be told why it is deprecated.'
		);
	}

	/**
	 * Call sites that pass no `$deprecated` argument are unaffected.
	 *
	 * `render_select_field()` is shared by every select on the settings screens;
	 * the new parameter is optional and trailing precisely so this stays true.
	 */
	public function test_untouched_select_fields_render_no_deprecation_markup() {
		$html = $this->render( 'results', 'coverage' );

		$this->assertNotSame( '', $html );
		$this->assertStringNotContainsString( 'deprecated', $html );
	}

	// ── Step 1.6: the notice ───────────────────────────────────

	/**
	 * A store on the recommended values is not nagged.
	 */
	public function test_no_notice_when_both_settings_hold_recommended_values() {
		update_option( 'shift64_woo_search_logic', 'AND' );
		update_option( 'shift64_woo_search_fallback_trigger', 'low_score' );

		foreach ( array( 'overview', 'relevance', 'system' ) as $tab ) {
			$this->assertStringNotContainsString(
				'shift64-woo-search-admin__deprecated-notice',
				$this->render( $tab ),
				"The {$tab} workspace should render no deprecation notice for a clean store."
			);
		}
	}

	/**
	 * Both stored produces one notice listing both, each linking to its own section.
	 */
	public function test_notice_lists_every_stored_deprecated_value_with_a_link() {
		update_option( 'shift64_woo_search_logic', 'OR' );
		update_option( 'shift64_woo_search_fallback_trigger', 'no_results' );

		$html = $this->render( 'relevance', 'basic' );

		$this->assertStringContainsString( 'shift64-woo-search-admin__deprecated-notice', $html );
		$this->assertSame(
			1,
			substr_count( $html, 'shift64-woo-search-admin__deprecated-notice' ),
			'Exactly one notice, listing both values — not one notice per value.'
		);
		$this->assertStringContainsString( 'Search Logic', $html );
		$this->assertStringContainsString( 'Fallback Trigger', $html );
		$this->assertStringContainsString( 'tab=relevance&#038;section=basic', $html );
		$this->assertStringContainsString( 'tab=relevance&#038;section=matching', $html );
	}

	/**
	 * The notice follows the merchant across workspaces, including ones that own
	 * neither field — otherwise a store would have to visit the exact section it
	 * does not know is misconfigured.
	 */
	public function test_notice_renders_on_a_workspace_that_owns_neither_field() {
		update_option( 'shift64_woo_search_logic', 'OR' );

		$html = $this->render( 'system', 'security' );

		$this->assertStringContainsString( 'shift64-woo-search-admin__deprecated-notice', $html );
		$this->assertStringContainsString( 'Search Logic', $html );
		$this->assertStringNotContainsString( 'Fallback Trigger', $html );
	}

	/**
	 * A hand-edited value nobody declared produces no notice.
	 */
	public function test_no_notice_for_an_undeclared_value() {
		update_option( 'shift64_woo_search_logic', 'xor' );

		$this->assertStringNotContainsString(
			'shift64-woo-search-admin__deprecated-notice',
			$this->render( 'relevance', 'basic' )
		);
	}

	/**
	 * Rendering the notice writes nothing.
	 *
	 * The existing route-wide write test runs on a clean store, so it never
	 * reaches this branch. This one renders with both deprecated values stored —
	 * the state that actually produces the notice — and asserts the option table
	 * is byte-identical afterwards.
	 */
	public function test_rendering_the_notice_stores_nothing() {
		update_option( 'shift64_woo_search_logic', 'OR' );
		update_option( 'shift64_woo_search_fallback_trigger', 'no_results' );

		$before = $this->option_snapshot();
		$this->assertNotEmpty( $before );

		foreach ( array( 'overview', 'relevance', 'system' ) as $tab ) {
			$html = $this->render( $tab );
			$this->assertStringContainsString( 'shift64-woo-search-admin__deprecated-notice', $html );
		}

		$this->assertSame( $before, $this->option_snapshot(), 'Rendering the deprecation notice must not write.' );
	}
}
