import {
	ColorPalette,
	FontSizePicker,
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { createBlock, registerBlockType } from '@wordpress/blocks';
import {
	BaseControl,
	ExternalLink,
	Notice,
	PanelBody,
	RadioControl,
	RangeControl,
	SelectControl,
	TabPanel,
	TextControl,
	ToggleControl,
	useBaseControlProps,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { groupFacets, useEditorFacets } from '../shared/facets';
import {
	actionStyleToVars,
	pillStyleToVars,
	setActionStyleValue,
	setPillStyleValue,
} from '../shared/pill-style';
import './editor.scss';
import './style.scss';

const PILL_BLOCK = 'shift64-woo-search/filter-pill';

// Core's own States UI is closed to third-party blocks in WP 7.1, so the pill
// exposes the same two states core surfaces for buttons — the default one and
// the pointer/keyboard one — as tabs over a single set of controls.
const STATE_TABS = [
	{ name: 'default', title: __( 'Default', 'shift64-woo-search' ) },
	{ name: 'hover', title: __( 'Hover', 'shift64-woo-search' ) },
];

// One filter row, one behavior: these settings describe how every pill in this
// container presents its options, so they live here and reach the pills as
// block context rather than being retyped per pill.
function PillOptionsPanel( { attributes, setAttributes } ) {
	const {
		selectionMode,
		showCounts,
		hideEmpty,
		orderBy,
		maxOptions,
		applyLabel,
		clearLabel,
	} = attributes;

	return (
		<PanelBody
			title={ __( 'Filter options', 'shift64-woo-search' ) }
			initialOpen
		>
			<RadioControl
				label={ __( 'Selection', 'shift64-woo-search' ) }
				selected={ selectionMode }
				options={ [
					{
						label: __( 'Multiple choices', 'shift64-woo-search' ),
						value: 'multiple',
					},
					{
						label: __( 'Single choice', 'shift64-woo-search' ),
						value: 'single',
					},
				] }
				onChange={ ( next ) =>
					setAttributes( { selectionMode: next } )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Show result counts', 'shift64-woo-search' ) }
				checked={ Boolean( showCounts ) }
				onChange={ ( next ) => setAttributes( { showCounts: next } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Hide options without results',
					'shift64-woo-search'
				) }
				help={ __(
					'Selected options always stay visible.',
					'shift64-woo-search'
				) }
				checked={ Boolean( hideEmpty ) }
				onChange={ ( next ) => setAttributes( { hideEmpty: next } ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Order options by', 'shift64-woo-search' ) }
				value={ orderBy }
				options={ [
					{
						label: __(
							'Result count (descending)',
							'shift64-woo-search'
						),
						value: 'count-desc',
					},
					{
						label: __( 'Name (A → Z)', 'shift64-woo-search' ),
						value: 'name-asc',
					},
					{
						label: __( 'Name (Z → A)', 'shift64-woo-search' ),
						value: 'name-desc',
					},
				] }
				onChange={ ( next ) => setAttributes( { orderBy: next } ) }
			/>
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __(
					'Maximum options (0 shows all)',
					'shift64-woo-search'
				) }
				value={ maxOptions }
				min={ 0 }
				max={ 100 }
				onChange={ ( next ) => setAttributes( { maxOptions: next } ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Apply label', 'shift64-woo-search' ) }
				value={ applyLabel }
				placeholder={ __( 'Apply', 'shift64-woo-search' ) }
				onChange={ ( next ) => setAttributes( { applyLabel: next } ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Clear label', 'shift64-woo-search' ) }
				value={ clearLabel }
				placeholder={ __( 'Clear', 'shift64-woo-search' ) }
				onChange={ ( next ) => setAttributes( { clearLabel: next } ) }
			/>
		</PanelBody>
	);
}

// A px integer round-trips through the stored CSS length without a unit
// control, and matches the border widths core's own controls offer.
function pxToNumber( value ) {
	const parsed = parseInt( value, 10 );
	return Number.isNaN( parsed ) ? undefined : parsed;
}

function PillColorControl( { label, value, onChange } ) {
	const { baseControlProps, controlProps } = useBaseControlProps( { label } );

	return (
		<BaseControl { ...baseControlProps } __nextHasNoMarginBottom>
			<ColorPalette
				{ ...controlProps }
				value={ value }
				clearable
				onChange={ ( next ) => onChange( next ) }
			/>
		</BaseControl>
	);
}

function StylePanel( {
	title,
	styleValue,
	onChange,
	setValue = setPillStyleValue,
} ) {
	const write = ( path, value ) =>
		onChange( setValue( styleValue, path, value ) );

	const read = ( path ) =>
		path.reduce(
			( carry, key ) =>
				carry && typeof carry === 'object' ? carry[ key ] : undefined,
			styleValue
		);

	return (
		<PanelBody title={ title } initialOpen>
			<TabPanel tabs={ STATE_TABS }>
				{ ( tab ) => {
					const prefix = 'hover' === tab.name ? [ ':hover' ] : [];

					return (
						<>
							<PillColorControl
								label={ __( 'Text', 'shift64-woo-search' ) }
								value={ read( [ ...prefix, 'color', 'text' ] ) }
								onChange={ ( next ) =>
									write(
										[ ...prefix, 'color', 'text' ],
										next
									)
								}
							/>
							<PillColorControl
								label={ __(
									'Background',
									'shift64-woo-search'
								) }
								value={ read( [
									...prefix,
									'color',
									'background',
								] ) }
								onChange={ ( next ) =>
									write(
										[ ...prefix, 'color', 'background' ],
										next
									)
								}
							/>
							<PillColorControl
								label={ __( 'Border', 'shift64-woo-search' ) }
								value={ read( [
									...prefix,
									'border',
									'color',
								] ) }
								onChange={ ( next ) =>
									write(
										[ ...prefix, 'border', 'color' ],
										next
									)
								}
							/>
							{ 'hover' === tab.name ? (
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
										value={ pxToNumber(
											read( [ 'border', 'width' ] )
										) }
										min={ 0 }
										max={ 8 }
										allowReset
										onChange={ ( next ) =>
											write(
												[ 'border', 'width' ],
												undefined === next
													? ''
													: `${ next }px`
											)
										}
									/>
									<RangeControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Border radius',
											'shift64-woo-search'
										) }
										value={ pxToNumber(
											read( [ 'border', 'radius' ] )
										) }
										min={ 0 }
										max={ 50 }
										allowReset
										onChange={ ( next ) =>
											write(
												[ 'border', 'radius' ],
												undefined === next
													? ''
													: `${ next }px`
											)
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

function PillStylePanel( { pillStyle, setAttributes } ) {
	return (
		<StylePanel
			title={ __( 'Pills', 'shift64-woo-search' ) }
			styleValue={ pillStyle }
			onChange={ ( next ) => setAttributes( { pillStyle: next } ) }
		/>
	);
}

function ActionStylePanel( { actionStyle, setAttributes } ) {
	const read = ( path ) =>
		path.reduce(
			( carry, key ) =>
				carry && typeof carry === 'object' ? carry[ key ] : undefined,
			actionStyle
		);
	const write = ( path, value ) =>
		setAttributes( {
			actionStyle: setActionStyleValue( actionStyle, path, value ),
		} );

	return (
		<PanelBody
			title={ __( 'Mobile Action buttons', 'shift64-woo-search' ) }
			initialOpen
		>
			<FontSizePicker
				value={ read( [ 'typography', 'fontSize' ] ) }
				onChange={ ( next ) =>
					write(
						[ 'typography', 'fontSize' ],
						undefined === next
							? ''
							: 'number' === typeof next
								? `${ next }px`
								: next
					)
				}
			/>
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Border radius', 'shift64-woo-search' ) }
				value={ pxToNumber( read( [ 'border', 'radius' ] ) ) }
				min={ 0 }
				max={ 50 }
				allowReset
				onChange={ ( next ) =>
					write(
						[ 'border', 'radius' ],
						undefined === next ? '' : `${ next }px`
					)
				}
			/>
		</PanelBody>
	);
}

function Edit( { attributes, clientId, setAttributes } ) {
	const {
		showClearAll,
		clearAllLabel,
		instanceId,
		pillStyle,
		actionStyle,
		previewOpen,
	} = attributes;
	const { replaceInnerBlocks } = useDispatch( 'core/block-editor' );
	const pillCount = useSelect(
		( select ) => select( 'core/block-editor' ).getBlockCount( clientId ),
		[ clientId ]
	);
	const { payload, isLoading, error } = useEditorFacets();
	const { ready } = groupFacets( payload.facets );
	// A parent without an instanceId was inserted this session (saved content
	// always carries one) — only such a fresh parent gets the default
	// Category pill, so a deliberately emptied parent stays empty.
	const freshInsert = useRef( ! instanceId );

	useEffect( () => {
		if ( ! instanceId ) {
			setAttributes( {
				instanceId: `s64ws-filters-${ clientId
					.replace( /[^a-z0-9]/gi, '' )
					.slice( 0, 12 ) }`,
			} );
		}
	}, [ clientId, instanceId, setAttributes ] );

	useEffect( () => {
		if ( ! freshInsert.current || isLoading || pillCount > 0 ) {
			return;
		}
		freshInsert.current = false;
		const category = ready.find( ( facet ) => facet.key === 'product_cat' );
		if ( category ) {
			replaceInnerBlocks(
				clientId,
				[ createBlock( PILL_BLOCK, { facet: category.key } ) ],
				false
			);
		}
	}, [ clientId, isLoading, pillCount, ready, replaceInnerBlocks ] );

	const blockProps = useBlockProps( {
		className: 'shift64-woo-search-product-filters is-editor-preview',
		// The pill tokens ride on the parent wrapper in the editor exactly as
		// they do on the frontend, so the preview and the storefront resolve
		// the same custom properties.
		style: {
			...pillStyleToVars( pillStyle ),
			...actionStyleToVars( actionStyle ),
		},
	} );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		allowedBlocks: [ PILL_BLOCK ],
		renderAppender: InnerBlocks.ButtonBlockAppender,
	} );

	const showSetupGuidance = ! isLoading && ! error && ready.length === 0;

	return (
		<>
			<InspectorControls>
				<PillOptionsPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
				<PanelBody title={ __( 'Clear all', 'shift64-woo-search' ) }>
					<ToggleControl
						label={ __(
							'Show a Clear all control',
							'shift64-woo-search'
						) }
						help={ __(
							'Rendered only while at least one of these filters is active.',
							'shift64-woo-search'
						) }
						checked={ Boolean( showClearAll ) }
						onChange={ ( next ) =>
							setAttributes( { showClearAll: next } )
						}
					/>
					{ showClearAll && (
						<TextControl
							label={ __(
								'Clear all label',
								'shift64-woo-search'
							) }
							value={ clearAllLabel }
							placeholder={ __(
								'Clear all',
								'shift64-woo-search'
							) }
							onChange={ ( next ) =>
								setAttributes( { clearAllLabel: next } )
							}
						/>
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'Editor preview', 'shift64-woo-search' ) }
				>
					<ToggleControl
						label={ __(
							'Show option lists',
							'shift64-woo-search'
						) }
						help={ __(
							'Show each pill’s options while editing. This changes the editor preview only.',
							'shift64-woo-search'
						) }
						checked={ Boolean( previewOpen ) }
						onChange={ ( next ) =>
							setAttributes( { previewOpen: next } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			{ /* Appearance belongs in the Styles tab next to the container's
			     own design tools, not split across two tabs. */ }
			<InspectorControls group="styles">
				<PillStylePanel
					pillStyle={ pillStyle }
					setAttributes={ setAttributes }
				/>
				<ActionStylePanel
					actionStyle={ actionStyle }
					setAttributes={ setAttributes }
				/>
			</InspectorControls>
			{ showSetupGuidance && (
				<Notice status="info" isDismissible={ false }>
					<p>
						{ payload.rebuildRequired
							? __(
									'Enable facets in Shift64 Results → Facets, rebuild the index, then return here.',
									'shift64-woo-search'
							  )
							: __(
									'Enable facets in Shift64 Results → Facets, then return here.',
									'shift64-woo-search'
							  ) }
					</p>
					{ payload.settingsUrl && (
						<ExternalLink href={ payload.settingsUrl }>
							{ __(
								'Open Facets settings',
								'shift64-woo-search'
							) }
						</ExternalLink>
					) }
				</Notice>
			) }
			{ error && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Facet availability could not be loaded. Saved pills keep their configuration.',
						'shift64-woo-search'
					) }
				</Notice>
			) }
			<div { ...innerBlocksProps } />
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
