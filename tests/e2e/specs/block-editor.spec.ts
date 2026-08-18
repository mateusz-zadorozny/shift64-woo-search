import { expect, test, type Page } from '@playwright/test';
import { wpCli } from '../helpers/env';

const SEARCH = 'shift64-woo-search/search';
const MODAL_SEARCH = 'shift64-woo-search/modal-search';
const CONTROL = 'shift64-woo-search/search-control';
const PANEL = 'shift64-woo-search/search-panel';

async function loginAsAdmin( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	const password = page.locator( '#user_pass' );
	// fill() sets the value atomically; wp-login's deferred JS can swallow
	// keystrokes from pressSequentially and leave a truncated password.
	await password.fill( 'admin' );
	await expect( password ).toHaveValue( 'admin' );
	await page.click( '#wp-submit' );
	await expect( page ).toHaveURL( /wp-admin/ );
}

test.describe( 'composable search block editor', () => {
	// Pre-seed the admin's persisted editor preferences so the editor never
	// opens its startup modals. On a block theme with starter page patterns
	// (Twenty Twenty-Five has them) the "Choose a pattern" modal appears on
	// every new page — asynchronously, once the patterns REST request
	// resolves — and aria-hides the toolbar, so any dismiss-when-visible
	// check in the test races it (and on a retry it can stack on top of the
	// welcome guide, hiding that dialog's close button too). Disabling the
	// preference up front removes the race instead of chasing it.
	test.beforeAll( () => {
		wpCli( [
			'user',
			'meta',
			'update',
			'admin',
			'wp_persisted_preferences',
			JSON.stringify( {
				core: { enableChoosePatternModal: false },
				'core/edit-post': { welcomeGuide: false },
				_modified: '2026-01-01T00:00:00.000Z',
			} ),
			'--format=json',
		] );
	} );

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

		// A block theme with starter page patterns (Twenty Twenty-Five has
		// them) opens a "Choose a pattern" modal on every new page, which
		// aria-hides the editor chrome — role-based lookups like the Document
		// Overview toggle below would never resolve. Give it a moment to
		// appear, then dismiss it; on themes without page patterns the wait
		// just times out and the catch is a no-op.
		const patternDialog = page.getByRole( 'dialog', {
			name: /Choose a pattern/i,
		} );
		await patternDialog
			.waitFor( { state: 'visible', timeout: 5000 } )
			.catch( () => {} );
		if ( await patternDialog.isVisible() ) {
			await patternDialog
				.getByRole( 'button', { name: 'Close' } )
				.click();
			await patternDialog.waitFor( { state: 'hidden' } );
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

				// Styling is per-block via the blocks' own color attributes
				// (native color supports were removed in favor of the custom
				// panels with hover + contrast checking).
				dispatch.updateBlockAttributes( control.clientId, {
					inputTextColor: '#112233',
				} );
				dispatch.updateBlockAttributes( panel.clientId, {
					allResultsColor: '#ddeeff',
				} );

				return {
					parentClientId: parent.clientId,
					childClientIds: [ control.clientId, panel.clientId ],
					modalParentClientId: modalParent.clientId,
					modalChildClientIds: modalParent.innerBlocks.map(
						( block: any ) => block.clientId
					),
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
						select.getBlockAttributes( control.clientId )
							.inputTextColor,
						select.getBlockAttributes( panel.clientId )
							.allResultsColor,
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
		expect( editorContract.styles ).toEqual( [ '#112233', '#ddeeff' ] );
		for ( const supports of editorContract.supports ) {
			// Color moved to block-specific attribute panels; the native
			// color and dimensions supports are intentionally absent.
			expect( supports.color ).toBeUndefined();
			expect( supports.spacing.padding ).toBe( true );
			expect( supports.typography.fontSize ).toBe( true );
		}
		expect( editorContract.supports[ 0 ].dimensions ).toBeUndefined();
		expect( editorContract.supports[ 1 ].dimensions ).toBeUndefined();

		await page.evaluate( ( parentClientId ) => {
			( window as any ).wp.data
				.dispatch( 'core/block-editor' )
				.selectBlock( parentClientId );
		}, editorContract.parentClientId );

		await expect
			.poll( () =>
				page.evaluate( ( panelClientId ) => {
					const iframe = document.querySelector< HTMLIFrameElement >(
						'iframe[name="editor-canvas"]'
					);
					const editorDocument = iframe?.contentDocument || document;
					const panel = editorDocument.querySelector< HTMLElement >(
						`[data-block="${ panelClientId }"]`
					);
					return panel ? getComputedStyle( panel ).display : null;
				}, editorContract.childClientIds[ 1 ] )
			)
			.toBe( 'none' );

		await page.evaluate(
			( parentClientIds ) => {
				const dispatch = ( window as any ).wp.data.dispatch(
					'core/block-editor'
				);
				for ( const clientId of parentClientIds ) {
					dispatch.updateBlockAttributes( clientId, {
						previewOpen: true,
					} );
				}
			},
			[
				editorContract.parentClientId,
				editorContract.modalParentClientId,
			]
		);

		await expect
			.poll( () =>
				page.evaluate( ( contract ) => {
					const iframe = document.querySelector< HTMLIFrameElement >(
						'iframe[name="editor-canvas"]'
					);
					const editorDocument = iframe?.contentDocument || document;
					const getBlock = ( clientId: string ) =>
						editorDocument.querySelector< HTMLElement >(
								`[data-block="${ clientId }"]`
							);
					const inlinePanel = getBlock(
						contract.childClientIds[ 1 ]
					);
					const modalParent = getBlock(
						contract.modalParentClientId
					);
					const modalControl = getBlock(
						contract.modalChildClientIds[ 0 ]
					);
					const modalPanel = getBlock(
						contract.modalChildClientIds[ 1 ]
					);

					if (
						! inlinePanel ||
						! modalParent ||
						! modalControl ||
						! modalPanel
					) {
						return null;
					}

					const controlBox = modalControl.getBoundingClientRect();
					const parentBox = modalParent.getBoundingClientRect();
					const panelBox = modalPanel.getBoundingClientRect();
					const modalTrigger = modalControl.querySelector< HTMLElement >(
						'.shift64-woo-search-modal__trigger'
					);
					const triggerParts = modalTrigger
						? [ ...modalTrigger.querySelectorAll< HTMLElement >( 'span' ) ]
						: [];
					const triggerPartBoxes = triggerParts.map( ( part ) =>
						part.getBoundingClientRect()
					);

					return {
						inlineHasList: Boolean(
							inlinePanel.querySelector( 'ul, ol' )
						),
						inlineHasStorefrontResult: Boolean(
							inlinePanel.querySelector(
								'.shift64-woo-search-results .shift64-woo-search-result'
							)
						),
						modalDisplay: getComputedStyle( modalParent ).display,
						modalHasEditorSurface: Boolean(
							modalPanel.querySelector(
								'.shift64-woo-search-panel__editor-dialog'
							)
						),
						modalHasNativeDialog: Boolean(
							modalPanel.querySelector( 'dialog' )
						),
						modalTriggerPartsDoNotOverlap:
							triggerPartBoxes.length < 2 ||
							triggerPartBoxes[ 0 ].right <=
								triggerPartBoxes[ 1 ].left + 0.5,
						modalPanelFillsParent:
							panelBox.width >= parentBox.width - 1,
						modalPartsDoNotOverlap:
							controlBox.bottom <= panelBox.top + 0.5,
					};
				}, editorContract )
			)
			.toEqual( {
				inlineHasList: false,
				inlineHasStorefrontResult: true,
				modalDisplay: 'grid',
				modalHasEditorSurface: true,
				modalHasNativeDialog: false,
				modalTriggerPartsDoNotOverlap: true,
				modalPanelFillsParent: true,
				modalPartsDoNotOverlap: true,
			} );

		await page.evaluate( ( panelClientId ) => {
			( window as any ).wp.data
				.dispatch( 'core/block-editor' )
				.selectBlock( panelClientId );
		}, editorContract.childClientIds[ 1 ] );

		const overviewToggle = page
			.getByRole( 'button', { name: /Document Overview|List View/i } )
			.first();
		await overviewToggle.click();
		const listView = page.locator( '.block-editor-list-view-tree' );
		await expect( listView ).toBeVisible();

		await expect(
			listView.getByText( 'Search Control', { exact: true } )
		).toBeVisible();
		await expect(
			listView.getByText( 'Search Panel', { exact: true } )
		).toBeVisible();
	} );
} );
