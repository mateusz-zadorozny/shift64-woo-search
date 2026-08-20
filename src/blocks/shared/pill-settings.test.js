import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { PILL_SETTING_DEFAULTS, pillSettings } from './pill-settings';

const PARENT_METADATA = JSON.parse(
	readFileSync( join( __dirname, '../product-filters/block.json' ), 'utf8' )
);
const PILL_METADATA = JSON.parse(
	readFileSync( join( __dirname, '../filter-pill/block.json' ), 'utf8' )
);

describe( 'pillSettings', () => {
	it( 'falls back to defaults while context is unavailable', () => {
		expect( pillSettings( undefined ) ).toEqual( PILL_SETTING_DEFAULTS );
		expect( pillSettings( {} ) ).toEqual( PILL_SETTING_DEFAULTS );
	} );

	it( 'reads the parent-provided values', () => {
		expect(
			pillSettings( {
				'shift64WooSearch/selectionMode': 'single',
				'shift64WooSearch/showCounts': false,
				'shift64WooSearch/maxOptions': 5,
				'shift64WooSearch/applyLabel': 'Use these',
			} )
		).toEqual( {
			...PILL_SETTING_DEFAULTS,
			selectionMode: 'single',
			showCounts: false,
			maxOptions: 5,
			applyLabel: 'Use these',
		} );
	} );

	it( 'keeps a deliberate false rather than treating it as unset', () => {
		expect(
			pillSettings( { 'shift64WooSearch/hideEmpty': false } ).hideEmpty
		).toBe( false );
	} );
} );

// The parent declares these settings, the pill consumes them, and both PHP and
// JS read the same defaults. A setting registered in only one place is a
// control that silently does nothing.
describe( 'parent/pill settings contract', () => {
	it.each( Object.keys( PILL_SETTING_DEFAULTS ) )(
		'%s is a parent attribute provided as context and used by the pill',
		( key ) => {
			expect( PARENT_METADATA.attributes ).toHaveProperty( key );
			expect( PARENT_METADATA.providesContext ).toHaveProperty(
				`shift64WooSearch/${ key }`,
				key
			);
			expect( PILL_METADATA.usesContext ).toContain(
				`shift64WooSearch/${ key }`
			);
		}
	);

	it.each( Object.keys( PILL_SETTING_DEFAULTS ) )(
		'%s no longer lingers as a pill attribute',
		( key ) => {
			expect( PILL_METADATA.attributes ).not.toHaveProperty( key );
		}
	);

	it( 'shares the parent block.json defaults', () => {
		Object.entries( PILL_SETTING_DEFAULTS ).forEach( ( [ key, value ] ) => {
			expect( PARENT_METADATA.attributes[ key ].default ).toEqual(
				value
			);
		} );
	} );
} );
