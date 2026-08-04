import { expect, test, type Page } from '@playwright/test';

const SEARCH = 'shift64-woo-search/search';
const MODAL_SEARCH = 'shift64-woo-search/modal-search';
const CONTROL = 'shift64-woo-search/search-control';
const PANEL = 'shift64-woo-search/search-panel';

async function loginAsAdmin( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	const password = page.locator( '#user_pass' );
	await password.click();
	await password.pressSequentially( 'admin' );
	await expect( password ).toHaveValue( 'admin' );
	await page.click( '#wp-submit' );
	await expect( page ).toHaveURL( /wp-admin/ );
}

test.describe( 'composable search block editor', () => {
	test( 'inserts a locked, selectable, independently styleable child pair', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/post-new.php?post_type=page' );

		await expect
			.poll( () =>
				page.evaluate( () =>
					Boolean( ( window as any ).wp?.blocks?.getBlockType )
				)
			)
			.toBe( true );

		const welcomeDialog = page.getByRole( 'dialog', {
			name: /Welcome to the editor/i,
		} );
		if ( await welcomeDialog.isVisible() ) {
			await welcomeDialog
				.getByRole( 'button', { name: 'Close' } )
				.click();
		}

		await page.evaluate(
			( blockNames ) => {
				const wp = ( window as any ).wp;
				wp.data
					.dispatch( 'core/block-editor' )
					.insertBlocks(
						blockNames.map( ( name ) =>
							wp.blocks.createBlock( name )
						)
					);
			},
			[ SEARCH, MODAL_SEARCH ]
		);

		await expect
			.poll( () =>
				page.evaluate(
					( parentNames ) => {
						const wp = ( window as any ).wp;
						const blocks = wp.data
							.select( 'core/block-editor' )
							.getBlocks();
						return parentNames.map(
							( parentName ) =>
								blocks
									.find(
										( block: any ) =>
											block.name === parentName
									)
									?.innerBlocks?.map(
										( block: any ) => block.name
									) || []
						);
					},
					[ SEARCH, MODAL_SEARCH ]
				)
			)
			.toEqual( [
				[ CONTROL, PANEL ],
				[ CONTROL, PANEL ],
			] );

		const editorContract = await page.evaluate(
			( { parentName, modalParentName, controlName, panelName } ) => {
				const wp = ( window as any ).wp;
				const select = wp.data.select( 'core/block-editor' );
				const dispatch = wp.data.dispatch( 'core/block-editor' );
				const parent = select
					.getBlocks()
					.find( ( block: any ) => block.name === parentName );
				const modalParent = select
					.getBlocks()
					.find( ( block: any ) => block.name === modalParentName );
				const [ control, panel ] = parent.innerBlocks;

				dispatch.selectBlock( control.clientId );
				const controlSelected =
					select.getSelectedBlockClientId() === control.clientId;
				dispatch.selectBlock( panel.clientId );
				const panelSelected =
					select.getSelectedBlockClientId() === panel.clientId;

				dispatch.updateBlockAttributes( control.clientId, {
					style: { color: { text: '#112233' } },
				} );
				dispatch.updateBlockAttributes( panel.clientId, {
					style: { color: { background: '#ddeeff' } },
				} );

				return {
					parentClientId: parent.clientId,
					childClientIds: [ control.clientId, panel.clientId ],
					templateLock: select.getTemplateLock( parent.clientId ),
					modalTemplateLock: select.getTemplateLock(
						modalParent.clientId
					),
					modalChildren: modalParent.innerBlocks.map(
						( block: any ) => block.name
					),
					canRemove: [
						select.canRemoveBlock( control.clientId ),
						select.canRemoveBlock( panel.clientId ),
					],
					canMove: [
						select.canMoveBlock( control.clientId ),
						select.canMoveBlock( panel.clientId ),
					],
					selected: [ controlSelected, panelSelected ],
					styles: [
						select.getBlockAttributes( control.clientId ).style,
						select.getBlockAttributes( panel.clientId ).style,
					],
					supports: [ controlName, panelName ].map(
						( name ) => wp.blocks.getBlockType( name ).supports
					),
				};
			},
			{
				parentName: SEARCH,
				modalParentName: MODAL_SEARCH,
				controlName: CONTROL,
				panelName: PANEL,
			}
		);

		expect( editorContract.templateLock ).toBe( 'all' );
		expect( editorContract.modalTemplateLock ).toBe( 'all' );
		expect( editorContract.modalChildren ).toEqual( [ CONTROL, PANEL ] );
		expect( editorContract.canRemove ).toEqual( [ false, false ] );
		expect( editorContract.canMove ).toEqual( [ false, false ] );
		expect( editorContract.selected ).toEqual( [ true, true ] );
		expect( editorContract.styles ).toEqual( [
			{ color: { text: '#112233' } },
			{ color: { background: '#ddeeff' } },
		] );
		for ( const supports of editorContract.supports ) {
			expect( supports.color.text ).toBe( true );
			expect( supports.color.background ).toBe( true );
			expect( supports.spacing.padding ).toBe( true );
			expect( supports.typography.fontSize ).toBe( true );
		}

		const overviewToggle = page
			.getByRole( 'button', { name: /Document Overview|List View/i } )
			.first();
		await overviewToggle.click();
		const listView = page.locator( '.block-editor-list-view-tree' );
		await expect( listView ).toBeVisible();

		const parentRow = listView.locator( '.block-editor-list-view-leaf', {
			hasText: 'Shift64 Product Search',
		} );
		if ( ( await parentRow.getAttribute( 'data-expanded' ) ) === 'false' ) {
			await parentRow
				.locator( '.block-editor-list-view__expander' )
				.click();
		}
		await expect(
			listView.getByText( 'Search Control', { exact: true } )
		).toBeVisible();
		await expect(
			listView.getByText( 'Search Panel', { exact: true } )
		).toBeVisible();
	} );
} );
