( function ( wp ) {
	'use strict';

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ColorPaletteControl = wp.blockEditor.ColorPaletteControl;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Disabled = wp.components.Disabled;
	var useDisabled = wp.compose && wp.compose.useDisabled;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var addFilter = wp.hooks.addFilter;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;

	var previewBlocks = [
		'shift64-woo-search/search',
		'shift64-woo-search/modal-search',
	];

	/**
	 * Render the editor preview through a POST request instead of a GET request.
	 *
	 * WordPress auto-registers these server-rendered blocks with a preview that
	 * puts every attribute into the REST request's query string. Attribute
	 * defaults such as the "Search products..." placeholder then put a `..`
	 * sequence in the URL, which common hosting firewalls reject as a traversal
	 * attempt — the preview fails with a 403 before the request reaches PHP.
	 * Sending the attributes in the request body keeps them out of the URL.
	 *
	 * @param {Object} settings Block settings.
	 * @param {string} name     Registered block name.
	 * @return {Object} Block settings.
	 */
	var addPostPreviewRequests = function ( settings, name ) {
		if ( previewBlocks.indexOf( name ) === -1 || ! ServerSideRender ) {
			return settings;
		}

		return Object.assign( {}, settings, {
			edit: function ( props ) {
				// Matches the wrapper WordPress builds for auto-registered
				// blocks, so no extra element lands inside the block.
				var blockProps = useBlockProps( useDisabled ? { ref: useDisabled() } : {} );
				var preview = createElement( ServerSideRender, {
					block: name,
					attributes: props.attributes,
					httpMethod: 'POST',
				} );

				if ( ! useDisabled ) {
					preview = createElement( Disabled, null, preview );
				}

				return createElement( 'div', blockProps, preview );
			},
		} );
	};

	addFilter(
		'blocks.registerBlockType',
		'shift64-woo-search/post-preview-requests',
		addPostPreviewRequests
	);

	var addModalSearchControls = function ( settings, name ) {
		if ( name !== 'shift64-woo-search/modal-search' ) {
			return settings;
		}

		var ServerSideEdit = settings.edit;

		return Object.assign( {}, settings, {
			edit: function ( props ) {
				var attributes = props.attributes;
				var setAttribute = function ( attribute, value ) {
					var update = {};
					update[ attribute ] = value;
					props.setAttributes( update );
				};
				var colorControl = function ( label, value, onChange, enableAlpha ) {
					return createElement( ColorPaletteControl, {
						label: label,
						value: value,
						onChange: onChange,
						clearable: true,
						enableAlpha: Boolean( enableAlpha ),
						__experimentalIsRenderedInSidebar: true,
					} );
				};
				var style = attributes.style || {};
				var searchBoxColors = style.color || {};
				var buttonColors = style.elements && style.elements.button && style.elements.button.color
					? style.elements.button.color
					: {};

				return createElement(
					Fragment,
					null,
					createElement(
						InspectorControls,
						null,
						createElement(
							PanelBody,
							{
								title: __( 'Modal preview', 'shift64-woo-search' ),
								initialOpen: true,
							},
							createElement( ToggleControl, {
								label: __( 'Show preview in editor', 'shift64-woo-search' ),
								checked: Boolean( attributes.preview ),
								onChange: function ( value ) {
									setAttribute( 'preview', value );
								},
								__nextHasNoMarginBottom: true,
							} )
						)
					),
					createElement( ServerSideEdit, props ),
					createElement(
						InspectorControls,
						{ group: 'styles' },
						createElement(
							PanelBody,
							{
								title: __( 'Trigger button', 'shift64-woo-search' ),
								initialOpen: true,
							},
							createElement( SelectControl, {
								label: __( 'Button style', 'shift64-woo-search' ),
								value: attributes.trigger_style,
								options: [
									{ label: __( 'Icon only', 'shift64-woo-search' ), value: 'icon' },
									{ label: __( 'Background', 'shift64-woo-search' ), value: 'background' },
									{ label: __( 'Outline', 'shift64-woo-search' ), value: 'outline' },
								],
								onChange: function ( value ) {
									setAttribute( 'trigger_style', value );
								},
								__nextHasNoMarginBottom: true,
							} ),
							createElement( SelectControl, {
								label: __( 'Search icon', 'shift64-woo-search' ),
								value: attributes.icon,
								options: [
									{ label: __( 'Default', 'shift64-woo-search' ), value: 'default' },
									{ label: __( 'Alternative', 'shift64-woo-search' ), value: 'alternative' },
								],
								onChange: function ( value ) {
									setAttribute( 'icon', value );
								},
								__nextHasNoMarginBottom: true,
							} ),
							createElement( RangeControl, {
								label: __( 'Icon size', 'shift64-woo-search' ),
								value: attributes.trigger_icon_size,
								min: 12,
								max: 40,
								step: 1,
								onChange: function ( value ) {
									setAttribute( 'trigger_icon_size', value );
								},
								__nextHasNoMarginBottom: true,
							} ),
							createElement( RangeControl, {
								label: __( 'Padding', 'shift64-woo-search' ),
								value: attributes.trigger_padding,
								min: 0,
								max: 30,
								step: 1,
								onChange: function ( value ) {
									setAttribute( 'trigger_padding', value );
								},
								__nextHasNoMarginBottom: true,
							} ),
							createElement( RangeControl, {
								label: __( 'Border radius', 'shift64-woo-search' ),
								value: attributes.trigger_border_radius,
								min: 0,
								max: 50,
								step: 1,
								onChange: function ( value ) {
									setAttribute( 'trigger_border_radius', value );
								},
								__nextHasNoMarginBottom: true,
							} ),
							attributes.trigger_style === 'outline' && createElement( RangeControl, {
								label: __( 'Outline width', 'shift64-woo-search' ),
								value: attributes.trigger_outline_width,
								min: 1,
								max: 10,
								step: 1,
								onChange: function ( value ) {
									setAttribute( 'trigger_outline_width', value );
								},
								__nextHasNoMarginBottom: true,
							} ),
							colorControl(
								__( 'Icon color', 'shift64-woo-search' ),
								attributes.trigger_icon_color,
								function ( value ) {
									setAttribute( 'trigger_icon_color', value || '' );
								}
							),
							colorControl(
								__( 'Icon hover color', 'shift64-woo-search' ),
								attributes.trigger_icon_hover_color,
								function ( value ) {
									setAttribute( 'trigger_icon_hover_color', value || '' );
								}
							),
							colorControl(
								__( 'Background or outline color', 'shift64-woo-search' ),
								attributes.trigger_surface_color,
								function ( value ) {
									setAttribute( 'trigger_surface_color', value || '' );
								}
							),
							colorControl(
								__( 'Background or outline hover color', 'shift64-woo-search' ),
								attributes.trigger_surface_hover_color,
								function ( value ) {
									setAttribute( 'trigger_surface_hover_color', value || '' );
								}
							)
						),
						createElement(
							PanelBody,
							{
								title: __( 'Search box input color', 'shift64-woo-search' ),
								initialOpen: true,
							},
							colorControl(
								__( 'Text', 'shift64-woo-search' ),
								attributes.search_input_text_color || searchBoxColors.text,
								function ( value ) {
									setAttribute( 'search_input_text_color', value || '' );
								}
							),
							colorControl(
								__( 'Background', 'shift64-woo-search' ),
								attributes.search_input_background_color || searchBoxColors.background,
								function ( value ) {
									setAttribute( 'search_input_background_color', value || '' );
								},
								true
							),
						),
						createElement(
							PanelBody,
							{
								title: __( 'Search button color', 'shift64-woo-search' ),
								initialOpen: true,
							},
							colorControl(
								__( 'Icon', 'shift64-woo-search' ),
								attributes.search_button_color || buttonColors.text,
								function ( value ) {
									setAttribute( 'search_button_color', value || '' );
								}
							),
							colorControl(
								__( 'Background', 'shift64-woo-search' ),
								attributes.search_button_background_color || buttonColors.background,
								function ( value ) {
									setAttribute( 'search_button_background_color', value || '' );
								},
								true
							),
							colorControl(
								__( 'Icon hover', 'shift64-woo-search' ),
								attributes.search_button_hover_color,
								function ( value ) {
									setAttribute( 'search_button_hover_color', value || '' );
								}
							),
							colorControl(
								__( 'Background hover', 'shift64-woo-search' ),
								attributes.search_button_background_hover_color,
								function ( value ) {
									setAttribute( 'search_button_background_hover_color', value || '' );
								},
								true
							)
						),
						createElement(
							PanelBody,
							{
								title: __( 'Modal', 'shift64-woo-search' ),
								initialOpen: true,
							},
							createElement( SelectControl, {
								label: __( 'Search field style', 'shift64-woo-search' ),
								value: attributes.modal_search_style,
								options: [
									{ label: __( 'Default', 'shift64-woo-search' ), value: 'default' },
									{ label: __( 'Pill', 'shift64-woo-search' ), value: 'pill' },
									{ label: __( 'Minimal', 'shift64-woo-search' ), value: 'minimal' },
								],
								onChange: function ( value ) {
									setAttribute( 'modal_search_style', value );
								},
								__nextHasNoMarginBottom: true,
							} ),
							colorControl(
								__( 'Modal background color', 'shift64-woo-search' ),
								attributes.modal_background_color,
								function ( value ) {
									setAttribute( 'modal_background_color', value || '' );
								}
							),
							createElement( RangeControl, {
								label: __( 'Modal transparency', 'shift64-woo-search' ),
								value: attributes.modal_background_transparency,
								min: 0,
								max: 100,
								step: 1,
								help: __( '0% is opaque; 100% is fully transparent.', 'shift64-woo-search' ),
								onChange: function ( value ) {
									setAttribute( 'modal_background_transparency', value );
								},
								__nextHasNoMarginBottom: true,
							} )
						)
					)
				);
			},
		} );
	};

	addFilter(
		'blocks.registerBlockType',
		'shift64-woo-search/modal-search-controls',
		addModalSearchControls
	);
}( window.wp ) );
