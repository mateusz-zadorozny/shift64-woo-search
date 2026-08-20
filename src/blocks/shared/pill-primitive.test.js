/**
 * Selector-parity guard for the shared pill primitive.
 *
 * The primitive is a documented markup/style contract reused by downstream
 * controls (Product Sort). This test fails when a contract selector is
 * renamed in the stylesheet without updating the visual fixture and the
 * documentation — the three artifacts must always agree.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { PILL_STYLE_VARS } from './pill-style';

const STYLESHEET = readFileSync(
	join( __dirname, 'pill-primitive.scss' ),
	'utf8'
);
const FIXTURE = readFileSync(
	join( __dirname, 'pill-primitive-fixture.html' ),
	'utf8'
);
const DOC = readFileSync(
	join( __dirname, '../../../docs/product-filter-pill-blocks.md' ),
	'utf8'
);

// SCSS nests `&__element` under `.shift64-woo-search-pill`; expand to the
// selectors consumers actually target.
const PILL_ELEMENTS = [
	...new Set(
		[ ...STYLESHEET.matchAll( /&(__[a-z-]+)/g ) ].map(
			( match ) => `.shift64-woo-search-pill${ match[ 1 ] }`
		)
	),
];

const PARENT_SELECTORS = [
	'.shift64-woo-search-product-filters__clear-all',
	'.shift64-woo-search-product-filters__backdrop',
];

const STACKING_TOKENS = [
	'--s64ws-pill-panel-z',
	'--s64ws-pill-backdrop-z',
	'--s64ws-pill-tray-z',
];

describe( 'pill primitive contract', () => {
	it( 'exposes the expected element selectors', () => {
		expect( PILL_ELEMENTS ).toEqual(
			expect.arrayContaining( [
				'.shift64-woo-search-pill__disclosure',
				'.shift64-woo-search-pill__trigger',
				'.shift64-woo-search-pill__summary-count',
				'.shift64-woo-search-pill__chevron',
				'.shift64-woo-search-pill__panel',
				'.shift64-woo-search-pill__heading',
				'.shift64-woo-search-pill__options',
				'.shift64-woo-search-pill__option',
				'.shift64-woo-search-pill__option-label',
				'.shift64-woo-search-pill__count',
				'.shift64-woo-search-pill__actions',
				'.shift64-woo-search-pill__apply',
				'.shift64-woo-search-pill__clear',
			] )
		);
	} );

	it.each( PILL_ELEMENTS )(
		'%s appears in the visual fixture',
		( selector ) => {
			expect( FIXTURE ).toContain( selector.replace( /^\./, '' ) );
		}
	);

	it.each( PILL_ELEMENTS )( '%s is documented', ( selector ) => {
		expect( DOC ).toContain( selector );
	} );

	it.each( PARENT_SELECTORS )(
		'parent-owned %s exists in fixture and docs',
		( selector ) => {
			expect( FIXTURE ).toContain( selector.replace( /^\./, '' ) );
			expect( DOC ).toContain( selector );
		}
	);

	it.each( STACKING_TOKENS )(
		'stacking token %s stays documented',
		( token ) => {
			expect( DOC ).toContain( token );
		}
	);

	// The parent writes these tokens, the stylesheet reads them, and the PHP
	// renderer mirrors the same map. A token that exists in only one of the
	// three is a silently dead control.
	it.each( PILL_STYLE_VARS.map( ( { token } ) => token ) )(
		'style token %s is consumed by the stylesheet and documented',
		( token ) => {
			expect( STYLESHEET ).toContain( token );
			expect( DOC ).toContain( token );
		}
	);

	it( 'gives every hover token a default-state counterpart to fall back to', () => {
		const tokens = PILL_STYLE_VARS.map( ( { token } ) => token );
		tokens
			.filter( ( token ) => token.endsWith( '-hover' ) )
			.forEach( ( token ) => {
				expect( tokens ).toContain( token.replace( /-hover$/, '' ) );
			} );
	} );
} );
