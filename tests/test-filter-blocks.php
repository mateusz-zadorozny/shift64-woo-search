<?php
/**
 * Tests for Product Filters / Filter Pill server rendering.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Progressive rendering tests for the filter blocks.
 *
 * Contract under test: pills render native details/summary disclosures with
 * plain GET forms writing canonical filter URLs; only ready facets render;
 * invalid request values are dropped; Clear / Clear All remove exactly their
 * own parameters while preserving search and sorting state.
 */
class Filter_Blocks_Test extends WP_UnitTestCase {

	/**
	 * Term ids by slug for the fixtures.
	 *
	 * @var array<string,int>
	 */
	private static $terms = array();

	/**
	 * Set up taxonomies, terms, settings, and a forced-ready eligibility set.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'product_cat', 'pa_material' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, 'product' );
			}
		}

		update_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_brands_enabled', 'no' );
		update_option( 'shift64_woo_search_filter_attributes', array( 'pa_material' ) );

		foreach ( array(
			array( 'product_cat', 'Lamps', 'lamps' ),
			array( 'product_cat', 'Chairs', 'chairs' ),
			array( 'pa_material', 'Cotton', 'cotton' ),
			array( 'pa_material', 'Wool', 'wool' ),
			array( 'pa_material', 'Linen', 'linen' ),
		) as $fixture ) {
			list( $taxonomy, $name, $slug ) = $fixture;
			if ( ! get_term_by( 'slug', $slug, $taxonomy ) ) {
				$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
				if ( ! is_wp_error( $created ) ) {
					self::$terms[ $slug ] = (int) $created['term_id'];
				}
			}
		}

		// The isolated test environment has no Redis index; force every
		// enabled facet ready so rendering is exercised deterministically.
		add_filter( 'shift64_woo_search_facet_entries', array( $this, 'force_ready' ) );

		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = '/shop/';
		Shift64_Woo_Search_Filter_Blocks::reset();
		Shift64_Woo_Search_Facet_Eligibility::reset();
	}

	/**
	 * Reset request globals.
	 */
	public function tear_down() {
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = '/';
		Shift64_Woo_Search_Filter_Blocks::reset();
		Shift64_Woo_Search_Facet_Eligibility::reset();
		parent::tear_down();
	}

	/**
	 * Force enabled facets ready (the index is unavailable under test).
	 *
	 * @param array $entries Eligibility entries.
	 * @return array
	 */
	public function force_ready( $entries ) {
		foreach ( $entries as $key => $entry ) {
			if ( 'disabled' !== $entry['status'] ) {
				$entries[ $key ]['status'] = 'ready';
			}
		}
		return $entries;
	}

	/**
	 * Render a parent with the given serialized pill fragments.
	 *
	 * Option-list settings live on the parent and reach pills as block
	 * context, so tests configure them here. `hideEmpty` defaults to false
	 * because the fixture terms have no products attached.
	 *
	 * @param string              $pills Serialized pill block comments.
	 * @param array<string,mixed> $parent_attrs Optional parent attributes.
	 * @return string
	 */
	private function render_filters( $pills, $parent_attrs = array() ) {
		Shift64_Woo_Search_Filter_Blocks::reset();
		$parent_attrs = array_merge( array( 'hideEmpty' => false ), $parent_attrs );
		return do_blocks(
			'<!-- wp:shift64-woo-search/product-filters ' . wp_json_encode( $parent_attrs ) . ' -->' .
			$pills .
			'<!-- /wp:shift64-woo-search/product-filters -->'
		);
	}

	/**
	 * Both blocks register from build metadata.
	 */
	public function test_blocks_are_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/product-filters' ) );
		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/filter-pill' ) );
	}

	/**
	 * A ready pill renders the progressive disclosure and canonical GET form.
	 */
	public function test_pill_renders_progressive_form() {
		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->'
		);

		$this->assertStringContainsString( 'shift64-woo-search-product-filters', $html );
		$this->assertStringContainsString( 'data-wp-router-region', $html );
		$this->assertStringContainsString( '<details class="shift64-woo-search-pill__disclosure"', $html );
		$this->assertStringContainsString( 'shift64-woo-search-pill__trigger', $html );
		$this->assertStringContainsString( 'name="filter_product_cat[]"', $html );
		$this->assertStringContainsString( 'value="lamps"', $html );
		$this->assertStringContainsString( 'value="chairs"', $html );
		$this->assertStringContainsString( 'method="get"', $html );
		$this->assertStringContainsString( '>Apply</button>', $html );
		// No counts are claimed before the count provider supplies them.
		$this->assertStringNotContainsString( 'shift64-woo-search-pill__count', $html );
	}

	/**
	 * A saved facet outside the ready set renders no storefront control while
	 * the parent wrapper (router region) stays.
	 */
	public function test_ineligible_pill_is_omitted() {
		update_option( 'shift64_woo_search_filter_brands_enabled', 'no' );

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_brand"} /-->' .
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_unknown"} /-->'
		);

		$this->assertStringContainsString( 'shift64-woo-search-product-filters', $html );
		$this->assertStringNotContainsString( 'shift64-woo-search-pill__trigger', $html );
	}

	/**
	 * Invalid and unknown slugs from the URL are dropped; valid ones check
	 * their boxes and surface in the selection summary.
	 */
	public function test_invalid_url_values_are_dropped() {
		$_GET['filter_product_cat'] = 'lamps,<script>alert(1)</script>,unknown-term';

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->'
		);

		$this->assertStringContainsString( 'value="lamps" checked=\'checked\'', $html );
		$this->assertStringNotContainsString( 'unknown-term', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'shift64-woo-search-pill__summary-count', $html );
	}

	/**
	 * Term names are escaped on output.
	 */
	public function test_option_labels_are_escaped() {
		wp_insert_term( 'Weird "<em>name</em>"', 'product_cat', array( 'slug' => 'weird' ) );

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->'
		);

		// wp_insert_term strips the tags; the surviving quotes must leave the
		// renderer entity-escaped, never raw.
		$this->assertStringNotContainsString( '<em>name</em>', $html );
		$this->assertStringContainsString( 'Weird &quot;name&quot;', $html );
	}

	/**
	 * Clearing one pill removes only its two parameters and resets paging,
	 * preserving search, sorting, and the other facet's state.
	 */
	public function test_clear_removes_only_own_parameters() {
		$_SERVER['REQUEST_URI'] = '/shop/?s=lamp&post_type=product&orderby=price&filter_product_cat=lamps&filter_pa_material=wool&query_type_pa_material=and';
		$_GET                   = array(
			's'                      => 'lamp',
			'post_type'              => 'product',
			'orderby'                => 'price',
			'filter_product_cat'     => 'lamps',
			'filter_pa_material'     => 'wool',
			'query_type_pa_material' => 'and',
		);

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->'
		);

		$this->assertMatchesRegularExpression(
			'#<a class="shift64-woo-search-pill__clear" href="([^"]+)"#',
			$html
		);
		preg_match( '#<a class="shift64-woo-search-pill__clear" href="([^"]+)"#', $html, $matches );
		$clear_url = html_entity_decode( $matches[1] );

		$this->assertStringNotContainsString( 'filter_product_cat', $clear_url );
		$this->assertStringContainsString( 'filter_pa_material=wool', $clear_url );
		$this->assertStringContainsString( 'query_type_pa_material=and', $clear_url );
		$this->assertStringContainsString( 's=lamp', $clear_url );
		$this->assertStringContainsString( 'orderby=price', $clear_url );
	}

	/**
	 * Hidden inputs carry the other facets' validated state so a no-JS submit
	 * preserves it, while paging is deliberately dropped.
	 */
	public function test_hidden_inputs_preserve_other_state() {
		$_SERVER['REQUEST_URI'] = '/shop/page/3/?s=lamp&post_type=product&orderby=price&filter_product_cat=lamps';
		$_GET                   = array(
			's'                  => 'lamp',
			'post_type'          => 'product',
			'orderby'            => 'price',
			'filter_product_cat' => 'lamps',
			'paged'              => '3',
		);

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->'
		);

		$this->assertStringContainsString( 'name="s" value="lamp"', $html );
		$this->assertStringContainsString( 'name="post_type" value="product"', $html );
		$this->assertStringContainsString( 'name="orderby" value="price"', $html );
		$this->assertStringContainsString( 'name="filter_product_cat" value="lamps"', $html );
		$this->assertStringNotContainsString( 'name="paged"', $html );
		// The form action strips the /page/N/ segment.
		preg_match( '#action="([^"]+)"#', $html, $matches );
		$this->assertStringEndsWith( '/shop/', $matches[1] );
	}

	/**
	 * Single selection mode renders radio semantics with a scalar name.
	 */
	public function test_single_mode_renders_radios() {
		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->',
			array( 'selectionMode' => 'single' )
		);

		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'name="filter_product_cat"', $html );
		$this->assertStringNotContainsString( 'name="filter_product_cat[]"', $html );
	}

	/**
	 * The AND operator is emitted only where the registry supports it.
	 */
	public function test_query_type_hidden_input_follows_operator_support() {
		$attribute_html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material","queryType":"and"} /-->'
		);
		$category_html  = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","queryType":"and"} /-->'
		);

		$this->assertStringContainsString( 'name="query_type_pa_material" value="and"', $attribute_html );
		// Category supports OR only; a saved AND never reaches the URL contract.
		$this->assertStringNotContainsString( 'query_type_product_cat', $category_html );
	}

	/**
	 * Options are ordered per orderBy and bounded by the maxOptions clamp.
	 */
	public function test_option_ordering_and_max_options() {
		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->',
			array(
				'orderBy'    => 'name-desc',
				'maxOptions' => 2,
			)
		);

		$this->assertStringContainsString( 'Wool', $html );
		$this->assertStringContainsString( 'Linen', $html );
		$this->assertStringNotContainsString( 'Cotton', $html );
		$this->assertGreaterThan(
			strpos( $html, 'Wool' ) !== false ? strpos( $html, 'Wool' ) : PHP_INT_MAX,
			strpos( $html, 'Linen' ),
			'name-desc must render Wool before Linen'
		);
	}

	/**
	 * With hideEmpty, a selected zero-count term stays visible and removable.
	 */
	public function test_selected_zero_count_term_stays_visible() {
		$_GET['filter_pa_material'] = 'wool';

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->',
			array( 'hideEmpty' => true )
		);

		$this->assertStringContainsString( 'value="wool" checked=\'checked\'', $html );
		$this->assertStringNotContainsString( 'value="cotton"', $html );
	}

	/**
	 * Clear All appears only with an active represented facet, removes only
	 * represented parameters, and preserves search and sorting.
	 */
	public function test_clear_all_scope_and_visibility() {
		$pills = '<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->' .
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->';

		$idle = $this->render_filters( $pills );
		$this->assertStringNotContainsString( '__clear-all', $idle );

		$_SERVER['REQUEST_URI'] = '/shop/?s=lamp&post_type=product&orderby=price&filter_product_cat=lamps&filter_pa_brandless=x';
		$_GET                   = array(
			's'                   => 'lamp',
			'post_type'           => 'product',
			'orderby'             => 'price',
			'filter_product_cat'  => 'lamps',
			'filter_pa_brandless' => 'x',
		);

		$active = $this->render_filters( $pills );
		$this->assertStringContainsString( '__clear-all', $active );
		preg_match( '#class="shift64-woo-search-product-filters__clear-all" href="([^"]+)"#', $active, $matches );
		$clear_all_url = html_entity_decode( $matches[1] );

		$this->assertStringNotContainsString( 'filter_product_cat', $clear_all_url );
		$this->assertStringNotContainsString( 'filter_pa_material', $clear_all_url );
		$this->assertStringContainsString( 's=lamp', $clear_all_url );
		$this->assertStringContainsString( 'orderby=price', $clear_all_url );
		// Unrepresented direct URL state is not this block's to erase.
		$this->assertStringContainsString( 'filter_pa_brandless=x', $clear_all_url );

		$disabled = $this->render_filters( $pills, array( 'showClearAll' => false ) );
		$this->assertStringNotContainsString( '__clear-all', $disabled );
	}

	/**
	 * Catalog State accepts the no-JS checkbox array form and normalizes it
	 * to the canonical comma-separated selection.
	 */
	public function test_catalog_state_normalizes_array_filter_params() {
		$context = new Shift64_Woo_Search_Product_Collection_Context(
			7,
			'pc-test',
			1,
			12,
			'',
			'',
			'',
			'catalog'
		);

		$state = Shift64_Woo_Search_Catalog_State::from_request(
			$context,
			array( 'filter_product_cat' => array( 'lamps', 'chairs' ) ),
			'/shop/'
		);

		$selected = $state->get_selected_filters();
		$this->assertSame( array( 'chairs', 'lamps' ), $selected['filter_product_cat'] );
	}

	/**
	 * Term slug "0" is a legitimate selection — empty() must not discard it.
	 */
	public function test_zero_slug_selection_survives() {
		// wp_insert_term() itself refuses a bare "0" slug, so force it the way
		// imports and direct writes can: straight into the terms table.
		global $wpdb;
		$created = wp_insert_term( 'Zero', 'pa_material', array( 'slug' => 'zero-tmp' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Forcing a "0" slug that the terms API refuses to create.
		$wpdb->update( $wpdb->terms, array( 'slug' => '0' ), array( 'term_id' => $created['term_id'] ) );
		clean_term_cache( $created['term_id'], 'pa_material' );

		$_GET['filter_pa_material'] = '0';
		$_SERVER['REQUEST_URI']     = '/shop/?filter_pa_material=0';

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->'
		);

		$this->assertStringContainsString( 'value="0" checked=\'checked\'', $html );
		$this->assertStringContainsString( 'shift64-woo-search-pill__summary-count', $html );
	}

	/**
	 * Subdirectory installs: REQUEST_URI already carries the subdirectory, so
	 * canonical URLs must not double it.
	 */
	public function test_subdirectory_install_urls_are_not_doubled() {
		update_option( 'home', 'http://example.org/store' );
		$_SERVER['REQUEST_URI']     = '/store/shop/?filter_product_cat=lamps';
		$_GET['filter_product_cat'] = 'lamps';

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->'
		);

		preg_match( '#class="shift64-woo-search-pill__clear" href="([^"]+)"#', $html, $matches );
		$this->assertNotEmpty( $matches );
		$this->assertStringStartsWith( 'http://example.org/store/shop/', html_entity_decode( $matches[1] ) );
		$this->assertStringNotContainsString( '/store/store/', $matches[1] );
	}

	/**
	 * The maxOptions bound never hides an active selection: a hidden checked
	 * box would be silently dropped by the next Apply.
	 */
	public function test_max_options_keeps_selected_options_visible() {
		$_GET['filter_pa_material'] = 'wool';
		$_SERVER['REQUEST_URI']     = '/shop/?filter_pa_material=wool';

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->',
			array(
				'orderBy'    => 'name-asc',
				'maxOptions' => 1,
			)
		);

		// Cotton wins the name-asc bound; selected Wool must survive anyway.
		$this->assertStringContainsString( 'value="cotton"', $html );
		$this->assertStringContainsString( 'value="wool" checked=\'checked\'', $html );
		$this->assertStringNotContainsString( 'value="linen"', $html );
	}

	/**
	 * Unrelated safe scalar parameters (language switchers, WooCommerce price
	 * widgets, plain-permalink scope) survive a no-JS submit as hidden inputs;
	 * paging and private parameters never do.
	 */
	public function test_hidden_inputs_preserve_unrelated_safe_params() {
		$_SERVER['REQUEST_URI'] = '/shop/?min_price=10&lang=en&product_cat=lamps&paged=3&_wpnonce=abc';
		$_GET                   = array(
			'min_price'   => '10',
			'lang'        => 'en',
			'product_cat' => 'lamps',
			'paged'       => '3',
			'_wpnonce'    => 'abc',
		);

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->'
		);

		$this->assertStringContainsString( 'name="min_price" value="10"', $html );
		$this->assertStringContainsString( 'name="lang" value="en"', $html );
		$this->assertStringContainsString( 'name="product_cat" value="lamps"', $html );
		$this->assertStringNotContainsString( 'name="paged"', $html );
		$this->assertStringNotContainsString( 'name="_wpnonce"', $html );
	}

	/**
	 * Array-form filter parameters (a no-JS checkbox submit) canonicalize to
	 * one comma-form redirect URL; canonical requests produce none.
	 */
	public function test_array_form_params_canonicalize_to_redirect_url() {
		$_SERVER['REQUEST_URI'] = '/shop/?s=lamp&post_type=product';
		$_GET                   = array(
			's'                  => 'lamp',
			'post_type'          => 'product',
			'filter_product_cat' => array( 'lamps', 'chairs', 'lamps' ),
		);
		$blocks                 = new Shift64_Woo_Search_Filter_Blocks();

		$canonical = $blocks->canonical_array_form_url();

		$this->assertIsString( $canonical );
		$this->assertStringContainsString( 'filter_product_cat=chairs%2Clamps', $canonical );
		$this->assertStringContainsString( 's=lamp', $canonical );

		$_GET['filter_product_cat'] = 'lamps';
		$this->assertNull( $blocks->canonical_array_form_url() );
	}

	/**
	 * Preserved query values are re-encoded: a literal ampersand in the search
	 * term must not split into a stray parameter in Clear URLs.
	 */
	public function test_build_url_reencodes_preserved_values() {
		$url = Shift64_Woo_Search_Catalog_State::build_url(
			'http://example.org/shop/?s=black%20%26%20decker&post_type=product&filter_product_cat=lamps',
			array( 'filter_product_cat' => null )
		);

		$this->assertStringContainsString( '%26', $url );
		$this->assertStringNotContainsString( '& decker', $url );
		$this->assertStringNotContainsString( 'decker=', $url );
	}

	/**
	 * A facet that is enabled in settings but absent from the live index is
	 * not ready, so its URL parameter is dropped from parsed state instead of
	 * building a Redis field expression that cannot exist.
	 */
	public function test_non_ready_facet_params_are_dropped_from_state() {
		remove_filter( 'shift64_woo_search_facet_entries', array( $this, 'force_ready' ) );
		Shift64_Woo_Search_Facet_Eligibility::reset();

		$context = new Shift64_Woo_Search_Product_Collection_Context( 7, 'pc-test', 1, 12, '', '', '', 'catalog' );
		$state   = Shift64_Woo_Search_Catalog_State::from_request(
			$context,
			array( 'filter_pa_material' => 'wool' ),
			'/shop/'
		);

		$this->assertSame( array(), $state->get_selected_filters() );
		$this->assertSame( array(), $state->get_redis_filters() );
	}

	/**
	 * One parent setting reaches every pill, so a row of pills cannot drift
	 * into inconsistent option lists.
	 */
	public function test_parent_option_settings_reach_every_pill() {
		// Both facets active so each pill renders its Clear control.
		$_GET['filter_product_cat'] = 'lamps';
		$_GET['filter_pa_material'] = 'wool';

		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->' .
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->',
			array(
				'selectionMode' => 'single',
				'applyLabel'    => 'Use these',
				'clearLabel'    => 'Reset',
			)
		);

		$this->assertSame( 2, substr_count( $html, 'shift64-woo-search-pill__trigger' ) );
		$this->assertSame( 2, substr_count( $html, '>Use these</button>' ) );
		$this->assertSame( 2, substr_count( $html, '>Reset</a>' ) );
		$this->assertSame( 0, substr_count( $html, 'type="checkbox"' ) );
		$this->assertStringContainsString( 'name="filter_product_cat"', $html );
		$this->assertStringContainsString( 'name="filter_pa_material"', $html );
	}

	/**
	 * A pill that somehow renders without parent context still falls back to
	 * the documented defaults rather than emitting a broken option list.
	 */
	public function test_pill_settings_fall_back_without_context() {
		$settings = new ReflectionMethod( 'Shift64_Woo_Search_Filter_Blocks', 'pill_settings' );

		$expected = array(
			'selectionMode' => 'multiple',
			'showCounts'    => true,
			'hideEmpty'     => true,
			'orderBy'       => 'count-desc',
			'maxOptions'    => 0,
			'applyLabel'    => '',
			'clearLabel'    => '',
		);

		$this->assertSame( $expected, $settings->invoke( null, null ) );

		// An out-of-range ordering must not reach the sort comparator.
		$block = new WP_Block(
			array(
				'blockName' => 'shift64-woo-search/filter-pill',
				'attrs'     => array( 'facet' => 'product_cat' ),
				'innerHTML' => '',
			),
			array( 'shift64WooSearch/orderBy' => 'not-a-mode' )
		);

		$this->assertSame( 'count-desc', $settings->invoke( null, $block )['orderBy'] );
	}

	/**
	 * The parent resolves pillStyle into custom properties on its own wrapper,
	 * which is the only element every pill inside it can inherit from.
	 */
	public function test_pill_style_renders_custom_properties_on_the_parent() {
		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->',
			array(
				'pillStyle' => array(
					'color'  => array(
						'text'       => '#111111',
						'background' => '#ffffff',
					),
					'border' => array(
						'color'  => '#cccccc',
						'width'  => '2px',
						'radius' => '8px',
					),
					':hover' => array(
						'color'  => array( 'background' => '#503aa8' ),
						'border' => array( 'color' => '#2f2166' ),
					),
				),
			)
		);

		$this->assertStringContainsString( '--s64ws-pill-color:#111111', $html );
		$this->assertStringContainsString( '--s64ws-pill-bg:#ffffff', $html );
		$this->assertStringContainsString( '--s64ws-pill-border-color:#cccccc', $html );
		$this->assertStringContainsString( '--s64ws-pill-border-width:2px', $html );
		$this->assertStringContainsString( '--s64ws-pill-radius:8px', $html );
		$this->assertStringContainsString( '--s64ws-pill-bg-hover:#503aa8', $html );
		$this->assertStringContainsString( '--s64ws-pill-border-color-hover:#2f2166', $html );

		// Unset tokens must stay unset so the stylesheet fallbacks apply.
		$this->assertStringNotContainsString( '--s64ws-pill-color-hover', $html );
	}

	/**
	 * An unstyled parent emits no style attribute of its own.
	 */
	public function test_unstyled_parent_emits_no_pill_tokens() {
		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->'
		);

		$this->assertStringNotContainsString( '--s64ws-pill-', $html );
	}

	/**
	 * Preset references resolve to the theme's custom property, mirroring
	 * core's wp_normalize_state_preset_vars().
	 */
	public function test_pill_style_expands_theme_presets() {
		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->',
			array(
				'pillStyle' => array(
					'color' => array( 'background' => 'var:preset|color|accent-3' ),
				),
			)
		);

		$this->assertStringContainsString(
			'--s64ws-pill-bg:var(--wp--preset--color--accent-3)',
			$html
		);
	}

	/**
	 * Core drops the whole style attribute when any single declaration looks
	 * hostile, so a bad value must be filtered per token rather than taking
	 * every sibling token down with it.
	 */
	public function test_hostile_pill_style_value_drops_only_itself() {
		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->',
			array(
				'pillStyle' => array(
					'color' => array(
						'text'       => 'url(javascript:alert(1))',
						'background' => '#ffffff',
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( '--s64ws-pill-color:', $html );
		$this->assertStringContainsString( '--s64ws-pill-bg:#ffffff', $html );
	}

	/**
	 * Filter Pill dropped its colour/border supports: a wrapper background
	 * paints the box around the control instead of the control, so saved
	 * colour styles must not be serialized onto the pill any more.
	 */
	public function test_pill_does_not_serialize_wrapper_colours() {
		$html = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","style":{"color":{"background":"#ff0000"}}} /-->'
		);

		$this->assertStringContainsString( 'shift64-woo-search-pill__trigger', $html );
		$this->assertStringNotContainsString( '#ff0000', $html );
		$this->assertStringNotContainsString( 'has-background', $html );
	}
}
