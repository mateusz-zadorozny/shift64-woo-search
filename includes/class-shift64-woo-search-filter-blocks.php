<?php
/**
 * Product Filters / Filter Pill server rendering.
 *
 * Progressive baseline (spec: .ai/specs/2026-07-30-product-filter-pill-blocks.md):
 * each pill renders a native details/summary disclosure with a plain GET form,
 * so filtering navigates to canonical `filter_{taxonomy}` URLs without any
 * JavaScript. The Interactivity API layer (Phase 2) upgrades the same markup.
 *
 * Only ready eligibility entries render controls; a saved-but-ineligible
 * facet is silently omitted on the storefront (it stays configured in the
 * editor, where a warning explains why).
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render callbacks and canonical URL state for the filter blocks.
 */
class Shift64_Woo_Search_Filter_Blocks {

	/**
	 * Hook the canonical-URL normalization for no-JS form submissions.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'redirect_array_filter_params' ) );
	}

	/**
	 * Redirect `filter_x[]=a&filter_x[]=b` (what a no-JS checkbox form
	 * submits) to the canonical `filter_x=a,b` form.
	 *
	 * Only the two new parsers understand the array form; the taxonomy
	 * archive interceptor, the legacy renderer, and WooCommerce layered nav
	 * all silently drop it, so pills would show a selection the query never
	 * applied. One early redirect converges every consumer — and shared
	 * URLs/page caches — on the canonical scalar form.
	 */
	public function redirect_array_filter_params() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$canonical = $this->canonical_array_form_url();
		if ( null === $canonical ) {
			return;
		}
		wp_safe_redirect( $canonical );
		exit;
	}

	/**
	 * The canonical comma-form URL for a request carrying array-form filter
	 * parameters, or null when the request is already canonical.
	 *
	 * @return string|null
	 */
	public function canonical_array_form_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public storefront state.
		$request = wp_unslash( $_GET );
		$changes = array();

		foreach ( Shift64_Woo_Search_Facet_Eligibility::get_ready() as $entry ) {
			$param = 'filter_' . $entry['taxonomy'];
			if ( ! isset( $request[ $param ] ) || ! is_array( $request[ $param ] ) ) {
				continue;
			}
			$slugs = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_title', array_filter( $request[ $param ], 'is_scalar' ) )
					)
				)
			);
			sort( $slugs, SORT_STRING );
			$changes[ $param ] = empty( $slugs ) ? null : implode( ',', $slugs );
		}

		if ( empty( $changes ) ) {
			return null;
		}

		return Shift64_Woo_Search_Catalog_State::build_url( $this->current_url(), $changes );
	}

	/**
	 * Per-request cache of the validated current selections, keyed by facet.
	 *
	 * Static so tests can reset it between simulated requests; the render
	 * callbacks live behind the block registry where the instance is
	 * unreachable.
	 *
	 * @var array<string,array>|null
	 */
	private static $selections = null;

	/**
	 * Per-request pill counter for unique interactivity ids.
	 *
	 * @var int
	 */
	private static $pill_sequence = 0;

	/**
	 * Pill style tokens, mirroring src/blocks/shared/pill-style.js.
	 *
	 * WordPress 7.1's per-block interactive states are gated on a hardcoded
	 * core allowlist (WP_Theme_JSON::VALID_BLOCK_PSEUDO_SELECTORS, and the
	 * matching VALID_BLOCK_PSEUDO_STATES in the editor bundle), so a
	 * third-party block cannot register for the native States UI. The parent
	 * therefore stores one `pillStyle` attribute in core's own `style` shape —
	 * `:hover` key included — and resolves it to custom properties the shared
	 * pill primitive consumes. Styling belongs to the parent because the
	 * pill's own block wrapper is the box *around* the control.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const PILL_STYLE_VARS = array(
		'--s64ws-pill-color'              => array( 'color', 'text' ),
		'--s64ws-pill-bg'                 => array( 'color', 'background' ),
		'--s64ws-pill-border-color'       => array( 'border', 'color' ),
		'--s64ws-pill-border-width'       => array( 'border', 'width' ),
		'--s64ws-pill-radius'             => array( 'border', 'radius' ),
		'--s64ws-pill-color-hover'        => array( ':hover', 'color', 'text' ),
		'--s64ws-pill-bg-hover'           => array( ':hover', 'color', 'background' ),
		'--s64ws-pill-border-color-hover' => array( ':hover', 'border', 'color' ),
	);

	/**
	 * Resolve a theme preset reference to a CSS custom property reference.
	 *
	 * Mirrors core's wp_normalize_state_preset_vars(), which is 7.1-only while
	 * this plugin still supports WordPress 6.0.
	 *
	 * @param string $value Stored style value.
	 * @return string CSS-ready value.
	 */
	private static function normalize_preset_value( $value ) {
		if ( ! str_starts_with( $value, 'var:preset|' ) ) {
			return $value;
		}

		return 'var(--wp--' . str_replace( '|', '--', substr( $value, strlen( 'var:' ) ) ) . ')';
	}

	/**
	 * Accept only value shapes a colour or length control can produce.
	 *
	 * Core's safecss_filter_attr() drops the *entire* style attribute when one
	 * declaration looks hostile, so a single bad stored value would silently
	 * strip every other token. Filtering per value keeps the blast radius to
	 * the offending token.
	 *
	 * @param mixed $value Stored style value.
	 * @return string Safe CSS value, or '' when unusable.
	 */
	private static function sanitize_style_value( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( self::normalize_preset_value( trim( $value ) ) );
		if ( '' === $value ) {
			return '';
		}

		// Accepted shapes: hex colours, the rgb/rgba/hsl/hsla colour
		// functions, a custom-property reference with an optional fallback,
		// CSS lengths, and bare keywords such as `transparent`.
		$patterns = array(
			'/^#[0-9a-f]{3,8}$/i',
			'/^(rgb|rgba|hsl|hsla)\(\s*[0-9a-z%.,\/\s+-]+\)$/i',
			'/^var\(\s*--[a-z0-9-]+\s*(,\s*[#a-z0-9%.\s-]+)?\)$/i',
			'/^-?[0-9]*\.?[0-9]+(px|em|rem|%|vh|vw|ch)?$/i',
			'/^[a-z-]+$/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Build the inline custom-property declarations for a pillStyle attribute.
	 *
	 * @param mixed $pill_style Stored pillStyle attribute.
	 * @return string Declarations without a trailing semicolon; '' when unstyled.
	 */
	private static function pill_style_vars( $pill_style ) {
		if ( ! is_array( $pill_style ) || empty( $pill_style ) ) {
			return '';
		}

		$declarations = array();
		foreach ( self::PILL_STYLE_VARS as $token => $path ) {
			$value = $pill_style;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					$value = null;
					break;
				}
				$value = $value[ $key ];
			}

			$value = self::sanitize_style_value( $value );
			if ( '' !== $value ) {
				$declarations[] = $token . ':' . $value;
			}
		}

		return implode( ';', $declarations );
	}

	/**
	 * Render the Product Filters parent: router region, pills, Clear all.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content Rendered pill children.
	 * @param WP_Block            $block Block instance.
	 * @return string
	 */
	public function render_product_filters( $attributes, $content, $block ) {
		$runtime_id = sanitize_html_class( $attributes['runtimeId'] ?? '' );
		if ( '' === $runtime_id ) {
			$runtime_id = 'shift64-woo-search-filters';
		}

		$clear_all = '';
		if ( ! empty( $attributes['showClearAll'] ) ) {
			$clear_all = $this->render_clear_all( $attributes, $block );
		}

		$represented = array();
		foreach ( $this->represented_facets( $block ) as $facet ) {
			$entry = $this->ready_entry( $facet );
			if ( null !== $entry ) {
				$represented[] = $entry['taxonomy'];
			}
		}

		$context = array(
			'parentId'        => $runtime_id,
			'clearTaxonomies' => $represented,
		);

		// The backdrop stays hidden without JavaScript; the tray presentation
		// only activates once the store controls the hidden binding.
		$backdrop = '<div class="shift64-woo-search-product-filters__backdrop" hidden data-wp-bind--hidden="!state.hasOpenPill" data-wp-on--click="actions.closeOpenPill" aria-hidden="true"></div>';

		$wrapper_args = array(
			'class'                 => 'shift64-woo-search-product-filters',
			'data-wp-interactive'   => 'shift64-woo-search/product-filters',
			'data-wp-context'       => wp_json_encode( $context ),
			'data-wp-router-region' => $runtime_id . '-region',
		);

		$pill_style = self::pill_style_vars( $attributes['pillStyle'] ?? array() );
		if ( '' !== $pill_style ) {
			$wrapper_args['style'] = $pill_style;
		}

		$wrapper = get_block_wrapper_attributes( $wrapper_args );

		return '<div ' . $wrapper . '>' . $content . $clear_all . $backdrop . '</div>';
	}

	/**
	 * Option-list settings, owned by the parent and shared by every pill.
	 *
	 * Selection mode, counts, ordering, and the button labels are a property
	 * of the filter row rather than of one facet — merchants configure them
	 * once and every pill obeys, so they travel down as block context. Only
	 * facet identity, the pill's own label, and the AND/OR operator (which is
	 * meaningless for facets whose index field cannot do AND) stay per-pill.
	 *
	 * @param WP_Block|null $block Pill block instance.
	 * @return array<string,mixed> Resolved settings.
	 */
	private static function pill_settings( $block ) {
		$context = ( $block instanceof WP_Block && is_array( $block->context ) ) ? $block->context : array();

		$read = static function ( $key, $default_value ) use ( $context ) {
			$namespaced = 'shift64WooSearch/' . $key;
			return array_key_exists( $namespaced, $context ) ? $context[ $namespaced ] : $default_value;
		};

		$order_by = (string) $read( 'orderBy', 'count-desc' );
		if ( ! in_array( $order_by, array( 'count-desc', 'name-asc', 'name-desc' ), true ) ) {
			$order_by = 'count-desc';
		}

		$selection_mode = (string) $read( 'selectionMode', 'multiple' );

		return array(
			'selectionMode' => 'single' === $selection_mode ? 'single' : 'multiple',
			'showCounts'    => (bool) $read( 'showCounts', true ),
			'hideEmpty'     => (bool) $read( 'hideEmpty', true ),
			'orderBy'       => $order_by,
			'maxOptions'    => absint( $read( 'maxOptions', 0 ) ),
			'applyLabel'    => (string) $read( 'applyLabel', '' ),
			'clearLabel'    => (string) $read( 'clearLabel', '' ),
		);
	}

	/**
	 * Render one Filter Pill as a progressive disclosure + GET form.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content Unused child content.
	 * @param WP_Block            $block Block instance.
	 * @return string
	 */
	public function render_filter_pill( $attributes, $content, $block ) {
		unset( $content );

		$entry = $this->ready_entry( $attributes['facet'] ?? '' );
		if ( null === $entry ) {
			return '';
		}

		$settings = self::pill_settings( $block );
		$taxonomy = $entry['taxonomy'];
		$selected = $this->selected_slugs( $taxonomy );
		$options  = $this->pill_options( $entry, $settings, $selected );
		if ( empty( $options ) ) {
			return '';
		}

		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = $entry['label'];
		}

		$single      = 'single' === $settings['selectionMode'];
		$show_counts = $settings['showCounts'];
		$apply_label = trim( (string) $settings['applyLabel'] );
		$clear_label = trim( (string) $settings['clearLabel'] );
		$apply_label = '' !== $apply_label ? $apply_label : __( 'Apply', 'shift64-woo-search' );
		$clear_label = '' !== $clear_label ? $clear_label : __( 'Clear', 'shift64-woo-search' );

		$summary_count = count( $selected );

		// One truth for AND support: the interactivity context and the no-JS
		// hidden input must never disagree about the operator.
		$operator_and = ! $single
			&& 'and' === ( $attributes['queryType'] ?? 'or' )
			&& in_array( 'and', $entry['operators'], true );

		++self::$pill_sequence;
		$pill_context = array(
			'pillId'      => $taxonomy . '-' . self::$pill_sequence,
			'taxonomy'    => $taxonomy,
			'operatorAnd' => $operator_and,
		);

		$html  = '<details class="shift64-woo-search-pill__disclosure" data-wp-context="' . esc_attr( wp_json_encode( $pill_context ) ) . '" data-wp-bind--open="state.isPillOpen" data-wp-on--toggle="actions.pillToggled" data-wp-on--keydown="actions.panelKeydown">';
		$html .= '<summary class="shift64-woo-search-pill__trigger" aria-expanded="false" data-wp-bind--aria-expanded="state.pillExpanded">';
		$html .= '<span class="shift64-woo-search-pill__label">' . esc_html( $label ) . '</span>';
		if ( $summary_count > 0 ) {
			$html .= '<span class="shift64-woo-search-pill__summary-count"><span>' . esc_html( (string) $summary_count ) . '</span></span>';
		}
		$html .= '<span class="shift64-woo-search-pill__chevron" aria-hidden="true"></span>';
		$html .= '</summary>';

		$html .= '<div class="shift64-woo-search-pill__panel">';

		// On a narrow screen the panel becomes a tray whose backdrop covers the
		// pill, so tapping the trigger again can no longer close it. This is the
		// explicit dismissal; it stays hidden until the store unhides it, so the
		// no-JS disclosure — which closes from its own summary — never renders a
		// button that would do nothing.
		$html .= '<button type="button" class="shift64-woo-search-pill__close" hidden data-wp-bind--hidden="!state.enhanced" data-wp-on--click="actions.dismissTray" aria-label="' . esc_attr__( 'Close filter options', 'shift64-woo-search' ) . '"><span aria-hidden="true">&times;</span></button>';
		$html .= '<p class="shift64-woo-search-pill__heading">' . esc_html( $label ) . '</p>';
		$html .= '<form class="shift64-woo-search-pill__form" method="get" action="' . esc_url( $this->form_action() ) . '" data-wp-on--submit="actions.apply">';
		$html .= $this->hidden_state_inputs( $taxonomy );

		if ( $operator_and ) {
			$html .= '<input type="hidden" name="query_type_' . esc_attr( $taxonomy ) . '" value="and" />';
		}

		$input_type = $single ? 'radio' : 'checkbox';
		$input_name = $single ? 'filter_' . $taxonomy : 'filter_' . $taxonomy . '[]';

		$html .= '<ul class="shift64-woo-search-pill__options">';
		foreach ( $options as $option ) {
			$html .= '<li class="shift64-woo-search-pill__option"><label>';
			$html .= '<input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $option['slug'] ) . '"' . checked( $option['selected'], true, false ) . ' />';
			$html .= '<span class="shift64-woo-search-pill__option-label">' . esc_html( $option['name'] ) . '</span>';
			if ( $show_counts && null !== $option['count'] ) {
				$html .= '<span class="shift64-woo-search-pill__count">' . esc_html( (string) $option['count'] ) . '</span>';
			}
			$html .= '</label></li>';
		}
		$html .= '</ul>';

		$html .= '<div class="shift64-woo-search-pill__actions">';
		if ( $summary_count > 0 ) {
			$clear_url = Shift64_Woo_Search_Catalog_State::build_url(
				$this->current_url(),
				array(
					'filter_' . $taxonomy     => null,
					'query_type_' . $taxonomy => null,
				)
			);
			$html     .= '<a class="shift64-woo-search-pill__clear" href="' . esc_url( $clear_url ) . '" data-wp-on--click="actions.clear">' . esc_html( $clear_label ) . '</a>';
		}
		$html .= '<button type="submit" class="shift64-woo-search-pill__apply wp-element-button">' . esc_html( $apply_label ) . '</button>';
		$html .= '</div></form></div></details>';

		$wrapper = get_block_wrapper_attributes(
			array( 'class' => 'shift64-woo-search-pill' )
		);

		return '<div ' . $wrapper . '>' . $html . '</div>';
	}

	/**
	 * Clear the per-request selection cache (tests).
	 */
	public static function reset() {
		self::$selections    = null;
		self::$pill_sequence = 0;
	}

	/**
	 * Render the Clear all control when a represented facet is active.
	 *
	 * Clear All removes only the filter parameters represented by this
	 * instance's children — direct URL state for unrepresented facets stays.
	 *
	 * @param array<string,mixed> $attributes Parent attributes.
	 * @param WP_Block            $block Parent block instance.
	 * @return string
	 */
	private function render_clear_all( $attributes, $block ) {
		$changes = array();
		$active  = false;

		foreach ( $this->represented_facets( $block ) as $facet ) {
			$entry = $this->ready_entry( $facet );
			if ( null === $entry ) {
				continue;
			}
			$taxonomy                             = $entry['taxonomy'];
			$changes[ 'filter_' . $taxonomy ]     = null;
			$changes[ 'query_type_' . $taxonomy ] = null;
			if ( count( $this->selected_slugs( $taxonomy ) ) > 0 ) {
				$active = true;
			}
		}

		if ( ! $active ) {
			return '';
		}

		$label = trim( (string) ( $attributes['clearAllLabel'] ?? '' ) );
		if ( '' === $label ) {
			$label = __( 'Clear all', 'shift64-woo-search' );
		}

		$url = Shift64_Woo_Search_Catalog_State::build_url( $this->current_url(), $changes );

		return '<a class="shift64-woo-search-product-filters__clear-all" href="' . esc_url( $url ) . '" data-wp-on--click="actions.clearAll">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Facet keys saved on this parent's pill children.
	 *
	 * @param WP_Block $block Parent block instance.
	 * @return array<int,string>
	 */
	private function represented_facets( $block ) {
		$facets = array();
		$inner  = array();
		if ( isset( $block->parsed_block['innerBlocks'] ) && is_array( $block->parsed_block['innerBlocks'] ) ) {
			$inner = $block->parsed_block['innerBlocks'];
		}
		foreach ( $inner as $child ) {
			if ( 'shift64-woo-search/filter-pill' !== ( $child['blockName'] ?? '' ) ) {
				continue;
			}
			$facet = sanitize_key( $child['attrs']['facet'] ?? '' );
			if ( '' !== $facet ) {
				$facets[] = $facet;
			}
		}
		return array_values( array_unique( $facets ) );
	}

	/**
	 * The ready eligibility entry for a saved facet key, or null.
	 *
	 * @param string $facet Saved facet key.
	 * @return array|null
	 */
	private function ready_entry( $facet ) {
		$facet = sanitize_key( (string) $facet );
		if ( '' === $facet ) {
			return null;
		}
		$entry = Shift64_Woo_Search_Facet_Eligibility::get_entry( $facet );
		if ( null === $entry || Shift64_Woo_Search_Facet_Eligibility::STATUS_READY !== $entry['status'] ) {
			return null;
		}
		return $entry;
	}

	/**
	 * Validated selected term slugs for a taxonomy from the current request.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int,string>
	 */
	private function selected_slugs( $taxonomy ) {
		$selections = $this->current_selections();
		return isset( $selections[ $taxonomy ] ) ? $selections[ $taxonomy ] : array();
	}

	/**
	 * Parse and validate every ready facet's filter parameter once per request.
	 *
	 * @return array<string,array<int,string>> Taxonomy => selected term slugs.
	 */
	private function current_selections() {
		if ( null !== self::$selections ) {
			return self::$selections;
		}

		self::$selections = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public storefront state.
		$request = wp_unslash( $_GET );

		foreach ( Shift64_Woo_Search_Facet_Eligibility::get_ready() as $entry ) {
			$taxonomy = $entry['taxonomy'];
			$param    = 'filter_' . $taxonomy;
			// isset, not empty: "0" is a legitimate term slug.
			if ( ! isset( $request[ $param ] ) ) {
				continue;
			}
			$raw = $request[ $param ];
			if ( is_array( $raw ) ) {
				$raw = implode( ',', array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) );
			}
			if ( ! is_scalar( $raw ) || '' === (string) $raw ) {
				continue;
			}
			$slugs = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_title', explode( ',', (string) $raw ) ),
						'strlen' // Not bare array_filter: "0" is a legitimate slug.
					)
				)
			);
			$valid = array();
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term instanceof WP_Term ) {
					$valid[] = $term->slug;
				}
			}
			if ( ! empty( $valid ) ) {
				sort( $valid, SORT_STRING );
				self::$selections[ $taxonomy ] = $valid;
			}
		}

		return self::$selections;
	}

	/**
	 * Options for one pill: taxonomy terms ordered and bounded per attributes.
	 *
	 * Counts come from the request-scoped facet provider (disjunctive Redis
	 * buckets, keyed by indexed term name). When that dimension is degraded
	 * or unavailable, options render without counts and `hideEmpty` falls
	 * back to the taxonomy's own empty-term flag — filtering stays possible
	 * either way. Selected terms always stay visible.
	 *
	 * @param array               $entry Eligibility entry.
	 * @param array<string,mixed> $settings Parent-owned pill settings.
	 * @param array<int,string>   $selected Selected term slugs.
	 * @return array<int,array{slug:string,name:string,count:?int,selected:bool}>
	 */
	private function pill_options( $entry, $settings, $selected ) {
		$hide_empty = ! empty( $settings['hideEmpty'] );
		$order_by   = (string) ( $settings['orderBy'] ?? 'count-desc' );

		// Counts cost Redis aggregations; skip them entirely when nothing in
		// the shared configuration renders or sorts by them.
		$needs_counts = ! empty( $settings['showCounts'] ) || $hide_empty || 'count-desc' === $order_by;

		$buckets     = $needs_counts ? Shift64_Woo_Search_Facet_Count_Provider::get_buckets( $entry['redis_field'] ) : null;
		$have_counts = is_array( $buckets );
		$counts      = array();
		if ( $have_counts ) {
			foreach ( $buckets as $bucket ) {
				if ( isset( $bucket['value'] ) ) {
					$counts[ (string) $bucket['value'] ] = (int) ( $bucket['count'] ?? 0 );
				}
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'               => $entry['taxonomy'],
				// With live counts the result set decides emptiness; without
				// them the taxonomy's own assignment count is the fallback.
				'hide_empty'             => $have_counts ? false : $hide_empty,
				'update_term_meta_cache' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$options = array();
		$seen    = array();
		foreach ( $terms as $term ) {
			$count       = $have_counts ? ( $counts[ $term->name ] ?? 0 ) : null;
			$is_selected = in_array( $term->slug, $selected, true );
			if ( $hide_empty && $have_counts && 0 === $count && ! $is_selected ) {
				continue;
			}
			$options[]           = array(
				'slug'     => $term->slug,
				'name'     => $term->name,
				'count'    => $count,
				'selected' => $is_selected,
			);
			$seen[ $term->slug ] = true;
		}

		// A selected term hidden by hide_empty stays visible and removable.
		foreach ( $selected as $slug ) {
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$term = get_term_by( 'slug', $slug, $entry['taxonomy'] );
			if ( $term instanceof WP_Term ) {
				$options[] = array(
					'slug'     => $term->slug,
					'name'     => $term->name,
					'count'    => $have_counts ? ( $counts[ $term->name ] ?? 0 ) : null,
					'selected' => true,
				);
			}
		}

		usort(
			$options,
			static function ( $a, $b ) use ( $order_by ) {
				if ( 'name-desc' === $order_by ) {
					return strcasecmp( $b['name'], $a['name'] );
				}
				if ( 'count-desc' === $order_by && $a['count'] !== $b['count'] ) {
					return (int) $b['count'] <=> (int) $a['count'];
				}
				// name-asc, and the deterministic tie-break for count-desc.
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$max = absint( $settings['maxOptions'] ?? 0 );
		if ( $max > 0 ) {
			$max = min( 100, $max );
			// The bound must never hide an active selection: an option cut
			// from the panel would be silently dropped by the next Apply
			// (the checkboxes are the draft state).
			$kept    = array_slice( $options, 0, $max );
			$dropped = array_slice( $options, $max );
			foreach ( $dropped as $option ) {
				if ( $option['selected'] ) {
					$kept[] = $option;
				}
			}
			$options = $kept;
		}

		return $options;
	}

	/**
	 * The current request URL, for canonical URL building.
	 *
	 * @return string
	 */
	private function current_url() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Re-encoded by Catalog_State::build_url/esc_url before output.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		// REQUEST_URI already contains any subdirectory the site lives in, so
		// join it to the bare origin — home_url( $request_uri ) would double
		// the path on subdirectory installs.
		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? 'localhost' )
			. ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return $origin . $request_uri;
	}

	/**
	 * The form target: the current path without query or a /page/N segment,
	 * so submitting always resets pagination.
	 *
	 * @return string
	 */
	private function form_action() {
		$url  = $this->current_url();
		$path = strtok( $url, '?' );
		return preg_replace( '#/page/\d+/?$#', '/', $path );
	}

	/**
	 * Hidden inputs preserving canonical state the form would otherwise drop:
	 * search, sorting, and other ready facets' validated selections. Paging is
	 * deliberately absent — applying a filter resets pagination.
	 *
	 * @param string $own_taxonomy The pill's own taxonomy (its inputs own it).
	 * @return string
	 */
	private function hidden_state_inputs( $own_taxonomy ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public storefront state.
		$request = wp_unslash( $_GET );
		$html    = '';

		// Preserve every unrelated safe scalar parameter (language switchers,
		// WooCommerce price/rating widgets, plain-permalink archive scope…) —
		// a GET form replaces the whole query string, and the JS path's
		// buildCatalogUrl preserves these, so the no-JS submit must too.
		// Facet parameters are re-emitted from validated state below; paging
		// and private parameters are deliberately dropped.
		$skip_exact  = array( 's', 'post_type', 'paged', 'query-page', '_wpnonce', '_wp_http_referer', 'preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid' );
		$skip_prefix = array( 'filter_', 'query_type_' );
		foreach ( $request as $key => $value ) {
			$key = (string) $key;
			if ( ! is_scalar( $value ) || in_array( $key, $skip_exact, true ) || preg_match( '/^query-\d+-page$/', $key ) ) {
				continue;
			}
			foreach ( $skip_prefix as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					continue 2;
				}
			}
			if ( '' === preg_replace( '/[^A-Za-z0-9_\-\[\]]/', '', $key ) || $key !== preg_replace( '/[^A-Za-z0-9_\-\[\]]/', '', $key ) ) {
				continue;
			}
			$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( sanitize_text_field( (string) $value ) ) . '" />';
		}

		if ( isset( $request['s'] ) && is_scalar( $request['s'] ) && '' !== (string) $request['s'] ) {
			$html .= '<input type="hidden" name="s" value="' . esc_attr( sanitize_text_field( (string) $request['s'] ) ) . '" />';
			$html .= '<input type="hidden" name="post_type" value="product" />';
		}

		foreach ( $this->current_selections() as $taxonomy => $slugs ) {
			if ( $taxonomy === $own_taxonomy ) {
				continue;
			}
			$html .= '<input type="hidden" name="filter_' . esc_attr( $taxonomy ) . '" value="' . esc_attr( implode( ',', $slugs ) ) . '" />';

			$operator_key = 'query_type_' . $taxonomy;
			if ( isset( $request[ $operator_key ] ) && is_scalar( $request[ $operator_key ] ) ) {
				$operator = sanitize_key( (string) $request[ $operator_key ] );
				if ( in_array( $operator, array( 'and', 'or' ), true ) ) {
					$html .= '<input type="hidden" name="' . esc_attr( $operator_key ) . '" value="' . esc_attr( $operator ) . '" />';
				}
			}
		}

		return $html;
	}
}
