import {
	clampMaxOptions,
	groupFacets,
	orderPreviewOptions,
	sampleCount,
	statusReason,
} from './facets-helpers';

describe( 'groupFacets', () => {
	it( 'splits ready facets from unavailable ones', () => {
		const { ready, unavailable } = groupFacets( [
			{ key: 'product_cat', status: 'ready' },
			{ key: 'product_brand', status: 'disabled' },
			{ key: 'pa_material', status: 'rebuild-required' },
		] );
		expect( ready.map( ( f ) => f.key ) ).toEqual( [ 'product_cat' ] );
		expect( unavailable.map( ( f ) => f.key ) ).toEqual( [
			'product_brand',
			'pa_material',
		] );
	} );

	it( 'tolerates a missing list', () => {
		expect( groupFacets( undefined ) ).toEqual( {
			ready: [],
			unavailable: [],
		} );
	} );
} );

describe( 'statusReason', () => {
	it( 'explains every unavailable status and stays silent for ready', () => {
		expect( statusReason( 'disabled' ) ).not.toBe( '' );
		expect( statusReason( 'rebuild-required' ) ).not.toBe( '' );
		expect( statusReason( 'taxonomy-missing' ) ).not.toBe( '' );
		expect( statusReason( 'ready' ) ).toBe( '' );
	} );
} );

describe( 'sampleCount', () => {
	it( 'is deterministic per facet key and index', () => {
		expect( sampleCount( 'pa_material', 0 ) ).toBe(
			sampleCount( 'pa_material', 0 )
		);
		expect( sampleCount( 'pa_material', 1 ) ).toBe(
			sampleCount( 'pa_material', 1 )
		);
	} );

	it( 'stays within the 1–24 preview range', () => {
		for ( let i = 0; i < 30; i++ ) {
			const count = sampleCount( 'product_cat', i );
			expect( count ).toBeGreaterThanOrEqual( 1 );
			expect( count ).toBeLessThanOrEqual( 24 );
		}
	} );
} );

describe( 'orderPreviewOptions', () => {
	const options = [
		{ name: 'Cotton', count: 3 },
		{ name: 'Wool', count: 9 },
		{ name: 'Linen', count: 3 },
	];

	it( 'orders by count desc with a name tie-break', () => {
		expect(
			orderPreviewOptions( options, 'count-desc', 0 ).map(
				( o ) => o.name
			)
		).toEqual( [ 'Wool', 'Cotton', 'Linen' ] );
	} );

	it( 'orders by name in both directions', () => {
		expect(
			orderPreviewOptions( options, 'name-asc', 0 ).map( ( o ) => o.name )
		).toEqual( [ 'Cotton', 'Linen', 'Wool' ] );
		expect(
			orderPreviewOptions( options, 'name-desc', 0 ).map(
				( o ) => o.name
			)
		).toEqual( [ 'Wool', 'Linen', 'Cotton' ] );
	} );

	it( 'bounds the list through the maxOptions clamp', () => {
		expect(
			orderPreviewOptions( options, 'count-desc', 2 )
		).toHaveLength( 2 );
		expect(
			orderPreviewOptions( options, 'count-desc', 0 )
		).toHaveLength( 3 );
	} );
} );

describe( 'clampMaxOptions', () => {
	it( 'treats zero and negatives as "all"', () => {
		expect( clampMaxOptions( 0 ) ).toBe( 0 );
		expect( clampMaxOptions( -5 ) ).toBe( 0 );
	} );

	it( 'clamps into 1–100 and rounds', () => {
		expect( clampMaxOptions( 1 ) ).toBe( 1 );
		expect( clampMaxOptions( 250 ) ).toBe( 100 );
		expect( clampMaxOptions( 2.6 ) ).toBe( 3 );
	} );

	it( 'treats junk as "all"', () => {
		expect( clampMaxOptions( 'abc' ) ).toBe( 0 );
	} );
} );
