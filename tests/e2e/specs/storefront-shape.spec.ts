import { expect, test } from '@playwright/test';
import { BLOCK_THEME, wpCli } from '../helpers/env';
import { E2E_HEADER_INLINE_INSTANCE, E2E_HEADER_MODAL_INSTANCE, SEL } from '../helpers/search';

const HEADER_PROVISIONER = 'bin/provision-block-theme-header.php';

function provisionHeader(): void {
	wpCli(['eval-file', HEADER_PROVISIONER, `theme=${BLOCK_THEME}`]);
}

function headerTemplateId(): string {
	return wpCli([
		'eval',
		`$template = get_block_template('${BLOCK_THEME}//header', 'wp_template_part'); echo $template ? (int) $template->wp_id : 0;`,
	]).trim();
}

test.describe('provisioned storefront shape', () => {
	test('the shop front page exposes two accessible, working header searches', async ({
		page,
	}) => {
		const shopPageId = wpCli(['option', 'get', 'woocommerce_shop_page_id']).trim();
		expect(shopPageId).toMatch(/^\d+$/);
		expect(Number(shopPageId)).toBeGreaterThan(0);
		expect(wpCli(['option', 'get', 'show_on_front']).trim()).toBe('page');
		expect(wpCli(['option', 'get', 'page_on_front']).trim()).toBe(shopPageId);

		await page.goto('/');
		await expect(page.locator(SEL.productsGrid).first()).toBeVisible();

		const inlineInput = page.locator(`#${E2E_HEADER_INLINE_INSTANCE}-input`);
		const inlineListbox = page.locator(`#${E2E_HEADER_INLINE_INSTANCE}-listbox`);
		await expect(inlineInput).toHaveAttribute(
			'aria-controls',
			`${E2E_HEADER_INLINE_INSTANCE}-listbox`
		);
		await expect(inlineListbox).toHaveCount(1);
		await inlineInput.fill('athena');
		await expect(inlineListbox.locator(SEL.rowTitle).first()).toContainText(/athena/i);
		await inlineInput.press('Escape');
		await expect(inlineListbox).not.toHaveClass(/shift64-woo-search-results--visible/);

		const modalTrigger = page.locator(
			`${SEL.modalTrigger}[aria-controls="${E2E_HEADER_MODAL_INSTANCE}-dialog"]`
		);
		const modal = page.locator(`#${E2E_HEADER_MODAL_INSTANCE}-dialog`);
		await expect(modalTrigger).toHaveCount(1);
		await expect(modal).toHaveCount(1);
		await modalTrigger.click();
		await expect(modal).toBeVisible();

		const modalInput = modal.locator(`#${E2E_HEADER_MODAL_INSTANCE}-input`);
		await expect(modalInput).toHaveAttribute(
			'aria-controls',
			`${E2E_HEADER_MODAL_INSTANCE}-listbox`
		);
		await modalInput.fill('athena');
		await expect(
			modal.locator(`#${E2E_HEADER_MODAL_INSTANCE}-listbox ${SEL.rowTitle}`).first()
		).toContainText(/athena/i);
	});

	test('header provisioning is idempotent, theme-scoped, and ignores draft navigation posts', () => {
		provisionHeader();
		const originalTemplateId = headerTemplateId();
		expect(Number(originalTemplateId)).toBeGreaterThan(0);

		provisionHeader();
		expect(headerTemplateId()).toBe(originalTemplateId);
		expect(
			wpCli(['post', 'term', 'list', originalTemplateId, 'wp_theme', '--field=name']).trim()
		).toBe(BLOCK_THEME);
		expect(
			wpCli([
				'post',
				'term',
				'list',
				originalTemplateId,
				'wp_template_part_area',
				'--field=name',
			]).trim()
		).toBe('header');

		const originalContent = wpCli(['post', 'get', originalTemplateId, '--field=post_content']);
		const navigationMatch = originalContent.match(/<!-- wp:navigation {"ref":(\d+),/);
		expect(navigationMatch).not.toBeNull();
		const originalNavigationId = navigationMatch?.[1] as string;
		let fixtureNavigationId = '';

		try {
			wpCli(['post', 'update', originalNavigationId, '--post_status=draft']);
			fixtureNavigationId = wpCli([
				'post',
				'create',
				'--post_type=wp_navigation',
				'--post_status=publish',
				'--post_title=E2E Published Navigation',
				'--post_content=<!-- wp:page-list /-->',
				'--porcelain',
			]).trim();

			const firstPublishedNavigationId = wpCli([
				'post',
				'list',
				'--post_type=wp_navigation',
				'--post_status=publish',
				'--orderby=ID',
				'--order=ASC',
				'--posts_per_page=1',
				'--field=ID',
			]).trim();

			provisionHeader();
			const reprovisionedContent = wpCli([
				'post',
				'get',
				headerTemplateId(),
				'--field=post_content',
			]);
			expect(reprovisionedContent).toContain(
				`<!-- wp:navigation {"ref":${firstPublishedNavigationId},`
			);
			expect(reprovisionedContent).not.toContain(
				`<!-- wp:navigation {"ref":${originalNavigationId},`
			);
		} finally {
			wpCli(['post', 'update', originalNavigationId, '--post_status=publish']);
			if (fixtureNavigationId) {
				wpCli(['post', 'delete', fixtureNavigationId, '--force']);
			}
			provisionHeader();
		}
	});
});
