<?php
/**
 * Single source for the search configuration held in options.
 *
 * The admin preview, the archive interceptor, and `wp shift64-woo-search test`
 * each used to assemble this array themselves. They drifted: the CLI copy read
 * neither `strategy` nor any `fallback_*` key, so it silently ran the class
 * defaults while the storefront ran the stored ones — the tool the docs
 * recommend for diagnosing relevance reproduced neither the dropdown nor the
 * results page. One reader keeps them honest.
 *
 * The SHORTINIT endpoint deliberately does not use this: it never boots the
 * options API, and reads the same values from the generated `config.php`
 * constants instead. Keep the two key sets in step.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads search configuration from WordPress options.
 */
class Shift64_Woo_Search_Settings {

	/**
	 * Build the search configuration array from stored options.
	 *
	 * Defaults here must match `Shift64_Woo_Search_Plugin::set_default_options()`
	 * and the fallbacks in `mu-plugins/endpoint.php`.
	 *
	 * @return array<string,mixed>
	 */
	public static function search_config() {
		return array(
			'min_query_length'              => (int) get_option( 'shift64_woo_search_min_query', 2 ),
			'autocomplete_limit'            => (int) get_option( 'shift64_woo_search_autocomplete_limit', 7 ),
			'full_limit'                    => (int) get_option( 'shift64_woo_search_full_limit', 20 ),
			'outofstock_mode'               => get_option( 'shift64_woo_search_outofstock_mode', 'exclude' ),
			'outofstock_demote_factor'      => (float) get_option( 'shift64_woo_search_outofstock_demote_factor', 0.3 ),
			'fuzzy_level'                   => (int) get_option( 'shift64_woo_search_fuzzy_level', 1 ),
			'logic'                         => get_option( 'shift64_woo_search_logic', 'AND' ),
			'strategy'                      => get_option( 'shift64_woo_search_strategy', 'mixed' ),
			'fallback_trigger'              => get_option( 'shift64_woo_search_fallback_trigger', 'low_score' ),
			'fallback_score_threshold'      => (float) get_option( 'shift64_woo_search_fallback_score_threshold', 0.5 ),
			'fallback_fuzzy_level'          => (int) get_option( 'shift64_woo_search_fallback_fuzzy_level', 1 ),
			'token_reduction_enabled'       => 'yes' === get_option( 'shift64_woo_search_token_reduction_enabled', 'yes' ),
			'weak_tokens'                   => get_option( 'shift64_woo_search_weak_tokens', 'do,na,z,i,w,od,po,za,ze,we,o,u,a,e' ),
			'drop_trailing_weak_token_only' => 'yes' === get_option( 'shift64_woo_search_drop_trailing_weak_token_only', 'yes' ),
			'diacritics_normalization'      => 'yes' === get_option( 'shift64_woo_search_diacritics_normalization', 'yes' ),
			'fuzzy_synonyms'                => 'yes' === get_option( 'shift64_woo_search_fuzzy_synonyms', 'no' ),
			'category_boost_rules'          => get_option( 'shift64_woo_search_category_boost_rules', '' ),
			'category_suggest_fuzzy'        => 'yes' === get_option( 'shift64_woo_search_category_suggest_fuzzy', 'no' ),
		);
	}
}
