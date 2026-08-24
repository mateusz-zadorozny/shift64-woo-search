import {
	BaseControl,
	Button,
	CheckboxControl,
	PanelBody,
	RangeControl,
	TabPanel,
	TextControl,
	useBaseControlProps,
} from '@wordpress/components';
import { ColorPalette, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './editor.scss';

// The canonical WooCommerce catalog orderby keys, in the order a fresh block
// offers them. `relevance` is intentionally absent: the renderer swaps
// `menu_order` for it on search results, where a menu order has no meaning.
// Keep in sync with Shift64_Woo_Search_Blocks::render_product_sort_block().
const CANONICAL_OPTIONS = [
	{ key: 'menu_order', label: __( 'Default sorting', 'shift64-woo-search' ) },
	{ key: 'popularity', label: __( 'Sort by popularity', 'shift64-woo-search' ) },
	{
		key: 'rating',
		label: __( 'Sort by average rating', 'shift64-woo-search' ),
	},
	{ key: 'date', label: __( 'Sort by latest', 'shift64-woo-search' ) },
	{
		key: 'price',
		label: __( 'Sort by price: low to high', 'shift64-woo-search' ),
	},
	{
		key: 'price-desc',
		label: __( 'Sort by price: high to low', 'shift64-woo-search' ),
	},
];

const DEFAULT_ORDER = CANONICAL_OPTIONS.map( ( option ) => option.key );

// Core's own States UI is closed to third-party blocks in WP 7.1, so the sort
// pill exposes the same two states the Filter Pill does — the default one and
// the pointer/keyboard one — as tabs over a single set of controls.
const STATE_TABS = [
	{ name: 'default', title: __( 'Default', 'shift64-woo-search' ) },
	{ name: 'hover', title: __( 'Hover', 'shift64-woo-search' ) },
];

function SortColorControl( { label, value, onChange } ) {
	const { baseControlProps, controlProps } = useBaseControlProps( { label } );

	return (
		<BaseControl { ...baseControlProps } __nextHasNoMarginBottom>
			<ColorPalette
				{ ...controlProps }
				value={ value }
				clearable
				onChange={ ( next ) => onChange( next || '' ) }
			/>
		</BaseControl>
	);
}

function SortOptionsPanel( { orderedOptions, labels, setAttributes } ) {
	const setOrder = ( next ) => setAttributes( { orderedOptions: next } );

	const toggleOption = ( key ) => {
		const index = orderedOptions.indexOf( key );
		const next = orderedOptions.slice();
		if ( index > -1 ) {
			next.splice( index, 1 );
		} else {
			next.push( key );
		}
		setOrder( next );
	};

	const moveOption = ( index, direction ) => {
		const target = index + direction;
		if ( target < 0 || target >= orderedOptions.length ) {
			return;
		}
		const next = orderedOptions.slice();
		next.splice( target, 0, next.splice( index, 1 )[ 0 ] );
		setOrder( next );
	};

	const setLabel = ( key, value ) => {
		const next = { ...labels };
		if ( value && value.trim() ) {
			next[ key ] = value;
		} else {
			delete next[ key ];
		}
		setAttributes( { labels: next } );
	};

	return (
		<PanelBody title={ __( 'Sort options', 'shift64-woo-search' ) } initialOpen>
			{ CANONICAL_OPTIONS.map( ( option ) => {
				const index = orderedOptions.indexOf( option.key );
				const isEnabled = index > -1;

				return (
					<div
						key={ option.key }
						className="shift64-woo-search-sort-option"
					>
						<div className="shift64-woo-search-sort-option__row">
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ option.label }
								checked={ isEnabled }
								onChange={ () => toggleOption( option.key ) }
							/>
							{ isEnabled && (
								<div className="shift64-woo-search-sort-option__move">
									<Button
										size="small"
										icon="arrow-up-alt2"
										label={ __(
											'Move up',
											'shift64-woo-search'
										) }
										disabled={ index <= 0 }
										onClick={ () =>
											moveOption( index, -1 )
										}
									/>
									<Button
										size="small"
										icon="arrow-down-alt2"
										label={ __(
											'Move down',
											'shift64-woo-search'
										) }
										disabled={
											index >= orderedOptions.length - 1
										}
										onClick={ () => moveOption( index, 1 ) }
									/>
								</div>
							) }
						</div>
						{ isEnabled && (
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __(
									'Custom label',
									'shift64-woo-search'
								) }
								value={ labels[ option.key ] || '' }
								placeholder={ option.label }
								onChange={ ( value ) =>
									setLabel( option.key, value )
								}
							/>
						) }
					</div>
				);
			} ) }
		</PanelBody>
	);
}

function PillStylePanel( { attributes, setAttributes } ) {
	return (
		<PanelBody title={ __( 'Pill style', 'shift64-woo-search' ) } initialOpen>
			<TabPanel tabs={ STATE_TABS }>
				{ ( tab ) => {
					const hover = 'hover' === tab.name;

					return (
						<>
							<SortColorControl
								label={ __( 'Text', 'shift64-woo-search' ) }
								value={
									hover
										? attributes.text_hover_color
										: attributes.text_color
								}
								onChange={ ( next ) =>
									setAttributes( {
										[ hover
											? 'text_hover_color'
											: 'text_color' ]: next,
									} )
								}
							/>
							<SortColorControl
								label={ __(
									'Background',
									'shift64-woo-search'
								) }
								value={
									hover
										? attributes.background_hover_color
										: attributes.background_color
								}
								onChange={ ( next ) =>
									setAttributes( {
										[ hover
											? 'background_hover_color'
											: 'background_color' ]: next,
									} )
								}
							/>
							<SortColorControl
								label={ __( 'Border', 'shift64-woo-search' ) }
								value={
									hover
										? attributes.border_hover_color
										: attributes.border_color
								}
								onChange={ ( next ) =>
									setAttributes( {
										[ hover
											? 'border_hover_color'
											: 'border_color' ]: next,
									} )
								}
							/>
							{ hover ? (
								<p className="shift64-woo-search-pill-style__hint">
									{ __(
										'Hover styles also apply on keyboard focus. Anything left empty falls back to the default state.',
										'shift64-woo-search'
									) }
								</p>
							) : (
								<>
									<RangeControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Border width',
											'shift64-woo-search'
										) }
										value={ Number(
											attributes.border_width
										) }
										min={ 0 }
										max={ 8 }
										allowReset
										resetFallbackValue={ 1 }
										onChange={ ( next ) =>
											setAttributes( {
												border_width:
													undefined === next
														? 1
														: Number( next ),
											} )
										}
									/>
									<RangeControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Border radius',
											'shift64-woo-search'
										) }
										value={ Math.min(
											Number( attributes.border_radius ),
											50
										) }
										min={ 0 }
										max={ 50 }
										allowReset
										resetFallbackValue={ 9999 }
										onChange={ ( next ) =>
											setAttributes( {
												border_radius:
													undefined === next
														? 9999
														: Number( next ),
											} )
										}
									/>
								</>
							) }
						</>
					);
				} }
			</TabPanel>
		</PanelBody>
	);
}

function styleVars( attributes ) {
	const vars = {
		'--s64ws-sort-text': attributes.text_color,
		'--s64ws-sort-text-hover': attributes.text_hover_color,
		'--s64ws-sort-bg': attributes.background_color,
		'--s64ws-sort-bg-hover': attributes.background_hover_color,
		'--s64ws-sort-border': attributes.border_color,
		'--s64ws-sort-border-hover': attributes.border_hover_color,
		'--s64ws-sort-radius': `${ Number( attributes.border_radius ) || 0 }px`,
		'--s64ws-sort-border-width': `${
			Number( attributes.border_width ) || 0
		}px`,
	};

	return Object.fromEntries(
		Object.entries( vars ).filter( ( [ , value ] ) => !! value )
	);
}

function Edit( { attributes, setAttributes } ) {
	const orderedOptions =
		Array.isArray( attributes.orderedOptions ) &&
		attributes.orderedOptions.length > 0
			? attributes.orderedOptions
			: DEFAULT_ORDER;
	const labels =
		attributes.labels && 'object' === typeof attributes.labels
			? attributes.labels
			: {};

	const activeKey = orderedOptions[ 0 ];
	const canonical = CANONICAL_OPTIONS.find(
		( option ) => option.key === activeKey
	);
	const activeLabel =
		labels[ activeKey ] || ( canonical ? canonical.label : activeKey );

	const blockProps = useBlockProps( {
		className: 'shift64-woo-search-product-sort',
		style: styleVars( attributes ),
	} );

	return (
		<>
			<InspectorControls>
				<SortOptionsPanel
					orderedOptions={ orderedOptions }
					labels={ labels }
					setAttributes={ setAttributes }
				/>
				<PillStylePanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="shift64-woo-search-product-sort__pill">
					<span className="shift64-woo-search-product-sort__trigger">
						<span className="shift64-woo-search-product-sort__label">
							{ activeLabel }
						</span>
						<span
							className="shift64-woo-search-product-sort__chevron"
							aria-hidden="true"
						>
							<svg
								width="12"
								height="12"
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								strokeWidth="2.5"
								strokeLinecap="round"
								strokeLinejoin="round"
							>
								<polyline points="6 9 12 15 18 9" />
							</svg>
						</span>
					</span>
				</div>
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit } );
