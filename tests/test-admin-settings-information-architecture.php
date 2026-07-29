<?php
/**
 * Acceptance-criteria regression suite for the admin settings information architecture.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Information-architecture acceptance criteria.
 *
 * Three suites already cover this migration in detail and this one deliberately does
 * not repeat them:
 *
 * - `tests/test-admin-routes.php` — per-input resolution: every canonical route, every
 *   alias, defaults, hostile `tab`/`section` values, idempotence, containment.
 * - `tests/test-admin-page-render.php` — the rendered shell: navigation order, active
 *   and accessible state, headings, per-section field expectations, the relocation
 *   notice, and the authoritative `s64ws-syn-table` Synonyms pin (canonical route and
 *   legacy `tab=synonyms` alike), which is why this file does not assert it again.
 * - `tests/test-admin-settings-persistence.php` — the persistence seam itself:
 *   allowlisting, sanitization, storage shape, credential cleanup.
 *
 * What is left is the checklist the spec's "Acceptance Criteria" states as whole-system
 * properties, which no single-surface suite can express:
 *
 * 1. The registry is complete and internally consistent — six ordered workspaces,
 *    nineteen destinations, one explicit, existing, unshared renderer each.
 * 2. All twelve legacy aliases match the spec's alias table *literally*, so a registry
 *    change cannot quietly redefine what a bookmark means.
 * 3. Single ownership — the option keys a section renders are exactly the ones the
 *    spec's "Existing Surface Placement" tables assign it, and no key is rendered by
 *    two sections.
 * 4. Render-without-write — a sweep of all nineteen destinations plus all twelve
 *    aliases leaves every `shift64_woo_search_*` option byte-identical.
 * 5. Saving one section cannot reach another section's options, credentials included.
 * 6. Credentials never appear outside the Connection form.
 *
 * Harness coverage and exclusions:
 *
 * - WooCommerce is not installed, so `wc_get_attribute_taxonomies()` is stubbed as a
 *   store with no global attributes (`tests/bootstrap.php`). The Facets section
 *   therefore renders its category and brand controls but no per-attribute rows; those
 *   rows post as `filter_attributes[]` through the specialized
 *   `shift64_woo_search_save_filters` handler and are covered by browser QA.
 * - Redis is not available, so `System > Index & Health` renders its documented
 *   degraded state; `Insights > Search Statistics` renders against a real but empty
 *   stats table. Both are still swept here — a healthy backend changes what the page
 *   says, not whether it writes — and their populated states are browser QA.
 * - The specialized Facets save is not part of the generic seam, so the partial-save
 *   tests exercise it only as a sentinel the generic save must never touch.
 */
class Shift64_Woo_Search_Admin_Information_Architecture_Test extends WP_UnitTestCase {

	/**
	 * Admin page controller under test.
	 *
	 * @var Shift64_Woo_Search_Admin
	 */
	private $admin;

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Statistics reads its own table; create it so the Insights sweep exercises a
		// real (empty) query rather than a missing-table error path.
		Shift64_Woo_Search_Stats::create_table();

		$this->admin = new Shift64_Woo_Search_Admin();
	}

	public function tear_down() {
		unset( $_GET['tab'], $_GET['section'] );

		parent::tear_down();
	}

	// ── Fixtures ───────────────────────────────────────────────

	/**
	 * The spec's legacy alias table, transcribed literally.
	 *
	 * Deliberately not derived from the registry: this is the contract every existing
	 * bookmark depends on, so the expectation has to come from the document rather than
	 * from the code it is meant to police.
	 *
	 * @return array<string, array{0: string, 1: string}> Legacy tab => array( workspace, section ).
	 */
	private function spec_alias_table() {
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
	 * Option → section ownership, transcribed from the spec's placement tables.
	 *
	 * One entry per canonical destination, listing the option keys that destination
	 * renders as named form inputs. Sections that manage content or run tools through
	 * AJAX (Query Suggestions, Synonyms, Field Weights, Test Search, Compare Passes,
	 * Statistics, Index & Health, Diagnostics) own no named input and are listed with an
	 * empty set — that is an assertion too, since a stray field there would be a key
	 * some other section's form also posts.
	 *
	 * `shift64_woo_search_filter_attributes` belongs to Facets but is absent below: it
	 * renders one `filter_attributes[]` checkbox per WooCommerce global attribute, and
	 * the harness has none. It is seeded as a sentinel in the partial-save tests instead.
	 *
	 * @return array<string, string[]> "workspace/section" => owned option keys.
	 */
	private function spec_option_ownership() {
		return array(
			'overview/overview'               => array(),
			'experience/search-field'         => array(
				'shift64_woo_search_debounce',
				'shift64_woo_search_input_selector',
				'shift64_woo_search_additional_selectors',
				'shift64_woo_search_button_selector',
			),
			'experience/autocomplete'         => array(
				'shift64_woo_search_min_query',
				'shift64_woo_search_autocomplete_limit',
				'shift64_woo_search_dropdown_width_mode',
				'shift64_woo_search_dropdown_width',
				'shift64_woo_search_show_sku',
				'shift64_woo_search_show_category',
				'shift64_woo_search_show_brand',
				'shift64_woo_search_category_suggest_fuzzy',
				'shift64_woo_search_brand_suggest_enabled',
			),
			'experience/query-suggestions'    => array(),
			'experience/category-suggestions' => array( 'shift64_woo_search_category_pin_rules' ),
			'results/coverage'                => array(
				'shift64_woo_search_archive_enabled',
				'shift64_woo_search_price_sort_mode',
				'shift64_woo_search_taxonomy_archive_scopes',
			),
			'results/facets'                  => array(
				'shift64_woo_search_filter_categories_enabled',
				'shift64_woo_search_filter_categories_excluded',
				'shift64_woo_search_filter_brands_enabled',
			),
			'relevance/basic'                 => array(
				'shift64_woo_search_logic',
				'shift64_woo_search_strategy',
				'shift64_woo_search_outofstock_mode',
				'shift64_woo_search_outofstock_demote_factor',
			),
			'relevance/matching'              => array(
				'shift64_woo_search_fuzzy_level',
				'shift64_woo_search_fallback_trigger',
				'shift64_woo_search_fallback_score_threshold',
				'shift64_woo_search_fallback_fuzzy_level',
				'shift64_woo_search_token_reduction_enabled',
				'shift64_woo_search_weak_tokens',
				'shift64_woo_search_drop_trailing_weak_token_only',
				'shift64_woo_search_diacritics_normalization',
				'shift64_woo_search_fuzzy_synonyms',
				'shift64_woo_search_full_limit',
			),
			'relevance/synonyms'              => array(),
			'relevance/merchandising'         => array( 'shift64_woo_search_category_boost_rules' ),
			'relevance/field-weights'         => array(),
			'relevance/test-search'           => array(),
			'relevance/compare-passes'        => array(),
			'insights/statistics'             => array(),
			'system/connection'               => array(
				'shift64_woo_search_redis_host',
				'shift64_woo_search_redis_port',
				'shift64_woo_search_redis_auth_enabled',
				'shift64_woo_search_redis_username',
				'shift64_woo_search_redis_password',
				'shift64_woo_search_redis_db',
				'shift64_woo_search_redis_prefix',
			),
			'system/index'                    => array(),
			'system/security'                 => array( 'shift64_woo_search_rate_limit' ),
			'system/diagnostics'              => array( 'shift64_woo_search_archive_debug_enabled' ),
		);
	}

	/**
	 * A realistic save payload for each section that posts through the generic seam.
	 *
	 * Facets is absent on purpose: its four option groups post through the specialized
	 * `shift64_woo_search_save_filters` handler, so the generic seam must be unable to
	 * write them at all — which is what the sentinels prove.
	 *
	 * @return array<string, array<string, mixed>> "workspace/section" => settings payload.
	 */
	private function generic_section_payloads() {
		return array(
			'experience/search-field'         => array(
				'shift64_woo_search_debounce'             => '175',
				'shift64_woo_search_input_selector'       => '.ia-input',
				'shift64_woo_search_additional_selectors' => '.ia-extra',
				'shift64_woo_search_button_selector'      => '.ia-button',
			),
			'experience/autocomplete'         => array(
				'shift64_woo_search_min_query'             => '3',
				'shift64_woo_search_autocomplete_limit'    => '8',
				'shift64_woo_search_dropdown_width_mode'   => 'custom',
				'shift64_woo_search_dropdown_width'        => '820',
				'shift64_woo_search_show_sku'              => 'no',
				'shift64_woo_search_show_category'         => 'yes',
				'shift64_woo_search_show_brand'            => 'no',
				'shift64_woo_search_category_suggest_fuzzy' => 'yes',
				'shift64_woo_search_brand_suggest_enabled' => 'no',
			),
			'experience/category-suggestions' => array(
				'shift64_woo_search_category_pin_rules' => 'ia-shoes|12',
			),
			'results/coverage'                => array(
				'shift64_woo_search_archive_enabled' => 'yes',
				'shift64_woo_search_price_sort_mode' => 'redis',
				'shift64_woo_search_taxonomy_archive_scopes' => array( 'product_cat' ),
			),
			'relevance/basic'                 => array(
				'shift64_woo_search_logic'           => 'and',
				'shift64_woo_search_strategy'        => 'balanced',
				'shift64_woo_search_outofstock_mode' => 'demote',
				'shift64_woo_search_outofstock_demote_factor' => '0.4',
			),
			'relevance/matching'              => array(
				'shift64_woo_search_fuzzy_level'          => '1',
				'shift64_woo_search_fallback_trigger'     => 'empty',
				'shift64_woo_search_fallback_score_threshold' => '2',
				'shift64_woo_search_fallback_fuzzy_level' => '2',
				'shift64_woo_search_token_reduction_enabled' => 'yes',
				'shift64_woo_search_weak_tokens'          => 'ia-weak',
				'shift64_woo_search_drop_trailing_weak_token_only' => 'no',
				'shift64_woo_search_diacritics_normalization' => 'yes',
				'shift64_woo_search_fuzzy_synonyms'       => 'no',
				'shift64_woo_search_full_limit'           => '48',
			),
			'relevance/merchandising'         => array(
				'shift64_woo_search_category_boost_rules' => 'ia-boots|1.5',
			),
			'system/connection'               => array(
				'shift64_woo_search_redis_host'         => 'ia-host',
				'shift64_woo_search_redis_port'         => '6380',
				'shift64_woo_search_redis_auth_enabled' => 'yes',
				'shift64_woo_search_redis_username'     => 'ia-user',
				'shift64_woo_search_redis_password'     => 'ia-secret',
				'shift64_woo_search_redis_db'           => '2',
				'shift64_woo_search_redis_prefix'       => 'ia:',
			),
			'system/security'                 => array(
				'shift64_woo_search_rate_limit' => '120',
			),
			'system/diagnostics'              => array(
				'shift64_woo_search_archive_debug_enabled' => 'yes',
			),
		);
	}

	// ── Helpers ────────────────────────────────────────────────

	/**
	 * Render a route and return its markup.
	 *
	 * @param mixed $tab     `tab` request value, or null to omit it.
	 * @param mixed $section `section` request value, or null to omit it.
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
	 * Every canonical destination, as `array( workspace, section )` pairs.
	 *
	 * Read from the registry rather than hard-coded so a route added later cannot slip
	 * past the sweeps below; the *shape* of that registry is pinned separately.
	 *
	 * @return array<int, array{0: string, 1: string}> Canonical destinations.
	 */
	private function canonical_destinations() {
		$destinations = array();

		foreach ( Shift64_Woo_Search_Admin_Routes::get_workspaces() as $workspace_slug => $workspace ) {
			foreach ( array_keys( $workspace['sections'] ) as $section_slug ) {
				$destinations[] = array( $workspace_slug, $section_slug );
			}
		}

		return $destinations;
	}

	/**
	 * Plugin option keys a page posts, read back out of its own markup.
	 *
	 * Array-valued fields render as `name="…[]"`; the brackets are dropped so a key is
	 * compared by option name regardless of how its input is shaped.
	 *
	 * @param string $html Rendered markup.
	 * @return string[] Sorted, unique option keys.
	 */
	private function rendered_option_keys( $html ) {
		preg_match_all( '/name="(shift64_woo_search_[a-z0-9_]+)(?:\[\])?"/', $html, $matches );

		$keys = array_values( array_unique( $matches[1] ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * Every stored plugin option, read straight from the options table.
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

	/**
	 * Give every owned option a recognisable value so any stray write shows up.
	 *
	 * @return array<string, mixed> Option name => sentinel value.
	 */
	private function seed_ownership_sentinels() {
		$sentinels = array(
			// Owned by Facets, but rendered as per-attribute rows the harness has none of.
			'shift64_woo_search_filter_attributes'       => array( 'pa_sentinel' ),
			// Owned by Result Coverage; the one array option in the generic allowlist.
			'shift64_woo_search_taxonomy_archive_scopes' => array(),
		);

		foreach ( $this->spec_option_ownership() as $owned ) {
			foreach ( $owned as $option ) {
				if ( ! isset( $sentinels[ $option ] ) ) {
					$sentinels[ $option ] = 'sentinel-' . $option;
				}
			}
		}

		foreach ( $sentinels as $option => $value ) {
			update_option( $option, $value );
		}

		return $sentinels;
	}

	// ── Registry completeness ──────────────────────────────────

	/**
	 * The navigation contract in one assertion set: six workspaces, in the documented
	 * order, nineteen destinations between them, each workspace landing on a section it
	 * actually declares.
	 *
	 * A default pointing at a section of another workspace — or at none at all — would
	 * make a workspace link resolve somewhere the secondary navigation never offers.
	 */
	public function test_registry_declares_six_ordered_workspaces_and_nineteen_destinations() {
		$workspaces = Shift64_Woo_Search_Admin_Routes::get_workspaces();

		$this->assertSame(
			array( 'overview', 'experience', 'results', 'relevance', 'insights', 'system' ),
			array_keys( $workspaces )
		);

		$destinations = 0;
		foreach ( $workspaces as $workspace_slug => $workspace ) {
			$destinations += count( $workspace['sections'] );

			$this->assertArrayHasKey(
				$workspace['default'],
				$workspace['sections'],
				"The {$workspace_slug} default section is not one of its own sections."
			);
		}

		$this->assertSame( 19, $destinations );
	}

	/**
	 * Every destination resolves to a renderer that is named by the registry, exists on
	 * the controller, and belongs to that destination alone.
	 *
	 * The blank legacy Synonyms tab was all three failures at once: a callback derived
	 * from the URL, naming a method that did not exist. A shared callback is the quieter
	 * version — the route looks right while the section renders, and saves, fields
	 * another section owns.
	 */
	public function test_every_destination_has_an_explicit_existing_and_unshared_renderer() {
		$workspaces = Shift64_Woo_Search_Admin_Routes::get_workspaces();
		$callbacks  = array();

		foreach ( $this->canonical_destinations() as $destination ) {
			list( $workspace, $section ) = $destination;

			$declared = $workspaces[ $workspace ]['sections'][ $section ]['callback'];
			$resolved = Shift64_Woo_Search_Admin_Routes::resolve( $workspace, $section );

			$this->assertSame( $declared, $resolved['callback'], "{$workspace}/{$section} resolved to a callback it does not declare." );
			$this->assertTrue(
				method_exists( Shift64_Woo_Search_Admin::class, $declared ),
				"{$workspace}/{$section} declares {$declared}(), which does not exist."
			);

			$callbacks[] = $declared;
		}

		$this->assertCount( 19, $callbacks );
		$this->assertSame(
			count( $callbacks ),
			count( array_unique( $callbacks ) ),
			'Two destinations share a renderer: ' . implode( ', ', array_keys( array_filter( array_count_values( $callbacks ), fn( $n ) => $n > 1 ) ) )
		);
	}

	// ── Legacy aliases ─────────────────────────────────────────

	/**
	 * Each of the twelve legacy tabs lands where the spec says it lands.
	 *
	 * @dataProvider spec_alias_provider
	 *
	 * @param string $legacy_tab Legacy `tab` value.
	 * @param string $workspace  Documented canonical workspace.
	 * @param string $section    Documented canonical section.
	 */
	public function test_legacy_alias_lands_on_its_documented_destination( $legacy_tab, $workspace, $section ) {
		$resolved = Shift64_Woo_Search_Admin_Routes::resolve( $legacy_tab, null );

		$this->assertSame( $workspace, $resolved['workspace'] );
		$this->assertSame( $section, $resolved['section'] );
	}

	public function spec_alias_provider() {
		$cases = array();

		foreach ( $this->spec_alias_table() as $legacy_tab => $target ) {
			$cases[ $legacy_tab ] = array( $legacy_tab, $target[0], $target[1] );
		}

		return $cases;
	}

	/**
	 * The alias map as a whole matches the spec table — no alias added, dropped, or
	 * repointed. Twelve is the number the compatibility promise names.
	 */
	public function test_alias_map_is_exactly_the_spec_table() {
		$this->assertSame( $this->spec_alias_table(), Shift64_Woo_Search_Admin_Routes::get_aliases() );
	}

	// ── Single ownership ───────────────────────────────────────

	/**
	 * A section renders the option keys the spec assigns it, and no others.
	 *
	 * Section forms post every field they render, so "which keys does this page show"
	 * and "which keys can this page overwrite" are the same question.
	 *
	 * @dataProvider spec_ownership_provider
	 *
	 * @param string   $workspace Workspace slug.
	 * @param string   $section   Section slug.
	 * @param string[] $owned     Option keys the spec assigns to the section.
	 */
	public function test_section_renders_exactly_the_option_keys_the_spec_assigns_it( $workspace, $section, $owned ) {
		sort( $owned );

		$this->assertSame( $owned, $this->rendered_option_keys( $this->render( $workspace, $section ) ) );
	}

	public function spec_ownership_provider() {
		$cases = array();

		foreach ( $this->spec_option_ownership() as $route => $owned ) {
			list( $workspace, $section ) = explode( '/', $route );

			$cases[ $route ] = array( $workspace, $section, $owned );
		}

		return $cases;
	}

	/**
	 * No option key is rendered by two destinations.
	 *
	 * Asserted against what the pages actually output rather than against the table
	 * above, so a field copied into a second section fails here even if someone updates
	 * the table to match.
	 */
	public function test_no_option_key_is_rendered_by_two_destinations() {
		$owners = array();

		foreach ( $this->canonical_destinations() as $destination ) {
			list( $workspace, $section ) = $destination;

			foreach ( $this->rendered_option_keys( $this->render( $workspace, $section ) ) as $key ) {
				$this->assertArrayNotHasKey(
					$key,
					$owners,
					sprintf( '%s is rendered by both %s and %s/%s.', $key, $owners[ $key ] ?? '', $workspace, $section )
				);

				$owners[ $key ] = "{$workspace}/{$section}";
			}
		}

		$this->assertNotEmpty( $owners, 'No section rendered a single plugin option — the collector is broken.' );
	}

	// ── Render without write ───────────────────────────────────

	/**
	 * The sweep: every canonical destination, every legacy alias, and the bare page with
	 * no `tab` at all leave the stored options byte-identical.
	 *
	 * This is what makes an admin URL safe to bookmark, refresh, prefetch, or hand to a
	 * colleague — including Overview, which merchants will hit most often and which must
	 * stay a signpost rather than a page that quietly normalizes defaults on arrival.
	 *
	 * PHPUnit converts notices, warnings, and deprecations into exceptions here, so
	 * rendering each route at all is an assertion in its own right.
	 */
	public function test_every_canonical_and_legacy_route_writes_no_options() {
		// Unusual legacy values: rendering must not tidy these up either.
		update_option( 'shift64_woo_search_logic', 'or' );
		update_option( 'shift64_woo_search_fuzzy_level', '' );
		update_option( 'shift64_woo_search_debounce', '150ms' );

		$routes = array( array( null, null ) );

		foreach ( $this->canonical_destinations() as $destination ) {
			$routes[] = $destination;
		}
		foreach ( array_keys( $this->spec_alias_table() ) as $legacy_tab ) {
			$routes[] = array( $legacy_tab, null );
		}

		$this->assertCount( 32, $routes, 'The sweep must cover the default landing, 19 destinations, and 12 aliases.' );

		$before = $this->option_snapshot();
		$this->assertNotEmpty( $before, 'The snapshot must observe real stored options.' );

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

	// ── Section-scoped saves ───────────────────────────────────

	/**
	 * Saving one section writes that section's keys and nothing else.
	 *
	 * `tests/test-admin-settings-persistence.php` proves the seam honours its allowlist;
	 * this is the same guarantee read through the ownership map — every *other* section's
	 * keys, the Facets options the generic seam may not touch at all, and the stored
	 * Redis credentials all keep their sentinel values.
	 *
	 * @dataProvider generic_section_provider
	 *
	 * @param string $route   "workspace/section" being saved.
	 * @param array  $payload Settings payload that section's form would post.
	 */
	public function test_saving_one_section_leaves_every_other_sections_options_untouched( $route, array $payload ) {
		$sentinels = $this->seed_ownership_sentinels();

		Shift64_Woo_Search_Admin_Settings::persist( $payload );

		foreach ( $payload as $option => $expected ) {
			$this->assertSame( $expected, get_option( $option ), "{$route} failed to save its own option {$option}." );
		}

		foreach ( $sentinels as $option => $value ) {
			if ( array_key_exists( $option, $payload ) ) {
				continue;
			}

			$this->assertSame( $value, get_option( $option ), "Saving {$route} overwrote {$option}, which it does not own." );
		}
	}

	public function generic_section_provider() {
		$cases = array();

		foreach ( $this->generic_section_payloads() as $route => $payload ) {
			$cases[ $route ] = array( $route, $payload );
		}

		return $cases;
	}

	/**
	 * The one intentional cross-field write, kept inside its own section.
	 *
	 * Turning authentication off has to wipe the stored credentials, or the regenerated
	 * SHORTINIT config would keep presenting them. Both cleared keys belong to
	 * Connection, so even this special case stays within the section that triggered it —
	 * every other section's options are still sentinel-valued afterwards.
	 */
	public function test_disabling_auth_clears_credentials_and_nothing_beyond_connection() {
		$sentinels = $this->seed_ownership_sentinels();

		Shift64_Woo_Search_Admin_Settings::persist(
			array( 'shift64_woo_search_redis_auth_enabled' => 'no' )
		);

		$this->assertSame( 'no', get_option( 'shift64_woo_search_redis_auth_enabled' ) );
		$this->assertSame( '', get_option( 'shift64_woo_search_redis_username' ) );
		$this->assertSame( '', get_option( 'shift64_woo_search_redis_password' ) );

		$cleared = array(
			'shift64_woo_search_redis_auth_enabled',
			'shift64_woo_search_redis_username',
			'shift64_woo_search_redis_password',
		);

		foreach ( $sentinels as $option => $value ) {
			if ( in_array( $option, $cleared, true ) ) {
				continue;
			}

			$this->assertSame( $value, get_option( $option ), "Disabling Redis auth overwrote {$option}." );
		}
	}

	// ── Credential containment ─────────────────────────────────

	/**
	 * Stored credentials appear on exactly one page, in exactly one place.
	 *
	 * Navigation is rendered on every route from the same registry, so a credential that
	 * leaked into a label, a link, or a data attribute would leak everywhere at once —
	 * including onto Overview, the page merchants are most likely to screenshot or share.
	 * The Connection form is the sole exception, and even there each value may appear
	 * only as its own input's value.
	 */
	public function test_credentials_appear_only_inside_the_connection_form() {
		$username = 's64ws-ia-username-sentinel';
		$password = 's64ws-ia-password-sentinel';

		update_option( 'shift64_woo_search_redis_auth_enabled', 'yes' );
		update_option( 'shift64_woo_search_redis_username', $username );
		update_option( 'shift64_woo_search_redis_password', $password );

		$routes = array( array( null, null ) );

		foreach ( $this->canonical_destinations() as $destination ) {
			$routes[] = $destination;
		}
		foreach ( array_keys( $this->spec_alias_table() ) as $legacy_tab ) {
			$routes[] = array( $legacy_tab, null );
		}

		$connection_routes = 0;

		foreach ( $routes as $route ) {
			list( $tab, $section ) = $route;

			$html     = $this->render( $tab, $section );
			$resolved = Shift64_Woo_Search_Admin_Routes::resolve( $tab, $section );
			$label    = sprintf( 'tab=%s section=%s', (string) $tab, (string) $section );

			if ( 'system' === $resolved['workspace'] && 'connection' === $resolved['section'] ) {
				++$connection_routes;

				$this->assertSame( 1, substr_count( $html, $username ), "{$label} renders the username more than once." );
				$this->assertSame( 1, substr_count( $html, $password ), "{$label} renders the password more than once." );
				$this->assertStringContainsString( 'name="shift64_woo_search_redis_username" value="' . $username . '"', $html );
				$this->assertStringContainsString( 'name="shift64_woo_search_redis_password" value="' . $password . '"', $html );

				continue;
			}

			$this->assertStringNotContainsString( $username, $html, "{$label} leaks the stored Redis username." );
			$this->assertStringNotContainsString( $password, $html, "{$label} leaks the stored Redis password." );
		}

		$this->assertSame( 2, $connection_routes, 'Connection is reachable canonically and through the legacy redis alias.' );
	}

	// ── Hostile input ──────────────────────────────────────────

	/**
	 * A hostile `section` falls back to the workspace default.
	 *
	 * Hostile `tab` values are covered at render level in
	 * `tests/test-admin-page-render.php`; `section` is the other half of the route and
	 * reaches the registry by the same path. Because the harness turns warnings and
	 * deprecations into exceptions, producing the default page at all is the assertion:
	 * nothing was interpolated into a method name, and nothing tripped over an array
	 * where a string was expected.
	 *
	 * Whitespace-padded slugs are absent from the cases below on purpose: the page
	 * sanitizes `$_GET` with `sanitize_text_field()` before resolving, which trims, so
	 * ` synonyms ` arrives at the registry as a perfectly ordinary `synonyms`. The
	 * resolver's own case for the untrimmed value lives in `tests/test-admin-routes.php`.
	 *
	 * @dataProvider hostile_section_provider
	 *
	 * @param mixed $section Hostile `section` request value.
	 */
	public function test_hostile_section_renders_the_workspace_default( $section ) {
		$html = $this->render( 'relevance', $section );

		$this->assertStringContainsString( '<h2 class="shift64-woo-search-admin__section-title">Basic Ranking</h2>', $html );
		$this->assertStringContainsString( 'name="shift64_woo_search_logic"', $html );
	}

	public function hostile_section_provider() {
		return array(
			'array'           => array( array( 'synonyms' ) ),
			'nested array'    => array( array( 'section' => array( 'synonyms' ) ) ),
			'path traversal'  => array( '../../etc/passwd' ),
			'markup'          => array( '<script>alert(1)</script>' ),
			'constructor'     => array( '__construct' ),
			'public method'   => array( 'render_page' ),
			'private method'  => array( 'render_synonys64ws_tab' ),
			'wrong case'      => array( 'SYNONYMS' ),
			'other workspace' => array( 'connection' ),
		);
	}
}
