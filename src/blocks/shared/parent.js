import { createBlock } from '@wordpress/blocks';
import {
	InspectorControls,
	InnerBlocks,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export const CHILDREN = [
	'shift64-woo-search/search-control',
	'shift64-woo-search/search-panel',
];

const LEGACY_KEYS = [
	'placeholder',
	'button',
	'label',
	'trigger_label',
	'close_label',
	'clear_label',
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
	'style',
	'fontSize',
	'textColor',
	'backgroundColor',
	'gradient',
];

const LEGACY_APPEARANCE_KEYS = LEGACY_KEYS.slice( 8 );

function cloneStyle( style ) {
	return style ? JSON.parse( JSON.stringify( style ) ) : {};
}

function standardSupports( attributes, style ) {
	return {
		...( Object.keys( style ).length ? { style } : {} ),
		...( attributes.fontSize ? { fontSize: attributes.fontSize } : {} ),
		...( attributes.textColor ? { textColor: attributes.textColor } : {} ),
		...( attributes.backgroundColor
			? { backgroundColor: attributes.backgroundColor }
			: {} ),
		...( attributes.gradient ? { gradient: attributes.gradient } : {} ),
	};
}

function legacyModalControlStyle( attributes ) {
	const style = {};
	if ( attributes.trigger_icon_color || attributes.trigger_surface_color ) {
		style.color = {
			...( attributes.trigger_icon_color
				? { text: attributes.trigger_icon_color }
				: {} ),
			...( attributes.trigger_style === 'background' &&
			attributes.trigger_surface_color
				? { background: attributes.trigger_surface_color }
				: {} ),
		};
	}
	if (
		attributes.trigger_style === 'outline' &&
		attributes.trigger_surface_color
	) {
		style.border = { color: attributes.trigger_surface_color };
	}
	if ( Number.isInteger( attributes.trigger_border_radius ) ) {
		style.border = {
			...style.border,
			radius: `${ attributes.trigger_border_radius }px`,
		};
	}
	if ( Number.isInteger( attributes.trigger_padding ) ) {
		style.spacing = { padding: `${ attributes.trigger_padding }px` };
	}
	return style;
}

function legacyModalPanelStyle( attributes ) {
	const style = cloneStyle( attributes.style );
	if (
		attributes.modal_background_color ||
		attributes.search_input_text_color
	) {
		style.color = {
			...style.color,
			...( attributes.modal_background_color
				? { background: attributes.modal_background_color }
				: {} ),
			...( attributes.search_input_text_color
				? { text: attributes.search_input_text_color }
				: {} ),
		};
	}
	return style;
}

export function migrateLegacyAttributes( attributes, variant ) {
	const controlSupports = standardSupports(
		attributes,
		variant === 'modal'
			? legacyModalControlStyle( attributes )
			: cloneStyle( attributes.style )
	);
	const panelSupports =
		variant === 'modal'
			? standardSupports(
					attributes,
					legacyModalPanelStyle( attributes )
			  )
			: {};

	return [
		{
			label: attributes.label,
			placeholder: attributes.placeholder,
			submitLabel: attributes.button,
			triggerLabel: attributes.trigger_label,
			triggerIcon: attributes.icon === 'none' ? 'none' : 'search',
			...controlSupports,
		},
		{
			dialogLabel: attributes.label,
			inputLabel: attributes.label,
			placeholder: attributes.placeholder,
			submitLabel: attributes.button,
			closeLabel: attributes.close_label,
			clearLabel: attributes.clear_label,
			noResultsLabel: __( 'No products found', 'shift64-woo-search' ),
			...panelSupports,
		},
	];
}

const createChildren = ( attributes, variant ) =>
	migrateLegacyAttributes( attributes, variant ).map(
		( childAttributes, index ) =>
			createBlock( CHILDREN[ index ], childAttributes )
	);

export function ParentEdit( { attributes, clientId, setAttributes }, variant ) {
	const { replaceInnerBlocks, updateBlockAttributes } =
		useDispatch( 'core/block-editor' );
	const [ showMigrationNotice, setShowMigrationNotice ] = useState( false );
	const legacyMigration = useRef(
		! attributes.instanceId &&
			LEGACY_KEYS.some( ( key ) => attributes[ key ] !== undefined )
	);
	const blocks = useSelect(
		( select ) => select( 'core/block-editor' ).getBlocks( clientId ),
		[ clientId ]
	);
	const instanceId = attributes.instanceId;

	useEffect( () => {
		if ( ! instanceId ) {
			setAttributes( {
				instanceId: `s64ws-${ clientId
					.replace( /[^a-z0-9]/gi, '' )
					.slice( 0, 12 ) }`,
			} );
		}
	}, [ clientId, instanceId, setAttributes ] );

	const template = useMemo(
		() => CHILDREN.map( ( name ) => [ name, {} ] ),
		[]
	);
	const blockProps = useBlockProps( {
		className: `shift64-woo-search-editor-parent is-${ variant }`,
	} );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		allowedBlocks: CHILDREN,
		template,
		templateLock: 'all',
		renderAppender: false,
	} );
	const validStructure =
		blocks.length === 2 &&
		blocks.every( ( block, index ) => block.name === CHILDREN[ index ] );

	useEffect( () => {
		if ( ! legacyMigration.current || ! validStructure ) {
			return;
		}
		const migratedChildren = createChildren( attributes, variant );
		blocks.forEach( ( block, index ) => {
			updateBlockAttributes(
				block.clientId,
				migratedChildren[ index ].attributes
			);
		} );
		const clearedLegacy = Object.fromEntries(
			LEGACY_KEYS.map( ( key ) => [ key, undefined ] )
		);
		setAttributes( {
			...clearedLegacy,
			previewOpen: Boolean( attributes.preview ),
			variant,
		} );
		setShowMigrationNotice(
			LEGACY_APPEARANCE_KEYS.some(
				( key ) => attributes[ key ] !== undefined
			)
		);
		legacyMigration.current = false;
	}, [
		attributes,
		blocks,
		setAttributes,
		updateBlockAttributes,
		validStructure,
		variant,
	] );

	return (
		<>
			{ showMigrationNotice && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Legacy appearance was mapped to the nearest native child styles. Review Control and Panel before saving.',
						'shift64-woo-search'
					) }
				</Notice>
			) }
			{ variant === 'modal' && (
				<InspectorControls>
					<PanelBody
						title={ __( 'Modal preview', 'shift64-woo-search' ) }
					>
						<ToggleControl
							label={ __(
								'Show open dialog in the editor',
								'shift64-woo-search'
							) }
							checked={ Boolean( attributes.previewOpen ) }
							onChange={ ( previewOpen ) =>
								setAttributes( { previewOpen } )
							}
						/>
					</PanelBody>
				</InspectorControls>
			) }
			{ ! validStructure && blocks.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'This search block has an unsupported child structure.',
							'shift64-woo-search'
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ () =>
							replaceInnerBlocks(
								clientId,
								createChildren( attributes, variant ),
								false
							)
						}
					>
						{ __( 'Repair search parts', 'shift64-woo-search' ) }
					</Button>
				</Notice>
			) }
			<div { ...innerBlocksProps } />
		</>
	);
}

export function saveParent() {
	return <InnerBlocks.Content />;
}

export function legacyDeprecations( variant ) {
	return [
		{
			attributes: {
				placeholder: { type: 'string' },
				button: { type: 'string' },
				label: { type: 'string' },
				trigger_label: { type: 'string' },
				close_label: { type: 'string' },
				clear_label: { type: 'string' },
				icon: { type: 'string' },
				preview: { type: 'boolean' },
				trigger_style: { type: 'string' },
				trigger_icon_color: { type: 'string' },
				trigger_icon_hover_color: { type: 'string' },
				trigger_surface_color: { type: 'string' },
				trigger_surface_hover_color: { type: 'string' },
				trigger_border_radius: { type: 'integer' },
				trigger_icon_size: { type: 'integer' },
				trigger_padding: { type: 'integer' },
				trigger_outline_width: { type: 'integer' },
				modal_search_style: { type: 'string' },
				modal_background_color: { type: 'string' },
				modal_background_transparency: { type: 'integer' },
				search_input_text_color: { type: 'string' },
				search_input_background_color: { type: 'string' },
				search_button_color: { type: 'string' },
				search_button_background_color: { type: 'string' },
				search_button_hover_color: { type: 'string' },
				search_button_background_hover_color: { type: 'string' },
				style: { type: 'object' },
				fontSize: { type: 'string' },
				textColor: { type: 'string' },
				backgroundColor: { type: 'string' },
				gradient: { type: 'string' },
			},
			save: () => null,
			migrate: ( attributes ) => [
				{
					instanceId: '',
					previewOpen: Boolean( attributes.preview ),
					variant,
				},
				createChildren( attributes, variant ),
			],
		},
	];
}
