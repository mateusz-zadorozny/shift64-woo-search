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

	// ── Retired dropdown width ─────────────────────────────────

	/**
	 * The configurable tray width is inert, and its option rows survive untouched.
	 *
	 * The width was a global appearance control: an administrator set one number
	 * in WP Admin and every autocomplete tray on the site took it, whatever
	 * rendered it. The block-theme-only cleanup replaced that with the Search
	 * Panel block's own `maxWidth`, which each placement owns. The stored rows
	 * stay so a version rollback finds them, and the tray falls back to matching
	 * the search field — the behaviour every site had before the setting existed.
	 */
	public function test_configured_tray_width_no_longer_reaches_the_frontend() {
		update_option( 'shift64_woo_search_dropdown_width_mode', 'custom' );
		update_option( 'shift64_woo_search_dropdown_width', '880' );

		$this->assertArrayNotHasKey(
			'dropdownWidth',
			$this->localized_config(),
			'The script no longer takes a tray width; the Search Panel block owns it.'
		);
		$this->assertStringNotContainsString(
			'--s64ws-dropdown-width',
			$this->inline_style(),
			'No global custom property may be emitted for the tray width.'
		);
		$this->assertFalse(
			method_exists( 'Shift64_Woo_Search_Frontend', 'get_dropdown_width' ),
			'The width resolver was removed with the setting it served.'
		);

		$this->assertSame( 'custom', get_option( 'shift64_woo_search_dropdown_width_mode' ) );
		$this->assertSame( '880', get_option( 'shift64_woo_search_dropdown_width' ) );
	}

	// ── Retired theme selectors ────────────────────────────────

	/**
	 * The script binds to the fallback's own field, never to configured selectors.
	 *
	 * Enhancing arbitrary theme inputs by CSS selector was the mechanism that let
	 * the plugin take over a classic theme's search box. Like the width, the
	 * option rows survive for rollback but nothing reads them.
	 */
	public function test_configured_theme_selectors_no_longer_reach_the_frontend() {
		update_option( 'shift64_woo_search_input_selector', '.theme-search-input' );
		update_option( 'shift64_woo_search_additional_selectors', '.theme-extra-input' );
		update_option( 'shift64_woo_search_button_selector', '.theme-search-button' );

		$config = $this->localized_config();

		$this->assertSame( '.shift64-woo-search-field__input', $config['selectors'] );
		$this->assertArrayNotHasKey( 'searchButtonSelector', $config );

		$this->assertSame( '.theme-search-input', get_option( 'shift64_woo_search_input_selector' ) );
		$this->assertSame( '.theme-extra-input', get_option( 'shift64_woo_search_additional_selectors' ) );
		$this->assertSame( '.theme-search-button', get_option( 'shift64_woo_search_button_selector' ) );
	}

	/**
	 * Nothing enqueues the fallback assets on an ordinary page view.
	 *
	 * The class used to hook `wp_enqueue_scripts`, so the stylesheet and script
	 * shipped with every storefront request whether or not anything on the page
	 * used them. Assets now arrive through block metadata, or through the
	 * childless-parent fallback calling this method directly.
	 */
	public function test_assets_are_not_enqueued_by_a_page_view() {
		$this->assertFalse(
			has_action( 'wp_enqueue_scripts', array( $this->frontend, 'enqueue_assets' ) ),
			'The global frontend enqueue was removed; assets are block-scoped now.'
		);
	}

	/**
	 * Frontend assets are versioned by file mtime, not by the plugin version.
	 *
	 * The plugin version only moves on release, so while a stylesheet or script is being
	 * edited the `?ver=` never changes and browsers keep serving a stale copy — the
	 * change is invisible without a private window. The admin enqueue already versioned
	 * by mtime; the frontend did not.
	 */
	public function test_frontend_assets_are_versioned_by_file_mtime() {
		$this->frontend->enqueue_assets();

		$expected = (string) filemtime( SHIFT64_WOO_SEARCH_PATH . 'frontend/css/shift64-woo-search.css' );

		$this->assertSame( $expected, wp_styles()->registered['shift64-woo-search']->ver );
		$this->assertNotSame(
			SHIFT64_WOO_SEARCH_VERSION,
			wp_styles()->registered['shift64-woo-search']->ver,
			'The stylesheet is still pinned to the plugin version, which does not move between releases.'
		);

		$this->assertSame(
			(string) filemtime( SHIFT64_WOO_SEARCH_PATH . 'frontend/js/shift64-woo-search.js' ),
			wp_scripts()->registered['shift64-woo-search']->ver
		);
	}

	/**
	 * The modal opts out of the custom width, and does so from a later rule.
	 *
	 * `.shift64-woo-search-modal__search .shift64-woo-search-results` and
	 * `.shift64-woo-search-shortcode .shift64-woo-search-results` have identical
	 * specificity — the modal's markup carries both classes — so the exemption only
	 * holds while it stays *after* the rule it is overriding. Reordering the file would
	 * silently reinstate the custom width inside the dialog, which is why the order is
	 * asserted rather than just the declaration.
	 */
	public function test_modal_tray_opts_out_of_the_custom_width() {
		$css = file_get_contents( SHIFT64_WOO_SEARCH_PATH . 'frontend/css/shift64-woo-search.css' );

		$shortcode = strpos( $css, '.shift64-woo-search-shortcode .shift64-woo-search-results {' );
		$modal     = strpos( $css, '.shift64-woo-search-modal__search .shift64-woo-search-results {' );

		$this->assertIsInt( $shortcode, 'The shortcode tray rule was not found.' );
		$this->assertIsInt( $modal, 'The modal tray exemption is missing.' );
		$this->assertGreaterThan(
			$shortcode,
			$modal,
			'The modal exemption must come after the shortcode rule or it loses on source order.'
		);

		preg_match(
			'/\.shift64-woo-search-modal__search \.shift64-woo-search-results \{(.*?)\}/s',
			$css,
			$matches
		);
		$this->assertMatchesRegularExpression(
			'/width:\s*100%/',
			$matches[1],
			'The modal tray must stay as wide as its dialog.'
		);
		$this->assertStringNotContainsString(
			'--s64ws-dropdown-width',
			$matches[1],
			'The modal tray must not consult the custom width at all.'
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
			'/^\s*width:\s*var\(--s64ws-dropdown-width,\s*auto\)/m',
			$rule,
			'The tray must size from --s64ws-dropdown-width, falling back to matching the field.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/^\s*min-width:\s*var\(--s64ws-dropdown-width/m',
			$rule,
			'A min-width floor leaves the setting inert on layouts wider than it.'
		);
	}
}
