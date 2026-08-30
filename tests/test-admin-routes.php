<?php
/**
 * Tests for Shift64_Woo_Search_Admin_Routes.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Fixed admin route registry and resolver tests.
 *
 * The registry is the only thing standing between a query-string value and a method
 * call on the admin controller, so these tests pin three properties:
 *
 * 1. Shape — the documented workspace order, section set, defaults, and aliases.
 * 2. Totality — every input resolves to a declared route, hostile values included.
 * 3. Containment — a resolved callback is always one the registry itself declared,
 *    never something derived from input.
 */
class Shift64_Woo_Search_Admin_Routes_Test extends WP_UnitTestCase {

	/**
	 * Every callback name declared anywhere in the registry.
	 *
	 * @return string[] Declared render callback method names.
	 */
	private function declared_callbacks() {
		$callbacks = array();

		foreach ( Shift64_Woo_Search_Admin_Routes::get_workspaces() as $workspace ) {
			foreach ( $workspace['sections'] as $section ) {
				$callbacks[] = $section['callback'];
			}
		}

		return array_values( array_unique( $callbacks ) );
	}

	// ── Registry shape ─────────────────────────────────────────

	/**
	 * Navigation order is part of the contract, not an implementation detail.
	 */
	public function test_workspace_order_is_exactly_the_documented_order() {
		$this->assertSame(
			array( 'overview', 'experience', 'results', 'relevance', 'insights', 'system' ),
			array_keys( Shift64_Woo_Search_Admin_Routes::get_workspaces() )
		);
	}

	/**
	 * Each workspace declares the labels, default, and section order the spec documents.
	 *
	 * @dataProvider workspace_shape_provider
	 *
	 * @param string   $workspace_slug   Workspace slug under test.
	 * @param string   $expected_label   Expected translated label.
	 * @param string   $expected_default Expected default section slug.
	 * @param string[] $expected_order   Expected ordered section slugs.
	 */
	public function test_workspace_declares_documented_sections_and_default( $workspace_slug, $expected_label, $expected_default, $expected_order ) {
		$workspaces = Shift64_Woo_Search_Admin_Routes::get_workspaces();

		$this->assertArrayHasKey( $workspace_slug, $workspaces );
		$this->assertSame( $expected_label, $workspaces[ $workspace_slug ]['label'] );
		$this->assertSame( $expected_default, $workspaces[ $workspace_slug ]['default'] );
		$this->assertSame( $expected_order, array_keys( $workspaces[ $workspace_slug ]['sections'] ) );
		$this->assertArrayHasKey(
			$expected_default,
			$workspaces[ $workspace_slug ]['sections'],
			'A workspace default must be one of its own sections.'
		);
	}

	public function workspace_shape_provider() {
		return array(
			'overview'   => array( 'overview', 'Overview', 'overview', array( 'overview' ) ),
			'experience' => array(
				'experience',
				'Search Experience',
				'autocomplete',
				array( 'autocomplete', 'query-suggestions', 'category-suggestions' ),
			),
			'results'    => array( 'results', 'Results & Filters', 'coverage', array( 'coverage', 'facets' ) ),
			'relevance'  => array(
				'relevance',
				'Relevance',
				'basic',
				array( 'basic', 'matching', 'synonyms', 'merchandising', 'field-weights', 'test-search', 'compare-passes' ),
			),
			'insights'   => array( 'insights', 'Insights', 'statistics', array( 'statistics' ) ),
			'system'     => array( 'system', 'System', 'connection', array( 'connection', 'index', 'security', 'diagnostics' ) ),
		);
	}

	public function test_registry_declares_exactly_eighteen_canonical_routes() {
		$count = 0;

		foreach ( Shift64_Woo_Search_Admin_Routes::get_workspaces() as $workspace ) {
			$count += count( $workspace['sections'] );
		}

		$this->assertSame( 18, $count );
	}

	public function test_every_section_declares_a_label_and_a_callback() {
		foreach ( Shift64_Woo_Search_Admin_Routes::get_workspaces() as $workspace_slug => $workspace ) {
			foreach ( $workspace['sections'] as $section_slug => $section ) {
				$route = "{$workspace_slug}/{$section_slug}";

				$this->assertArrayHasKey( 'label', $section, "{$route} is missing a label." );
				$this->assertNotSame( '', $section['label'], "{$route} has an empty label." );
				$this->assertArrayHasKey( 'callback', $section, "{$route} is missing a callback." );
				$this->assertMatchesRegularExpression( '/^render_[a-z0-9_]+$/', $section['callback'], "{$route} has a suspicious callback name." );
			}
		}
	}

	// ── Canonical destinations ─────────────────────────────────

	/**
	 * Every canonical destination resolves to exactly the callback the registry declares.
	 *
	 * @dataProvider canonical_route_provider
	 *
	 * @param string $workspace Workspace slug.
	 * @param string $section   Section slug.
	 * @param string $callback  Expected render callback.
	 */
	public function test_canonical_route_resolves_to_its_declared_callback( $workspace, $section, $callback ) {
		$this->assertSame(
			array(
				'workspace' => $workspace,
				'section'   => $section,
				'callback'  => $callback,
			),
			Shift64_Woo_Search_Admin_Routes::resolve( $workspace, $section )
		);
	}

	public function canonical_route_provider() {
		return array(
			'overview/overview'               => array( 'overview', 'overview', 'render_overview_tab' ),
			'experience/autocomplete'         => array( 'experience', 'autocomplete', 'render_experience_autocomplete_section' ),
			'experience/query-suggestions'    => array( 'experience', 'query-suggestions', 'render_experience_query_suggestions_section' ),
			'experience/category-suggestions' => array( 'experience', 'category-suggestions', 'render_experience_category_suggestions_section' ),
			'results/coverage'                => array( 'results', 'coverage', 'render_results_coverage_section' ),
			'results/facets'                  => array( 'results', 'facets', 'render_results_facets_section' ),
			'relevance/basic'                 => array( 'relevance', 'basic', 'render_relevance_basic_section' ),
			'relevance/matching'              => array( 'relevance', 'matching', 'render_relevance_matching_section' ),
			'relevance/synonyms'              => array( 'relevance', 'synonyms', 'render_synonys64ws_tab' ),
			'relevance/merchandising'         => array( 'relevance', 'merchandising', 'render_relevance_merchandising_section' ),
			'relevance/field-weights'         => array( 'relevance', 'field-weights', 'render_weights_tab' ),
			'relevance/test-search'           => array( 'relevance', 'test-search', 'render_test_tab' ),
			'relevance/compare-passes'        => array( 'relevance', 'compare-passes', 'render_tuning_tab' ),
			'insights/statistics'             => array( 'insights', 'statistics', 'render_stats_tab' ),
			'system/connection'               => array( 'system', 'connection', 'render_system_connection_section' ),
			'system/index'                    => array( 'system', 'index', 'render_index_tab' ),
			'system/security'                 => array( 'system', 'security', 'render_system_security_section' ),
			'system/diagnostics'              => array( 'system', 'diagnostics', 'render_system_diagnostics_section' ),
		);
	}

	/**
	 * The Synonyms defect this migration fixes: the legacy dynamic router built
	 * `render_synonyms_tab`, which does not exist, so the tab rendered blank. The
	 * registry must name the renderer that is actually implemented.
	 */
	public function test_synonyms_routes_point_at_the_implemented_renderer() {
		$this->assertFalse( method_exists( Shift64_Woo_Search_Admin::class, 'render_synonyms_tab' ) );

		$canonical = Shift64_Woo_Search_Admin_Routes::resolve( 'relevance', 'synonyms' );
		$legacy    = Shift64_Woo_Search_Admin_Routes::resolve( 'synonyms', null );

		$this->assertSame( 'render_synonys64ws_tab', $canonical['callback'] );
		$this->assertSame( $canonical, $legacy );
	}

	/**
	 * Every callback in the registry must exist on the admin controller, otherwise a
	 * route silently renders nothing — exactly the legacy Synonyms failure mode.
	 */
	public function test_declared_callbacks_exist_on_the_admin_controller() {
		foreach ( $this->declared_callbacks() as $callback ) {
			$this->assertTrue(
				method_exists( Shift64_Woo_Search_Admin::class, $callback ),
				"Registry declares {$callback}(), which does not exist on Shift64_Woo_Search_Admin."
			);
		}
	}

	/**
	 * The legacy Search tab was a mixed page whose fields now live in four different
	 * sections. Its renderer is gone rather than kept as a shim, so nothing can
	 * accidentally render an owned field at two addresses; the `search` alias reaches
	 * Basic Ranking through the registry instead.
	 */
	public function test_the_legacy_search_renderer_is_gone() {
		$this->assertFalse( method_exists( Shift64_Woo_Search_Admin::class, 'render_search_tab' ) );
		$this->assertNotContains( 'render_search_tab', $this->declared_callbacks() );
	}

	/**
	 * The legacy Redis tab bundled connection credentials, rate limiting, and the
	 * generated-config readout into one form. Phase 1 pointed three System routes at
	 * it as a placeholder; each now owns its own renderer, and the shared one is gone
	 * so the placeholder cannot creep back in.
	 */
	public function test_the_legacy_redis_renderer_is_gone() {
		$this->assertFalse( method_exists( Shift64_Woo_Search_Admin::class, 'render_redis_tab' ) );
		$this->assertNotContains( 'render_redis_tab', $this->declared_callbacks() );
	}

	/**
	 * No two sections may share a renderer. A shared renderer is exactly how a
	 * placeholder hides: the route looks correct, but the section renders — and saves
	 * — fields another section owns. One callback per section is the structural
	 * guarantee that every option has exactly one address.
	 */
	public function test_every_section_declares_its_own_renderer() {
		$sections = 0;
		foreach ( Shift64_Woo_Search_Admin_Routes::get_workspaces() as $workspace ) {
			$sections += count( $workspace['sections'] );
		}

		$this->assertSame( $sections, count( $this->declared_callbacks() ), 'Two sections share a render callback.' );
	}

	// ── Legacy aliases ─────────────────────────────────────────

	/**
	 * Each legacy tab lands on its canonical destination.
	 *
	 * @dataProvider alias_provider
	 *
	 * @param string $legacy_tab Legacy tab slug.
	 * @param string $workspace  Expected canonical workspace.
	 * @param string $section    Expected canonical section.
	 */
	public function test_legacy_alias_resolves_to_its_canonical_destination( $legacy_tab, $workspace, $section ) {
		$resolved = Shift64_Woo_Search_Admin_Routes::resolve( $legacy_tab, null );

		$this->assertSame( $workspace, $resolved['workspace'] );
		$this->assertSame( $section, $resolved['section'] );
		$this->assertSame(
			Shift64_Woo_Search_Admin_Routes::resolve( $workspace, $section ),
			$resolved,
			'An alias must be indistinguishable from its canonical route.'
		);
	}

	public function alias_provider() {
		return array(
			'test'        => array( 'test', 'relevance', 'test-search' ),
			'tuning'      => array( 'tuning', 'relevance', 'compare-passes' ),
			'synonyms'    => array( 'synonyms', 'relevance', 'synonyms' ),
			'suggestions' => array( 'suggestions', 'experience', 'query-suggestions' ),
			'catboost'    => array( 'catboost', 'experience', 'category-suggestions' ),
			'stats'       => array( 'stats', 'insights', 'statistics' ),
			'weights'     => array( 'weights', 'relevance', 'field-weights' ),
			'filters'     => array( 'filters', 'results', 'facets' ),
			'index'       => array( 'index', 'system', 'index' ),
			'redis'       => array( 'redis', 'system', 'connection' ),
			'search'      => array( 'search', 'relevance', 'basic' ),
			'frontend'    => array( 'frontend', 'experience', 'autocomplete' ),
		);
	}

	public function test_registry_declares_exactly_the_twelve_legacy_aliases() {
		$this->assertSame(
			array( 'test', 'tuning', 'synonyms', 'suggestions', 'catboost', 'stats', 'weights', 'filters', 'index', 'redis', 'search', 'frontend' ),
			array_keys( Shift64_Woo_Search_Admin_Routes::get_aliases() )
		);
	}

	public function test_every_alias_target_exists_in_the_registry() {
		$workspaces = Shift64_Woo_Search_Admin_Routes::get_workspaces();

		foreach ( Shift64_Woo_Search_Admin_Routes::get_aliases() as $legacy_tab => $target ) {
			list( $workspace, $section ) = $target;

			$this->assertArrayHasKey( $workspace, $workspaces, "Alias {$legacy_tab} targets unknown workspace {$workspace}." );
			$this->assertArrayHasKey(
				$section,
				$workspaces[ $workspace ]['sections'],
				"Alias {$legacy_tab} targets unknown section {$workspace}/{$section}."
			);
		}
	}

	/**
	 * No alias may shadow a canonical workspace slug, otherwise the same URL would
	 * mean two different things depending on lookup order.
	 */
	public function test_no_alias_collides_with_a_workspace_slug() {
		$this->assertSame(
			array(),
			array_intersect(
				array_keys( Shift64_Woo_Search_Admin_Routes::get_aliases() ),
				array_keys( Shift64_Woo_Search_Admin_Routes::get_workspaces() )
			)
		);
	}

	/**
	 * A legacy URL could never carry a `section`, so the alias — not the query
	 * string — decides the destination.
	 */
	public function test_alias_ignores_a_supplied_section() {
		$this->assertSame(
			Shift64_Woo_Search_Admin_Routes::resolve( 'redis', null ),
			Shift64_Woo_Search_Admin_Routes::resolve( 'redis', 'diagnostics' )
		);
	}

	// ── Defaults ───────────────────────────────────────────────

	/**
	 * A workspace with no usable section falls back to its documented default.
	 *
	 * @dataProvider workspace_default_provider
	 *
	 * @param string $workspace Workspace slug.
	 * @param string $section   Expected default section slug.
	 */
	public function test_missing_section_resolves_to_the_workspace_default( $workspace, $section ) {
		foreach ( array( null, '' ) as $missing ) {
			$resolved = Shift64_Woo_Search_Admin_Routes::resolve( $workspace, $missing );

			$this->assertSame( $workspace, $resolved['workspace'] );
			$this->assertSame( $section, $resolved['section'] );
		}
	}

	public function workspace_default_provider() {
		return array(
			'overview'   => array( 'overview', 'overview' ),
			'experience' => array( 'experience', 'autocomplete' ),
			'results'    => array( 'results', 'coverage' ),
			'relevance'  => array( 'relevance', 'basic' ),
			'insights'   => array( 'insights', 'statistics' ),
			'system'     => array( 'system', 'connection' ),
		);
	}

	/**
	 * A section belonging to another workspace is not valid here; it falls back to
	 * the requested workspace's default rather than switching workspaces.
	 */
	public function test_section_from_another_workspace_falls_back_to_the_default() {
		$resolved = Shift64_Woo_Search_Admin_Routes::resolve( 'system', 'facets' );

		$this->assertSame( 'system', $resolved['workspace'] );
		$this->assertSame( 'connection', $resolved['section'] );
	}

	/**
	 * Overview declares a single section, so any `section` value lands on it.
	 */
	public function test_overview_ignores_the_section_parameter() {
		$expected = array(
			'workspace' => 'overview',
			'section'   => 'overview',
			'callback'  => 'render_overview_tab',
		);

		foreach ( array( null, '', 'overview', 'facets', 'statistics', '../../etc/passwd' ) as $section ) {
			$this->assertSame( $expected, Shift64_Woo_Search_Admin_Routes::resolve( 'overview', $section ) );
		}
	}

	// ── Hostile and invalid input ──────────────────────────────

	/**
	 * Anything that is not a workspace slug or a legacy alias lands on Overview.
	 *
	 * @dataProvider hostile_tab_provider
	 *
	 * @param mixed $tab Hostile or invalid `tab` value.
	 */
	public function test_invalid_tab_resolves_to_overview( $tab ) {
		$this->assertSame(
			array(
				'workspace' => 'overview',
				'section'   => 'overview',
				'callback'  => 'render_overview_tab',
			),
			Shift64_Woo_Search_Admin_Routes::resolve( $tab, null )
		);
	}

	public function hostile_tab_provider() {
		return array(
			'array'            => array( array( 'relevance' ) ),
			'nested array'     => array( array( 'tab' => array( 'system' ) ) ),
			'path traversal'   => array( '../../etc/passwd' ),
			'markup'           => array( '<script>alert(1)</script>' ),
			'constructor'      => array( '__construct' ),
			'public method'    => array( 'render_page' ),
			'private renderer' => array( 'render_system_connection_section' ),
			'section renderer' => array( 'render_relevance_basic_section' ),
			'removed renderer' => array( 'render_redis_tab' ),
			'empty string'     => array( '' ),
			'null'             => array( null ),
			'wrong case'       => array( 'SEARCH' ),
			'wrong case slug'  => array( 'Relevance' ),
			'padded slug'      => array( ' relevance ' ),
			'integer'          => array( 0 ),
			'boolean'          => array( true ),
			'float'            => array( 1.5 ),
			'object'           => array( new stdClass() ),
		);
	}

	/**
	 * Anything that is not a section of the requested workspace falls back to its default.
	 *
	 * @dataProvider hostile_section_provider
	 *
	 * @param mixed $section Hostile or invalid `section` value.
	 */
	public function test_invalid_section_resolves_to_the_workspace_default( $section ) {
		$this->assertSame(
			array(
				'workspace' => 'relevance',
				'section'   => 'basic',
				'callback'  => 'render_relevance_basic_section',
			),
			Shift64_Woo_Search_Admin_Routes::resolve( 'relevance', $section )
		);
	}

	public function hostile_section_provider() {
		return array(
			'array'            => array( array( 'synonyms' ) ),
			'path traversal'   => array( '../../etc/passwd' ),
			'markup'           => array( '<script>alert(1)</script>' ),
			'constructor'      => array( '__construct' ),
			'public method'    => array( 'render_page' ),
			'private renderer' => array( 'render_test_tab' ),
			'empty string'     => array( '' ),
			'null'             => array( null ),
			'wrong case'       => array( 'SYNONYMS' ),
			'padded slug'      => array( ' synonyms ' ),
			'integer'          => array( 0 ),
			'boolean'          => array( false ),
			'object'           => array( new stdClass() ),
		);
	}

	// ── Containment ────────────────────────────────────────────

	/**
	 * Whatever goes in, the callback that comes out is one the registry declared.
	 * This is the property that makes the resolver safe to call with raw `$_GET`.
	 */
	public function test_resolved_callback_always_comes_from_the_registry() {
		$declared = $this->declared_callbacks();

		$tabs = array_merge(
			array_keys( Shift64_Woo_Search_Admin_Routes::get_workspaces() ),
			array_keys( Shift64_Woo_Search_Admin_Routes::get_aliases() ),
			array( '', null, 'SEARCH', '__construct', 'render_page', '../../etc/passwd', '<script>alert(1)</script>', array( 'x' ), 42 )
		);

		$sections = array( '', null, 'basic', 'facets', '__construct', 'render_page', '../../etc/passwd', '<script>alert(1)</script>', array( 'x' ), 42 );

		foreach ( $tabs as $tab ) {
			foreach ( $sections as $section ) {
				$resolved = Shift64_Woo_Search_Admin_Routes::resolve( $tab, $section );

				$this->assertSame( array( 'workspace', 'section', 'callback' ), array_keys( $resolved ) );
				$this->assertContains( $resolved['callback'], $declared );
			}
		}
	}

	/**
	 * The resolved route must itself be canonical — feeding a resolution back in
	 * has to be a no-op, or canonical links would drift from what was rendered.
	 */
	public function test_resolution_is_idempotent() {
		$inputs = array_merge(
			array_keys( Shift64_Woo_Search_Admin_Routes::get_aliases() ),
			array( '', null, 'SEARCH', 'render_page', '../../etc/passwd' )
		);

		foreach ( $inputs as $input ) {
			$once  = Shift64_Woo_Search_Admin_Routes::resolve( $input, null );
			$twice = Shift64_Woo_Search_Admin_Routes::resolve( $once['workspace'], $once['section'] );

			$this->assertSame( $once, $twice );
		}
	}

	/**
	 * `$section` is optional so callers can resolve a workspace-only URL.
	 */
	public function test_section_argument_is_optional() {
		$this->assertSame(
			Shift64_Woo_Search_Admin_Routes::resolve( 'system', null ),
			Shift64_Woo_Search_Admin_Routes::resolve( 'system' )
		);
	}
}
