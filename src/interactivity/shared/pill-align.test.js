/**
 * The panel anchor decision, away from the DOM.
 *
 * The helper itself lives beside the other frontend script modules, because
 * the hand-written Product Sort module imports it over plain HTTP while the
 * built Product Filters view imports it through webpack — the same split the
 * catalog-navigation module already lives with.
 */

import {
	PILL_VIEWPORT_GUTTER,
	shouldAlignEnd,
} from '../../../frontend/js/shift64-woo-search-pill-align';

describe( 'shouldAlignEnd', () => {
	const VIEWPORT = 1000;
	const PANEL = 260;

	it( 'keeps the start anchor when the panel fits beside the trigger', () => {
		expect( shouldAlignEnd( 100, PANEL, VIEWPORT ) ).toBe( false );
	} );

	it( 'flips once the panel would cross the viewport edge', () => {
		expect( shouldAlignEnd( 800, PANEL, VIEWPORT ) ).toBe( true );
	} );

	it( 'flips before the panel reaches the edge, not after', () => {
		// The last position that still leaves the full gutter free.
		const clear = VIEWPORT - PANEL - PILL_VIEWPORT_GUTTER;
		expect( shouldAlignEnd( clear, PANEL, VIEWPORT ) ).toBe( false );
		expect( shouldAlignEnd( clear + 1, PANEL, VIEWPORT ) ).toBe( true );
		// Flush against the window is already too close: it fits, but with
		// nothing between the panel and the edge.
		expect( shouldAlignEnd( VIEWPORT - PANEL, PANEL, VIEWPORT ) ).toBe(
			true
		);
		// A caller asking for more breathing room flips sooner.
		expect( shouldAlignEnd( clear, PANEL, VIEWPORT, 16 ) ).toBe( true );
	} );

	it( 'leaves a panel wider than the window alone', () => {
		// Flipping cannot rescue it — it only moves which end is cut off, and
		// the first options are the ones worth keeping.
		expect( shouldAlignEnd( 10, 400, 320 ) ).toBe( false );
	} );

	it( 'answers false while the panel is unmeasurable', () => {
		expect( shouldAlignEnd( 800, 0, VIEWPORT ) ).toBe( false );
		expect( shouldAlignEnd( 800, PANEL, 0 ) ).toBe( false );
	} );
} );
