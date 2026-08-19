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
	 * @param string $pills Serialized pill block comments.
	 * @param string $parent_attrs Optional parent attribute JSON.
	 * @return string
	 */
	private function render_filters( $pills, $parent_attrs = '' ) {
		Shift64_Woo_Search_Filter_Blocks::reset();
		$attrs = '' !== $parent_attrs ? ' ' . $parent_attrs : '';
		return do_blocks(
			'<!-- wp:shift64-woo-search/product-filters' . $attrs . ' -->' . $pills . '<!-- /wp:shift64-woo-search/product-filters -->'
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","hideEmpty":false} /-->'
		);

		$this->assertStringContainsString( 'shift64-woo-search-product-filters', $html );
		$this->assertStringContainsString( 'data-wp-router-region', $html );
		$this->assertStringContainsString( '<details class="shift64-woo-search-pill__disclosure">', $html );
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","hideEmpty":false} /-->'
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","hideEmpty":false} /-->'
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","hideEmpty":false} /-->'
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material","hideEmpty":false} /-->'
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","selectionMode":"single","hideEmpty":false} /-->'
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material","queryType":"and","hideEmpty":false} /-->'
		);
		$category_html  = $this->render_filters(
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","queryType":"and","hideEmpty":false} /-->'
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material","orderBy":"name-desc","maxOptions":2,"hideEmpty":false} /-->'
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
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material"} /-->'
		);

		$this->assertStringContainsString( 'value="wool" checked=\'checked\'', $html );
		$this->assertStringNotContainsString( 'value="cotton"', $html );
	}

	/**
	 * Clear All appears only with an active represented facet, removes only
	 * represented parameters, and preserves search and sorting.
	 */
	public function test_clear_all_scope_and_visibility() {
		$pills = '<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","hideEmpty":false} /-->' .
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_material","hideEmpty":false} /-->';

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

		$disabled = $this->render_filters( $pills, '{"showClearAll":false}' );
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
}
