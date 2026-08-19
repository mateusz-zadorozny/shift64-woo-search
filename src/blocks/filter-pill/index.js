import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	ExternalLink,
	Notice,
	PanelBody,
	RadioControl,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';
import {
	groupFacets,
	orderPreviewOptions,
	sampleCount,
	statusReason,
	useEditorFacets,
} from '../shared/facets';
import './style.scss';

const FALLBACK_TERM_NAMES = [ 'Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo' ];

function facetChoices( facets ) {
	const { ready, unavailable } = groupFacets( facets );
	const choices = [
		{
			label: __( 'Select a facet…', 'shift64-woo-search' ),
			value: '',
		},
	];
	ready.forEach( ( facet ) => {
		choices.push( { label: facet.label, value: facet.key } );
	} );
	unavailable.forEach( ( facet ) => {
		choices.push( {
			label: sprintf(
				/* translators: 1: facet label, 2: reason it is unavailable. */
				__( '%1$s — unavailable: %2$s', 'shift64-woo-search' ),
				facet.label,
				statusReason( facet.status )
			),
			value: facet.key,
			disabled: true,
		} );
	} );
	return choices;
}

function PillPreview( { attributes, entry, instanceId, termNames } ) {
	const { label, selectionMode, showCounts, orderBy, maxOptions } =
		attributes;
	const heading = label || ( entry ? entry.label : '' );
	const options = orderPreviewOptions(
		termNames.map( ( name, index ) => ( {
			name,
			count: sampleCount( entry ? entry.key : '', index ),
		} ) ),
		orderBy,
		maxOptions
	);

	return (
		<div className="shift64-woo-search-pill is-editor-preview">
			<span className="shift64-woo-search-pill__trigger">
				<span className="shift64-woo-search-pill__label">
					{ heading }
				</span>
				<span
					className="shift64-woo-search-pill__chevron"
					aria-hidden="true"
				/>
			</span>
			<div className="shift64-woo-search-pill__panel is-open">
				<p className="shift64-woo-search-pill__heading">{ heading }</p>
				<ul className="shift64-woo-search-pill__options">
					{ options.map( ( option, index ) => {
						const optionId = `${ instanceId }-option-${ index }`;
						return (
							<li
								key={ option.name }
								className="shift64-woo-search-pill__option"
							>
								<label htmlFor={ optionId }>
									<input
										id={ optionId }
										type={
											selectionMode === 'single'
												? 'radio'
												: 'checkbox'
										}
										disabled
										readOnly
									/>
									<span className="shift64-woo-search-pill__option-label">
										{ option.name }
									</span>
									{ showCounts && (
										<span className="shift64-woo-search-pill__count">
											{ option.count }
										</span>
									) }
								</label>
							</li>
						);
					} ) }
				</ul>
			</div>
		</div>
	);
}

function Edit( { attributes, clientId, setAttributes } ) {
	const {
		facet,
		label,
		selectionMode,
		queryType,
		showCounts,
		hideEmpty,
		orderBy,
		maxOptions,
		applyLabel,
		clearLabel,
	} = attributes;
	const { payload, isLoading } = useEditorFacets();
	const entry = payload.facets.find( ( item ) => item.key === facet );
	const supportsAnd = Boolean(
		entry && entry.operators && entry.operators.includes( 'and' )
	);
	const isReady = Boolean( entry && entry.status === 'ready' );

	const terms = useSelect(
		( select ) => {
			if ( ! entry || ! isReady ) {
				return null;
			}
			return select( 'core' ).getEntityRecords(
				'taxonomy',
				entry.taxonomy,
				{ per_page: 8, hide_empty: false, context: 'view' }
			);
		},
		[ entry ? entry.taxonomy : '', isReady ]
	);
	const termNames =
		terms && terms.length
			? terms.map( ( term ) => term.name )
			: FALLBACK_TERM_NAMES;

	const blockProps = useBlockProps( {
		className: 'shift64-woo-search-pill-wrapper',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Facet', 'shift64-woo-search' ) }>
					<SelectControl
						label={ __( 'Facet', 'shift64-woo-search' ) }
						value={ facet }
						options={ facetChoices( payload.facets ) }
						onChange={ ( next ) =>
							setAttributes( { facet: next } )
						}
						help={
							isLoading
								? __(
										'Loading available facets…',
										'shift64-woo-search'
								  )
								: undefined
						}
					/>
					{ entry && ! isReady && (
						<Notice status="warning" isDismissible={ false }>
							<p>{ statusReason( entry.status ) }</p>
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
				</PanelBody>
				{ entry && (
					<PanelBody title={ __( 'Behavior', 'shift64-woo-search' ) }>
						<TextControl
							label={ __( 'Label', 'shift64-woo-search' ) }
							value={ label }
							placeholder={ entry.label }
							onChange={ ( next ) =>
								setAttributes( { label: next } )
							}
						/>
						<RadioControl
							label={ __( 'Selection', 'shift64-woo-search' ) }
							selected={ selectionMode }
							options={ [
								{
									label: __(
										'Multiple choices',
										'shift64-woo-search'
									),
									value: 'multiple',
								},
								{
									label: __(
										'Single choice',
										'shift64-woo-search'
									),
									value: 'single',
								},
							] }
							onChange={ ( next ) =>
								setAttributes( { selectionMode: next } )
							}
						/>
						{ supportsAnd && selectionMode === 'multiple' && (
							<SelectControl
								label={ __(
									'Match products against',
									'shift64-woo-search'
								) }
								value={ queryType }
								options={ [
									{
										label: __(
											'Any selected value (OR)',
											'shift64-woo-search'
										),
										value: 'or',
									},
									{
										label: __(
											'All selected values (AND)',
											'shift64-woo-search'
										),
										value: 'and',
									},
								] }
								onChange={ ( next ) =>
									setAttributes( { queryType: next } )
								}
							/>
						) }
						<ToggleControl
							label={ __(
								'Show result counts',
								'shift64-woo-search'
							) }
							checked={ Boolean( showCounts ) }
							onChange={ ( next ) =>
								setAttributes( { showCounts: next } )
							}
						/>
						<ToggleControl
							label={ __(
								'Hide options without results',
								'shift64-woo-search'
							) }
							help={ __(
								'Selected options always stay visible.',
								'shift64-woo-search'
							) }
							checked={ Boolean( hideEmpty ) }
							onChange={ ( next ) =>
								setAttributes( { hideEmpty: next } )
							}
						/>
						<SelectControl
							label={ __(
								'Order options by',
								'shift64-woo-search'
							) }
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
									label: __(
										'Name (A → Z)',
										'shift64-woo-search'
									),
									value: 'name-asc',
								},
								{
									label: __(
										'Name (Z → A)',
										'shift64-woo-search'
									),
									value: 'name-desc',
								},
							] }
							onChange={ ( next ) =>
								setAttributes( { orderBy: next } )
							}
						/>
						<RangeControl
							label={ __(
								'Maximum options (0 shows all)',
								'shift64-woo-search'
							) }
							value={ maxOptions }
							min={ 0 }
							max={ 100 }
							onChange={ ( next ) =>
								setAttributes( { maxOptions: next } )
							}
						/>
						<TextControl
							label={ __( 'Apply label', 'shift64-woo-search' ) }
							value={ applyLabel }
							placeholder={ __( 'Apply', 'shift64-woo-search' ) }
							onChange={ ( next ) =>
								setAttributes( { applyLabel: next } )
							}
						/>
						<TextControl
							label={ __( 'Clear label', 'shift64-woo-search' ) }
							value={ clearLabel }
							placeholder={ __( 'Clear', 'shift64-woo-search' ) }
							onChange={ ( next ) =>
								setAttributes( { clearLabel: next } )
							}
						/>
					</PanelBody>
				) }
			</InspectorControls>
			<div { ...blockProps }>
				{ ! facet && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Choose a facet for this pill in the block settings.',
							'shift64-woo-search'
						) }
					</Notice>
				) }
				{ entry && ! isReady && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: 1: facet label, 2: reason it is unavailable. */
							__(
								'The %1$s facet is saved but not usable yet: %2$s This pill is skipped on the storefront.',
								'shift64-woo-search'
							),
							entry.label,
							statusReason( entry.status )
						) }
					</Notice>
				) }
				{ facet && ! entry && ! isLoading && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The saved facet is unknown on this site. This pill is skipped on the storefront.',
							'shift64-woo-search'
						) }
					</Notice>
				) }
				{ entry && (
					<PillPreview
						attributes={ attributes }
						entry={ entry }
						instanceId={ `s64ws-pill-${ clientId }` }
						termNames={ termNames }
					/>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
