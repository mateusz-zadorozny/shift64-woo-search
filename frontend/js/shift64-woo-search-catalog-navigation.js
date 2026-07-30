/**
 * Shared canonical URL and Interactivity Router navigation helpers.
 *
 * Controls remain ordinary links/forms. Their interactive action calls
 * navigate(), which upgrades to router navigation only when the Product
 * Collection exposes a matching region. Any unsupported or failed path falls
 * back to a normal browser navigation.
 *
 * @package Shift64_Woo_Search
 */

const PRIVATE_PARAMS = [
	'_wpnonce',
	'_wp_http_referer',
	'preview',
	'preview_id',
	'preview_nonce',
	'customize_changeset_uuid',
];

/**
 * Return every paging parameter recognized by WordPress and Product Collection.
 *
 * @param {number|null} queryId Product Collection query ID.
 * @return {string[]} Paging parameter names.
 */
function pagingKeys(queryId) {
	const keys = ['query-page', 'paged'];
	if (Number.isInteger(queryId) && queryId >= 0) {
		keys.push(`query-${queryId}-page`);
	}
	return keys;
}

/**
 * Build a canonical storefront URL without mutating browser state.
 *
 * Search, sort, or facet changes reset every paging form. A page-only change
 * preserves search/filter/sort state.
 *
 * @param {string|URL} source Current storefront URL.
 * @param {Record<string, string|string[]|number|null>} changes Canonical changes.
 * @param {number|null} queryId Product Collection query ID.
 * @return {string} Canonical absolute URL.
 */
export function buildCatalogUrl(source, changes, queryId = null) {
	const url = new URL(source, window.location.href);
	PRIVATE_PARAMS.forEach((key) => url.searchParams.delete(key));

	let resetsPage = false;
	Object.entries(changes).forEach(([key, value]) => {
		if (key !== 'page') {
			resetsPage = true;
		}
		if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
			url.searchParams.delete(key);
			return;
		}
		url.searchParams.set(key, Array.isArray(value) ? [...new Set(value)].sort().join(',') : String(value));
	});

	if (resetsPage) {
		pagingKeys(queryId).forEach((key) => url.searchParams.delete(key));
		[...url.searchParams.keys()]
			.filter((key) => /^query-\d+-page$/.test(key))
			.forEach((key) => url.searchParams.delete(key));
		url.pathname = url.pathname.replace(/\/page\/\d+\/?$/, '/');
	} else if (Object.prototype.hasOwnProperty.call(changes, 'page')) {
		url.searchParams.delete('page');
		const pageKey = Number.isInteger(queryId) ? `query-${queryId}-page` : 'query-page';
		url.searchParams.set(pageKey, String(Math.max(1, Number(changes.page) || 1)));
	}

	url.searchParams.sort();
	return url.toString();
}

/**
 * Whether the document exposes an enhanced Product Collection router region.
 *
 * @param {ParentNode} root Search root.
 * @return {boolean} True when region-based navigation is available.
 */
export function hasEnhancedProductCollection(root = document) {
	return Boolean(root.querySelector('[data-wp-router-region^="wc-product-collection-"]'));
}

/**
 * Upgrade a progressive link/form destination to router navigation.
 *
 * @param {string|URL} destination Canonical destination.
 * @param {{forceReload?: boolean}} options Navigation options.
 * @return {Promise<void>}
 */
export async function navigate(destination, { forceReload = false } = {}) {
	const url = String(destination);
	if (forceReload || !hasEnhancedProductCollection()) {
		window.location.assign(url);
		return;
	}

	try {
		const { actions } = await import('@wordpress/interactivity-router');
		await actions.navigate(url);
	} catch (error) {
		window.location.assign(url);
	}
}
