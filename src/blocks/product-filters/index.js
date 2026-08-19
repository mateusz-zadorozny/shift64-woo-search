import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { createBlock, registerBlockType } from '@wordpress/blocks';
import {
	ExternalLink,
	Notice,
	PanelBody,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { groupFacets, useEditorFacets } from '../shared/facets';
import './editor.scss';
import './style.scss';

const PILL_BLOCK = 'shift64-woo-search/filter-pill';

function Edit( { attributes, clientId, setAttributes } ) {
	const { showClearAll, clearAllLabel, instanceId } = attributes;
	const { replaceInnerBlocks } = useDispatch( 'core/block-editor' );
	const pillCount = useSelect(
		( select ) =>
			select( 'core/block-editor' ).getBlockCount( clientId ),
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
	} );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		allowedBlocks: [ PILL_BLOCK ],
		renderAppender: InnerBlocks.ButtonBlockAppender,
	} );

	const showSetupGuidance =
		! isLoading && ! error && ready.length === 0;

	return (
		<>
			<InspectorControls>
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
							label={ __( 'Clear all label', 'shift64-woo-search' ) }
							value={ clearAllLabel }
							placeholder={ __( 'Clear all', 'shift64-woo-search' ) }
							onChange={ ( next ) =>
								setAttributes( { clearAllLabel: next } )
							}
						/>
					) }
				</PanelBody>
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
							{ __( 'Open Facets settings', 'shift64-woo-search' ) }
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
