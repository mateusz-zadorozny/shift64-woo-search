import {
	isEnabled,
	moveActiveIndex,
	normalizeResponse,
	safeUrl,
} from './helpers';

const config = {
	searchUrl: 'https://example.test/',
	fallbackUrl: 'https://example.test/?s={query}&post_type=product',
	showSku: true,
	showCategory: true,
	showBrand: false,
	suggestionsHeaderText: 'SUGGESTIONS',
	categoriesHeaderText: 'CATEGORIES',
	brandsHeaderText: 'BRANDS',
	productsHeaderText: 'PRODUCTS',
};

describe( 'search helpers', () => {
	it( 'normalizes endpoint sections and strips disabled metadata', () => {
		const model = normalizeResponse(
			{
				query: 'lamp',
				suggestions: [ 'lamps' ],
				categories: [
					{ name: 'Lighting', url: '/product-category/lighting/' },
				],
				brands: [],
				results: [
					{
						title: 'Lamp',
						url: '/product/lamp/',
						sku: 'LAMP-1',
						category: 'Lighting|Home',
						brand: 'Hidden brand',
					},
				],
			},
			config,
			'lamp'
		);

		expect( model.sections.map( ( section ) => section.slug ) ).toEqual( [
			'suggestions',
			'categories',
			'products',
		] );
		expect( model.items[ 2 ] ).toMatchObject( {
			label: 'Lamp',
			sku: 'LAMP-1',
			category: 'Lighting',
			brand: '',
		} );
	} );

	it( 'rejects executable URLs and understands localized bool values', () => {
		expect( safeUrl( 'javascript:alert(1)', config.searchUrl ) ).toBe( '' );
		expect(
			safeUrl( 'https://elsewhere.example/product/', config.searchUrl, {
				sameOrigin: true,
			} )
		).toBe( '' );
		expect(
			safeUrl( 'https://cdn.example/image.jpg', config.searchUrl )
		).toBe( 'https://cdn.example/image.jpg' );
		expect( isEnabled( '' ) ).toBe( false );
		expect( isEnabled( '1' ) ).toBe( true );
	} );

	it( 'bounds keyboard movement', () => {
		expect( moveActiveIndex( -1, 'ArrowDown', 2 ) ).toBe( 0 );
		expect( moveActiveIndex( 1, 'ArrowDown', 2 ) ).toBe( 1 );
		expect( moveActiveIndex( 0, 'ArrowUp', 2 ) ).toBe( -1 );
	} );
} );
