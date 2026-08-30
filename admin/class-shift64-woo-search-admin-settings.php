<?php
/**
 * Admin — allowlisted persistence seam for the generic settings save.
 *
 * The generic `shift64_woo_search_save_setting` AJAX action accepts an arbitrary
 * subset of the settings surface, which is what lets several section forms share
 * one handler. This class is that handler's entire persistence logic, extracted
 * so it can be exercised without an HTTP request, a nonce, or a capability.
 *
 * Two properties matter:
 *
 * 1. Allowlisted. Only keys declared here are written, so a hand-crafted payload
 *    cannot reach an unrelated option.
 * 2. Payload-scoped. Every write — including the Redis credential cleanup — is
 *    decided from the submitted payload alone, never from stored state. A form
 *    that does not submit a field can therefore never change it, which is what
 *    makes section-scoped partial saves safe.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists an allowlisted subset of plugin settings.
 */
class Shift64_Woo_Search_Admin_Settings {

	/**
	 * Scalar options, stored as sanitized strings.
	 *
	 * Consumers cast on read, so values stay strings here — `'30'`, not `30`.
	 *
	 * Dropping a key from this list is how the block-theme-only cleanup made the
	 * retired appearance, selector and tray-width settings inert: their stored
	 * rows are left exactly as they are, so rolling the plugin back finds them,
	 * but nothing in WP Admin renders them and no payload can write them.
	 *
	 * @return string[] Allowlisted scalar option names.
	 */
	private static function scalar_options() {
		return array(
			'shift64_woo_search_redis_host',
			'shift64_woo_search_redis_port',
			'shift64_woo_search_redis_auth_enabled',
			'shift64_woo_search_redis_username',
			'shift64_woo_search_redis_password',
			'shift64_woo_search_redis_db',
			'shift64_woo_search_redis_prefix',
			'shift64_woo_search_min_query',
			'shift64_woo_search_autocomplete_limit',
			'shift64_woo_search_show_sku',
			'shift64_woo_search_show_category',
			'shift64_woo_search_show_brand',
			'shift64_woo_search_full_limit',
			'shift64_woo_search_fuzzy_level',
			'shift64_woo_search_logic',
			'shift64_woo_search_outofstock_mode',
			'shift64_woo_search_outofstock_demote_factor',
			'shift64_woo_search_debounce',
			'shift64_woo_search_strategy',
			'shift64_woo_search_fallback_trigger',
			'shift64_woo_search_fallback_score_threshold',
			'shift64_woo_search_fallback_fuzzy_level',
			'shift64_woo_search_token_reduction_enabled',
			'shift64_woo_search_weak_tokens',
			'shift64_woo_search_drop_trailing_weak_token_only',
			'shift64_woo_search_diacritics_normalization',
			'shift64_woo_search_fuzzy_synonyms',
			'shift64_woo_search_category_suggest_fuzzy',
			'shift64_woo_search_brand_suggest_enabled',
			'shift64_woo_search_archive_enabled',
			'shift64_woo_search_archive_debug_enabled',
			'shift64_woo_search_price_sort_mode',
			'shift64_woo_search_rate_limit',
		);
	}

	/**
	 * Array-valued options (multi-select / multi-checkbox).
	 *
	 * These need per-value sanitization rather than sanitize_text_field on the
	 * whole value.
	 *
	 * @return string[] Allowlisted array option names.
	 */
	private static function array_options() {
		return array(
			'shift64_woo_search_taxonomy_archive_scopes',
		);
	}

	/**
	 * Multi-line options, sanitized with newlines preserved.
	 *
	 * @return string[] Allowlisted textarea option names.
	 */
	private static function textarea_options() {
		return array(
			'shift64_woo_search_category_boost_rules',
			'shift64_woo_search_category_pin_rules',
		);
	}

	/**
	 * Persist the allowlisted keys present in a settings payload.
	 *
	 * Keys absent from `$settings` are left untouched — that is the contract the
	 * section forms rely on. Non-allowlisted keys are silently ignored and do not
	 * count towards the returned total.
	 *
	 * @param array $settings Raw (unslashed) key => value payload.
	 * @return int Number of options written.
	 */
	public static function persist( array $settings ) {
		$scalars   = self::scalar_options();
		$arrays    = self::array_options();
		$textareas = self::textarea_options();

		$saved         = 0;
		$disables_auth = false;

		foreach ( $settings as $key => $value ) {
			$key = sanitize_text_field( $key );

			if ( in_array( $key, $textareas, true ) ) {
				$textarea_value = is_scalar( $value ) ? (string) $value : '';
				update_option( $key, sanitize_textarea_field( $textarea_value ) );
				++$saved;
			} elseif ( in_array( $key, $scalars, true ) ) {
				$scalar_value = sanitize_text_field( $value );

				if ( 'shift64_woo_search_redis_auth_enabled' === $key && 'no' === $scalar_value ) {
					$disables_auth = true;
				}

				update_option( $key, $scalar_value );
				++$saved;
			} elseif ( in_array( $key, $arrays, true ) ) {
				$array_value = is_array( $value ) ? $value : array();
				$sanitized   = array_values( array_filter( array_map( 'sanitize_text_field', $array_value ) ) );

				// Array options with a known closed set of valid values get filtered
				// to those keys — prevents stale/hand-crafted POSTs from writing
				// junk into wp_options.
				if ( 'shift64_woo_search_taxonomy_archive_scopes' === $key ) {
					$valid_scopes = array_keys( Shift64_Woo_Search_Taxonomy_Archive::get_scope_map() );
					$sanitized    = array_values( array_intersect( $sanitized, $valid_scopes ) );
				}

				update_option( $key, $sanitized );
				++$saved;
			}
		}

		// Turning auth off wipes any stored credentials, so the regenerated SHORTINIT
		// config cannot keep using them. The trigger is the submitted payload, not the
		// stored option: a section form that never shows the auth toggle must not be
		// able to clear credentials just because auth happens to be off already.
		if ( $disables_auth ) {
			update_option( 'shift64_woo_search_redis_username', '' );
			update_option( 'shift64_woo_search_redis_password', '' );
		}

		return $saved;
	}
}
