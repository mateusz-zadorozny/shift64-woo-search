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
			SHIFT64_WOO_SEARCH_VERSION
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
				'supports'        => $this->get_block_supports( false, true ),
				'styles'          => $this->get_modal_styles(),
				'style'           => 'shift64-woo-search',
				'render_callback' => array( $this, 'render_modal_search_block' ),
			)
		);
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
	 * @param array<string, string> $attributes Block attributes.
	 * @return string
	 */
	public function render_modal_search_block( $attributes ) {
		$html = $this->frontend->render_search_modal_shortcode( $attributes );
		$html = str_replace(
			array(
				'class="shift64-woo-search-modal__trigger"',
				'class="shift64-woo-search-field__submit"',
			),
			array(
				'class="shift64-woo-search-modal__trigger wp-element-button"',
				'class="shift64-woo-search-field__submit wp-element-button"',
			),
			$html
		);

		$class_name = 'shift64-woo-search-block shift64-woo-search-block--modal';
		if ( ! empty( $attributes['preview'] ) ) {
			$class_name .= ' is-preview-open';
		}

		return $this->wrap_block(
			$html,
			$class_name
		);
	}

	/**
	 * Apply native block support classes and styles to a renderer result.
	 *
	 * @param string $html       Rendered search markup.
	 * @param string $class_name Additional wrapper classes.
	 * @return string
	 */
	private function wrap_block( $html, $class_name ) {
		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => $class_name,
			)
		);

		return '<div ' . $wrapper_attributes . '>' . $html . '</div>';
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
				'trigger_label' => array(
					'label'   => __( 'Open button label', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => __( 'Open product search', 'shift64-woo-search' ),
				),
				'close_label'   => array(
					'label'   => __( 'Close button label', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => __( 'Close search', 'shift64-woo-search' ),
				),
				'clear_label'   => array(
					'label'   => __( 'Clear button label', 'shift64-woo-search' ),
					'type'    => 'string',
					'default' => __( 'Clear search', 'shift64-woo-search' ),
				),
				'icon'          => array(
					'label'   => __( 'Search icon', 'shift64-woo-search' ),
					'type'    => 'string',
					'enum'    => array( 'default', 'alternative' ),
					'default' => 'default',
				),
				'preview'       => array(
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
	 * @param bool $button_colors_only    Whether to hide wrapper color controls.
	 * @return array<string, mixed>
	 */
	private function get_block_supports( $apply_colors_to_field = false, $button_colors_only = false ) {
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

		if ( $button_colors_only ) {
			$supports['color'] = array( 'button' => true );
		} elseif ( $apply_colors_to_field ) {
			$supports['color']['__experimentalSkipSerialization'] = array( 'text', 'background', 'gradients' );
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
	 * Modal trigger style choices shown in the editor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_modal_styles() {
		return array(
			array(
				'name'  => 'soft',
				'label' => __( 'Soft background', 'shift64-woo-search' ),
			),
			array(
				'name'  => 'outline',
				'label' => __( 'Outline', 'shift64-woo-search' ),
			),
		);
	}
}
