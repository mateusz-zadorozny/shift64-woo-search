<?php
/**
 * Block-theme-only upgrade notice tests.
 *
 * The notice exists because this release changes a storefront on update, with no
 * action from the merchant. That makes it the one piece of UI in the plugin
 * whose job is to be seen exactly once by exactly the right person — which is
 * also what makes it easy to get wrong in both directions: shown to shoppers or
 * to editors who cannot act on it, or impossible to get rid of.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Visibility, dismissal and scoping of the upgrade notice.
 */
class Shift64_Woo_Search_Upgrade_Notice_Test extends WP_UnitTestCase {

	/**
	 * Capture the notice output for the current user.
	 *
	 * @return string
	 */
	private function render_notices() {
		ob_start();
		Shift64_Woo_Search_Plugin::get_instance()->admin_notices();

		return (string) ob_get_clean();
	}

	/**
	 * Log in as a user of the given role.
	 *
	 * @param string $role Role slug.
	 * @return int User ID.
	 */
	private function login_as( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * A shop manager sees the notice, with the migration guide linked.
	 *
	 * The link is the actionable part. A notice that says the storefront changed
	 * without saying where to read what to do about it is just alarming.
	 */
	public function test_a_manager_sees_the_notice_and_the_migration_guide_link() {
		$this->login_as( 'administrator' );

		$html = $this->render_notices();

		$this->assertStringContainsString( 'the storefront is now block-only', $html );
		$this->assertStringContainsString(
			esc_url( Shift64_Woo_Search_Plugin::MIGRATION_GUIDE_URL ),
			$html
		);
		$this->assertStringContainsString( 'Read the migration guide', $html );
	}

	/**
	 * A user who cannot manage the plugin never sees it.
	 *
	 * An author or contributor cannot edit site templates, so the notice would be
	 * an unactionable interruption on every admin page they open.
	 */
	public function test_a_user_without_management_capability_sees_nothing() {
		foreach ( array( 'author', 'contributor', 'subscriber' ) as $role ) {
			$this->login_as( $role );

			$this->assertStringNotContainsString(
				'the storefront is now block-only',
				$this->render_notices(),
				sprintf( 'The %s role cannot act on this notice and must not see it.', $role )
			);
		}
	}

	/**
	 * Dismissing it records the decision against that user and hides it.
	 */
	public function test_dismissal_is_recorded_per_user() {
		$user_id = $this->login_as( 'administrator' );

		update_user_meta(
			$user_id,
			Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_META,
			Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_ID
		);

		$this->assertStringNotContainsString( 'the storefront is now block-only', $this->render_notices() );
	}

	/**
	 * One user's dismissal does not silence the notice for anyone else.
	 *
	 * Storing it as user meta rather than an option is what makes that true, and
	 * on a multi-administrator store it is the difference between everyone being
	 * told and only the first person to log in being told.
	 */
	public function test_one_users_dismissal_does_not_hide_it_from_another() {
		$first = $this->login_as( 'administrator' );
		update_user_meta(
			$first,
			Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_META,
			Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_ID
		);
		$this->assertStringNotContainsString( 'the storefront is now block-only', $this->render_notices() );

		$this->login_as( 'administrator' );
		$this->assertStringContainsString( 'the storefront is now block-only', $this->render_notices() );
	}

	/**
	 * A dismissal recorded for a different notice does not hide this one.
	 *
	 * The meta stores which notice was dismissed rather than a boolean, so a
	 * later breaking release can raise its own notice and still be seen by
	 * someone who dismissed this one.
	 */
	public function test_a_dismissal_of_another_notice_does_not_hide_this_one() {
		$user_id = $this->login_as( 'administrator' );

		update_user_meta(
			$user_id,
			Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_META,
			'some-future-release'
		);

		$this->assertStringContainsString( 'the storefront is now block-only', $this->render_notices() );
	}

	/**
	 * The dismissal link carries a nonce.
	 *
	 * Without one, any page could dismiss the notice on an administrator's behalf
	 * simply by getting their browser to load a URL.
	 */
	public function test_the_dismiss_link_is_nonce_protected() {
		$this->login_as( 'administrator' );

		$this->assertMatchesRegularExpression(
			'/' . preg_quote( Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_QUERY_ARG, '/' ) . '=1[^"]*_wpnonce=/',
			$this->render_notices()
		);
	}

	/**
	 * A dismissal request without a valid nonce records nothing.
	 */
	public function test_dismissal_without_a_valid_nonce_is_ignored() {
		$user_id = $this->login_as( 'administrator' );

		$_GET[ Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_QUERY_ARG ] = '1';
		$_GET['_wpnonce'] = 'not-a-real-nonce';

		Shift64_Woo_Search_Plugin::get_instance()->maybe_dismiss_upgrade_notice();

		unset( $_GET[ Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_QUERY_ARG ], $_GET['_wpnonce'] );

		$this->assertSame(
			'',
			get_user_meta( $user_id, Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_META, true )
		);
	}

	/**
	 * A dismissal request from a user without the capability records nothing.
	 *
	 * A valid nonce proves who sent the request, not what they may do, so the
	 * capability is checked again at the handler.
	 */
	public function test_dismissal_without_the_capability_is_ignored() {
		$user_id = $this->login_as( 'subscriber' );

		$_GET[ Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_QUERY_ARG ] = '1';
		$_GET['_wpnonce'] = wp_create_nonce( Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_QUERY_ARG );

		Shift64_Woo_Search_Plugin::get_instance()->maybe_dismiss_upgrade_notice();

		unset( $_GET[ Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_QUERY_ARG ], $_GET['_wpnonce'] );

		$this->assertSame(
			'',
			get_user_meta( $user_id, Shift64_Woo_Search_Plugin::UPGRADE_NOTICE_META, true )
		);
	}
}
