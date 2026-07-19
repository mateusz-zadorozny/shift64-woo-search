( function ( wp ) {
	'use strict';

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ColorPaletteControl = wp.blockEditor.ColorPaletteControl;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var addFilter = wp.hooks.addFilter;
	var __ = wp.i18n.__;

	var addModalSearchControls = function ( settings, name ) {
		if ( name !== 'shift64-woo-search/modal-search' ) {
			return settings;
		}

		var ServerSideEdit = settings.edit;
		var modalSupports = Object.assign( {}, settings.supports, {
			color: false,
		} );

		return Object.assign( {}, settings, {
			supports: modalSupports,
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
				var setSearchBoxColor = function ( property, value ) {
					var style = Object.assign( {}, attributes.style || {} );
					style.color = Object.assign( {}, style.color || {} );
					if ( value ) {
						style.color[ property ] = value;
					} else {
						delete style.color[ property ];
					}
					props.setAttributes( { style: style } );
				};
				var setSearchButtonColor = function ( property, value ) {
					var style = Object.assign( {}, attributes.style || {} );
					style.elements = Object.assign( {}, style.elements || {} );
					style.elements.button = Object.assign( {}, style.elements.button || {} );
					style.elements.button.color = Object.assign( {}, style.elements.button.color || {} );
					if ( value ) {
						style.elements.button.color[ property ] = value;
					} else {
						delete style.elements.button.color[ property ];
					}
					props.setAttributes( { style: style } );
				};
				var style = attributes.style || {};
				var searchBoxColors = style.color || {};
				var buttonColors = style.elements && style.elements.button && style.elements.button.color
					? style.elements.button.color
					: {};

				return createElement(
					Fragment,
					null,
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
								title: __( 'Color of search box and button', 'shift64-woo-search' ),
								initialOpen: true,
							},
							colorControl(
								__( 'Search box text', 'shift64-woo-search' ),
								searchBoxColors.text,
								function ( value ) {
									setSearchBoxColor( 'text', value );
								}
							),
							colorControl(
								__( 'Search box background', 'shift64-woo-search' ),
								searchBoxColors.background,
								function ( value ) {
									setSearchBoxColor( 'background', value );
								},
								true
							),
							colorControl(
								__( 'Search button text', 'shift64-woo-search' ),
								buttonColors.text,
								function ( value ) {
									setSearchButtonColor( 'text', value );
								}
							),
							colorControl(
								__( 'Search button background', 'shift64-woo-search' ),
								buttonColors.background,
								function ( value ) {
									setSearchButtonColor( 'background', value );
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
							} ),
							createElement( ToggleControl, {
								label: __( 'Show preview in editor', 'shift64-woo-search' ),
								checked: Boolean( attributes.preview ),
								onChange: function ( value ) {
									setAttribute( 'preview', value );
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
