<?php
/**
 * Tests for the rendered admin navigation shell.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Admin page render tests.
 *
 * `tests/test-admin-routes.php` proves the registry resolves correctly. These tests
 * prove the page actually built from that resolution: the six-workspace primary
 * navigation, the secondary section navigation, the accessible active states, the
 * Synonyms regression, and — the property the spec cares most about — that merely
 * looking at a route never writes anything.
 */
class Shift64_Woo_Search_Admin_Page_Render_Test extends WP_UnitTestCase {

	/**
	 * Admin page controller under test.
	 *
	 * @var Shift64_Woo_Search_Admin
	 */
	private $admin;

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->admin = new Shift64_Woo_Search_Admin();
	}

	public function tear_down() {
		unset( $_GET['tab'], $_GET['section'] );

		parent::tear_down();
	}

	/**
	 * Render a route and return its markup.
	 *
	 * @param string|null $tab     `tab` request value, or null to omit it.
	 * @param string|null $section `section` request value, or null to omit it.
	 * @return string Rendered markup.
	 */
	private function render( $tab = null, $section = null ) {
		unset( $_GET['tab'], $_GET['section'] );

		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}
		if ( null !== $section ) {
			$_GET['section'] = $section;
		}

		ob_start();
		$this->admin->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Every stored plugin option, keyed by name.
	 *
	 * Read straight from the options table rather than through `get_option()` so a
	 * newly created or deleted row is visible, not just a changed value.
	 *
	 * @return array<string, string> Option name => raw stored value.
	 */
	private function option_snapshot() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading raw option rows is the point of the assertion.
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$wpdb->esc_like( 'shift64_woo_search_' ) . '%'
			),
			ARRAY_A
		);

		$snapshot = array();
		foreach ( (array) $rows as $row ) {
			$snapshot[ $row['option_name'] ] = $row['option_value'];
		}

		return $snapshot;
	}

	// ── Default landing ────────────────────────────────────────

	/**
	 * With no `tab`, the plugin lands on Overview — not on the query debugger.
	 */
	public function test_default_landing_renders_the_overview_workspace() {
		$html = $this->render();

		$this->assertStringContainsString( 'shift64-woo-search-overview__cards', $html );
		$this->assertStringContainsString( 'Every setting, content list, tool, and report lives in one of the workspaces below.', $html );
	}

	/**
	 * Overview is a signpost: one card per task workspace, each a canonical link.
	 */
	public function test_overview_links_to_the_five_task_workspaces() {
		$html = $this->render();

		foreach ( array( 'experience', 'results', 'relevance', 'insights', 'system' ) as $workspace ) {
			$this->assertMatchesRegularExpression(
				'#<h3 class="shift64-woo-search-overview__card-title">\s*<a href="[^"]*tab=' . $workspace . '"#',
				$html,
				"Overview is missing a card link to the {$workspace} workspace."
			);
		}

		$this->assertSame( 5, substr_count( $html, 'shift64-woo-search-overview__card-title' ) );
	}

	/**
	 * Overview must not leak connection credentials into markup, URLs, or attributes.
	 */
	public function test_overview_contains_no_credentials() {
		update_option( 'shift64_woo_search_redis_username', 's64ws-test-user' );
		update_option( 'shift64_woo_search_redis_password', 's64ws-test-secret' );

		$html = $this->render( 'overview' );

		$this->assertStringNotContainsString( 's64ws-test-user', $html );
		$this->assertStringNotContainsString( 's64ws-test-secret', $html );
		$this->assertStringNotContainsString( 'redis_password', $html );
	}

	// ── Primary navigation ─────────────────────────────────────

	/**
	 * Navigation order is part of the contract, so the rendered order is asserted too.
	 */
	public function test_primary_navigation_renders_six_workspaces_in_documented_order() {
		$html = $this->render();

		$this->assertSame( 6, preg_match_all( '/class="nav-tab(?: nav-tab-active)?"/', $html ) );

		$previous = -1;
		foreach ( array( 'overview', 'experience', 'results', 'relevance', 'insights', 'system' ) as $workspace ) {
			$position = strpos( $html, 'tab=' . $workspace . '"' );

			$this->assertIsInt( $position, "No primary link for the {$workspace} workspace." );
			$this->assertGreaterThan( $previous, $position, "The {$workspace} primary link is out of documented order." );

			$previous = $position;
		}
	}

	public function test_primary_navigation_is_a_labelled_nav_landmark() {
		$this->assertMatchesRegularExpression( '/<nav class="nav-tab-wrapper" aria-label="Primary">/', $this->render() );
	}

	/**
	 * The active workspace is announced, not only coloured.
	 *
	 * @dataProvider active_primary_link_provider
	 *
	 * @param string|null $tab                Requested `tab` value.
	 * @param string      $expected_workspace Workspace expected to be active.
	 */
	public function test_active_primary_link_is_marked_current( $tab, $expected_workspace ) {
		$html = $this->render( $tab );

		$this->assertSame(
			1,
			preg_match_all( '/<a href="[^"]*" class="nav-tab nav-tab-active" aria-current="page">/', $html ),
			'Exactly one primary link may be active.'
		);
		$this->assertMatchesRegularExpression(
			'#<a href="[^"]*tab=' . $expected_workspace . '" class="nav-tab nav-tab-active" aria-current="page">#',
			$html,
			"The {$expected_workspace} primary link should be the active one."
		);
	}

	public function active_primary_link_provider() {
		return array(
			'no tab'          => array( null, 'overview' ),
			'canonical'       => array( 'relevance', 'relevance' ),
			'legacy alias'    => array( 'synonyms', 'relevance' ),
			'legacy frontend' => array( 'frontend', 'experience' ),
			'invalid'         => array( 'nope', 'overview' ),
		);
	}

	// ── Secondary navigation ───────────────────────────────────

	/**
	 * A workspace with several sections offers them in the documented order.
	 */
	public function test_multi_section_workspace_renders_a_labelled_secondary_nav() {
		$html = $this->render( 'relevance' );

		$this->assertStringContainsString( '<nav class="shift64-woo-search-admin__sections" aria-label="Sections">', $html );

		$previous = -1;
		foreach ( array( 'basic', 'matching', 'synonyms', 'merchandising', 'field-weights', 'test-search', 'compare-passes' ) as $section ) {
			$position = strpos( $html, 'tab=relevance&#038;section=' . $section . '"' );

			$this->assertIsInt( $position, "No secondary link for the relevance/{$section} section." );
			$this->assertGreaterThan( $previous, $position, "The {$section} secondary link is out of documented order." );

			$previous = $position;
		}
	}

	public function test_active_secondary_link_is_marked_current_and_visibly_active() {
		$html = $this->render( 'relevance', 'synonyms' );

		$this->assertSame(
			1,
			preg_match_all( '/aria-current="page">/', $this->strip_primary_nav( $html ) ),
			'Exactly one secondary link may be active.'
		);
		$this->assertMatchesRegularExpression(
			'#<a href="[^"]*tab=relevance&\#038;section=synonyms" class="shift64-woo-search-admin__section-link current" aria-current="page">#',
			$html
		);
	}

	/**
	 * A single-section workspace has nothing to choose between, so it renders no
	 * secondary navigation at all.
	 *
	 * @dataProvider single_section_workspace_provider
	 *
	 * @param string $tab Single-section workspace slug.
	 */
	public function test_single_section_workspace_renders_no_secondary_nav( $tab ) {
		$this->assertStringNotContainsString( 'aria-label="Sections"', $this->render( $tab ) );
	}

	public function single_section_workspace_provider() {
		return array(
			'overview' => array( 'overview' ),
			'insights' => array( 'insights' ),
		);
	}

	// ── Headings ───────────────────────────────────────────────

	/**
	 * Heading order is plugin `h1`, then the canonical section `h2`, then whatever
	 * headings the relocated content already had.
	 */
	public function test_canonical_section_heading_follows_the_plugin_heading() {
		$html = $this->render( 'relevance', 'synonyms' );

		$h1 = strpos( $html, '<h1>Shift64 Woo Search</h1>' );
		$h2 = strpos( $html, '<h2 class="shift64-woo-search-admin__section-title">Synonyms</h2>' );

		$this->assertIsInt( $h1 );
		$this->assertIsInt( $h2 );
		$this->assertGreaterThan( $h1, $h2 );
	}

	// ── Dispatch ───────────────────────────────────────────────

	/**
	 * The legacy Synonyms tab rendered an empty content area because the dynamic
	 * router looked for a `render_synonyms_tab()` that never existed. Both the legacy
	 * and the canonical route must now reach the real manager.
	 *
	 * @dataProvider synonyms_route_provider
	 *
	 * @param string      $tab     Requested `tab` value.
	 * @param string|null $section Requested `section` value.
	 */
	public function test_synonyms_route_renders_the_synonyms_manager( $tab, $section ) {
		$this->assertStringContainsString( 's64ws-syn-table', $this->render( $tab, $section ) );
	}

	public function synonyms_route_provider() {
		return array(
			'canonical'         => array( 'relevance', 'synonyms' ),
			'legacy alias'      => array( 'synonyms', null ),
			'alias ignores sec' => array( 'synonyms', 'field-weights' ),
		);
	}

	// ── Search Experience sections ─────────────────────────────

	/**
	 * The Search Field section owns the four selector/debounce fields, reachable
	 * through both the canonical route and the legacy `frontend` bookmark.
	 *
	 * @dataProvider search_field_route_provider
	 *
	 * @param string      $tab     Requested `tab` value.
	 * @param string|null $section Requested `section` value.
	 */
	public function test_search_field_section_renders_the_frontend_selector_fields( $tab, $section ) {
		$html = $this->render( $tab, $section );

		foreach ( array( 'debounce', 'input_selector', 'additional_selectors', 'button_selector' ) as $field ) {
			$this->assertStringContainsString( 'name="shift64_woo_search_' . $field . '"', $html );
		}
	}

	public function search_field_route_provider() {
		return array(
			'canonical'         => array( 'experience', 'search-field' ),
			'legacy alias'      => array( 'frontend', null ),
			'workspace default' => array( 'experience', null ),
		);
	}

	/**
	 * Section forms submit only their own keys, so a section may render only the
	 * fields it owns. Autocomplete owns the dropdown fields; `full_limit` sizes the
	 * full results page and stays with Relevance.
	 */
	public function test_autocomplete_section_renders_only_the_fields_it_owns() {
		$html = $this->render( 'experience', 'autocomplete' );

		foreach ( array( 'min_query', 'autocomplete_limit', 'category_suggest_fuzzy', 'brand_suggest_enabled' ) as $field ) {
			$this->assertStringContainsString( 'name="shift64_woo_search_' . $field . '"', $html );
		}

		$this->assertStringNotContainsString( 'shift64_woo_search_full_limit', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_debounce', $html );
	}

	/**
	 * The moved fields must not keep rendering at their old address as well —
	 * two forms writing one option is exactly what section ownership rules out.
	 */
	public function test_relocated_experience_fields_no_longer_render_on_the_legacy_search_route() {
		$html = $this->render( 'search', null );

		foreach ( array( 'min_query', 'autocomplete_limit', 'category_suggest_fuzzy', 'brand_suggest_enabled', 'category_pin_rules' ) as $field ) {
			$this->assertStringNotContainsString( 'shift64_woo_search_' . $field, $html );
		}
	}

	public function test_query_suggestions_section_renders_the_suggestions_manager() {
		$this->assertStringContainsString( 's64ws-sug-table', $this->render( 'experience', 'query-suggestions' ) );
	}

	/**
	 * Category Suggestions gathers the three editors that shape the autocomplete
	 * category list: pins, boosts, and exclusions. Product ranking is not among them.
	 */
	public function test_category_suggestions_section_renders_pins_boosts_and_exclusions() {
		$html = $this->render( 'experience', 'category-suggestions' );

		$this->assertStringContainsString( 'name="shift64_woo_search_category_pin_rules"', $html );
		$this->assertStringContainsString( 's64ws-cb-table', $html );
		$this->assertStringContainsString( 's64ws-cx-table', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_category_boost_rules', $html );
	}

	// ── Results & Filters sections ─────────────────────────────

	/**
	 * Result Coverage owns which listings Redis serves — and nothing else. In
	 * particular it must not carry the Facets form, which posts through a different,
	 * non-partial handler.
	 *
	 * @dataProvider coverage_route_provider
	 *
	 * @param string      $tab     Requested `tab` value.
	 * @param string|null $section Requested `section` value.
	 */
	public function test_result_coverage_section_owns_the_redis_listing_switches( $tab, $section ) {
		$html = $this->render( $tab, $section );

		$this->assertStringContainsString( 'name="shift64_woo_search_archive_enabled"', $html );
		$this->assertStringContainsString( 'name="shift64_woo_search_price_sort_mode"', $html );
		$this->assertStringContainsString( 'name="shift64_woo_search_taxonomy_archive_scopes[]"', $html );

		$this->assertStringNotContainsString( 'filter_attributes', $html );
		$this->assertStringNotContainsString( 's64ws-filters-form', $html );
	}

	public function coverage_route_provider() {
		return array(
			'canonical'         => array( 'results', 'coverage' ),
			'workspace default' => array( 'results', null ),
		);
	}

	/**
	 * The four filter groups stay in the single specialized form, reachable from the
	 * canonical route and the legacy `filters` bookmark alike.
	 *
	 * WooCommerce is absent from the unit harness, so `wc_get_attribute_taxonomies()`
	 * is stubbed in `tests/bootstrap.php` as a store with no global attributes. That
	 * covers the form itself and its category/brand controls; per-attribute rows need
	 * a real WooCommerce data layer and are left to browser QA.
	 *
	 * @dataProvider facets_route_provider
	 *
	 * @param string      $tab     Requested `tab` value.
	 * @param string|null $section Requested `section` value.
	 */
	public function test_facets_section_renders_the_specialized_filters_form( $tab, $section ) {
		$html = $this->render( $tab, $section );

		$this->assertStringContainsString( 'id="s64ws-filters-form"', $html );
		$this->assertStringContainsString( 'id="s64ws-filters-status"', $html );
		$this->assertStringContainsString( 'name="shift64_woo_search_filter_categories_enabled"', $html );
		$this->assertStringContainsString( 'name="shift64_woo_search_filter_categories_excluded"', $html );
		$this->assertStringContainsString( 'name="shift64_woo_search_filter_brands_enabled"', $html );
	}

	public function facets_route_provider() {
		return array(
			'canonical'    => array( 'results', 'facets' ),
			'legacy alias' => array( 'filters', null ),
		);
	}

	/**
	 * Coverage moved out of the legacy Search tab; it must not keep rendering there.
	 */
	public function test_relocated_coverage_fields_no_longer_render_on_the_legacy_search_route() {
		$html = $this->render( 'search', null );

		foreach ( array( 'archive_enabled', 'price_sort_mode', 'taxonomy_archive_scopes' ) as $field ) {
			$this->assertStringNotContainsString( 'shift64_woo_search_' . $field, $html );
		}
	}

	// ── Relevance sections ─────────────────────────────────────

	/**
	 * Basic Ranking owns how a match is decided and ordered — and only that. Each
	 * section's form posts every key it renders, so a key rendered on two sections is
	 * a key either section could overwrite; fuzzy tuning, the endpoint limit, and the
	 * boost rules therefore have to be absent here.
	 *
	 * The legacy `search` bookmark is the third route in the provider: it lands here,
	 * which is why it must render exactly the same fields.
	 *
	 * @dataProvider basic_ranking_route_provider
	 *
	 * @param string      $tab     Requested `tab` value.
	 * @param string|null $section Requested `section` value.
	 */
	public function test_basic_ranking_section_owns_the_ranking_mode_fields( $tab, $section ) {
		$html = $this->render( $tab, $section );

		foreach ( array( 'logic', 'strategy', 'outofstock_mode', 'outofstock_demote_factor' ) as $field ) {
			$this->assertStringContainsString( 'name="shift64_woo_search_' . $field . '"', $html );
		}

		$this->assertStringNotContainsString( 'shift64_woo_search_fuzzy_level', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_full_limit', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_category_boost_rules', $html );
	}

	public function basic_ranking_route_provider() {
		return array(
			'canonical'         => array( 'relevance', 'basic' ),
			'workspace default' => array( 'relevance', null ),
			'legacy search tab' => array( 'search', null ),
		);
	}

	/**
	 * The old mixed Search page maps deterministically to Basic Ranking, so the
	 * heading a returning bookmark lands on has to say so.
	 */
	public function test_legacy_search_bookmark_lands_on_the_basic_ranking_section() {
		$this->assertStringContainsString(
			'<h2 class="shift64-woo-search-admin__section-title">Basic Ranking</h2>',
			$this->render( 'search', null )
		);
	}

	// ── Legacy relocation notice ───────────────────────────────

	/**
	 * Landing on Basic Ranking answers "where did ranking go" but not "where did the
	 * rest of the Search tab go". The notice has to name the two workspaces that took
	 * the other halves, and it has to link there — telling a merchant a setting moved
	 * without a way to reach it is worse than saying nothing.
	 */
	public function test_legacy_search_bookmark_renders_the_relocation_notice() {
		$html = $this->render( 'search', null );

		$this->assertStringContainsString( 'shift64-woo-search-admin__relocation-notice', $html );
		$this->assertStringContainsString( 'The old Search tab has been split up.', $html );

		$this->assertMatchesRegularExpression( '#<a href="[^"]*tab=experience[^"]*">Search Experience</a>#', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*tab=results[^"]*">Results &amp; Filters</a>#', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*tab=relevance[^"]*">Matching &amp; Fallback</a>#', $html );
	}

	/**
	 * The notice belongs to the alias, not to the destination. It renders above the
	 * section content, and only for someone who actually arrived on the old URL.
	 */
	public function test_relocation_notice_precedes_the_basic_ranking_form() {
		$html = $this->render( 'search', null );

		$notice = strpos( $html, 'shift64-woo-search-admin__relocation-notice' );
		$form   = strpos( $html, 'id="s64ws-settings-form"' );

		$this->assertIsInt( $notice );
		$this->assertIsInt( $form );
		$this->assertLessThan( $form, $notice, 'The relocation notice must render above the section content.' );
	}

	/**
	 * Reaching Basic Ranking by its canonical address means nothing moved out from
	 * under you, so the notice would be noise.
	 *
	 * @dataProvider notice_free_route_provider
	 *
	 * @param string      $tab     Requested `tab` value.
	 * @param string|null $section Requested `section` value.
	 */
	public function test_canonical_routes_render_no_relocation_notice( $tab, $section ) {
		$this->assertStringNotContainsString( 'shift64-woo-search-admin__relocation-notice', $this->render( $tab, $section ) );
	}

	public function notice_free_route_provider() {
		return array(
			'canonical basic'    => array( 'relevance', 'basic' ),
			'workspace default'  => array( 'relevance', null ),
			'canonical matching' => array( 'relevance', 'matching' ),
			'other alias'        => array( 'frontend', null ),
			'overview'           => array( 'overview', null ),
		);
	}

	/**
	 * The notice is a hint, not stored state: showing it must not write an option, and
	 * neither must dismissing it — the dismissal is core's client-side one and is
	 * never persisted.
	 */
	public function test_relocation_notice_stores_nothing() {
		$before = $this->option_snapshot();

		$this->render( 'search', null );

		$this->assertSame( $before, $this->option_snapshot() );
		$this->assertSame( '', get_user_meta( get_current_user_id(), 'shift64_woo_search_relocation_notice_dismissed', true ) );
	}

	/**
	 * Matching & Fallback owns typo tolerance, token reduction, normalization, and the
	 * full-search limit that bounds the endpoint those passes feed.
	 */
	public function test_matching_section_owns_the_fuzzy_and_fallback_fields() {
		$html = $this->render( 'relevance', 'matching' );

		$fields = array(
			'fuzzy_level',
			'fallback_trigger',
			'fallback_score_threshold',
			'fallback_fuzzy_level',
			'token_reduction_enabled',
			'weak_tokens',
			'drop_trailing_weak_token_only',
			'diacritics_normalization',
			'fuzzy_synonyms',
			'full_limit',
		);

		foreach ( $fields as $field ) {
			$this->assertStringContainsString( 'name="shift64_woo_search_' . $field . '"', $html );
		}

		$this->assertStringNotContainsString( 'shift64_woo_search_logic', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_outofstock_mode', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_category_boost_rules', $html );
	}

	/**
	 * Merchandising is the one place a merchant overrides organic product ranking on
	 * purpose. Its editor boosts products by category or tag — not to be confused with
	 * the similarly named `category_boosts` editor that orders the autocomplete
	 * category list over in Search Experience.
	 */
	public function test_merchandising_section_renders_the_product_boost_rule_editor() {
		$html = $this->render( 'relevance', 'merchandising' );

		$this->assertStringContainsString( 'name="shift64_woo_search_category_boost_rules"', $html );
		$this->assertStringNotContainsString( 's64ws-cb-table', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_logic', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_fuzzy_level', $html );
	}

	// ── System sections ────────────────────────────────────────

	/**
	 * Connection owns everything needed to reach Redis, and only that. Rate limiting
	 * and the SHORTINIT config readout shared this form before the split; if either
	 * reappears here, saving the connection would post fields another section owns.
	 *
	 * The legacy `redis` bookmark lands on the same section through the alias map.
	 *
	 * @dataProvider connection_route_provider
	 *
	 * @param string      $tab     Requested `tab` value.
	 * @param string|null $section Requested `section` value.
	 */
	public function test_connection_section_owns_the_redis_connection_fields( $tab, $section ) {
		$html = $this->render( $tab, $section );

		foreach ( array( 'redis_host', 'redis_port', 'redis_auth_enabled', 'redis_username', 'redis_password', 'redis_db', 'redis_prefix' ) as $field ) {
			$this->assertStringContainsString( 'name="shift64_woo_search_' . $field . '"', $html );
		}

		$this->assertStringContainsString( 'id="s64ws-test-connection"', $html );
		$this->assertStringContainsString( 'id="s64ws-connection-status"', $html );

		$this->assertStringNotContainsString( 'shift64_woo_search_rate_limit', $html );
		$this->assertStringNotContainsString( 's64ws-regen-config', $html );
	}

	public function connection_route_provider() {
		return array(
			'canonical'         => array( 'system', 'connection' ),
			'workspace default' => array( 'system', null ),
			'legacy alias'      => array( 'redis', null ),
		);
	}

	/**
	 * The password field stays masked and stays out of browser autofill, exactly as
	 * before the move. This is the one field whose input type is a security property
	 * rather than a styling choice.
	 */
	public function test_connection_password_field_stays_masked() {
		$html = $this->render( 'system', 'connection' );

		$this->assertMatchesRegularExpression(
			'#<input type="password" id="shift64_woo_search_redis_password" name="shift64_woo_search_redis_password"[^>]*autocomplete="new-password"#',
			$html
		);
	}

	/**
	 * Security & Traffic carries a single key. That is the whole point of giving it
	 * its own section: its save can never reach a stored Redis credential, and the
	 * page itself renders no credential field to leak one.
	 */
	public function test_security_section_owns_only_the_rate_limit() {
		$html = $this->render( 'system', 'security' );

		$this->assertStringContainsString( 'name="shift64_woo_search_rate_limit"', $html );

		foreach ( array( 'redis_host', 'redis_port', 'redis_auth_enabled', 'redis_username', 'redis_password', 'redis_db', 'redis_prefix' ) as $field ) {
			$this->assertStringNotContainsString( 'shift64_woo_search_' . $field, $html );
		}

		$this->assertStringNotContainsString( 'type="password"', $html );
		$this->assertStringNotContainsString( 's64ws-test-connection', $html );
	}

	/**
	 * Diagnostics is a readout plus one action. It keeps the ids the admin script
	 * binds to — the button lives inside `#s64ws-settings-form` because that is where
	 * the existing initializer looks for it — and carries no named input at all, so
	 * there is nothing here a save could overwrite.
	 */
	public function test_diagnostics_section_owns_the_generated_config_readout() {
		$html = $this->render( 'system', 'diagnostics' );

		$this->assertStringContainsString( 'id="s64ws-settings-form"', $html );
		$this->assertStringContainsString( 'id="s64ws-regen-config"', $html );
		$this->assertStringContainsString( 'id="s64ws-connection-status"', $html );

		$this->assertStringNotContainsString( 'shift64_woo_search_rate_limit', $html );
		$this->assertStringNotContainsString( 'shift64_woo_search_redis_', $html );
		$this->assertStringNotContainsString( 's64ws-test-connection', $html );
		$this->assertStringNotContainsString( 'type="submit"', $html );
	}

	/**
	 * Hostile `tab` values fall back to Overview. PHPUnit is configured to convert
	 * notices, warnings, and deprecations into exceptions, so this also proves the
	 * fallback path is diagnostic-free.
	 *
	 * @dataProvider hostile_tab_provider
	 *
	 * @param mixed $tab Hostile `tab` request value.
	 */
	public function test_hostile_tab_renders_overview_without_diagnostics( $tab ) {
		$html = $this->render( $tab );

		$this->assertStringContainsString( 'shift64-woo-search-overview__cards', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*tab=overview" class="nav-tab nav-tab-active" aria-current="page">#', $html );
	}

	public function hostile_tab_provider() {
		return array(
			'array'          => array( array( 'relevance' ) ),
			'path traversal' => array( '../../etc/passwd' ),
			'markup'         => array( '<script>alert(1)</script>' ),
			'constructor'    => array( '__construct' ),
			'public method'  => array( 'render_page' ),
			'private method' => array( 'render_system_connection_section' ),
			'empty string'   => array( '' ),
			'wrong case'     => array( 'Relevance' ),
		);
	}

	// ── Render without write ───────────────────────────────────

	/**
	 * Visiting a route must not persist anything: no option created, changed, or
	 * removed. This is the guarantee that makes navigation safe to bookmark, refresh,
	 * and crawl.
	 */
	public function test_rendering_routes_writes_no_options() {
		// Unusual legacy values: rendering must not normalize or rewrite them either.
		update_option( 'shift64_woo_search_logic', 'or' );
		update_option( 'shift64_woo_search_fuzzy_level', '' );
		update_option( 'shift64_woo_search_debounce', '150ms' );

		$before = $this->option_snapshot();

		$this->assertNotEmpty( $before, 'The snapshot must observe real stored options.' );

		$routes = array(
			array( null, null ),
			array( 'overview', null ),
			array( 'experience', 'search-field' ),
			array( 'experience', 'autocomplete' ),
			array( 'experience', 'query-suggestions' ),
			array( 'experience', 'category-suggestions' ),
			array( 'results', 'coverage' ),
			array( 'results', 'facets' ),
			array( 'relevance', 'basic' ),
			array( 'relevance', 'matching' ),
			array( 'relevance', 'synonyms' ),
			array( 'relevance', 'merchandising' ),
			array( 'relevance', 'field-weights' ),
			array( 'relevance', 'test-search' ),
			array( 'relevance', 'compare-passes' ),
			// Insights and Index & Health need a live Redis/stats backend, so they are
			// exercised in browser QA rather than here; the rest of System renders from
			// options and one file check alone.
			array( 'system', 'connection' ),
			array( 'system', 'security' ),
			array( 'system', 'diagnostics' ),
			// Legacy aliases reach the same renderers through the alias map.
			array( 'frontend', null ),
			array( 'suggestions', null ),
			array( 'catboost', null ),
			array( 'filters', null ),
			array( 'search', null ),
			array( 'synonyms', null ),
			array( 'weights', null ),
			array( 'test', null ),
			array( 'tuning', null ),
			array( 'redis', null ),
			array( 'nope', null ),
		);

		foreach ( $routes as $route ) {
			list( $tab, $section ) = $route;

			$this->assertNotSame( '', $this->render( $tab, $section ), 'A route rendered nothing at all.' );
			$this->assertSame(
				$before,
				$this->option_snapshot(),
				sprintf( 'Rendering tab=%s section=%s changed stored options.', (string) $tab, (string) $section )
			);
		}
	}

	// ── Canonical internal links ───────────────────────────────

	/**
	 * Aliases exist for bookmarks the plugin cannot edit. Links the plugin *does*
	 * own have no excuse to keep using them, so the dashboard widget points at the
	 * canonical Insights route rather than the legacy `tab=stats`.
	 */
	public function test_dashboard_widget_links_to_the_canonical_statistics_route() {
		Shift64_Woo_Search_Stats::create_table();

		ob_start();
		$this->admin->render_dashboard_widget();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'tab=insights', $html );
		$this->assertStringContainsString( 'section=statistics', $html );
		$this->assertStringNotContainsString( 'tab=stats', $html );
	}

	/**
	 * The Facets form tells merchants to rebuild after an attribute change. It used
	 * to name a tab that no longer exists; it now links to the section that owns the
	 * rebuild.
	 */
	public function test_facets_rebuild_hint_links_to_the_canonical_index_route() {
		$html = $this->render( 'results', 'facets' );

		$this->assertMatchesRegularExpression( '#<a href="[^"]*tab=system[^"]*section=index">#', $html );
		$this->assertStringNotContainsString( 'the Index tab', $html );
	}

	/**
	 * Drop the primary navigation so secondary-link assertions cannot match a
	 * primary link by accident.
	 *
	 * @param string $html Rendered page markup.
	 * @return string Markup with the primary nav removed.
	 */
	private function strip_primary_nav( $html ) {
		return (string) preg_replace( '#<nav class="nav-tab-wrapper".*?</nav>#s', '', $html );
	}
}
