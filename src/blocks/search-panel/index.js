import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Edit( { attributes, context, setAttributes } ) {
	const variant = context[ 'shift64WooSearch/variant' ] || 'inline';
	const previewOpen = Boolean( context[ 'shift64WooSearch/previewOpen' ] );
	const blockProps = useBlockProps( {
		className: `shift64-woo-search-panel is-${ variant }${
			variant === 'modal' && previewOpen ? ' is-preview-open' : ''
		}`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Panel copy', 'shift64-woo-search' ) }>
					<TextControl
						label={ __( 'Input label', 'shift64-woo-search' ) }
						value={ attributes.inputLabel }
						onChange={ ( inputLabel ) =>
							setAttributes( { inputLabel } )
						}
					/>
					<TextControl
						label={ __( 'Placeholder', 'shift64-woo-search' ) }
						value={ attributes.placeholder }
						onChange={ ( placeholder ) =>
							setAttributes( { placeholder } )
						}
					/>
					<TextControl
						label={ __( 'Submit label', 'shift64-woo-search' ) }
						value={ attributes.submitLabel }
						onChange={ ( submitLabel ) =>
							setAttributes( { submitLabel } )
						}
					/>
					<TextControl
						label={ __( 'Dialog label', 'shift64-woo-search' ) }
						value={ attributes.dialogLabel }
						onChange={ ( dialogLabel ) =>
							setAttributes( { dialogLabel } )
						}
					/>
					<TextControl
						label={ __( 'Close label', 'shift64-woo-search' ) }
						value={ attributes.closeLabel }
						onChange={ ( closeLabel ) =>
							setAttributes( { closeLabel } )
						}
					/>
					<TextControl
						label={ __( 'Clear label', 'shift64-woo-search' ) }
						value={ attributes.clearLabel }
						onChange={ ( clearLabel ) =>
							setAttributes( { clearLabel } )
						}
					/>
					<TextControl
						label={ __( 'No results label', 'shift64-woo-search' ) }
						value={ attributes.noResultsLabel }
						onChange={ ( noResultsLabel ) =>
							setAttributes( { noResultsLabel } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ variant === 'modal' && ! previewOpen ? (
					<p>
						{ __(
							'Enable the modal preview from the parent block settings.',
							'shift64-woo-search'
						) }
					</p>
				) : (
					<div
						className="shift64-woo-search-panel__preview"
						role="presentation"
					>
						<strong>
							{ variant === 'modal'
								? attributes.dialogLabel
								: __(
										'Search suggestions',
										'shift64-woo-search'
								  ) }
						</strong>
						<ul>
							<li>
								{ __( 'Sample product', 'shift64-woo-search' ) }
							</li>
							<li>
								{ __(
									'Sample category',
									'shift64-woo-search'
								) }
							</li>
						</ul>
					</div>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
