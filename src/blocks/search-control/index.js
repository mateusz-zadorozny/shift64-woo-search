import {
	ContrastChecker,
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
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
	const inlineVars = {};
	const inlineClasses = [];
	[
		[
			attributes.buttonTextColor,
			'has-inline-button-color',
			'--s64ws-inline-button-color',
		],
		[
			attributes.buttonTextHoverColor,
			'has-inline-button-hover-color',
			'--s64ws-inline-button-hover-color',
		],
		[
			attributes.buttonBackgroundColor,
			'has-inline-button-background',
			'--s64ws-inline-button-background',
		],
		[
			attributes.buttonBackgroundHoverColor,
			'has-inline-button-hover-background',
			'--s64ws-inline-button-hover-background',
		],
		[
			attributes.inputTextColor,
			'has-inline-input-color',
			'--s64ws-inline-input-color',
		],
		[
			attributes.inputBackgroundColor,
			'has-inline-input-background',
			'--s64ws-inline-input-background',
		],
	].forEach( ( [ value, className, cssVar ] ) => {
		if ( value ) {
			inlineVars[ cssVar ] = value;
			inlineClasses.push( className );
		}
	} );
	if ( Number.isFinite( attributes.inputRadius ) ) {
		inlineVars[
			'--s64ws-inline-input-radius'
		] = `${ attributes.inputRadius }px`;
		inlineClasses.push( 'has-inline-input-radius' );
	}
	if ( Number.isFinite( attributes.buttonRadius ) ) {
		inlineVars[
			'--s64ws-inline-button-radius'
		] = `${ attributes.buttonRadius }px`;
		inlineClasses.push( 'has-inline-button-radius' );
	}
	if (
		Number.isFinite( attributes.inputPaddingY ) ||
		Number.isFinite( attributes.inputPaddingX )
	) {
		inlineVars[ '--s64ws-inline-input-pad-y' ] = `${
			Number.isFinite( attributes.inputPaddingY )
				? attributes.inputPaddingY
				: 13
		}px`;
		inlineVars[ '--s64ws-inline-input-pad-x' ] = `${
			Number.isFinite( attributes.inputPaddingX )
				? attributes.inputPaddingX
				: 18
		}px`;
		inlineClasses.push( 'has-inline-input-padding' );
	}
	if (
		Number.isFinite( attributes.buttonPaddingY ) ||
		Number.isFinite( attributes.buttonPaddingX )
	) {
		inlineVars[ '--s64ws-inline-button-pad-y' ] = `${
			Number.isFinite( attributes.buttonPaddingY )
				? attributes.buttonPaddingY
				: 13
		}px`;
		inlineVars[ '--s64ws-inline-button-pad-x' ] = `${
			Number.isFinite( attributes.buttonPaddingX )
				? attributes.buttonPaddingX
				: 24
		}px`;
		inlineClasses.push( 'has-inline-button-padding' );
	}
	if ( Number.isFinite( attributes.fieldGap ) && ! attributes.joined ) {
		inlineVars[ '--s64ws-inline-gap' ] = `${ attributes.fieldGap }px`;
		inlineClasses.push( 'has-inline-gap' );
	}
	if ( attributes.joined ) {
		inlineClasses.push( 'is-joined' );
	}
	const sharedPaddingY = Number.isFinite( attributes.inputPaddingY )
		? attributes.inputPaddingY
		: attributes.buttonPaddingY;
	const blockProps = useBlockProps( {
		className: `shift64-woo-search-control is-${ variant } ${
			variant === 'modal' ? '' : inlineClasses.join( ' ' )
		}`,
		style: variant === 'modal' ? triggerVars : inlineVars,
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
				{ variant !== 'modal' && (
					<>
						<PanelColorSettings
							title={ __( 'Input colors', 'shift64-woo-search' ) }
							colorSettings={ [
								{
									label: __( 'Text', 'shift64-woo-search' ),
									value: attributes.inputTextColor,
									onChange: ( inputTextColor ) =>
										setAttributes( { inputTextColor } ),
								},
								{
									label: __(
										'Background',
										'shift64-woo-search'
									),
									value: attributes.inputBackgroundColor,
									onChange: ( inputBackgroundColor ) =>
										setAttributes( {
											inputBackgroundColor,
										} ),
								},
							] }
						>
							<ContrastChecker
								textColor={ attributes.inputTextColor }
								backgroundColor={
									attributes.inputBackgroundColor
								}
							/>
						</PanelColorSettings>
						<PanelColorSettings
							title={ __(
								'Button colors',
								'shift64-woo-search'
							) }
							colorSettings={ [
								{
									label: __( 'Text', 'shift64-woo-search' ),
									value: attributes.buttonTextColor,
									onChange: ( buttonTextColor ) =>
										setAttributes( { buttonTextColor } ),
								},
								{
									label: __(
										'Text (hover)',
										'shift64-woo-search'
									),
									value: attributes.buttonTextHoverColor,
									onChange: ( buttonTextHoverColor ) =>
										setAttributes( {
											buttonTextHoverColor,
										} ),
								},
								{
									label: __(
										'Background',
										'shift64-woo-search'
									),
									value: attributes.buttonBackgroundColor,
									onChange: ( buttonBackgroundColor ) =>
										setAttributes( {
											buttonBackgroundColor,
										} ),
								},
								{
									label: __(
										'Background (hover)',
										'shift64-woo-search'
									),
									value: attributes.buttonBackgroundHoverColor,
									onChange: ( buttonBackgroundHoverColor ) =>
										setAttributes( {
											buttonBackgroundHoverColor,
										} ),
								},
							] }
						>
							<ContrastChecker
								textColor={ attributes.buttonTextColor }
								backgroundColor={
									attributes.buttonBackgroundColor
								}
							/>
							<ContrastChecker
								textColor={ attributes.buttonTextHoverColor }
								backgroundColor={
									attributes.buttonBackgroundHoverColor
								}
							/>
						</PanelColorSettings>
						<PanelBody
							title={ __( 'Shape', 'shift64-woo-search' ) }
							initialOpen={ false }
						>
							<RangeControl
								label={ __(
									'Input radius',
									'shift64-woo-search'
								) }
								value={ attributes.inputRadius }
								onChange={ ( inputRadius ) =>
									setAttributes( { inputRadius } )
								}
								min={ 0 }
								max={ 50 }
								allowReset
							/>
							<RangeControl
								label={ __(
									'Button radius',
									'shift64-woo-search'
								) }
								value={ attributes.buttonRadius }
								onChange={ ( buttonRadius ) =>
									setAttributes( { buttonRadius } )
								}
								min={ 0 }
								max={ 50 }
								allowReset
							/>
							{ attributes.joined ? (
								<RangeControl
									label={ __(
										'Vertical padding',
										'shift64-woo-search'
									) }
									help={ __(
										'Shared by the joined input and button.',
										'shift64-woo-search'
									) }
									value={ sharedPaddingY }
									onChange={ ( paddingY ) =>
										setAttributes( {
											inputPaddingY: paddingY,
											buttonPaddingY: paddingY,
										} )
									}
									min={ 0 }
									max={ 40 }
									allowReset
								/>
							) : (
								<>
									<RangeControl
										label={ __(
											'Input padding — vertical',
											'shift64-woo-search'
										) }
										value={ attributes.inputPaddingY }
										onChange={ ( inputPaddingY ) =>
											setAttributes( { inputPaddingY } )
										}
										min={ 0 }
										max={ 40 }
										allowReset
									/>
									<RangeControl
										label={ __(
											'Button padding — vertical',
											'shift64-woo-search'
										) }
										value={ attributes.buttonPaddingY }
										onChange={ ( buttonPaddingY ) =>
											setAttributes( { buttonPaddingY } )
										}
										min={ 0 }
										max={ 40 }
										allowReset
									/>
									<RangeControl
										label={ __(
											'Gap between input and button',
											'shift64-woo-search'
										) }
										value={ attributes.fieldGap }
										onChange={ ( fieldGap ) =>
											setAttributes( { fieldGap } )
										}
										min={ 0 }
										max={ 40 }
										allowReset
									/>
								</>
							) }
							<RangeControl
								label={ __(
									'Input padding — horizontal',
									'shift64-woo-search'
								) }
								value={ attributes.inputPaddingX }
								onChange={ ( inputPaddingX ) =>
									setAttributes( { inputPaddingX } )
								}
								min={ 0 }
								max={ 60 }
								allowReset
							/>
							<RangeControl
								label={ __(
									'Button padding — horizontal',
									'shift64-woo-search'
								) }
								value={ attributes.buttonPaddingX }
								onChange={ ( buttonPaddingX ) =>
									setAttributes( { buttonPaddingX } )
								}
								min={ 0 }
								max={ 60 }
								allowReset
							/>
							<ToggleControl
								label={ __(
									'Join input and button',
									'shift64-woo-search'
								) }
								help={ __(
									'Fuse them into one bar with a shared edge.',
									'shift64-woo-search'
								) }
								checked={ Boolean( attributes.joined ) }
								onChange={ ( joined ) =>
									setAttributes( {
										joined,
										// Joining shares one vertical padding;
										// align both sides on the way in.
										...( joined &&
										Number.isFinite( sharedPaddingY )
											? {
													inputPaddingY:
														sharedPaddingY,
													buttonPaddingY:
														sharedPaddingY,
											  }
											: {} ),
									} )
								}
							/>
						</PanelBody>
					</>
				) }
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
					>
						<ContrastChecker
							textColor={ attributes.triggerIconColor }
							backgroundColor={
								attributes.triggerBackgroundColor
							}
						/>
						<ContrastChecker
							textColor={ attributes.triggerIconHoverColor }
							backgroundColor={
								attributes.triggerBackgroundHoverColor
							}
						/>
					</PanelColorSettings>
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
							className="shift64-woo-search-field__input"
							aria-label={ label }
							placeholder={ placeholder }
							disabled
						/>
						<button
							type="button"
							className="shift64-woo-search-field__submit wp-element-button"
							disabled
						>
							{ submitLabel }
						</button>
					</div>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
