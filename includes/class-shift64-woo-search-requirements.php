<?php
/**
 * Runtime requirement checks for the block-native storefront.
 *
 * The plugin's storefront is a block theme rendering block templates around an
 * inherited WooCommerce Product Collection, driven by the block and
 * Interactivity APIs. That raises the floor: the declared minimums are not a
 * formality, they are the versions those APIs exist in.
 *
 * Two different situations are distinguished, because they deserve different
 * treatment:
 *
 * 1. **Below the version floor.** Block bootstrap does not run, because
 *    registering blocks against an API that is missing is how a plugin turns a
 *    version mismatch into a fatal. Redis, indexing, the SHORTINIT endpoint and
 *    the CLI keep working, and an admin notice says what to upgrade.
 * 2. **A classic theme on a supported version.** Everything runs. The plugin
 *    simply injects nothing into the theme's output, which is the whole point
 *    of the block-theme-only release, so the notice explains the frontend
 *    requirement rather than reporting a fault.
 *
 * Nothing here ever calls `wp_die()` or triggers an error. A merchant who
 * upgrades into an unsupported combination gets a storefront that still sells
 * and an explanation, not a white screen.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves whether the runtime satisfies the declared minimums.
 */
class Shift64_Woo_Search_Requirements {

	/**
	 * Minimum supported WordPress version.
	 *
	 * Keep in step with the plugin header, `readme.txt`, the documentation, and
	 * `tests/test-php-requirement-declarations.php`, which pins all of them to
	 * one value.
	 */
	const MIN_WP = '7.0';

	/**
	 * Minimum supported WooCommerce version.
	 */
	const MIN_WC = '10.9';

	/**
	 * Describe every unmet version requirement.
	 *
	 * @return string[] Human-readable, actionable descriptions; empty when the
	 *                  runtime is supported, or when a version cannot be read.
	 */
	public static function unmet() {
		$unmet = array();

		if ( version_compare( self::wordpress_version(), self::MIN_WP, '<' ) ) {
			$unmet[] = sprintf(
				/* translators: 1: required WordPress version, 2: running WordPress version. */
				__( 'WordPress %1$s or newer is required; this site runs %2$s.', 'shift64-woo-search' ),
				self::MIN_WP,
				self::wordpress_version()
			);
		}

		// A version we cannot determine is deliberately not treated as unmet.
		// The plugin already returns early when WooCommerce is not among the
		// active plugins, so reaching here means it is active; if its version is
		// somehow unreadable, switching the whole block layer off would be a
		// worse outcome than trusting it. The guard fails open on "unknown" and
		// closed only on a version it can positively read as too old.
		$woocommerce = self::woocommerce_version();

		if ( null !== $woocommerce && version_compare( $woocommerce, self::MIN_WC, '<' ) ) {
			$unmet[] = sprintf(
				/* translators: 1: required WooCommerce version, 2: running WooCommerce version. */
				__( 'WooCommerce %1$s or newer is required; this site runs %2$s.', 'shift64-woo-search' ),
				self::MIN_WC,
				$woocommerce
			);
		}

		return $unmet;
	}

	/**
	 * Whether the runtime satisfies every declared minimum.
	 *
	 * @return bool
	 */
	public static function are_met() {
		return array() === self::unmet();
	}

	/**
	 * Whether the active theme is a block theme.
	 *
	 * A classic theme is supported in the sense that nothing breaks — the search
	 * endpoint, indexing and the CLI are theme-agnostic — but no storefront
	 * control is rendered, because the plugin no longer injects markup into a
	 * theme it does not own.
	 *
	 * @return bool
	 */
	public static function block_theme_active() {
		return function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false;
	}

	/**
	 * The running WordPress version, without any `-beta`/`-RC` suffix.
	 *
	 * `version_compare()` treats `7.0-RC1` as older than `7.0`, which would make
	 * a release candidate of the very version being required read as unmet.
	 *
	 * @return string
	 */
	private static function wordpress_version() {
		$version = get_bloginfo( 'version' );

		return (string) preg_replace( '/-.*$/', '', (string) $version );
	}

	/**
	 * The running WooCommerce version, or null when WooCommerce is absent.
	 *
	 * @return string|null
	 */
	private static function woocommerce_version() {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		if ( class_exists( 'WooCommerce' ) && isset( WooCommerce::instance()->version ) ) {
			return (string) WooCommerce::instance()->version;
		}

		return null;
	}
}
