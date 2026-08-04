import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Edit( { attributes, context, setAttributes } ) {
	const variant = context[ 'shift64WooSearch/variant' ] || 'inline';
	const blockProps = useBlockProps( {
		className: `shift64-woo-search-control is-${ variant }`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Search copy', 'shift64-woo-search' ) }>
					<TextControl
						label={ __( 'Accessible label', 'shift64-woo-search' ) }
						value={ attributes.label }
						onChange={ ( label ) => setAttributes( { label } ) }
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
					{ variant === 'modal' && (
						<>
							<TextControl
								label={ __(
									'Trigger label',
									'shift64-woo-search'
								) }
								value={ attributes.triggerLabel }
								onChange={ ( triggerLabel ) =>
									setAttributes( { triggerLabel } )
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
			</InspectorControls>
			<div { ...blockProps }>
				{ variant === 'modal' ? (
					<button
						type="button"
						className="shift64-woo-search-modal__trigger"
					>
						{ attributes.triggerIcon === 'search' && (
							<span aria-hidden="true">⌕</span>
						) }
						<span>{ attributes.triggerLabel }</span>
					</button>
				) : (
					<div className="shift64-woo-search-field">
						<span className="screen-reader-text">
							{ attributes.label }
						</span>
						<input
							type="search"
							aria-label={ attributes.label }
							placeholder={ attributes.placeholder }
							disabled
						/>
						<button type="button" disabled>
							{ attributes.submitLabel }
						</button>
					</div>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
