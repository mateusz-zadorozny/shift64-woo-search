<?php
/**
 * The declared set of deprecated option *values* and who still has one stored.
 *
 * Deliberately separate from `Shift64_Woo_Search_Settings`. That class's own
 * docblock scopes it to being the single reader of the search configuration,
 * and records that the SHORTINIT endpoint intentionally does not use it.
 * Nothing here is ever read by the query path, the archive interceptor, or the
 * generated mu-plugin config — it exists for the admin screens and WP-CLI —
 * so folding it in would widen a remit whose narrowness is the documented
 * point.
 *
 * "Deprecated" here means *documented as going away*, not *degraded*. Every
 * value listed below is still readable, still writable, and still honored at
 * runtime exactly as before; the registry only decides what the admin and
 * `wp shift64-woo-search health` say about it. Removal is a separate,
 * breaking change that needs the migration path in
 * `BACKWARD_COMPATIBILITY.md` §6.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of deprecated option values, and the store's current exposure to them.
 */
class Shift64_Woo_Search_Deprecations {

	/**
	 * Every deprecated option value this release knows about.
	 *
	 * Keyed by option name, then by the deprecated stored value. Each entry
	 * carries what the admin and the CLI need to describe it without either
	 * surface re-deriving the wording:
	 *
	 * - `field`       — the settings field's label, so a message can name the
	 *                   control a merchant sees rather than the option key.
	 * - `value_label` — how the deprecated value reads in the dropdown.
	 * - `reason`      — one merchant-facing sentence on why it is going away
	 *                   and what to use instead.
	 * - `workspace` / `section` — the admin route that owns the field, so the
	 *                   notice can link straight at it.
	 *
	 * @return array<string,array<string,array<string,string>>>
	 */
	public static function registry() {
		return array(
			'shift64_woo_search_logic'             => array(
				'OR' => array(
					'field'       => __( 'Search Logic', 'shift64-woo-search' ),
					'value_label' => __( 'OR — any term matches', 'shift64-woo-search' ),
					'reason'      => __( 'Measured worse than AND on both precision and latency, with no case where it wins. Switch to AND — the strict-first ladder already widens the query when an exact pass finds nothing.', 'shift64-woo-search' ),
					'workspace'   => 'relevance',
					'section'     => 'basic',
				),
			),
			'shift64_woo_search_fallback_trigger'  => array(
				'no_results' => array(
					'field'       => __( 'Fallback Trigger', 'shift64-woo-search' ),
					'value_label' => __( 'Only when no results', 'shift64-woo-search' ),
					'reason'      => __( 'This disables the fallback ladder rather than tightening it: any non-empty first pass wins, so a typo in one word of a multi-word query is never repaired. Switch to the recommended trigger — Strict-first already keeps its first pass exact.', 'shift64-woo-search' ),
					'workspace'   => 'relevance',
					'section'     => 'matching',
				),
			),
		);
	}

	/**
	 * The deprecated values this store currently has stored, in registry order.
	 *
	 * Derived at read time from `get_option()`, so it needs no cache and no
	 * invalidation: the moment a merchant changes the setting, the entry stops
	 * being returned. Matching is exact and registry-driven — a hand-edited
	 * option holding something nobody declared is not reported, because the
	 * registry describes values this plugin shipped, not everything that is
	 * not the recommended one.
	 *
	 * Note that an option row which does not exist at all resolves to the
	 * plugin's default, which is the recommended value for both keys, so
	 * "never saved" and "saved to the recommended value" behave identically.
	 *
	 * @return array<int,array<string,string>> Registry entries, each with `option` and `value` added.
	 */
	public static function stored() {
		$found = array();

		foreach ( self::registry() as $option => $deprecated_values ) {
			$current = get_option( $option, '' );

			if ( ! is_string( $current ) || ! isset( $deprecated_values[ $current ] ) ) {
				continue;
			}

			$found[] = array_merge(
				$deprecated_values[ $current ],
				array(
					'option' => $option,
					'value'  => $current,
				)
			);
		}

		return $found;
	}

	/**
	 * The deprecated values declared for one option, for the field renderer.
	 *
	 * @param string $option Option name.
	 * @return array<string,string> Deprecated value => merchant-facing reason. Empty when the option has none.
	 */
	public static function for_option( $option ) {
		$registry = self::registry();

		if ( ! isset( $registry[ $option ] ) ) {
			return array();
		}

		return wp_list_pluck( $registry[ $option ], 'reason' );
	}
}
