import {
	Button,
	CheckboxControl,
	PanelBody,
	TextControl,
} from '@wordpress/components';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { pillStyleToVars } from '../shared/pill-style';
import { StylePanel } from '../shared/pill-style-panel';
import './editor.scss';
import './style.scss';

// The canonical WooCommerce catalog orderby keys, in the order a fresh block
// offers them. `relevance` is intentionally absent: the renderer swaps
// `menu_order` for it on search results, where a menu order has no meaning.
// Keep in sync with Shift64_Woo_Search_Blocks::render_product_sort_block().
const CANONICAL_OPTIONS = [
	{ key: 'menu_order', label: __( 'Default sorting', 'shift64-woo-search' ) },
	{
		key: 'popularity',
		label: __( 'Sort by popularity', 'shift64-woo-search' ),
	},
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
		<PanelBody
			title={ __( 'Sort options', 'shift64-woo-search' ) }
			initialOpen
		>
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

	// The preview resolves the same tokens the renderer writes, so the editor
	// and the storefront cannot drift apart on a merchant's colours.
	const blockProps = useBlockProps( {
		className:
			'shift64-woo-search-product-sort shift64-woo-search-pill is-editor-preview',
		style: pillStyleToVars( attributes.pillStyle ),
	} );

	return (
		<>
			<InspectorControls>
				<SortOptionsPanel
					orderedOptions={ orderedOptions }
					labels={ labels }
					setAttributes={ setAttributes }
				/>
				<StylePanel
					title={ __( 'Pill', 'shift64-woo-search' ) }
					styleValue={ attributes.pillStyle }
					onChange={ ( next ) =>
						setAttributes( { pillStyle: next } )
					}
				/>
			</InspectorControls>
			<div { ...blockProps }>
				<span className="shift64-woo-search-pill__trigger">
					<span className="shift64-woo-search-pill__label">
						{ activeLabel }
					</span>
					<span
						className="shift64-woo-search-pill__chevron"
						aria-hidden="true"
					/>
				</span>
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit } );
