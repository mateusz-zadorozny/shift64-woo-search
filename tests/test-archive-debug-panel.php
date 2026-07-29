<?php
/**
 * Tests for the opt-in storefront debug panel.
 *
 * The panel is a fixed-position overlay drawn across the bottom of the
 * storefront. It used to render for anyone holding `manage_woocommerce`, which
 * meant shop managers got a debug bar over the shop whether they wanted one or
 * not. It is now gated on `shift64_woo_search_archive_debug_enabled`, which
 * defaults to off, with the capability check kept on top.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Archive debug panel gating tests.
 */
class Archive_Debug_Panel_Test extends WP_UnitTestCase {

	/**
	 * The option under test.
	 *
	 * @var string
	 */
	private const OPTION = 'shift64_woo_search_archive_debug_enabled';

	/**
	 * Build an archive instance carrying a non-empty debug log.
	 *
	 * The constructor registers hooks and bails in admin context, neither of
	 * which this behavior depends on — so the instance is built without it and
	 * the private log is seeded directly.
	 *
	 * @return Shift64_Woo_Search_Archive Instance with a seeded debug log.
	 */
	private function archive_with_log() {
		$archive = ( new ReflectionClass( Shift64_Woo_Search_Archive::class ) )->newInstanceWithoutConstructor();

		$log = new ReflectionProperty( Shift64_Woo_Search_Archive::class, 'debug_log' );
		$log->setAccessible( true );
		$log->setValue( $archive, array( '[0.0ms] Intercepted → q="aeon" page=1 per_page=12' ) );

		return $archive;
	}

	/**
	 * Invoke the private gate helper.
	 *
	 * @param Shift64_Woo_Search_Archive $archive Instance under test.
	 * @return bool Whether the panel may render.
	 */
	private function debug_enabled( $archive ) {
		$method = new ReflectionMethod( Shift64_Woo_Search_Archive::class, 'debug_enabled' );
		$method->setAccessible( true );

		return $method->invoke( $archive );
	}

	/**
	 * Sign in a user holding `manage_woocommerce`.
	 *
	 * WooCommerce is not installed in the test environment, so no role carries
	 * the capability — it is granted directly on the user instead.
	 *
	 * @return void
	 */
	private function login_shop_manager() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );
	}

	/**
	 * A fresh install has never been asked for the panel, so it stays dark even
	 * for a user who would otherwise be allowed to see it.
	 */
	public function test_panel_is_disabled_when_option_was_never_set() {
		delete_option( self::OPTION );
		$this->login_shop_manager();

		$this->assertFalse( $this->debug_enabled( $this->archive_with_log() ) );
	}

	/**
	 * An explicit "no" reads the same as an absent option.
	 */
	public function test_panel_is_disabled_when_option_is_no() {
		update_option( self::OPTION, 'no' );
		$this->login_shop_manager();

		$this->assertFalse( $this->debug_enabled( $this->archive_with_log() ) );
	}

	/**
	 * Opting in turns the panel on for a capable user — the whole point of the
	 * setting.
	 */
	public function test_panel_is_enabled_when_opted_in_by_capable_user() {
		update_option( self::OPTION, 'yes' );
		$this->login_shop_manager();

		$this->assertTrue( $this->debug_enabled( $this->archive_with_log() ) );
	}

	/**
	 * The option narrows who sees the panel; it never widens it. A shopper with
	 * the option on still gets nothing, so enabling debug output cannot leak
	 * query internals to the storefront at large.
	 */
	public function test_option_does_not_grant_the_panel_to_users_without_the_capability() {
		update_option( self::OPTION, 'yes' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( $this->debug_enabled( $this->archive_with_log() ) );

		wp_set_current_user( 0 );

		$this->assertFalse( $this->debug_enabled( $this->archive_with_log() ) );
	}

	/**
	 * A value that is neither "yes" nor "no" — a stale or hand-crafted write —
	 * must not be read as consent.
	 */
	public function test_unrecognized_option_value_does_not_enable_the_panel() {
		update_option( self::OPTION, '1' );
		$this->login_shop_manager();

		$this->assertFalse( $this->debug_enabled( $this->archive_with_log() ) );
	}

	/**
	 * The footer render path emits nothing at all while the panel is off — no
	 * bar, and no HTML comment either, since the comment leaks the same query
	 * internals to anyone reading the page source.
	 */
	public function test_footer_render_emits_nothing_while_disabled() {
		delete_option( self::OPTION );
		$this->login_shop_manager();

		$archive = $this->archive_with_log();

		ob_start();
		$archive->render_debug();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * With the panel on, the footer path renders both the source comment and the
	 * visible bar, carrying the logged lines.
	 */
	public function test_footer_render_emits_the_bar_once_enabled() {
		update_option( self::OPTION, 'yes' );
		$this->login_shop_manager();

		$archive = $this->archive_with_log();

		ob_start();
		$archive->render_debug();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'shift64-woo-search-debug-bar', $output );
		$this->assertStringContainsString( 'Shift64 Archive Debug', $output );
		$this->assertStringContainsString( 'Intercepted', $output );
	}

	/**
	 * An empty log renders nothing regardless of the option — a request that was
	 * never intercepted has nothing to report.
	 */
	public function test_enabled_panel_renders_nothing_without_a_log() {
		update_option( self::OPTION, 'yes' );
		$this->login_shop_manager();

		$archive = ( new ReflectionClass( Shift64_Woo_Search_Archive::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$archive->render_debug();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
