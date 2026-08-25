/**
 * The style panel every pill-shaped Shift64 control shares.
 *
 * A merchant styling a filter row and a sort control should meet the same two
 * tabs, the same three colours, and the same two length controls — so the panel
 * itself is the shared thing, not just the tokens underneath it. Callers pass
 * the style object they own and a writer for it; the panel has no idea whether
 * it is editing a Product Filters parent or a standalone Product Sort block.
 */

import { ColorPalette } from '@wordpress/block-editor';
import {
	BaseControl,
	PanelBody,
	RangeControl,
	TabPanel,
	useBaseControlProps,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { setPillStyleValue } from './pill-style';

// Core's own States UI is closed to third-party blocks in WP 7.1, so the pill
// exposes the same two states core surfaces for buttons — the default one and
// the pointer/keyboard one — as tabs over a single set of controls.
const STATE_TABS = [
	{ name: 'default', title: __( 'Default', 'shift64-woo-search' ) },
	{ name: 'hover', title: __( 'Hover', 'shift64-woo-search' ) },
];

// A px integer round-trips through the stored CSS length without a unit
// control, and matches the border widths core's own controls offer.
export function pxToNumber( value ) {
	const parsed = parseInt( value, 10 );
	return Number.isNaN( parsed ) ? undefined : parsed;
}

export function PillColorControl( { label, value, onChange } ) {
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

export function StylePanel( {
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
