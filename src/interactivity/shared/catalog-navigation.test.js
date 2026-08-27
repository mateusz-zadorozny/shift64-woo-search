import { syncBreadcrumbs } from '../../../frontend/js/shift64-woo-search-catalog-navigation';

describe( 'syncBreadcrumbs', () => {
	it( 'updates every breadcrumb by selector and index', () => {
		document.body.innerHTML = `
			<nav class="woocommerce-breadcrumb">Old Woo 1</nav>
			<div class="shift64-woo-search-header__breadcrumbs">Old header 1</div>
			<nav class="woocommerce-breadcrumb">Old Woo 2</nav>
			<div class="shift64-woo-search-header__breadcrumbs">Old header 2</div>
		`;
		const source = new window.DOMParser().parseFromString(
			`
				<div class="shift64-woo-search-header__breadcrumbs">New header 1</div>
				<nav class="woocommerce-breadcrumb">New Woo 1</nav>
				<div class="shift64-woo-search-header__breadcrumbs">New header 2</div>
				<nav class="woocommerce-breadcrumb">New Woo 2</nav>
			`,
			'text/html'
		);

		syncBreadcrumbs( source, document );

		expect(
			[ ...document.querySelectorAll( '.woocommerce-breadcrumb' ) ].map(
				( element ) => element.textContent
			)
		).toEqual( [ 'New Woo 1', 'New Woo 2' ] );
		expect(
			[
				...document.querySelectorAll(
					'.shift64-woo-search-header__breadcrumbs'
				),
			].map( ( element ) => element.textContent )
		).toEqual( [ 'New header 1', 'New header 2' ] );
	} );
} );
