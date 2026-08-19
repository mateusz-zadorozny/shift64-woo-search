( function ( wp ) {
	'use strict';

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ColorPaletteControl = wp.blockEditor.ColorPaletteControl;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Disabled = wp.components.Disabled;
	var useDisabled = wp.compose && wp.compose.useDisabled;
	var Button = wp.components.Button;
	var CheckboxControl = wp.components.CheckboxControl;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var addFilter = wp.hooks.addFilter;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;

	var previewBlocks = [
		'shift64-woo-search/search',
		'shift64-woo-search/modal-search',
		'shift64-woo-search/product-sort',
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

	var addProductSortControls = function ( settings, name ) {
		if ( name !== 'shift64-woo-search/product-sort' ) {
			return settings;
		}

		var ServerSideEdit = settings.edit;

		var canonicalOptions = [
			{ key: 'menu_order', defaultLabel: __( 'Default sorting', 'shift64-woo-search' ) },
			{ key: 'popularity', defaultLabel: __( 'Sort by popularity', 'shift64-woo-search' ) },
			{ key: 'rating', defaultLabel: __( 'Sort by average rating', 'shift64-woo-search' ) },
			{ key: 'date', defaultLabel: __( 'Sort by latest', 'shift64-woo-search' ) },
			{ key: 'price', defaultLabel: __( 'Sort by price: low to high', 'shift64-woo-search' ) },
			{ key: 'price-desc', defaultLabel: __( 'Sort by price: high to low', 'shift64-woo-search' ) },
		];

		return Object.assign( {}, settings, {
			edit: function ( props ) {
				var attributes = props.attributes;
				var orderedOptions = Array.isArray( attributes.orderedOptions ) && attributes.orderedOptions.length > 0
					? attributes.orderedOptions
					: [ 'menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc' ];
				var labels = Object.assign( {}, attributes.labels || {} );

				var setOrderedOptions = function ( newOptions ) {
					props.setAttributes( { orderedOptions: newOptions } );
				};

				var setLabel = function ( key, val ) {
					var nextLabels = Object.assign( {}, labels );
					if ( val && val.trim() ) {
						nextLabels[ key ] = val;
					} else {
						delete nextLabels[ key ];
					}
					props.setAttributes( { labels: nextLabels } );
				};

				var toggleOption = function ( key ) {
					var index = orderedOptions.indexOf( key );
					var next = orderedOptions.slice();
					if ( index > -1 ) {
						next.splice( index, 1 );
					} else {
						next.push( key );
					}
					setOrderedOptions( next );
				};

				var moveOption = function ( index, direction ) {
					var targetIndex = index + direction;
					if ( targetIndex < 0 || targetIndex >= orderedOptions.length ) {
						return;
					}
					var next = orderedOptions.slice();
					var item = next.splice( index, 1 )[ 0 ];
					next.splice( targetIndex, 0, item );
					setOrderedOptions( next );
				};

				return createElement(
					Fragment,
					null,
					createElement(
						InspectorControls,
						null,
						createElement(
							PanelBody,
							{
								title: __( 'Sort options', 'shift64-woo-search' ),
								initialOpen: true,
							},
							canonicalOptions.map( function ( item ) {
								var isChecked = orderedOptions.indexOf( item.key ) > -1;
								var currentIndex = orderedOptions.indexOf( item.key );
								var customLabel = labels[ item.key ] || '';

								return createElement(
									'div',
									{
										key: item.key,
										style: {
											marginBottom: '16px',
											paddingBottom: '12px',
											borderBottom: '1px solid #e0e0e0',
										},
									},
									createElement(
										'div',
										{
											style: {
												display: 'flex',
												alignItems: 'center',
												justifyContent: 'space-between',
											},
										},
										createElement( CheckboxControl, {
											label: item.defaultLabel,
											checked: isChecked,
											onChange: function () {
												toggleOption( item.key );
											},
											__nextHasNoMarginBottom: true,
										} ),
										isChecked && createElement(
											'div',
											{ style: { display: 'flex', gap: '4px' } },
											createElement( Button, {
												icon: 'arrow-up-alt2',
												label: __( 'Move up', 'shift64-woo-search' ),
												disabled: currentIndex <= 0,
												isSmall: true,
												onClick: function () {
													moveOption( currentIndex, -1 );
												},
											} ),
											createElement( Button, {
												icon: 'arrow-down-alt2',
												label: __( 'Move down', 'shift64-woo-search' ),
												disabled: currentIndex >= orderedOptions.length - 1,
												isSmall: true,
												onClick: function () {
													moveOption( currentIndex, 1 );
												},
											} )
										)
									),
									isChecked && createElement( TextControl, {
										label: __( 'Custom label', 'shift64-woo-search' ),
										value: customLabel,
										placeholder: item.defaultLabel,
										onChange: function ( val ) {
											setLabel( item.key, val );
										},
										__nextHasNoMarginBottom: true,
									} )
								);
							} )
						)
					),
					createElement( ServerSideEdit, props )
				);
			},
		} );
	};

	addFilter(
		'blocks.registerBlockType',
		'shift64-woo-search/product-sort-controls',
		addProductSortControls
	);
}( window.wp ) );
