import {
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function ResultsPreview() {
	return (
		<div
			className="shift64-woo-search-results shift64-woo-search-results--visible shift64-woo-search-panel__editor-results"
			role="presentation"
		>
			<div className="shift64-woo-search-results__scroll">
				<div className="shift64-woo-search-section shift64-woo-search-section--products">
					<div className="shift64-woo-search-section__header">
						{ __( 'Products', 'shift64-woo-search' ) }
					</div>
					<div className="shift64-woo-search-result shift64-woo-search-result--product">
						<div className="shift64-woo-search-result__content">
							<span className="shift64-woo-search-result__title">
								{ __(
									'Example product',
									'shift64-woo-search'
								) }
							</span>
							<div className="shift64-woo-search-result__meta">
								<span className="shift64-woo-search-result__sku">
									DEMO-001
								</span>
								<span className="shift64-woo-search-result__meta-sep">
									|
								</span>
								<span className="shift64-woo-search-result__category">
									{ __(
										'Example category',
										'shift64-woo-search'
									) }
								</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}

function EditorPreview( { copy, variant } ) {
	if ( variant === 'inline' ) {
		return <ResultsPreview />;
	}

	return (
		<div
			className="shift64-woo-search-panel__editor-dialog"
			role="presentation"
		>
			<button
				type="button"
				className="shift64-woo-search-modal__close"
				aria-label={ copy.closeLabel }
				disabled
			>
				×
			</button>
			<div className="shift64-woo-search-field">
				<span className="screen-reader-text">{ copy.inputLabel }</span>
				<input
					type="search"
					aria-label={ copy.inputLabel }
					placeholder={ copy.placeholder }
					disabled
				/>
				<button
					type="button"
					className="shift64-woo-search-field__submit wp-element-button"
					disabled
				>
					{ copy.submitLabel }
				</button>
			</div>
			<ResultsPreview />
		</div>
	);
}

function Edit( { attributes, context, isSelected, setAttributes } ) {
	const variant = context[ 'shift64WooSearch/variant' ] || 'inline';
	const previewOpen = Boolean( context[ 'shift64WooSearch/previewOpen' ] );
	const copy = {
		dialogLabel:
			attributes.dialogLabel ||
			__( 'Search products', 'shift64-woo-search' ),
		inputLabel:
			attributes.inputLabel ||
			__( 'Search products', 'shift64-woo-search' ),
		placeholder:
			attributes.placeholder ||
			__( 'Search products…', 'shift64-woo-search' ),
		submitLabel:
			attributes.submitLabel || __( 'Search', 'shift64-woo-search' ),
		closeLabel:
			attributes.closeLabel || __( 'Close search', 'shift64-woo-search' ),
		clearLabel:
			attributes.clearLabel || __( 'Clear search', 'shift64-woo-search' ),
		noResultsLabel:
			attributes.noResultsLabel ||
			__( 'No products found', 'shift64-woo-search' ),
	};
	const buttonColorMap = [
		[
			attributes.buttonTextColor,
			'has-panel-button-color',
			'--s64ws-panel-button-color',
		],
		[
			attributes.buttonTextHoverColor,
			'has-panel-button-hover-color',
			'--s64ws-panel-button-hover-color',
		],
		[
			attributes.buttonBackgroundColor,
			'has-panel-button-background',
			'--s64ws-panel-button-background',
		],
		[
			attributes.buttonBackgroundHoverColor,
			'has-panel-button-hover-background',
			'--s64ws-panel-button-hover-background',
		],
	];
	const buttonVars = {};
	const buttonClasses = [];
	buttonColorMap.forEach( ( [ value, className, cssVar ] ) => {
		if ( value ) {
			buttonVars[ cssVar ] = value;
			buttonClasses.push( className );
		}
	} );
	const blockProps = useBlockProps( {
		className: `shift64-woo-search-panel is-${ variant } ${
			previewOpen ? 'is-preview-open' : 'is-preview-closed'
		} ${ buttonClasses.join( ' ' ) }`,
		style: variant === 'modal' ? buttonVars : undefined,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Panel copy', 'shift64-woo-search' ) }>
					<TextControl
						label={ __( 'Input label', 'shift64-woo-search' ) }
						value={ copy.inputLabel }
						onChange={ ( inputLabel ) =>
							setAttributes( { inputLabel } )
						}
					/>
					<TextControl
						label={ __( 'Placeholder', 'shift64-woo-search' ) }
						value={ copy.placeholder }
						onChange={ ( placeholder ) =>
							setAttributes( { placeholder } )
						}
					/>
					<TextControl
						label={ __( 'Submit label', 'shift64-woo-search' ) }
						value={ copy.submitLabel }
						onChange={ ( submitLabel ) =>
							setAttributes( { submitLabel } )
						}
					/>
					<TextControl
						label={ __( 'Dialog label', 'shift64-woo-search' ) }
						value={ copy.dialogLabel }
						onChange={ ( dialogLabel ) =>
							setAttributes( { dialogLabel } )
						}
					/>
					<TextControl
						label={ __( 'Close label', 'shift64-woo-search' ) }
						value={ copy.closeLabel }
						onChange={ ( closeLabel ) =>
							setAttributes( { closeLabel } )
						}
					/>
					<TextControl
						label={ __( 'Clear label', 'shift64-woo-search' ) }
						value={ copy.clearLabel }
						onChange={ ( clearLabel ) =>
							setAttributes( { clearLabel } )
						}
					/>
					<TextControl
						label={ __( 'No results label', 'shift64-woo-search' ) }
						value={ copy.noResultsLabel }
						onChange={ ( noResultsLabel ) =>
							setAttributes( { noResultsLabel } )
						}
					/>
				</PanelBody>
				{ variant === 'modal' && (
					<PanelColorSettings
						title={ __( 'Button colors', 'shift64-woo-search' ) }
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
									setAttributes( { buttonTextHoverColor } ),
							},
							{
								label: __( 'Background', 'shift64-woo-search' ),
								value: attributes.buttonBackgroundColor,
								onChange: ( buttonBackgroundColor ) =>
									setAttributes( { buttonBackgroundColor } ),
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
					/>
				) }
			</InspectorControls>
			<div { ...blockProps }>
				{ ! previewOpen && isSelected && (
					<p className="shift64-woo-search-panel__editor-closed">
						{ __(
							'Suggestions are closed. Enable the preview from the parent block settings.',
							'shift64-woo-search'
						) }
					</p>
				) }
				{ previewOpen && (
					<EditorPreview copy={ copy } variant={ variant } />
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
