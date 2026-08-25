<?php
/**
 * Dynamic search blocks backed by the existing shortcode renderers.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the search form and modal search blocks.
 */
class Shift64_Woo_Search_Blocks {

	/**
	 * Shared frontend renderer.
	 *
	 * @var Shift64_Woo_Search_Frontend
	 */
	private $frontend;

	/**
	 * Runtime IDs emitted during the current render request.
	 *
	 * @var array<string,int>
	 */
	private $runtime_ids = array();

	/**
	 * Product Filters / Filter Pill renderer.
	 *
	 * @var Shift64_Woo_Search_Filter_Blocks
	 */
	private $filter_blocks;

	/**
	 * Set up block registration.
	 *
	 * @param Shift64_Woo_Search_Frontend $frontend Shared shortcode renderer and asset loader.
	 */
	public function __construct( Shift64_Woo_Search_Frontend $frontend ) {
		$this->frontend      = $frontend;
		$this->filter_blocks = new Shift64_Woo_Search_Filter_Blocks();
		add_filter( 'render_block_data', array( $this, 'add_runtime_id' ), 10, 2 );
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register dynamic search blocks.
	 */
	public function register_blocks() {
		wp_register_style(
			'shift64-woo-search',
			SHIFT64_WOO_SEARCH_URL . 'frontend/css/shift64-woo-search.css',
			array(),
			Shift64_Woo_Search_Frontend::asset_version( 'frontend/css/shift64-woo-search.css' )
		);

		$blocks = array(
			'search'          => array( $this, 'render_search_parent' ),
			'modal-search'    => array( $this, 'render_modal_search_parent' ),
			'search-control'  => array( $this, 'render_search_control' ),
			'search-panel'    => array( $this, 'render_search_panel' ),
			'product-filters' => array( $this->filter_blocks, 'render_product_filters' ),
			'filter-pill'     => array( $this->filter_blocks, 'render_filter_pill' ),
			'product-sort'    => array( $this, 'render_product_sort_block' ),
		);

		foreach ( $blocks as $directory => $callback ) {
			$block = register_block_type_from_metadata(
				SHIFT64_WOO_SEARCH_PATH . 'build/blocks/' . $directory,
				array( 'render_callback' => $callback )
			);
			if ( ! $block instanceof WP_Block_Type ) {
				continue;
			}

			$editor_handles = isset( $block->editor_script_handles ) ? $block->editor_script_handles : array();
			foreach ( $editor_handles as $handle ) {
				wp_set_script_translations( $handle, 'shift64-woo-search', SHIFT64_WOO_SEARCH_PATH . 'languages' );
			}
			$this->mark_view_modules_client_navigation_compatible( $block );
		}
	}

	/**
	 * Give every rendered parent a request-unique DOM ID without mutating saved content.
	 *
	 * @param array<string,mixed> $parsed_block Parsed block data.
	 * @param array<string,mixed> $source_block Original block data.
	 * @return array<string,mixed>
	 */
	public function add_runtime_id( $parsed_block, $source_block ) {
		unset( $source_block );
		return $this->prepare_runtime_ids( $parsed_block );
	}

	/**
	 * Assign runtime IDs throughout one parsed block tree before it is rendered.
	 *
	 * WordPress applies render_block_data() to blocks passed through
	 * render_block(), but recursively rendered inner blocks are instantiated
	 * directly by WP_Block. Preparing descendants here keeps parent-provided
	 * context intact when a search block is nested in a Group, Column, or
	 * Template Part.
	 *
	 * @param array<string,mixed> $parsed_block Parsed block data.
	 * @return array<string,mixed>
	 */
	private function prepare_runtime_ids( $parsed_block ) {
		$parent_blocks = array(
			'shift64-woo-search/search',
			'shift64-woo-search/modal-search',
			'shift64-woo-search/product-filters',
		);

		if (
			in_array( $parsed_block['blockName'] ?? '', $parent_blocks, true ) &&
			empty( $parsed_block['_shift64WooSearchRuntimePrepared'] )
		) {
			$attributes = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
			$base       = isset( $attributes['instanceId'] ) ? sanitize_html_class( $attributes['instanceId'] ) : '';
			if ( '' === $base ) {
				$base = 'shift64-woo-search/product-filters' === $parsed_block['blockName'] ? 'shift64-woo-search-filters' : 'shift64-woo-search-instance';
			}
			$count                      = ( $this->runtime_ids[ $base ] ?? 0 ) + 1;
			$this->runtime_ids[ $base ] = $count;
			$attributes['runtimeId']    = 1 === $count ? $base : $base . '-' . $count;
			$parsed_block['attrs']      = $attributes;
			$parsed_block['_shift64WooSearchRuntimePrepared'] = true;
		}

		if ( isset( $parsed_block['innerBlocks'] ) && is_array( $parsed_block['innerBlocks'] ) ) {
			foreach ( $parsed_block['innerBlocks'] as $index => $inner_block ) {
				if ( is_array( $inner_block ) ) {
					$parsed_block['innerBlocks'][ $index ] = $this->prepare_runtime_ids( $inner_block );
				}
			}
		}

		return $parsed_block;
	}

	/**
	 * Mark generated view modules as safe across Product Collection navigation.
	 *
	 * @param WP_Block_Type $block Registered block type.
	 */
	private function mark_view_modules_client_navigation_compatible( WP_Block_Type $block ) {
		if ( ! function_exists( 'wp_interactivity' ) || empty( $block->view_script_module_ids ) ) {
			return;
		}
		$interactivity = wp_interactivity();
		if ( ! is_object( $interactivity ) || ! method_exists( $interactivity, 'add_client_navigation_support_to_script_module' ) ) {
			return;
		}
		foreach ( $block->view_script_module_ids as $module_id ) {
			$interactivity->add_client_navigation_support_to_script_module( $module_id );
		}
	}

	/**
	 * Render the inline parent or its untouched legacy fallback.
	 *
	 * @param array<string,mixed> $attributes Parent attributes.
	 * @param string              $content Rendered children.
	 * @param WP_Block            $block Block instance.
	 * @return string
	 */
	public function render_search_parent( $attributes, $content, $block ) {
		if ( '' === trim( $content ) ) {
			return $this->render_search_block( $attributes );
		}
		return $this->render_parent( $attributes, $content, $block, 'inline' );
	}

	/**
	 * Render the modal parent or its untouched legacy fallback.
	 *
	 * @param array<string,mixed> $attributes Parent attributes.
	 * @param string              $content Rendered children.
	 * @param WP_Block            $block Block instance.
	 * @return string
	 */
	public function render_modal_search_parent( $attributes, $content, $block ) {
		if ( '' === trim( $content ) ) {
			return $this->render_modal_search_block( $attributes );
		}
		return $this->render_parent( $attributes, $content, $block, 'modal' );
	}

	/**
	 * Render one scoped Interactivity API root around saved child content.
	 *
	 * @param array<string,mixed> $attributes Parent attributes.
	 * @param string              $content Rendered children.
	 * @param WP_Block            $block Block instance.
	 * @param string              $variant Inline or modal.
	 * @return string
	 */
	private function render_parent( $attributes, $content, $block, $variant ) {
		$context            = $this->get_interactivity_context( $attributes, $block, $variant );
		$context_attributes = function_exists( 'wp_interactivity_data_wp_context' )
			? wp_interactivity_data_wp_context( $context, 'shift64-woo-search/search' )
			: 'data-wp-context="' . esc_attr( wp_json_encode( $context ) ) . '"';
		$class_name         = 'shift64-woo-search-block shift64-woo-search-block--' . ( 'modal' === $variant ? 'modal' : 'form' );
		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class'                    => $class_name,
				'data-shift64-search-root' => '',
				'data-wp-interactive'      => 'shift64-woo-search/search',
				'data-wp-init'             => 'callbacks.init',
			)
		);

		return '<div ' . $wrapper_attributes . ' ' . $context_attributes . '>' . $content . '</div>';
	}

	/**
	 * Build per-instance configuration and reactive state.
	 *
	 * @param array<string,mixed> $attributes Parent attributes.
	 * @param WP_Block            $block Block instance.
	 * @param string              $variant Inline or modal.
	 * @return array<string,mixed>
	 */
	private function get_interactivity_context( $attributes, $block, $variant ) {
		$panel_attributes = $this->get_child_attributes( $block, 'shift64-woo-search/search-panel' );
		$runtime_id       = isset( $attributes['runtimeId'] ) ? sanitize_html_class( $attributes['runtimeId'] ) : '';
		if ( '' === $runtime_id ) {
			$runtime_id = wp_unique_id( 'shift64-woo-search-' );
		}

		return array(
			'instanceId'            => $runtime_id,
			'variant'               => $variant,
			'isOpen'                => false,
			'panelOpen'             => false,
			'query'                 => get_search_query( false ),
			'requestStatus'         => 'idle',
			'activeIndex'           => -1,
			'activeDescendant'      => '',
			'suggestions'           => array(),
			'statusMessage'         => '',
			'endpoint'              => content_url( '/mu-plugins/shift64-woo-search/endpoint.php' ),
			'searchUrl'             => home_url( '/' ),
			'fallbackUrl'           => home_url( '/?s={query}&post_type=product' ),
			'minQueryLength'        => max( 1, (int) get_option( 'shift64_woo_search_min_query', 2 ) ),
			'debounce'              => max( 0, (int) get_option( 'shift64_woo_search_debounce', 150 ) ),
			// The panel block can narrow the global settings per instance: its
			// counts replace the option, its show toggles AND with it (the
			// global switch stays the site-wide kill switch).
			'limit'                 => is_numeric( $panel_attributes['productsCount'] ?? null )
				? max( 1, (int) $panel_attributes['productsCount'] )
				: max( 1, (int) get_option( 'shift64_woo_search_autocomplete_limit', 7 ) ),
			'categoriesCount'       => is_numeric( $panel_attributes['categoriesCount'] ?? null ) ? max( 0, (int) $panel_attributes['categoriesCount'] ) : -1,
			'suggestionsCount'      => is_numeric( $panel_attributes['suggestionsCount'] ?? null ) ? max( 0, (int) $panel_attributes['suggestionsCount'] ) : -1,
			'showSku'               => ( 'yes' === get_option( 'shift64_woo_search_show_sku', 'yes' ) ) && false !== ( $panel_attributes['showSku'] ?? true ),
			'showCategory'          => ( 'yes' === get_option( 'shift64_woo_search_show_category', 'yes' ) ) && false !== ( $panel_attributes['showCategory'] ?? true ),
			'showBrand'             => ( 'yes' === get_option( 'shift64_woo_search_show_brand', 'yes' ) ) && false !== ( $panel_attributes['showBrand'] ?? true ),
			'suggestionsHeaderText' => __( 'SEARCH SUGGESTIONS', 'shift64-woo-search' ),
			'categoriesHeaderText'  => __( 'CATEGORIES', 'shift64-woo-search' ),
			'brandsHeaderText'      => __( 'BRANDS', 'shift64-woo-search' ),
			'productsHeaderText'    => __( 'PRODUCTS', 'shift64-woo-search' ),
			'noResultsLabel'        => isset( $panel_attributes['noResultsLabel'] ) ? sanitize_text_field( $panel_attributes['noResultsLabel'] ) : __( 'No products found', 'shift64-woo-search' ),
			'loadingLabel'          => __( 'Searching…', 'shift64-woo-search' ),
			'errorLabel'            => __( 'Something went wrong. Please try again.', 'shift64-woo-search' ),
			'timeoutLabel'          => __( 'Quick search timed out. Use the search button.', 'shift64-woo-search' ),
			'unavailableLabel'      => __( 'Quick search is unavailable. Use the search button.', 'shift64-woo-search' ),
			// Translators: %d is the number of search results available.
			'resultsLabel'          => __( '%d results available', 'shift64-woo-search' ),
			'seeAllLabel'           => __( 'See all results', 'shift64-woo-search' ),
		);
	}

	/**
	 * Read raw saved child attributes from a parent block.
	 *
	 * @param WP_Block $block Parent block.
	 * @param string   $name Child block name.
	 * @return array<string,mixed>
	 */
	private function get_child_attributes( $block, $name ) {
		$inner_blocks = isset( $block->parsed_block['innerBlocks'] ) && is_array( $block->parsed_block['innerBlocks'] )
			? $block->parsed_block['innerBlocks']
			: array();
		foreach ( $inner_blocks as $inner_block ) {
			if ( $name === ( $inner_block['blockName'] ?? '' ) ) {
				return isset( $inner_block['attrs'] ) && is_array( $inner_block['attrs'] ) ? $inner_block['attrs'] : array();
			}
		}
		return array();
	}

	/**
	 * Render the inline form or modal trigger child.
	 *
	 * @param array<string,mixed> $attributes Child attributes.
	 * @param string              $content Unused dynamic content.
	 * @param WP_Block            $block Block instance.
	 * @return string
	 */
	public function render_search_control( $attributes, $content, $block ) {
		unset( $content );
		$variant    = $block->context['shift64WooSearch/variant'] ?? 'inline';
		$runtime_id = $this->runtime_id_from_context( $block );
		$label      = isset( $attributes['label'] ) ? $attributes['label'] : __( 'Search products', 'shift64-woo-search' );

		$trigger_vars = array(
			'--s64ws-trigger-icon-color'          => $attributes['triggerIconColor'] ?? '',
			'--s64ws-trigger-icon-hover-color'    => $attributes['triggerIconHoverColor'] ?? '',
			'--s64ws-trigger-surface-color'       => $attributes['triggerBackgroundColor'] ?? '',
			'--s64ws-trigger-surface-hover-color' => $attributes['triggerBackgroundHoverColor'] ?? '',
		);
		$style        = '';
		foreach ( $trigger_vars as $var => $value ) {
			if ( '' !== $value && is_string( $value ) ) {
				$style .= $var . ':' . $value . ';';
			}
		}
		$classes = 'shift64-woo-search-control is-' . $variant;
		if ( 'modal' !== $variant ) {
			// Inline field: gated button colors plus optional corner radii and
			// the joined input+button mode.
			$style        = '';
			$inline_pairs = array(
				'--s64ws-inline-button-color'            => array( $attributes['buttonTextColor'] ?? '', 'has-inline-button-color' ),
				'--s64ws-inline-button-hover-color'      => array( $attributes['buttonTextHoverColor'] ?? '', 'has-inline-button-hover-color' ),
				'--s64ws-inline-button-background'       => array( $attributes['buttonBackgroundColor'] ?? '', 'has-inline-button-background' ),
				'--s64ws-inline-button-hover-background' => array( $attributes['buttonBackgroundHoverColor'] ?? '', 'has-inline-button-hover-background' ),
				'--s64ws-inline-input-color'             => array( $attributes['inputTextColor'] ?? '', 'has-inline-input-color' ),
				'--s64ws-inline-input-background'        => array( $attributes['inputBackgroundColor'] ?? '', 'has-inline-input-background' ),
			);
			foreach ( $inline_pairs as $var => $pair ) {
				if ( '' !== $pair[0] && is_string( $pair[0] ) ) {
					$style   .= $var . ':' . $pair[0] . ';';
					$classes .= ' ' . $pair[1];
				}
			}
			if ( isset( $attributes['inputRadius'] ) && is_numeric( $attributes['inputRadius'] ) ) {
				$style   .= '--s64ws-inline-input-radius:' . (int) $attributes['inputRadius'] . 'px;';
				$classes .= ' has-inline-input-radius';
			}
			if ( isset( $attributes['buttonRadius'] ) && is_numeric( $attributes['buttonRadius'] ) ) {
				$style   .= '--s64ws-inline-button-radius:' . (int) $attributes['buttonRadius'] . 'px;';
				$classes .= ' has-inline-button-radius';
			}
			if ( isset( $attributes['inputPaddingY'] ) || isset( $attributes['inputPaddingX'] ) ) {
				$pad_y = is_numeric( $attributes['inputPaddingY'] ?? null ) ? (int) $attributes['inputPaddingY'] : null;
				$pad_x = is_numeric( $attributes['inputPaddingX'] ?? null ) ? (int) $attributes['inputPaddingX'] : null;
				if ( null !== $pad_y || null !== $pad_x ) {
					$style   .= '--s64ws-inline-input-pad-y:' . ( $pad_y ?? 13 ) . 'px;--s64ws-inline-input-pad-x:' . ( $pad_x ?? 18 ) . 'px;';
					$classes .= ' has-inline-input-padding';
				}
			}
			if ( isset( $attributes['buttonPaddingY'] ) || isset( $attributes['buttonPaddingX'] ) ) {
				$pad_y = is_numeric( $attributes['buttonPaddingY'] ?? null ) ? (int) $attributes['buttonPaddingY'] : null;
				$pad_x = is_numeric( $attributes['buttonPaddingX'] ?? null ) ? (int) $attributes['buttonPaddingX'] : null;
				if ( null !== $pad_y || null !== $pad_x ) {
					$style   .= '--s64ws-inline-button-pad-y:' . ( $pad_y ?? 13 ) . 'px;--s64ws-inline-button-pad-x:' . ( $pad_x ?? 24 ) . 'px;';
					$classes .= ' has-inline-button-padding';
				}
			}
			if ( is_numeric( $attributes['fieldGap'] ?? null ) && empty( $attributes['joined'] ) ) {
				$style   .= '--s64ws-inline-gap:' . (int) $attributes['fieldGap'] . 'px;';
				$classes .= ' has-inline-gap';
			}
			if ( ! empty( $attributes['joined'] ) ) {
				$classes .= ' is-joined';
			}
		}
		$extra = array( 'class' => $classes );
		if ( '' !== $style ) {
			$extra['style'] = $style;
		}
		$wrapper = get_block_wrapper_attributes( $extra );

		if ( 'modal' === $variant ) {
			$trigger_label = isset( $attributes['triggerLabel'] ) ? $attributes['triggerLabel'] : __( 'Open product search', 'shift64-woo-search' );
			$icon          = 'none' === ( $attributes['triggerIcon'] ?? 'search' ) ? '' : $this->render_search_icon();
			// With an icon the label is assistive-only; without one it is the visible button text.
			$label_class   = '' === $icon ? 'shift64-woo-search-modal__trigger-label' : 'shift64-woo-search-modal__trigger-label screen-reader-text';
			$trigger_class = '' === $icon ? 'shift64-woo-search-modal__trigger shift64-woo-search-modal__trigger--text' : 'shift64-woo-search-modal__trigger';
			return '<div ' . $wrapper . '><button type="button" class="' . esc_attr( $trigger_class ) . '" aria-haspopup="dialog" aria-controls="' . esc_attr( $runtime_id . '-dialog' ) . '" aria-expanded="false" data-wp-bind--aria-expanded="state.isDialogOpen" data-wp-on--click="actions.open" data-shift64-woo-search-modal-trigger>' . $icon . '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $trigger_label ) . '</span></button></div>';
		}

		$placeholder  = isset( $attributes['placeholder'] ) ? $attributes['placeholder'] : __( 'Search products...', 'shift64-woo-search' );
		$submit_label = isset( $attributes['submitLabel'] ) ? $attributes['submitLabel'] : __( 'Search', 'shift64-woo-search' );

		return '<div ' . $wrapper . '><form role="search" method="get" class="shift64-woo-search-field" action="' . esc_url( home_url( '/' ) ) . '" data-wp-on--submit="actions.submit"><label class="screen-reader-text" for="' . esc_attr( $runtime_id . '-input' ) . '">' . esc_html( $label ) . '</label><input type="search" id="' . esc_attr( $runtime_id . '-input' ) . '" class="shift64-woo-search-field__input" name="s" value="' . esc_attr( get_search_query( false ) ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" enterkeyhint="search" role="combobox" aria-autocomplete="list" aria-controls="' . esc_attr( $runtime_id . '-listbox' ) . '" aria-expanded="false" required data-wp-bind--value="context.query" data-wp-bind--aria-expanded="state.isExpanded" data-wp-bind--aria-busy="state.isBusy" data-wp-bind--aria-activedescendant="state.activeDescendant" data-wp-on--input="actions.onInput" data-wp-on--focus="actions.onFocus" data-wp-on--keydown="actions.onKeydown"><input type="hidden" name="post_type" value="product"><button type="submit" class="shift64-woo-search-field__submit wp-element-button">' . esc_html( $submit_label ) . '</button></form></div>';
	}

	/**
	 * Render the suggestion tray or native dialog child.
	 *
	 * @param array<string,mixed> $attributes Child attributes.
	 * @param string              $content Unused dynamic content.
	 * @param WP_Block            $block Block instance.
	 * @return string
	 */
	public function render_search_panel( $attributes, $content, $block ) {
		unset( $content );
		$variant    = $block->context['shift64WooSearch/variant'] ?? 'inline';
		$runtime_id = $this->runtime_id_from_context( $block );
		$listbox    = $this->render_results_shell( $runtime_id );
		$status     = '<p class="screen-reader-text shift64-woo-search-status" role="status" aria-live="polite" aria-atomic="true" data-wp-text="context.statusMessage"></p>';

		// "All results" footer link color — the legacy stylesheet already reads
		// this var, a wrapper-level override cascades into the results shell.
		$see_all_style = '';
		if ( ! empty( $attributes['allResultsColor'] ) && is_string( $attributes['allResultsColor'] ) ) {
			$see_all_style = '--s64ws-see-all-color:' . $attributes['allResultsColor'] . ';';
		}
		$max_width_class = '';
		if ( is_numeric( $attributes['maxWidth'] ?? null ) && (int) $attributes['maxWidth'] > 0 ) {
			$see_all_style  .= '--s64ws-panel-max-width:' . (int) $attributes['maxWidth'] . 'px;';
			$max_width_class = ' has-panel-max-width';
		}

		if ( 'modal' !== $variant ) {
			$inline_extra = array( 'class' => 'shift64-woo-search-panel is-inline' . $max_width_class );
			if ( '' !== $see_all_style ) {
				$inline_extra['style'] = $see_all_style;
			}
			$wrapper = get_block_wrapper_attributes( $inline_extra );
			return '<div ' . $wrapper . '><button type="button" class="shift64-woo-search-results__overlay" aria-label="' . esc_attr__( 'Close suggestions', 'shift64-woo-search' ) . '" hidden data-wp-bind--hidden="!state.isExpanded" data-wp-on--click="actions.closePanel"></button>' . $listbox . $status . '</div>';
		}

		$dialog_label = isset( $attributes['dialogLabel'] ) ? $attributes['dialogLabel'] : __( 'Search products', 'shift64-woo-search' );
		$close_label  = isset( $attributes['closeLabel'] ) ? $attributes['closeLabel'] : __( 'Close search', 'shift64-woo-search' );
		$clear_label  = isset( $attributes['clearLabel'] ) ? $attributes['clearLabel'] : __( 'Clear search', 'shift64-woo-search' );
		$input_label  = isset( $attributes['inputLabel'] ) ? $attributes['inputLabel'] : __( 'Search products', 'shift64-woo-search' );
		$placeholder  = isset( $attributes['placeholder'] ) ? $attributes['placeholder'] : __( 'Search products...', 'shift64-woo-search' );
		$submit_label = isset( $attributes['submitLabel'] ) ? $attributes['submitLabel'] : __( 'Search', 'shift64-woo-search' );
		// Button colors are gated behind has-* classes so an unset color keeps
		// the theme's wp-element-button styling untouched.
		$button_vars    = array(
			'--s64ws-panel-button-color'            => array( $attributes['buttonTextColor'] ?? '', 'has-panel-button-color' ),
			'--s64ws-panel-button-hover-color'      => array( $attributes['buttonTextHoverColor'] ?? '', 'has-panel-button-hover-color' ),
			'--s64ws-panel-button-background'       => array( $attributes['buttonBackgroundColor'] ?? '', 'has-panel-button-background' ),
			'--s64ws-panel-button-hover-background' => array( $attributes['buttonBackgroundHoverColor'] ?? '', 'has-panel-button-hover-background' ),
		);
		$button_style   = '';
		$button_classes = '';
		foreach ( $button_vars as $var => $pair ) {
			if ( '' !== $pair[0] && is_string( $pair[0] ) ) {
				$button_style   .= $var . ':' . $pair[0] . ';';
				$button_classes .= ' ' . $pair[1];
			}
		}
		$wrapper_extra = array(
			'id'    => $runtime_id . '-dialog',
			'class' => 'shift64-woo-search-dialog' . $button_classes . $max_width_class,
		);
		if ( '' !== $button_style . $see_all_style ) {
			$wrapper_extra['style'] = $button_style . $see_all_style;
		}
		$wrapper = get_block_wrapper_attributes(
			array_merge(
				$wrapper_extra,
				array(
					'aria-label'                    => $dialog_label,
					'aria-modal'                    => 'true',
					'data-shift64-woo-search-modal' => '',
					'data-wp-on--click'             => 'actions.onDialogClick',
					'data-wp-on--cancel'            => 'actions.onDialogCancel',
				)
			)
		);

		return '<dialog ' . $wrapper . '><div class="shift64-woo-search-dialog__surface shift64-woo-search-modal__dialog shift64-woo-search-modal__search"><button type="button" class="shift64-woo-search-modal__close" aria-label="' . esc_attr( $close_label ) . '" data-wp-on--click="actions.close" data-shift64-woo-search-modal-close>×</button><form role="search" method="get" class="shift64-woo-search-field" action="' . esc_url( home_url( '/' ) ) . '" data-wp-on--submit="actions.submit"><label class="screen-reader-text" for="' . esc_attr( $runtime_id . '-input' ) . '">' . esc_html( $input_label ) . '</label><div class="shift64-woo-search-field__controls"><div class="shift64-woo-search-field__input-wrap"><input type="search" id="' . esc_attr( $runtime_id . '-input' ) . '" class="shift64-woo-search-field__input" name="s" value="' . esc_attr( get_search_query( false ) ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" enterkeyhint="search" role="combobox" aria-autocomplete="list" aria-controls="' . esc_attr( $runtime_id . '-listbox' ) . '" aria-expanded="false" required data-wp-bind--value="context.query" data-wp-bind--aria-expanded="state.isExpanded" data-wp-bind--aria-busy="state.isBusy" data-wp-bind--aria-activedescendant="state.activeDescendant" data-wp-on--input="actions.onInput" data-wp-on--focus="actions.onFocus" data-wp-on--keydown="actions.onKeydown"><button type="button" class="shift64-woo-search-field__clear" aria-label="' . esc_attr( $clear_label ) . '" hidden data-wp-bind--hidden="!state.hasQuery" data-wp-on--click="actions.clear" data-shift64-woo-search-clear>×</button></div><input type="hidden" name="post_type" value="product"><button type="submit" class="shift64-woo-search-field__submit wp-element-button">' . esc_html( $submit_label ) . '</button></div></form>' . $listbox . $status . '</div></dialog>';
	}

	/**
	 * Render a progressively hidden listbox populated by the view module.
	 *
	 * @param string $runtime_id Parent runtime ID.
	 * @return string
	 */
	private function render_results_shell( $runtime_id ) {
		return '<div id="' . esc_attr( $runtime_id . '-listbox' ) . '" class="shift64-woo-search-results" role="listbox" aria-label="' . esc_attr__( 'Search results', 'shift64-woo-search' ) . '" hidden data-shift64-search-results data-wp-bind--hidden="!state.isExpanded" data-wp-class--shift64-woo-search-results--visible="state.isExpanded"></div>';
	}

	/**
	 * Resolve a safe runtime ID inherited by a child block.
	 *
	 * @param WP_Block $block Child block.
	 * @return string
	 */
	private function runtime_id_from_context( $block ) {
		$runtime_id = $block->context['shift64WooSearch/runtimeId'] ?? $block->context['shift64WooSearch/instanceId'] ?? '';
		$runtime_id = sanitize_html_class( $runtime_id );
		return '' !== $runtime_id ? $runtime_id : wp_unique_id( 'shift64-woo-search-' );
	}

	/**
	 * Render the stable magnifier used by the modal trigger.
	 *
	 * @return string
	 */
	private function render_search_icon() {
		return '<svg class="shift64-woo-search-icon shift64-woo-search-icon--search" aria-hidden="true" focusable="false" viewBox="0 0 640 640" width="24" height="24" fill="currentColor"><path d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM272 416C351.5 416 416 351.5 416 272C416 192.5 351.5 128 272 128C192.5 128 128 192.5 128 272C128 351.5 192.5 416 272 416z"></path></svg>';
	}

	/**
	 * Render the regular search block through the shortcode renderer.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_search_block( $attributes ) {
		$html = $this->frontend->render_search_shortcode( $attributes );
		$html = $this->apply_search_field_colors( $html, $attributes );
		$html = str_replace(
			'class="shift64-woo-search-field__submit"',
			'class="shift64-woo-search-field__submit wp-element-button"',
			$html
		);

		return $this->wrap_block(
			$html,
			'shift64-woo-search-block shift64-woo-search-block--form'
		);
	}

	/**
	 * Move native block text and background colors onto the search input.
	 *
	 * Applying these colors to the outer wrapper would leave the input white and
	 * would produce a rectangular background behind the pill style.
	 *
	 * @param string               $html       Rendered search markup.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function apply_search_field_colors( $html, $attributes ) {
		$color_styles = array();

		$preset_text          = isset( $attributes['textColor'] ) ? "var:preset|color|{$attributes['textColor']}" : null;
		$custom_text          = $attributes['style']['color']['text'] ?? null;
		$color_styles['text'] = $preset_text ? $preset_text : $custom_text;

		$preset_background          = isset( $attributes['backgroundColor'] ) ? "var:preset|color|{$attributes['backgroundColor']}" : null;
		$custom_background          = $attributes['style']['color']['background'] ?? null;
		$color_styles['background'] = $preset_background ? $preset_background : $custom_background;

		$preset_gradient          = isset( $attributes['gradient'] ) ? "var:preset|gradient|{$attributes['gradient']}" : null;
		$custom_gradient          = $attributes['style']['color']['gradient'] ?? null;
		$color_styles['gradient'] = $preset_gradient ? $preset_gradient : $custom_gradient;

		$styles = function_exists( 'wp_style_engine_get_styles' )
			? wp_style_engine_get_styles(
				array( 'color' => array_filter( $color_styles ) ),
				array( 'convert_vars_to_classnames' => true )
			)
			: array();

		$class_name = 'shift64-woo-search-field__input';
		if ( ! empty( $styles['classnames'] ) ) {
			$class_name .= ' ' . $styles['classnames'];
		}

		$input_attributes = 'class="' . esc_attr( $class_name ) . '"';
		if ( ! empty( $styles['css'] ) ) {
			$input_attributes .= ' style="' . esc_attr( $styles['css'] ) . '"';
		}

		return str_replace(
			'class="shift64-woo-search-field__input"',
			$input_attributes,
			$html
		);
	}

	/**
	 * Render the modal search block through the shortcode renderer.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_modal_search_block( $attributes ) {
		$html                  = $this->frontend->render_search_modal_shortcode( $attributes );
		$trigger_border_radius = isset( $attributes['trigger_border_radius'] )
			? min( 50, max( 0, absint( $attributes['trigger_border_radius'] ) ) )
			: 50;
		$html                  = str_replace(
			'class="shift64-woo-search-modal__trigger"',
			'class="shift64-woo-search-modal__trigger" style="border-radius:' . esc_attr( $trigger_border_radius . 'px' ) . ';"',
			$html
		);
		$html                  = str_replace(
			'class="shift64-woo-search-field__submit"',
			'class="shift64-woo-search-field__submit wp-element-button"',
			$html
		);

		$trigger_style = $attributes['trigger_style'] ?? 'icon';
		if ( ! in_array( $trigger_style, array( 'icon', 'background', 'outline' ), true ) ) {
			$trigger_style = 'icon';
		}

		$search_style = $attributes['modal_search_style'] ?? 'default';
		if ( ! in_array( $search_style, array( 'default', 'pill', 'minimal' ), true ) ) {
			$search_style = 'default';
		}

		$class_name  = 'shift64-woo-search-block shift64-woo-search-block--modal';
		$class_name .= ' has-trigger-style-' . $trigger_style;
		$class_name .= ' has-modal-search-style-' . $search_style;
		if ( ! empty( $attributes['preview'] ) ) {
			$class_name .= ' is-preview-open';
		}

		$custom_properties  = $this->get_modal_custom_properties( $attributes );
		$extra_attributes   = $custom_properties ? array( 'style' => $custom_properties ) : array();
		$wrapper_attributes = $this->get_block_wrapper_attributes( $class_name, $extra_attributes );
		$html               = $this->apply_modal_portal_styles( $html, $attributes, $wrapper_attributes );

		return '<div ' . $wrapper_attributes . '>' . $html . '</div>';
	}

	/**
	 * Keep modal search styles after the frontend moves the modal under body.
	 *
	 * @param string               $html               Rendered modal markup.
	 * @param array<string, mixed> $attributes         Block attributes.
	 * @param string               $wrapper_attributes Rendered block wrapper attributes.
	 * @return string
	 */
	private function apply_modal_portal_styles( $html, $attributes, $wrapper_attributes ) {
		$class_names  = array( 'shift64-woo-search-modal', 'shift64-woo-search-modal--block' );
		$search_style = $attributes['modal_search_style'] ?? 'default';
		if ( ! in_array( $search_style, array( 'default', 'pill', 'minimal' ), true ) ) {
			$search_style = 'default';
		}
		$class_names[] = 'has-modal-search-style-' . $search_style;

		if ( preg_match_all( '/\bwp-elements-[a-f0-9]+\b/', $wrapper_attributes, $matches ) ) {
			$class_names = array_merge( $class_names, $matches[0] );
		}

		$preset_font_size = isset( $attributes['fontSize'] ) ? "var:preset|font-size|{$attributes['fontSize']}" : null;
		$typography       = array(
			'fontSize'   => $preset_font_size ? $preset_font_size : ( $attributes['style']['typography']['fontSize'] ?? null ),
			'fontWeight' => $attributes['style']['typography']['fontWeight'] ?? null,
			'lineHeight' => $attributes['style']['typography']['lineHeight'] ?? null,
		);
		$styles           = function_exists( 'wp_style_engine_get_styles' )
			? wp_style_engine_get_styles(
				array( 'typography' => array_filter( $typography ) ),
				array( 'convert_vars_to_classnames' => true )
			)
			: array();

		if ( ! empty( $styles['classnames'] ) ) {
			$class_names[] = $styles['classnames'];
		}

		$css                = $styles['css'] ?? '';
		$modal_background   = isset( $attributes['modal_background_color'] )
			? $this->sanitize_css_color( $attributes['modal_background_color'] )
			: '';
		$transparency       = isset( $attributes['modal_background_transparency'] )
			? min( 100, max( 0, absint( $attributes['modal_background_transparency'] ) ) )
			: 17;
		$background_opacity = 100 - $transparency;
		if ( $modal_background ) {
			$css .= '--s64ws-modal-background-color:' . $modal_background . ';';
		}
		$css .= '--s64ws-modal-background-opacity:' . $background_opacity . '%;';

		$legacy_style = $attributes['style'] ?? array();
		$form_colors  = array(
			'search_input_text_color'              => array(
				'property' => '--s64ws-modal-input-color',
				'fallback' => $legacy_style['color']['text'] ?? '',
			),
			'search_input_background_color'        => array(
				'property' => '--s64ws-modal-input-background',
				'fallback' => $legacy_style['color']['background'] ?? '',
			),
			'search_button_color'                  => array(
				'property' => '--s64ws-modal-button-color',
				'fallback' => $legacy_style['elements']['button']['color']['text'] ?? '',
			),
			'search_button_background_color'       => array(
				'property' => '--s64ws-modal-button-background',
				'fallback' => $legacy_style['elements']['button']['color']['background'] ?? '',
			),
			'search_button_hover_color'            => array(
				'property' => '--s64ws-modal-button-hover-color',
				'fallback' => '',
			),
			'search_button_background_hover_color' => array(
				'property' => '--s64ws-modal-button-hover-background',
				'fallback' => '',
			),
		);

		foreach ( $form_colors as $attribute => $color_data ) {
			$color = isset( $attributes[ $attribute ] ) && '' !== $attributes[ $attribute ]
				? $attributes[ $attribute ]
				: $color_data['fallback'];
			$color = $this->sanitize_css_color( $color );
			if ( $color ) {
				$css          .= $color_data['property'] . ':' . $color . ';';
				$class_names[] = 'has-' . str_replace( '_', '-', $attribute );
			}
		}

		$modal_attributes = 'class="' . esc_attr( implode( ' ', array_unique( $class_names ) ) ) . '"';
		if ( $css ) {
			$modal_attributes .= ' style="' . esc_attr( $css ) . '"';
		}

		return str_replace( 'class="shift64-woo-search-modal"', $modal_attributes, $html );
	}

	/**
	 * Build safe CSS custom properties for the trigger and modal preview.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function get_modal_custom_properties( $attributes ) {
		$properties = array();
		$colors     = array(
			'trigger_icon_color'          => '--s64ws-trigger-icon-color',
			'trigger_icon_hover_color'    => '--s64ws-trigger-icon-hover-color',
			'trigger_surface_color'       => '--s64ws-trigger-surface-color',
			'trigger_surface_hover_color' => '--s64ws-trigger-surface-hover-color',
		);

		foreach ( $colors as $attribute => $property ) {
			$color = isset( $attributes[ $attribute ] ) ? $this->sanitize_css_color( $attributes[ $attribute ] ) : '';
			if ( $color ) {
				$properties[] = $property . ':' . $color;
			}
		}

		if ( isset( $attributes['trigger_border_radius'] ) ) {
			$radius       = min( 50, max( 0, absint( $attributes['trigger_border_radius'] ) ) );
			$properties[] = '--s64ws-trigger-radius:' . $radius . 'px';
		}

		if ( isset( $attributes['trigger_icon_size'] ) ) {
			$icon_size    = min( 40, max( 12, absint( $attributes['trigger_icon_size'] ) ) );
			$properties[] = '--s64ws-trigger-icon-size:' . $icon_size . 'px';
		}

		if ( isset( $attributes['trigger_padding'] ) ) {
			$padding      = min( 30, max( 0, absint( $attributes['trigger_padding'] ) ) );
			$properties[] = '--s64ws-trigger-padding:' . $padding . 'px';
		}

		if ( isset( $attributes['trigger_outline_width'] ) ) {
			$outline_width = min( 10, max( 1, absint( $attributes['trigger_outline_width'] ) ) );
			$properties[]  = '--s64ws-trigger-outline-width:' . $outline_width . 'px';
		}

		return $properties ? implode( ';', $properties ) . ';' : '';
	}

	/**
	 * Accept color values emitted by WordPress' color palette control.
	 *
	 * @param mixed $color Candidate CSS color.
	 * @return string
	 */
	private function sanitize_css_color( $color ) {
		if ( ! is_string( $color ) ) {
			return '';
		}

		$color = trim( wp_strip_all_tags( $color ) );
		if ( preg_match( '/^(?:#[0-9a-f]{3,8}|(?:rgb|hsl)a?\([0-9.%\s,\/+-]+\)|var\(--[a-z0-9_-]+\))$/i', $color ) ) {
			return $color;
		}

		return '';
	}

	/**
	 * Apply native block support classes and styles to a renderer result.
	 *
	 * @param string $html       Rendered search markup.
	 * @param string $class_name Additional wrapper classes.
	 * @param array  $extra_attributes Additional wrapper attributes.
	 * @return string
	 */
	private function wrap_block( $html, $class_name, $extra_attributes = array() ) {
		$wrapper_attributes = $this->get_block_wrapper_attributes( $class_name, $extra_attributes );

		return '<div ' . $wrapper_attributes . '>' . $html . '</div>';
	}

	/**
	 * Render native block support attributes for a wrapper.
	 *
	 * @param string $class_name      Additional wrapper classes.
	 * @param array  $extra_attributes Additional wrapper attributes.
	 * @return string
	 */
	private function get_block_wrapper_attributes( $class_name, $extra_attributes = array() ) {
		$extra_attributes['class'] = isset( $extra_attributes['class'] )
			? trim( $class_name . ' ' . $extra_attributes['class'] )
			: $class_name;

		$wrapper_attributes = get_block_wrapper_attributes(
			$extra_attributes
		);

		return $wrapper_attributes;
	}

	/**
	 * Attributes shared by the regular and modal search forms.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_search_attributes() {
		return array(
			'placeholder' => array(
				'label'   => __( 'Placeholder', 'shift64-woo-search' ),
				'type'    => 'string',
				'default' => __( 'Search products...', 'shift64-woo-search' ),
			),
			'button'      => array(
				'label'   => __( 'Search button text', 'shift64-woo-search' ),
				'type'    => 'string',
				'default' => __( 'Search', 'shift64-woo-search' ),
			),
			'label'       => array(
				'label'   => __( 'Accessible search label', 'shift64-woo-search' ),
				'type'    => 'string',
				'default' => __( 'Search products', 'shift64-woo-search' ),
			),
		);
	}

	/**
	 * Modal-specific block attributes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_modal_attributes() {
		return array_merge(
			$this->get_search_attributes(),
			array(
				'trigger_label'                        => array(
					'label'   => __( 'Open button label', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => __( 'Open product search', 'shift64-woo-search' ),
				),
				'close_label'                          => array(
					'label'   => __( 'Close button label', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => __( 'Close search', 'shift64-woo-search' ),
				),
				'clear_label'                          => array(
					'label'   => __( 'Clear button label', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => __( 'Clear search', 'shift64-woo-search' ),
				),
				'icon'                                 => array(
					'label'   => __( 'Search icon', 'shift64-woo-search' ),
					'type'    => 'string',
					'enum'    => array( 'default', 'alternative' ),
					'default' => 'default',
				),
				'trigger_style'                        => array(
					'label'   => __( 'Trigger button style', 'shift64-woo-search' ),
					'type'    => 'string',
					'enum'    => array( 'icon', 'background', 'outline' ),
					'default' => 'icon',
				),
				'trigger_icon_color'                   => array(
					'label'   => __( 'Trigger icon color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'trigger_icon_hover_color'             => array(
					'label'   => __( 'Trigger icon hover color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'trigger_surface_color'                => array(
					'label'   => __( 'Trigger background or outline color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'trigger_surface_hover_color'          => array(
					'label'   => __( 'Trigger background or outline hover color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'trigger_border_radius'                => array(
					'label'   => __( 'Trigger border radius', 'shift64-woo-search' ),
					'type'    => 'integer',
					'default' => 50,
				),
				'trigger_icon_size'                    => array(
					'label'   => __( 'Trigger icon size', 'shift64-woo-search' ),
					'type'    => 'integer',
					'default' => 24,
				),
				'trigger_padding'                      => array(
					'label'   => __( 'Trigger padding', 'shift64-woo-search' ),
					'type'    => 'integer',
					'default' => 10,
				),
				'trigger_outline_width'                => array(
					'label'   => __( 'Trigger outline width', 'shift64-woo-search' ),
					'type'    => 'integer',
					'default' => 1,
				),
				'modal_search_style'                   => array(
					'label'   => __( 'Modal search field style', 'shift64-woo-search' ),
					'type'    => 'string',
					'enum'    => array( 'default', 'pill', 'minimal' ),
					'default' => 'default',
				),
				'modal_background_color'               => array(
					'label'   => __( 'Modal background color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'modal_background_transparency'        => array(
					'label'   => __( 'Modal background transparency', 'shift64-woo-search' ),
					'type'    => 'integer',
					'default' => 17,
				),
				'search_input_text_color'              => array(
					'label'   => __( 'Search input text color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'search_input_background_color'        => array(
					'label'   => __( 'Search input background color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'search_button_color'                  => array(
					'label'   => __( 'Search button icon color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'search_button_background_color'       => array(
					'label'   => __( 'Search button background color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'search_button_hover_color'            => array(
					'label'   => __( 'Search button icon hover color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'search_button_background_hover_color' => array(
					'label'   => __( 'Search button background hover color', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => '',
				),
				'preview'                              => array(
					'label'   => __( 'Show modal preview in editor', 'shift64-woo-search' ),
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
	}

	/**
	 * Native block design tools plus WordPress 7 PHP-only registration.
	 *
	 * @param bool $apply_colors_to_field Whether input colors are rendered on the field.
	 * @param bool $enable_color_support  Whether WordPress should expose native color tools.
	 * @return array<string, mixed>
	 */
	private function get_block_supports( $apply_colors_to_field = false, $enable_color_support = true ) {
		global $wp_version;

		$supports = array(
			'align'      => array( 'wide', 'full' ),
			'anchor'     => true,
			'border'     => array(
				'color'  => true,
				'radius' => true,
				'style'  => true,
				'width'  => true,
			),
			'color'      => array(
				'background' => true,
				'button'     => true,
				'gradients'  => true,
				'text'       => true,
			),
			'html'       => false,
			'spacing'    => array(
				'margin'  => true,
				'padding' => true,
			),
			'typography' => array(
				'fontSize'   => true,
				'fontWeight' => true,
				'lineHeight' => true,
			),
		);

		if ( $apply_colors_to_field ) {
			$supports['color']['__experimentalSkipSerialization'] = array( 'text', 'background', 'gradients' );
		}
		if ( ! $enable_color_support ) {
			unset( $supports['color'] );
		}

		if ( version_compare( $wp_version, '7.0', '>=' ) ) {
			$supports['autoRegister'] = true;
		}

		return $supports;
	}

	/**
	 * Search form style choices shown in the editor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_search_styles() {
		return array(
			array(
				'name'  => 'pill',
				'label' => __( 'Pill', 'shift64-woo-search' ),
			),
			array(
				'name'  => 'minimal',
				'label' => __( 'Minimal', 'shift64-woo-search' ),
			),
		);
	}

	/**
	 * Render the product sort block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @param WP_Block|null        $block      Block instance.
	 * @return string Rendered HTML.
	 */
	public function render_product_sort_block( $attributes = array(), $content = '', $block = null ) {
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( Shift64_Woo_Search_Catalog_Navigation::PRODUCT_SORT_MODULE_ID );
		}

		$query_context = ( $block instanceof WP_Block && isset( $block->context['query'] ) ) ? $block->context['query'] : array();
		$query_id      = ( $block instanceof WP_Block && isset( $block->context['queryId'] ) ) ? $block->context['queryId'] : null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public storefront state.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public storefront state.
		$is_search = is_search() || ! empty( $query_context['search'] ) || ( 'product' === $post_type && isset( $_GET['s'] ) );

		$default_labels = array(
			'relevance'  => __( 'Search relevance', 'shift64-woo-search' ),
			'menu_order' => __( 'Default sorting', 'shift64-woo-search' ),
			'popularity' => __( 'Sort by popularity', 'shift64-woo-search' ),
			'rating'     => __( 'Sort by average rating', 'shift64-woo-search' ),
			'date'       => __( 'Sort by latest', 'shift64-woo-search' ),
			'price'      => __( 'Sort by price: low to high', 'shift64-woo-search' ),
			'price-desc' => __( 'Sort by price: high to low', 'shift64-woo-search' ),
		);

		$configured_options = ! empty( $attributes['orderedOptions'] ) && is_array( $attributes['orderedOptions'] )
			? $attributes['orderedOptions']
			: array( 'menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc' );

		// Remap menu_order to relevance in search context if relevance was not explicitly positioned.
		if ( $is_search ) {
			$remapped      = array();
			$has_relevance = in_array( 'relevance', $configured_options, true );
			foreach ( $configured_options as $opt ) {
				if ( 'menu_order' === $opt ) {
					if ( ! $has_relevance ) {
						$remapped[]    = 'relevance';
						$has_relevance = true;
					}
					continue;
				}
				$remapped[] = $opt;
			}
			$configured_options = $remapped;
		}

		$available_keys = $is_search
			? array( 'relevance', 'popularity', 'rating', 'date', 'price', 'price-desc' )
			: array( 'menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc' );

		$active_keys = array_values( array_intersect( $configured_options, $available_keys ) );
		if ( empty( $active_keys ) ) {
			return '';
		}

		$custom_labels    = ( isset( $attributes['labels'] ) && is_array( $attributes['labels'] ) ) ? $attributes['labels'] : array();
		$rendered_options = array();
		foreach ( $active_keys as $key ) {
			$rendered_options[ $key ] = ! empty( $custom_labels[ $key ] ) ? $custom_labels[ $key ] : ( $default_labels[ $key ] ?? $key );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public storefront state.
		$requested_sort = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : null;
		$effective_sort = Shift64_Woo_Search_Sort::get_effective_sort( $requested_sort, $is_search );

		if ( ! empty( $requested_sort ) && ! isset( $rendered_options[ $requested_sort ] ) ) {
			$fallback_label   = $default_labels[ $requested_sort ] ?? ucfirst( str_replace( array( '-', '_' ), ' ', $requested_sort ) );
			$rendered_options = array( $requested_sort => $fallback_label ) + $rendered_options;
		}

		static $instance_count = 0;
		++$instance_count;
		$select_id = 'shift64-sort-' . $instance_count;

		$active_label = $rendered_options[ $effective_sort ] ?? reset( $rendered_options );

		$context = array(
			'isOpen'      => false,
			'alignEnd'    => false,
			'queryId'     => $query_id,
			'activeSort'  => $effective_sort,
			'activeLabel' => $active_label,
		);

		// One token map for every pill-shaped control, so a sort pill and a
		// filter pill styled with the same values render identically.
		$style_vars = Shift64_Woo_Search_Pill_Style::pill_vars( $attributes['pillStyle'] ?? array() );

		$wrapper_args = array(
			'class'                      => 'shift64-woo-search-product-sort shift64-woo-search-pill',
			'data-wp-interactive'        => 'shift64/woo-search-product-sort',
			'data-wp-context'            => wp_json_encode( $context ),
			'data-wp-on-window--click'   => 'actions.onClickOutside',
			'data-wp-on-window--keydown' => 'actions.onKeyDown',
		);
		if ( '' !== $style_vars ) {
			$wrapper_args['style'] = $style_vars;
		}

		$wrapper_attrs = get_block_wrapper_attributes( $wrapper_args );

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<button
				type="button"
				class="shift64-woo-search-pill__trigger"
				data-wp-on--click="actions.toggleDropdown"
				data-wp-bind--aria-expanded="context.isOpen"
				aria-haspopup="listbox"
				aria-label="<?php esc_attr_e( 'Sort products by', 'shift64-woo-search' ); ?>"
			>
				<span class="shift64-woo-search-pill__label" data-wp-text="context.activeLabel">
					<?php echo esc_html( $active_label ); ?>
				</span>
				<span class="shift64-woo-search-pill__chevron" data-wp-class--is-open="context.isOpen" aria-hidden="true"></span>
			</button>

			<div
				class="shift64-woo-search-pill__panel"
				data-wp-bind--hidden="!context.isOpen"
				data-wp-class--is-aligned-end="context.alignEnd"
				role="listbox"
				aria-label="<?php esc_attr_e( 'Sort options', 'shift64-woo-search' ); ?>"
				hidden
			>
				<ul class="shift64-woo-search-pill__options" role="presentation">
					<?php
					foreach ( $rendered_options as $slug => $label ) :
						$is_selected  = ( $effective_sort === $slug );
						$item_context = wp_json_encode(
							array(
								'slug'  => $slug,
								'label' => $label,
							)
						);
						?>
						<li
							class="shift64-woo-search-pill__option"
							role="presentation"
							data-wp-context="<?php echo esc_attr( $item_context ); ?>"
						>
							<button
								type="button"
								role="option"
								class="shift64-woo-search-product-sort__option<?php echo $is_selected ? ' is-selected' : ''; ?>"
								data-slug="<?php echo esc_attr( $slug ); ?>"
								data-label="<?php echo esc_attr( $label ); ?>"
								data-wp-on--click="actions.selectOption"
								data-wp-class--is-selected="state.isSelected"
								data-wp-bind--aria-selected="state.isSelected"
								aria-selected="<?php echo $is_selected ? 'true' : 'false'; ?>"
							>
								<span class="shift64-woo-search-pill__option-label">
									<?php echo esc_html( $label ); ?>
								</span>
								<span class="shift64-woo-search-product-sort__check" aria-hidden="true"></span>
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<form class="woocommerce-ordering shift64-woo-search-product-sort__fallback" method="get">
				<label for="<?php echo esc_attr( $select_id ); ?>" class="screen-reader-text">
					<?php esc_html_e( 'Sort by', 'shift64-woo-search' ); ?>
				</label>
				<select
					id="<?php echo esc_attr( $select_id ); ?>"
					name="orderby"
					class="orderby shift64-woo-search-product-sort__select"
					aria-label="<?php esc_attr_e( 'Shop order', 'shift64-woo-search' ); ?>"
					data-wp-on--change="actions.onSortChange"
				>
					<?php foreach ( $rendered_options as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $effective_sort, $slug ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				foreach ( $_GET as $key => $val ) {
					$clean_key = sanitize_key( $key );
					if ( in_array( $clean_key, array( 'orderby', 'submit', 'paged', 'query-page' ), true ) || preg_match( '/^query-\d+-page$/', $clean_key ) ) {
						continue;
					}
					if ( is_array( $val ) ) {
						foreach ( $val as $inner_val ) {
							echo '<input type="hidden" name="' . esc_attr( $clean_key ) . '[]" value="' . esc_attr( sanitize_text_field( wp_unslash( $inner_val ) ) ) . '" />';
						}
					} else {
						echo '<input type="hidden" name="' . esc_attr( $clean_key ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $val ) ) ) . '" />';
					}
				}
				?>
			</form>
		</div>
		<?php
		return trim( ob_get_clean() );
	}
}
