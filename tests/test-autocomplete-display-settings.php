<?php
/**
 * Tests for the quick-search dropdown density settings.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Quick-search meta-line toggles and dropdown width.
 *
 * The dropdown always rendered SKU, category, and brand and was locked to 645px, so a
 * store with long SKUs and deep category paths could not thin the tray out or widen it
 * (#41). The display gates already existed in the frontend script; what was missing was
 * a way to reach them. These tests pin both halves of that seam:
 *
 * 1. Each toggle reaches the localized config independently, and any combination of
 *    them stacks — the meta line is assembled from whichever parts survive.
 * 2. The width lands in the stylesheet as a bounded integer. It is interpolated into
 *    CSS, so a stored value that is not a usable number must never pass through.
 * 3. Defaults reproduce the pre-#41 rendering exactly, which is what makes the change
 *    safe for sites that never visit the setting.
 */
class Shift64_Woo_Search_Autocomplete_Display_Settings_Test extends WP_UnitTestCase {

	/**
	 * Frontend asset loader under test.
	 *
	 * @var Shift64_Woo_Search_Frontend
	 */
	private $frontend;

	public function set_up() {
		parent::set_up();

		// Assets only enqueue once Redis is configured.
		update_option( 'shift64_woo_search_redis_host', '127.0.0.1' );

		$this->frontend = new Shift64_Woo_Search_Frontend();
	}

	public function tear_down() {
		wp_dequeue_script( 'shift64-woo-search' );
		wp_dequeue_style( 'shift64-woo-search' );
		wp_deregister_script( 'shift64-woo-search' );
		wp_deregister_style( 'shift64-woo-search' );

		parent::tear_down();
	}

	// ── Helpers ────────────────────────────────────────────────

	/**
	 * Run the enqueue and decode the config handed to the frontend script.
	 *
	 * `wp_localize_script` stores the payload as a JS assignment; parsing it back is
	 * the only way to assert on what the browser actually receives. Note that it
	 * stringifies every scalar on the way out, so the bools this plugin declares arrive
	 * as '1' and '' — see `assert_switch()`.
	 *
	 * @return array<string, mixed> Decoded `shift64_woo_search_config` object.
	 */
	private function localized_config() {
		$this->frontend->enqueue_assets();

		$data = wp_scripts()->get_data( 'shift64-woo-search', 'data' );
		$this->assertIsString( $data, 'The frontend script received no localized config.' );

		preg_match( '/var shift64_woo_search_config = (.*);/', $data, $matches );
		$this->assertNotEmpty( $matches, 'The localized config is not in the expected shape.' );

		return json_decode( $matches[1], true );
	}

	/**
	 * Run the enqueue and return the inline CSS attached to the plugin stylesheet.
	 *
	 * @return string Concatenated inline style payload.
	 */
	private function inline_style() {
		$this->frontend->enqueue_assets();

		return implode( '', (array) wp_styles()->get_data( 'shift64-woo-search', 'after' ) );
	}

	/**
	 * Assert a display switch arrives in the on/off state the browser will act on.
	 *
	 * The config declares these keys as bools and `BACKWARD_COMPATIBILITY.md` forbids
	 * retyping them, but wp_localize_script flattens a bool to '1' or '' in transit.
	 * Asserting the transit value is deliberate: it is what the frontend script's
	 * isEnabled() actually sees, and a plain `!== false` guard against '' is precisely
	 * the bug that would make every toggle a no-op.
	 *
	 * @param bool   $expected Whether the part should render.
	 * @param mixed  $actual   Value read out of the localized config.
	 * @param string $message  Assertion message.
	 */
	private function assert_switch( $expected, $actual, $message = '' ) {
		$this->assertSame( $expected ? '1' : '', $actual, $message );
	}

	// ── Meta-line toggles ──────────────────────────────────────

	/**
	 * With nothing configured, every part still shows — the pre-#41 rendering.
	 */
	public function test_meta_line_parts_default_to_visible() {
		$config = $this->localized_config();

		$this->assert_switch( true, $config['showSku'] );
		$this->assert_switch( true, $config['showCategory'] );
		$this->assert_switch( true, $config['showBrand'] );
	}

	/**
	 * Each toggle switches its own part and no other.
	 *
	 * @dataProvider toggle_provider
	 *
	 * @param string $option Option name being turned off.
	 * @param string $key    Config key it controls.
	 */
	public function test_each_toggle_controls_exactly_its_own_config_key( $option, $key ) {
		update_option( $option, 'no' );

		$config = $this->localized_config();

		$this->assert_switch( false, $config[ $key ], "{$option} did not disable {$key}." );

		foreach ( array( 'showSku', 'showCategory', 'showBrand' ) as $other ) {
			if ( $other === $key ) {
				continue;
			}

			$this->assert_switch( true, $config[ $other ], "Disabling {$option} also disabled {$other}." );
		}
	}

	public function toggle_provider() {
		return array(
			'sku'      => array( 'shift64_woo_search_show_sku', 'showSku' ),
			'category' => array( 'shift64_woo_search_show_category', 'showCategory' ),
			'brand'    => array( 'shift64_woo_search_show_brand', 'showBrand' ),
		);
	}

	/**
	 * The toggles stack: any combination is expressible, including none at all.
	 *
	 * This is the issue's first ask — SKU/brand/category "turned on/off or stacked so
	 * the combos display right" — so the all-off corner is asserted rather than assumed.
	 */
	public function test_toggles_stack_into_arbitrary_combinations() {
		update_option( 'shift64_woo_search_show_sku', 'no' );
		update_option( 'shift64_woo_search_show_category', 'no' );
		update_option( 'shift64_woo_search_show_brand', 'no' );

		$config = $this->localized_config();

		$this->assert_switch( false, $config['showSku'] );
		$this->assert_switch( false, $config['showCategory'] );
		$this->assert_switch( false, $config['showBrand'] );
	}

	/**
	 * Only the literal 'yes' keeps a part visible.
	 *
	 * The checkbox posts a hidden 'no' companion, so an unchecked box stores 'no'; an
	 * option left over from a hand-edited row must not read as enabled either.
	 */
	public function test_non_yes_values_read_as_disabled() {
		update_option( 'shift64_woo_search_show_sku', '' );

		$this->assert_switch( false, $this->localized_config()['showSku'] );
	}

	// ── Dropdown width ─────────────────────────────────────────

	/**
	 * The stock width matches the value the stylesheet ships with, so an untouched
	 * site renders exactly as it did before the setting existed.
	 */
	public function test_width_defaults_to_the_stylesheet_value() {
		$this->assertSame( 645, Shift64_Woo_Search_Frontend::get_dropdown_width() );
		$this->assertStringContainsString( '--s64ws-dropdown-width:645px', $this->inline_style() );
	}

	/**
	 * A configured width reaches the stylesheet as a custom property.
	 */
	public function test_configured_width_is_emitted_as_a_custom_property() {
		update_option( 'shift64_woo_search_dropdown_width', '880' );

		$this->assertSame( 880, Shift64_Woo_Search_Frontend::get_dropdown_width() );
		$this->assertStringContainsString( ':root{--s64ws-dropdown-width:880px;}', $this->inline_style() );
	}

	/**
	 * Stored values are cast and bounded before they reach the stylesheet.
	 *
	 * Out-of-range numbers clamp to the nearest supported edge; values that are not a
	 * usable number at all fall back to the stock width. Either way the emitted rule is
	 * a plain integer, so nothing from the options table can break out into CSS.
	 *
	 * @dataProvider width_bounds_provider
	 *
	 * @param mixed $stored   Raw stored option value.
	 * @param int   $expected Width the frontend must use.
	 */
	public function test_width_is_clamped_and_sanitized( $stored, $expected ) {
		update_option( 'shift64_woo_search_dropdown_width', $stored );

		$this->assertSame( $expected, Shift64_Woo_Search_Frontend::get_dropdown_width() );
		$this->assertStringContainsString( '--s64ws-dropdown-width:' . $expected . 'px', $this->inline_style() );
	}

	public function width_bounds_provider() {
		return array(
			'below the minimum' => array( '120', 320 ),
			'at the minimum'    => array( '320', 320 ),
			'at the maximum'    => array( '1200', 1200 ),
			'above the maximum' => array( '5000', 1200 ),
			'empty string'      => array( '', 645 ),
			'non-numeric'       => array( 'wide', 645 ),
			'css injection'     => array( '900px;} body{display:none}', 900 ),
			'negative'          => array( '-40', 645 ),
		);
	}

	/**
	 * The stylesheet must *size* the tray, not merely floor it.
	 *
	 * The first cut of this setting set `min-width` on the results container. That is a
	 * no-op wherever the search field is already wider than the configured value — a
	 * full-width search block, for one — so the setting appeared to do nothing at all
	 * on the layout most likely to need it. A floor also cannot make the tray narrower,
	 * which is half of what the setting is for.
	 *
	 * Asserted against the stylesheet because there is no DOM here to measure; browser
	 * QA covers what it actually looks like.
	 */
	public function test_stylesheet_sizes_the_results_tray_rather_than_flooring_it() {
		$css = file_get_contents( SHIFT64_WOO_SEARCH_PATH . 'frontend/css/shift64-woo-search.css' );

		// Anchored at line start so a selector list merely *ending* in this class does
		// not match ahead of the container's own rule.
		preg_match( '/^\.shift64-woo-search-results \{(.*?)^\}/ms', $css, $matches );
		$this->assertNotEmpty( $matches, 'The results container rule was not found.' );

		$rule = $matches[1];

		$this->assertMatchesRegularExpression(
			'/^\s*width:\s*var\(--s64ws-dropdown-width/m',
			$rule,
			'The results tray must take its width from --s64ws-dropdown-width.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/^\s*min-width:\s*var\(--s64ws-dropdown-width/m',
			$rule,
			'A min-width floor leaves the setting inert on layouts wider than it.'
		);
	}
}
