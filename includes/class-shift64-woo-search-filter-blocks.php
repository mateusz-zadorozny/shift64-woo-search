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

		$wrapper = get_block_wrapper_attributes(
			array(
				'class'                 => 'shift64-woo-search-product-filters',
				'id'                    => $runtime_id,
				'data-wp-interactive'   => 'shift64-woo-search/product-filters',
				'data-wp-router-region' => $runtime_id . '-region',
			)
		);

		return '<div ' . $wrapper . '>' . $content . $clear_all . '</div>';
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
		unset( $content, $block );

		$entry = $this->ready_entry( $attributes['facet'] ?? '' );
		if ( null === $entry ) {
			return '';
		}

		$taxonomy = $entry['taxonomy'];
		$selected = $this->selected_slugs( $taxonomy );
		$options  = $this->pill_options( $entry, $attributes, $selected );
		if ( empty( $options ) ) {
			return '';
		}

		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = $entry['label'];
		}

		$single      = 'single' === ( $attributes['selectionMode'] ?? 'multiple' );
		$show_counts = ! empty( $attributes['showCounts'] );
		$apply_label = trim( (string) ( $attributes['applyLabel'] ?? '' ) );
		$clear_label = trim( (string) ( $attributes['clearLabel'] ?? '' ) );
		$apply_label = '' !== $apply_label ? $apply_label : __( 'Apply', 'shift64-woo-search' );
		$clear_label = '' !== $clear_label ? $clear_label : __( 'Clear', 'shift64-woo-search' );

		$summary_count = count( $selected );

		$html  = '<details class="shift64-woo-search-pill__disclosure">';
		$html .= '<summary class="shift64-woo-search-pill__trigger">';
		$html .= '<span class="shift64-woo-search-pill__label">' . esc_html( $label ) . '</span>';
		if ( $summary_count > 0 ) {
			$html .= '<span class="shift64-woo-search-pill__summary-count"><span>' . esc_html( (string) $summary_count ) . '</span></span>';
		}
		$html .= '<span class="shift64-woo-search-pill__chevron" aria-hidden="true"></span>';
		$html .= '</summary>';

		$html .= '<div class="shift64-woo-search-pill__panel">';
		$html .= '<p class="shift64-woo-search-pill__heading">' . esc_html( $label ) . '</p>';
		$html .= '<form class="shift64-woo-search-pill__form" method="get" action="' . esc_url( $this->form_action() ) . '">';
		$html .= $this->hidden_state_inputs( $taxonomy );

		if ( ! $single && 'and' === ( $attributes['queryType'] ?? 'or' ) && in_array( 'and', $entry['operators'], true ) ) {
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
			$html     .= '<a class="shift64-woo-search-pill__clear" href="' . esc_url( $clear_url ) . '">' . esc_html( $clear_label ) . '</a>';
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
		self::$selections = null;
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

		return '<a class="shift64-woo-search-product-filters__clear-all" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
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
			if ( empty( $request[ $param ] ) ) {
				continue;
			}
			$raw = $request[ $param ];
			if ( is_array( $raw ) ) {
				$raw = implode( ',', array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) );
			}
			if ( ! is_scalar( $raw ) || '' === (string) $raw ) {
				continue;
			}
			$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', explode( ',', (string) $raw ) ) ) ) );
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
	 * Counts arrive with the Phase 2 facet provider; until then options render
	 * without counts (the degraded contract) and `hideEmpty` falls back to the
	 * taxonomy's own empty-term flag. Selected terms always stay visible.
	 *
	 * @param array               $entry Eligibility entry.
	 * @param array<string,mixed> $attributes Pill attributes.
	 * @param array<int,string>   $selected Selected term slugs.
	 * @return array<int,array{slug:string,name:string,count:?int,selected:bool}>
	 */
	private function pill_options( $entry, $attributes, $selected ) {
		$hide_empty = ! empty( $attributes['hideEmpty'] );
		$terms      = get_terms(
			array(
				'taxonomy'   => $entry['taxonomy'],
				'hide_empty' => $hide_empty,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$options = array();
		$seen    = array();
		foreach ( $terms as $term ) {
			$options[]           = array(
				'slug'     => $term->slug,
				'name'     => $term->name,
				'count'    => null,
				'selected' => in_array( $term->slug, $selected, true ),
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
					'count'    => null,
					'selected' => true,
				);
			}
		}

		$order_by = (string) ( $attributes['orderBy'] ?? 'count-desc' );
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

		$max = absint( $attributes['maxOptions'] ?? 0 );
		if ( $max > 0 ) {
			$options = array_slice( $options, 0, min( 100, $max ) );
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
		return home_url( $request_uri );
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

		if ( isset( $request['s'] ) && is_scalar( $request['s'] ) && '' !== (string) $request['s'] ) {
			$html .= '<input type="hidden" name="s" value="' . esc_attr( sanitize_text_field( (string) $request['s'] ) ) . '" />';
			$html .= '<input type="hidden" name="post_type" value="product" />';
		}

		if ( isset( $request['orderby'] ) && is_scalar( $request['orderby'] ) ) {
			$orderby = sanitize_key( (string) $request['orderby'] );
			if ( '' !== $orderby ) {
				$html .= '<input type="hidden" name="orderby" value="' . esc_attr( $orderby ) . '" />';
			}
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
