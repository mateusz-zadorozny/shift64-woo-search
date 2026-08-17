import {
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Edit( { attributes, context, setAttributes } ) {
	const variant = context[ 'shift64WooSearch/variant' ] || 'inline';
	const label =
		attributes.label || __( 'Search products', 'shift64-woo-search' );
	const placeholder =
		attributes.placeholder ||
		__( 'Search products…', 'shift64-woo-search' );
	const submitLabel =
		attributes.submitLabel || __( 'Search', 'shift64-woo-search' );
	const triggerLabel =
		attributes.triggerLabel ||
		__( 'Open product search', 'shift64-woo-search' );
	const triggerVars = {};
	if ( attributes.triggerIconColor ) {
		triggerVars[ '--s64ws-trigger-icon-color' ] =
			attributes.triggerIconColor;
	}
	if ( attributes.triggerIconHoverColor ) {
		triggerVars[ '--s64ws-trigger-icon-hover-color' ] =
			attributes.triggerIconHoverColor;
	}
	if ( attributes.triggerBackgroundColor ) {
		triggerVars[ '--s64ws-trigger-surface-color' ] =
			attributes.triggerBackgroundColor;
	}
	if ( attributes.triggerBackgroundHoverColor ) {
		triggerVars[ '--s64ws-trigger-surface-hover-color' ] =
			attributes.triggerBackgroundHoverColor;
	}
	const blockProps = useBlockProps( {
		className: `shift64-woo-search-control is-${ variant }`,
		style: variant === 'modal' ? triggerVars : undefined,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Search copy', 'shift64-woo-search' ) }>
					<TextControl
						label={ __( 'Accessible label', 'shift64-woo-search' ) }
						value={ label }
						onChange={ ( nextLabel ) =>
							setAttributes( { label: nextLabel } )
						}
					/>
					<TextControl
						label={ __( 'Placeholder', 'shift64-woo-search' ) }
						value={ placeholder }
						onChange={ ( nextPlaceholder ) =>
							setAttributes( { placeholder: nextPlaceholder } )
						}
					/>
					<TextControl
						label={ __( 'Submit label', 'shift64-woo-search' ) }
						value={ submitLabel }
						onChange={ ( nextSubmitLabel ) =>
							setAttributes( { submitLabel: nextSubmitLabel } )
						}
					/>
					{ variant === 'modal' && (
						<>
							<TextControl
								label={ __(
									'Trigger label',
									'shift64-woo-search'
								) }
								value={ triggerLabel }
								onChange={ ( nextTriggerLabel ) =>
									setAttributes( {
										triggerLabel: nextTriggerLabel,
									} )
								}
							/>
							<SelectControl
								label={ __(
									'Trigger icon',
									'shift64-woo-search'
								) }
								value={ attributes.triggerIcon }
								options={ [
									{
										label: __(
											'Search',
											'shift64-woo-search'
										),
										value: 'search',
									},
									{
										label: __(
											'None',
											'shift64-woo-search'
										),
										value: 'none',
									},
								] }
								onChange={ ( triggerIcon ) =>
									setAttributes( { triggerIcon } )
								}
							/>
						</>
					) }
				</PanelBody>
				{ variant === 'modal' && (
					<PanelColorSettings
						title={ __( 'Trigger colors', 'shift64-woo-search' ) }
						colorSettings={ [
							{
								label: __(
									'Text / icon',
									'shift64-woo-search'
								),
								value: attributes.triggerIconColor,
								onChange: ( triggerIconColor ) =>
									setAttributes( { triggerIconColor } ),
							},
							{
								label: __(
									'Text / icon (hover)',
									'shift64-woo-search'
								),
								value: attributes.triggerIconHoverColor,
								onChange: ( triggerIconHoverColor ) =>
									setAttributes( { triggerIconHoverColor } ),
							},
							{
								label: __( 'Background', 'shift64-woo-search' ),
								value: attributes.triggerBackgroundColor,
								onChange: ( triggerBackgroundColor ) =>
									setAttributes( { triggerBackgroundColor } ),
							},
							{
								label: __(
									'Background (hover)',
									'shift64-woo-search'
								),
								value: attributes.triggerBackgroundHoverColor,
								onChange: ( triggerBackgroundHoverColor ) =>
									setAttributes( {
										triggerBackgroundHoverColor,
									} ),
							},
						] }
					/>
				) }
			</InspectorControls>
			<div { ...blockProps }>
				{ variant === 'modal' ? (
					<button
						type="button"
						className={
							attributes.triggerIcon === 'none'
								? 'shift64-woo-search-modal__trigger shift64-woo-search-modal__trigger--text'
								: 'shift64-woo-search-modal__trigger'
						}
					>
						{ attributes.triggerIcon !== 'none' && (
							<svg
								className="shift64-woo-search-icon shift64-woo-search-icon--search"
								aria-hidden="true"
								focusable="false"
								viewBox="0 0 640 640"
								width="24"
								height="24"
								fill="currentColor"
							>
								<path d="M480 272C480 317.9 465.1 360.3 440 394.7L566.6 521.4C579.1 533.9 579.1 554.2 566.6 566.7C554.1 579.2 533.8 579.2 521.3 566.7L394.7 440C360.3 465.1 317.9 480 272 480C157.1 480 64 386.9 64 272C64 157.1 157.1 64 272 64C386.9 64 480 157.1 480 272zM272 416C351.5 416 416 351.5 416 272C416 192.5 351.5 128 272 128C192.5 128 128 192.5 128 272C128 351.5 192.5 416 272 416z" />
							</svg>
						) }
						<span
							className={
								attributes.triggerIcon === 'none'
									? 'shift64-woo-search-modal__trigger-label'
									: 'shift64-woo-search-modal__trigger-label screen-reader-text'
							}
						>
							{ triggerLabel }
						</span>
					</button>
				) : (
					<div className="shift64-woo-search-field">
						<span className="screen-reader-text">{ label }</span>
						<input
							type="search"
							aria-label={ label }
							placeholder={ placeholder }
							disabled
						/>
						<button type="button" disabled>
							{ submitLabel }
						</button>
					</div>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
