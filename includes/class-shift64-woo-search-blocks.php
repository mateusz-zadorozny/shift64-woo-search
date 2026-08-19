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
	 * Set up block registration.
	 *
	 * @param Shift64_Woo_Search_Frontend $frontend Shared shortcode renderer and asset loader.
	 */
	public function __construct( Shift64_Woo_Search_Frontend $frontend ) {
		$this->frontend = $frontend;
		add_filter( 'register_block_type_args', array( $this, 'configure_modal_editor_controls' ), 20, 2 );
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
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

		wp_register_script(
			'shift64-woo-search-block-editor',
			SHIFT64_WOO_SEARCH_URL . 'admin/js/shift64-woo-search-block-editor.js',
			array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-compose', 'wp-hooks', 'wp-i18n', 'wp-server-side-render' ),
			Shift64_Woo_Search_Frontend::asset_version( 'admin/js/shift64-woo-search-block-editor.js' ),
			false
		);
		wp_set_script_translations(
			'shift64-woo-search-block-editor',
			'shift64-woo-search',
			SHIFT64_WOO_SEARCH_PATH . 'languages'
		);

		register_block_type(
			'shift64-woo-search/search',
			array(
				'api_version'     => 3,
				'title'           => __( 'Shift64 Product Search', 'shift64-woo-search' ),
				'category'        => 'widgets',
				'icon'            => 'search',
				'description'     => __( 'A product search form with Shift64 autocomplete.', 'shift64-woo-search' ),
				'keywords'        => array(
					'Shift64',
					__( 'products', 'shift64-woo-search' ),
					__( 'WooCommerce', 'shift64-woo-search' ),
				),
				'textdomain'      => 'shift64-woo-search',
				'attributes'      => $this->get_search_attributes(),
				'supports'        => $this->get_block_supports( true ),
				'styles'          => $this->get_search_styles(),
				'style'           => 'shift64-woo-search',
				'render_callback' => array( $this, 'render_search_block' ),
			)
		);

		register_block_type(
			'shift64-woo-search/modal-search',
			array(
				'api_version'     => 3,
				'title'           => __( 'Shift64 Modal Product Search', 'shift64-woo-search' ),
				'category'        => 'widgets',
				'icon'            => 'search',
				'description'     => __( 'A compact search icon that opens the Shift64 product search modal.', 'shift64-woo-search' ),
				'keywords'        => array(
					'Shift64',
					__( 'products', 'shift64-woo-search' ),
					__( 'modal', 'shift64-woo-search' ),
				),
				'textdomain'      => 'shift64-woo-search',
				'attributes'      => $this->get_modal_attributes(),
				'supports'        => $this->get_block_supports( false, false ),
				'style'           => 'shift64-woo-search',
				'render_callback' => array( $this, 'render_modal_search_block' ),
			)
		);

		register_block_type(
			'shift64-woo-search/product-sort',
			array(
				'api_version'     => 3,
				'title'           => __( 'Shift64 Product Sort', 'shift64-woo-search' ),
				'category'        => 'woocommerce',
				'icon'            => 'sort',
				'description'     => __( 'A product sorting dropdown powered by Shift64 and RediSearch.', 'shift64-woo-search' ),
				'keywords'        => array(
					'Shift64',
					__( 'sort', 'shift64-woo-search' ),
					__( 'orderby', 'shift64-woo-search' ),
					__( 'WooCommerce', 'shift64-woo-search' ),
				),
				'textdomain'      => 'shift64-woo-search',
				'attributes'      => array(
					'orderedOptions' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc' ),
					),
					'labels'         => array(
						'type'    => 'object',
						'default' => array(),
					),
					'className'      => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => $this->get_sort_block_supports(),
				'style'           => 'shift64-woo-search',
				'render_callback' => array( $this, 'render_product_sort_block' ),
				'uses_context'    => array( 'query', 'queryId' ),
			)
		);
	}

	/**
	 * Block supports for the Product Sort block.
	 *
	 * @return array<string, mixed>
	 */
	private function get_sort_block_supports() {
		global $wp_version;

		$supports = array(
			'html'          => false,
			'interactivity' => true,
			'inserter'      => true,
			'color'         => array(
				'text'       => true,
				'background' => true,
			),
			'typography'    => array(
				'fontSize'   => true,
				'lineHeight' => true,
			),
			'spacing'       => array(
				'margin'  => true,
				'padding' => true,
			),
		);

		if ( version_compare( $wp_version, '7.0', '>=' ) ) {
			$supports['autoRegister'] = true;
		}

		return $supports;
	}

	/**
	 * Load the modal inspector extension before the block editor renders.
	 */
	public function enqueue_editor_assets() {
		wp_enqueue_script( 'shift64-woo-search-block-editor' );
	}

	/**
	 * Keep custom modal design attributes out of WordPress' generic controls.
	 *
	 * WordPress 7 marks every primitive PHP attribute for automatic controls.
	 * These attributes use purpose-built color, range, and select controls from
	 * the editor script instead.
	 *
	 * @param array<string, mixed> $args       Block registration arguments.
	 * @param string               $block_name Registered block name.
	 * @return array<string, mixed>
	 */
	public function configure_modal_editor_controls( $args, $block_name ) {
		if ( 'shift64-woo-search/modal-search' !== $block_name || empty( $args['attributes'] ) ) {
			return $args;
		}

		$custom_controls = array(
			'icon',
			'preview',
			'trigger_style',
			'trigger_icon_color',
			'trigger_icon_hover_color',
			'trigger_surface_color',
			'trigger_surface_hover_color',
			'trigger_border_radius',
			'trigger_icon_size',
			'trigger_padding',
			'trigger_outline_width',
			'modal_search_style',
			'modal_background_color',
			'modal_background_transparency',
			'search_input_text_color',
			'search_input_background_color',
			'search_button_color',
			'search_button_background_color',
			'search_button_hover_color',
			'search_button_background_hover_color',
		);

		foreach ( $custom_controls as $attribute ) {
			unset( $args['attributes'][ $attribute ]['autoGenerateControl'] );
		}

		return $args;
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

		$wrapper_attrs = get_block_wrapper_attributes(
			array(
				'class'               => 'shift64-woo-search-product-sort',
				'data-wp-interactive' => 'shift64/woo-search-product-sort',
				'data-wp-context'     => wp_json_encode( array( 'queryId' => $query_id ) ),
			)
		);

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<form class="woocommerce-ordering shift64-woo-search-product-sort__form" method="get">
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
