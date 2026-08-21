import {
	actionStyleToVars,
	normalizePresetValue,
	pillStyleToVars,
	setActionStyleValue,
	setPillStyleValue,
} from './pill-style';

describe( 'normalizePresetValue', () => {
	it( 'expands a theme preset reference', () => {
		expect( normalizePresetValue( 'var:preset|color|accent-3' ) ).toBe(
			'var(--wp--preset--color--accent-3)'
		);
	} );

	it( 'leaves literal values untouched', () => {
		expect( normalizePresetValue( '#503aa8' ) ).toBe( '#503aa8' );
		expect( normalizePresetValue( undefined ) ).toBeUndefined();
	} );
} );

describe( 'pillStyleToVars', () => {
	it( 'returns nothing for an unstyled parent', () => {
		expect( pillStyleToVars( undefined ) ).toEqual( {} );
		expect( pillStyleToVars( {} ) ).toEqual( {} );
	} );

	it( 'maps default and hover values onto the token contract', () => {
		expect(
			pillStyleToVars( {
				color: { text: '#111', background: '#fff' },
				border: { color: '#ccc', width: '2px', radius: '8px' },
				':hover': {
					color: { text: '#fff', background: '#503aa8' },
					border: { color: '#503aa8' },
				},
			} )
		).toEqual( {
			'--s64ws-pill-color': '#111',
			'--s64ws-pill-bg': '#fff',
			'--s64ws-pill-border-color': '#ccc',
			'--s64ws-pill-border-width': '2px',
			'--s64ws-pill-radius': '8px',
			'--s64ws-pill-color-hover': '#fff',
			'--s64ws-pill-bg-hover': '#503aa8',
			'--s64ws-pill-border-color-hover': '#503aa8',
		} );
	} );

	it( 'emits only the tokens that carry a value', () => {
		expect(
			pillStyleToVars( {
				':hover': { color: { background: '#503aa8' } },
			} )
		).toEqual( { '--s64ws-pill-bg-hover': '#503aa8' } );
	} );

	it( 'resolves preset references so the storefront gets usable CSS', () => {
		expect(
			pillStyleToVars( {
				color: { background: 'var:preset|color|accent-3' },
			} )
		).toEqual( {
			'--s64ws-pill-bg': 'var(--wp--preset--color--accent-3)',
		} );
	} );
} );

describe( 'actionStyleToVars', () => {
	it( 'maps the shared action-button style contract', () => {
		expect(
			actionStyleToVars( {
				typography: { fontSize: '16px' },
				border: { radius: '8px' },
				color: { text: '#fff', background: '#111' },
			} )
		).toEqual( {
			'--s64ws-action-font-size': '16px',
			'--s64ws-action-radius': '8px',
		} );
	} );

	it( 'uses the same theme preset normalization as pill styles', () => {
		expect(
			actionStyleToVars( {
				typography: {
					fontSize: 'var:preset|font-size|large',
				},
			} )
		).toEqual( {
			'--s64ws-action-font-size': 'var(--wp--preset--font-size--large)',
		} );
	} );
} );

describe( 'setPillStyleValue', () => {
	it( 'writes a nested value without mutating the source', () => {
		const source = { color: { text: '#111' } };
		const next = setPillStyleValue(
			source,
			[ 'color', 'background' ],
			'#fff'
		);

		expect( next ).toEqual( {
			color: { text: '#111', background: '#fff' },
		} );
		expect( source ).toEqual( { color: { text: '#111' } } );
	} );

	it( 'creates missing branches', () => {
		expect(
			setPillStyleValue( {}, [ ':hover', 'color', 'text' ], '#fff' )
		).toEqual( { ':hover': { color: { text: '#fff' } } } );
	} );

	it( 'prunes branches emptied by a cleared control', () => {
		expect(
			setPillStyleValue(
				{ ':hover': { color: { text: '#fff' } } },
				[ ':hover', 'color', 'text' ],
				undefined
			)
		).toEqual( {} );
	} );

	it( 'keeps siblings when one value is cleared', () => {
		expect(
			setPillStyleValue(
				{ color: { text: '#111', background: '#fff' } },
				[ 'color', 'text' ],
				''
			)
		).toEqual( { color: { background: '#fff' } } );
	} );
} );

describe( 'setActionStyleValue', () => {
	it( 'writes and prunes the shared action style shape', () => {
		const source = { color: { text: '#fff' } };
		const next = setActionStyleValue(
			source,
			[ ':hover', 'color', 'background' ],
			'#111'
		);

		expect( next ).toEqual( {
			color: { text: '#fff' },
			':hover': { color: { background: '#111' } },
		} );
		expect(
			setActionStyleValue(
				next,
				[ ':hover', 'color', 'background' ],
				undefined
			)
		).toEqual( { color: { text: '#fff' } } );
	} );
} );
