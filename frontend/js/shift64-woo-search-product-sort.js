/**
 * Interactivity API store for Shift64 Product Sort block.
 *
 * @package Shift64_Woo_Search
 */

import { store, getContext } from '@wordpress/interactivity';
import { buildCatalogUrl, navigate } from 'shift64-woo-search/catalog-navigation';

store('shift64/woo-search-product-sort', {
	actions: {
		*onSortChange(event) {
			const select = event.target;
			const orderby = select ? select.value : '';
			const context = getContext();
			const queryId = context?.queryId ?? null;
			const targetUrl = buildCatalogUrl(window.location.href, { orderby }, queryId);
			yield navigate(targetUrl);
		},
	},
});
