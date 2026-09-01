import { syncBreadcrumbs } from '../../../frontend/js/shift64-woo-search-catalog-navigation';

describe( 'syncBreadcrumbs', () => {
	it( 'updates every breadcrumb by selector and index', () => {
		document.body.innerHTML = `
			<nav class="woocommerce-breadcrumb">Old Woo 1</nav>
			<nav class="woocommerce-breadcrumb">Old Woo 2</nav>
		`;
		const source = new window.DOMParser().parseFromString(
			`
				<nav class="woocommerce-breadcrumb">New Woo 1</nav>
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
	} );

	it( 'leaves plugin-owned header markup alone now that it is not emitted', () => {
		document.body.innerHTML = `
			<nav class="woocommerce-breadcrumb">Old Woo</nav>
			<div class="shift64-woo-search-header__breadcrumbs">Stale header</div>
		`;
		const source = new window.DOMParser().parseFromString(
			`
				<nav class="woocommerce-breadcrumb">New Woo</nav>
				<div class="shift64-woo-search-header__breadcrumbs">New header</div>
			`,
			'text/html'
		);

		syncBreadcrumbs( source, document );

		expect(
			document.querySelector( '.woocommerce-breadcrumb' ).textContent
		).toBe( 'New Woo' );
		expect(
			document.querySelector( '.shift64-woo-search-header__breadcrumbs' )
				.textContent
		).toBe( 'Stale header' );
	} );
} );
