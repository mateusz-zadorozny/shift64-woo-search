import {
	clearAllChanges,
	selectionChanges,
	selectionFromInputs,
	shouldLockScroll,
	trapTabIndex,
} from './helpers';

describe( 'selectionFromInputs', () => {
	it( 'collects checked values sorted and deduplicated', () => {
		expect(
			selectionFromInputs( [
				{ checked: true, value: 'wool' },
				{ checked: false, value: 'linen' },
				{ checked: true, value: 'cotton' },
				{ checked: true, value: 'wool' },
			] )
		).toEqual( [ 'cotton', 'wool' ] );
	} );

	it( 'ignores empty values and empty input lists', () => {
		expect(
			selectionFromInputs( [ { checked: true, value: '' } ] )
		).toEqual( [] );
		expect( selectionFromInputs( undefined ) ).toEqual( [] );
	} );
} );

describe( 'selectionChanges', () => {
	it( 'writes the canonical pair for a selection with AND support', () => {
		expect(
			selectionChanges( 'pa_material', [ 'cotton', 'wool' ], true )
		).toEqual( {
			filter_pa_material: 'cotton,wool',
			query_type_pa_material: 'and',
		} );
	} );

	it( 'omits the operator for OR pills', () => {
		expect( selectionChanges( 'product_cat', [ 'lamps' ], false ) ).toEqual(
			{
				filter_product_cat: 'lamps',
				query_type_product_cat: null,
			}
		);
	} );

	it( 'removes both parameters for an empty selection', () => {
		expect( selectionChanges( 'pa_material', [], true ) ).toEqual( {
			filter_pa_material: null,
			query_type_pa_material: null,
		} );
	} );
} );

describe( 'clearAllChanges', () => {
	it( 'nulls exactly the represented taxonomies', () => {
		expect( clearAllChanges( [ 'product_cat', 'pa_material' ] ) ).toEqual( {
			filter_product_cat: null,
			query_type_product_cat: null,
			filter_pa_material: null,
			query_type_pa_material: null,
		} );
	} );
} );

describe( 'shouldLockScroll', () => {
	it( 'locks while a tray is open on a narrow viewport', () => {
		expect( shouldLockScroll( true, { filters: 'product_cat-1' } ) ).toBe(
			true
		);
	} );

	it( 'leaves the desktop dropdown scrollable', () => {
		expect( shouldLockScroll( false, { filters: 'product_cat-1' } ) ).toBe(
			false
		);
	} );

	it( 'releases the lock once every surface is closed', () => {
		expect( shouldLockScroll( true, { filters: '', other: '' } ) ).toBe(
			false
		);
	} );

	it( 'stays unlocked when no parent has ever opened', () => {
		expect( shouldLockScroll( true, {} ) ).toBe( false );
		expect( shouldLockScroll( true, undefined ) ).toBe( false );
	} );

	it( 'locks when any one of several parents is open', () => {
		expect(
			shouldLockScroll( true, { first: '', second: 'pa_material-2' } )
		).toBe( true );
	} );
} );

describe( 'trapTabIndex', () => {
	it( 'wraps forward from the last element', () => {
		expect( trapTabIndex( 3, 2, false ) ).toBe( 0 );
	} );

	it( 'wraps backward from the first element', () => {
		expect( trapTabIndex( 3, 0, true ) ).toBe( 2 );
	} );

	it( 'lets ordinary tabbing continue', () => {
		expect( trapTabIndex( 3, 1, false ) ).toBe( -1 );
		expect( trapTabIndex( 3, 1, true ) ).toBe( -1 );
	} );

	it( 'handles an empty list', () => {
		expect( trapTabIndex( 0, -1, false ) ).toBe( -1 );
	} );
} );
