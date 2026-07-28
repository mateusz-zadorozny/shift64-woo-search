<?php
/**
 * Admin — fixed route registry and resolver for the settings information architecture.
 *
 * The admin page is addressed by `tab` (workspace) and `section`. This class is the
 * single source of truth for which routes exist, what they are called, which one a
 * workspace lands on by default, and which renderer serves each of them.
 *
 * Two properties matter for safety:
 *
 * 1. Callbacks come only from this registry. Query input is never concatenated into a
 *    method name, so a crafted `tab` value cannot reach an arbitrary method.
 * 2. Resolution is total. Every input — including arrays, paths, markup, and method
 *    names — resolves to a declared route, so callers never have to re-validate.
 *
 * The registry is internal. It is not stored in `wp_options` and is not a public API.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed registry of admin workspaces, sections, legacy aliases, and render callbacks.
 */
class Shift64_Woo_Search_Admin_Routes {

	/**
	 * The workspace every unresolvable request lands on.
	 *
	 * @var string
	 */
	public const DEFAULT_WORKSPACE = 'overview';

	/**
	 * Ordered workspace registry.
	 *
	 * Array order is navigation order and is part of the contract. Each workspace
	 * declares a translated label, its default section slug, and its ordered sections.
	 * Each section declares a translated label and the explicit
	 * `Shift64_Woo_Search_Admin` method that renders it.
	 *
	 * Several sections deliberately point at a broader legacy renderer as a
	 * placeholder: the route exists and is stable from now on, while the split of the
	 * underlying form into per-section markup lands in later phases.
	 *
	 * @return array<string, array{label: string, default: string, sections: array<string, array{label: string, callback: string}>}> Ordered workspace map.
	 */
	public static function get_workspaces() {
		return array(
			'overview'   => array(
				'label'    => __( 'Overview', 'shift64-woo-search' ),
				'default'  => 'overview',
				'sections' => array(
					'overview' => array(
						'label'    => __( 'Overview', 'shift64-woo-search' ),
						'callback' => 'render_overview_tab',
					),
				),
			),
			'experience' => array(
				'label'    => __( 'Search Experience', 'shift64-woo-search' ),
				'default'  => 'search-field',
				'sections' => array(
					'search-field'         => array(
						'label'    => __( 'Search Field', 'shift64-woo-search' ),
						'callback' => 'render_experience_search_field_section',
					),
					'autocomplete'         => array(
						'label'    => __( 'Autocomplete', 'shift64-woo-search' ),
						'callback' => 'render_experience_autocomplete_section',
					),
					'query-suggestions'    => array(
						'label'    => __( 'Query Suggestions', 'shift64-woo-search' ),
						'callback' => 'render_experience_query_suggestions_section',
					),
					'category-suggestions' => array(
						'label'    => __( 'Category Suggestions', 'shift64-woo-search' ),
						'callback' => 'render_experience_category_suggestions_section',
					),
				),
			),
			'results'    => array(
				'label'    => __( 'Results & Filters', 'shift64-woo-search' ),
				'default'  => 'coverage',
				'sections' => array(
					'coverage' => array(
						'label'    => __( 'Result Coverage', 'shift64-woo-search' ),
						'callback' => 'render_search_tab',
					),
					'facets'   => array(
						'label'    => __( 'Facets', 'shift64-woo-search' ),
						'callback' => 'render_filters_tab',
					),
				),
			),
			'relevance'  => array(
				'label'    => __( 'Relevance', 'shift64-woo-search' ),
				'default'  => 'basic',
				'sections' => array(
					'basic'          => array(
						'label'    => __( 'Basic Ranking', 'shift64-woo-search' ),
						'callback' => 'render_search_tab',
					),
					'matching'       => array(
						'label'    => __( 'Matching & Fallback', 'shift64-woo-search' ),
						'callback' => 'render_search_tab',
					),
					'synonyms'       => array(
						'label'    => __( 'Synonyms', 'shift64-woo-search' ),
						// Intentional: the implemented renderer is `render_synonys64ws_tab()`.
						// The legacy dynamic router looked for a `render_synonyms_tab()` that
						// never existed, which is why the old Synonyms tab rendered blank.
						'callback' => 'render_synonys64ws_tab',
					),
					'merchandising'  => array(
						'label'    => __( 'Merchandising', 'shift64-woo-search' ),
						'callback' => 'render_search_tab',
					),
					'field-weights'  => array(
						'label'    => __( 'Field Weights', 'shift64-woo-search' ),
						'callback' => 'render_weights_tab',
					),
					'test-search'    => array(
						'label'    => __( 'Test Search', 'shift64-woo-search' ),
						'callback' => 'render_test_tab',
					),
					'compare-passes' => array(
						'label'    => __( 'Compare Passes', 'shift64-woo-search' ),
						'callback' => 'render_tuning_tab',
					),
				),
			),
			'insights'   => array(
				'label'    => __( 'Insights', 'shift64-woo-search' ),
				'default'  => 'statistics',
				'sections' => array(
					'statistics' => array(
						'label'    => __( 'Search Statistics', 'shift64-woo-search' ),
						'callback' => 'render_stats_tab',
					),
				),
			),
			'system'     => array(
				'label'    => __( 'System', 'shift64-woo-search' ),
				'default'  => 'connection',
				'sections' => array(
					'connection'  => array(
						'label'    => __( 'Connection', 'shift64-woo-search' ),
						'callback' => 'render_redis_tab',
					),
					'index'       => array(
						'label'    => __( 'Index & Health', 'shift64-woo-search' ),
						'callback' => 'render_index_tab',
					),
					'security'    => array(
						'label'    => __( 'Security & Traffic', 'shift64-woo-search' ),
						'callback' => 'render_redis_tab',
					),
					'diagnostics' => array(
						'label'    => __( 'Diagnostics', 'shift64-woo-search' ),
						'callback' => 'render_redis_tab',
					),
				),
			),
		);
	}

	/**
	 * Legacy `tab` values mapped to their canonical destination.
	 *
	 * These are the twelve tabs of the pre-migration admin page. Aliases resolve
	 * internally — no HTTP redirect — so existing bookmarks keep working without
	 * risking a redirect loop while internal links migrate to canonical URLs.
	 *
	 * An alias determines both the workspace and the section: legacy URLs never
	 * carried a `section`, so honouring one would make the destination depend on a
	 * parameter the legacy layout could not produce.
	 *
	 * @return array<string, array{0: string, 1: string}> Legacy tab slug => array( workspace, section ).
	 */
	public static function get_aliases() {
		return array(
			'test'        => array( 'relevance', 'test-search' ),
			'tuning'      => array( 'relevance', 'compare-passes' ),
			'synonyms'    => array( 'relevance', 'synonyms' ),
			'suggestions' => array( 'experience', 'query-suggestions' ),
			'catboost'    => array( 'experience', 'category-suggestions' ),
			'stats'       => array( 'insights', 'statistics' ),
			'weights'     => array( 'relevance', 'field-weights' ),
			'filters'     => array( 'results', 'facets' ),
			'index'       => array( 'system', 'index' ),
			'redis'       => array( 'system', 'connection' ),
			'search'      => array( 'relevance', 'basic' ),
			'frontend'    => array( 'experience', 'search-field' ),
		);
	}

	/**
	 * Resolve a requested route to a declared workspace, section, and render callback.
	 *
	 * Resolution rules:
	 *
	 * - A non-string or unknown `$tab` that is not a legacy alias resolves to `overview`.
	 * - A legacy alias resolves to its canonical destination and ignores `$section`.
	 * - A non-string or unknown `$section` resolves to the workspace's default section.
	 * - `overview` declares a single section, so it ignores `$section` by construction.
	 *
	 * Matching is exact and case-sensitive; `SEARCH` is not `search`.
	 *
	 * @param mixed $tab     Raw `tab` request value. Any type is accepted.
	 * @param mixed $section Raw `section` request value. Any type is accepted.
	 * @return array{workspace: string, section: string, callback: string} Resolved route.
	 */
	public static function resolve( $tab, $section = null ) {
		$workspaces = self::get_workspaces();

		$workspace_slug = is_string( $tab ) ? $tab : '';
		$section_slug   = is_string( $section ) ? $section : '';

		if ( ! isset( $workspaces[ $workspace_slug ] ) ) {
			$aliases = self::get_aliases();

			if ( isset( $aliases[ $workspace_slug ] ) ) {
				list( $workspace_slug, $section_slug ) = $aliases[ $workspace_slug ];
			} else {
				$workspace_slug = self::DEFAULT_WORKSPACE;
				$section_slug   = '';
			}
		}

		$workspace = $workspaces[ $workspace_slug ];

		if ( ! isset( $workspace['sections'][ $section_slug ] ) ) {
			$section_slug = $workspace['default'];
		}

		return array(
			'workspace' => $workspace_slug,
			'section'   => $section_slug,
			'callback'  => $workspace['sections'][ $section_slug ]['callback'],
		);
	}
}
