import { migrateLegacyAttributes } from './parent';

jest.mock( '@wordpress/blocks', () => ( {
	createBlock: ( name, attributes ) => ( { name, attributes } ),
} ) );
jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: () => null,
	InnerBlocks: { Content: () => null },
	useBlockProps: () => ( {} ),
	useInnerBlocksProps: () => ( {} ),
} ) );
jest.mock( '@wordpress/components', () => ( {
	Button: () => null,
	Notice: () => null,
	PanelBody: () => null,
	ToggleControl: () => null,
} ) );
jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {} ),
	useSelect: () => [],
} ) );
jest.mock( '@wordpress/element', () => ( {
	useEffect: () => {},
	useMemo: ( callback ) => callback(),
	useRef: ( value ) => ( { current: value } ),
	useState: ( value ) => [ value, () => {} ],
} ) );
jest.mock( '@wordpress/i18n', () => ( { __: ( text ) => text } ) );

describe( 'legacy parent migration', () => {
	it( 'moves inline copy and native styles to Search Control', () => {
		const [ control, panel ] = migrateLegacyAttributes(
			{
				label: 'Catalog search',
				placeholder: 'Find lamps',
				button: 'Go',
				textColor: 'contrast',
				style: { spacing: { padding: '12px' } },
			},
			'inline'
		);

		expect( control ).toMatchObject( {
			label: 'Catalog search',
			placeholder: 'Find lamps',
			submitLabel: 'Go',
			textColor: 'contrast',
			style: { spacing: { padding: '12px' } },
		} );
		expect( panel.style ).toBeUndefined();
	} );

	it( 'maps modal copy and safe equivalents to separate children', () => {
		const [ control, panel ] = migrateLegacyAttributes(
			{
				label: 'Catalog dialog',
				trigger_label: 'Open catalog',
				close_label: 'Close catalog',
				clear_label: 'Clear catalog',
				trigger_style: 'outline',
				trigger_icon_color: '#123456',
				trigger_surface_color: '#abcdef',
				trigger_border_radius: 8,
				trigger_padding: 6,
				modal_background_color: '#ffffff',
				search_input_text_color: '#111111',
			},
			'modal'
		);

		expect( control ).toMatchObject( {
			triggerLabel: 'Open catalog',
			style: {
				color: { text: '#123456' },
				border: { color: '#abcdef', radius: '8px' },
				spacing: { padding: '6px' },
			},
		} );
		expect( panel ).toMatchObject( {
			dialogLabel: 'Catalog dialog',
			inputLabel: 'Catalog dialog',
			closeLabel: 'Close catalog',
			clearLabel: 'Clear catalog',
			style: {
				color: { background: '#ffffff', text: '#111111' },
			},
		} );
	} );
} );
