/**
 * Shift64 Woo Search — AJAX Pagination + Faceted Filters (Vanilla JS)
 *
 * Intercepts pagination link clicks and filter checkbox changes on product
 * search pages, fetches the target page via fetch(), extracts the product
 * grid + pagination + result count + filters from the response HTML, and
 * swaps them in the DOM without a full page reload.
 *
 * Uses history.pushState() for URL updates and back/forward support.
 *
 * @package Shift64_Woo_Search
 */
(function () {
    'use strict';

    // Selectors — Kadence + WooCommerce defaults.
    var SELECTORS = {
        productWrap:    '.kwt-products-wrap, ul.products, .products, .wc-block-grid__products, .wp-block-woocommerce-product-template',
        // Classic WooCommerce markup plus WooCommerce's blockified pagination,
        // which block themes render instead of nav.woocommerce-pagination.
        pagination:     'nav.woocommerce-pagination, nav.wp-block-query-pagination',
        resultCount:    '.woocommerce-result-count',
        ordering:       '.woocommerce-ordering',
        filters:        '.shift64-woo-search-filters',
        filterCheckbox: '.shift64-woo-search-filter__checkbox',
        // WooCommerce's standard Product Collection block. Pagination rendered
        // inside it is NOT ours — see ownsPagination() below.
        productCollection: '.wp-block-woocommerce-product-collection'
    };

    var isLoading = false;

    /**
     * Pagination ownership (issue #20).
     *
     * Who handles a pagination click depends on the markup it lives in:
     *
     *   classic Woo markup, Kadence, custom pagers -> this plugin (AJAX swap)
     *   Product Collection + data-wp-router-region -> WooCommerce (Interactivity API)
     *   Product Collection + forcePageReload       -> plain browser navigation
     *
     * Inside a standard Product Collection the block owns its own navigation,
     * in both of its configurations, so we detect and defer. Previously this
     * script claimed every `a.page-numbers` click, which meant that on block
     * themes BOTH handlers ran: two fetches of the same URL and two history
     * entries per click, so Back needed two presses to leave page 2.
     *
     * Note this cannot be solved with stopPropagation(): the listener runs on
     * `document` in the bubble phase, by which time the Interactivity API's
     * own listener on the link has already fired. Winning the click would need
     * capture-phase interception plus an early preventDefault() — which would
     * take ownership away from WooCommerce everywhere, including where its
     * integration is better (prefetch-on-hover, router-region updates).
     * Deferring is the intent, not a workaround.
     *
     * @param {Element} el Element to test (a pagination link, or the grid).
     * @return {boolean} True when this script owns navigation for that element.
     */
    function ownsPagination(el) {
        if (!el || typeof el.closest !== 'function') return true;
        return !el.closest(SELECTORS.productCollection);
    }

    function init() {
        // Activate if filters element or product wrap exists.
        if (!document.querySelector(SELECTORS.filters) && !document.querySelector(SELECTORS.productWrap)) return;

        delegate();
    }

    /**
     * Build a descendant selector for the anchors inside a comma-separated
     * selector list. Concatenating ' a' onto the list itself would scope the
     * suffix to the last alternative only, leaving the earlier ones matching
     * their container element.
     *
     * @param {string} list Comma-separated selector list.
     * @return {string} Selector list matching only descendant anchors.
     */
    function descendantLinks(list) {
        return list.split(',').map(function (sel) {
            return sel.trim() + ' a';
        }).join(', ');
    }

    /**
     * Event delegation — listen for clicks on pagination links,
     * ordering changes, filter changes, and popstate.
     */
    function delegate() {
        // Pill dropdown toggle.
        document.addEventListener('click', function (e) {
            var pill = e.target.closest('.shift64-woo-search-filter__pill');
            if (pill) {
                var filter = pill.closest('.shift64-woo-search-filter');
                var wasOpen = filter.classList.contains('shift64-woo-search-filter--open');

                // Close all dropdowns.
                document.querySelectorAll('.shift64-woo-search-filter--open').forEach(function (el) {
                    el.classList.remove('shift64-woo-search-filter--open');
                });

                // Toggle the clicked one.
                if (!wasOpen) {
                    filter.classList.add('shift64-woo-search-filter--open');
                }
                return;
            }

            // Click outside any dropdown — close all.
            if (!e.target.closest('.shift64-woo-search-filter__dropdown')) {
                document.querySelectorAll('.shift64-woo-search-filter--open').forEach(function (el) {
                    el.classList.remove('shift64-woo-search-filter--open');
                });
            }
        });

        // Pagination links.
        document.addEventListener('click', function (e) {
            var link = e.target.closest(descendantLinks(SELECTORS.pagination) + ', a.page-numbers');
            if (!link || isLoading) return;

            // Not ours: the Product Collection block handles this click itself
            // (Interactivity API, or a plain reload when forcePageReload is
            // set). Return WITHOUT preventDefault so whichever owner applies
            // still gets the untouched event.
            if (!ownsPagination(link)) return;

            e.preventDefault();
            loadPage(link.href);
        });

        // Ordering select — capture phase fires BEFORE WooCommerce's jQuery
        // handler, which calls form.submit() (bypasses native submit event).
        // stopPropagation prevents WooCommerce from triggering the form submit.
        document.addEventListener('change', function (e) {
            if (!e.target.matches(SELECTORS.ordering + ' select.orderby')) return;
            if (isLoading) return;

            e.stopPropagation();

            var form = e.target.closest('form');
            if (!form) return;

            var params = new URLSearchParams(new FormData(form));
            params.set('paged', '1');

            // Preserve filter params from current URL.
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.forEach(function (val, key) {
                if (key.indexOf('filter_') === 0) {
                    params.set(key, val);
                }
            });

            var base = window.location.pathname.replace(/\/page\/\d+\/?/, '/');
            loadPage(base + '?' + params.toString());
        }, true); // true = capture phase

        // Filter checkboxes — delegated to document for dynamically replaced content.
        document.addEventListener('change', function (e) {
            if (!e.target.matches(SELECTORS.filterCheckbox)) return;
            if (isLoading) return;

            applyFilters();
        });

        // Clear all filters button.
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#shift64-woo-search-filters-clear')) return;
            if (isLoading) return;

            var url = new URL(window.location.href);
            stripFilterParams(url);
            loadPage(url.toString());
        });

        // ── Mobile: open filter modal ──
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#shift64-woo-search-mobile-filter-open')) return;
            var modal = document.getElementById('shift64-woo-search-filter-modal');
            if (modal) {
                modal.classList.add('shift64-woo-search-filter-modal--open');
                document.body.classList.add('shift64-woo-search-modal-open');
            }
        });

        // Mobile: close filter modal (X button or backdrop).
        document.addEventListener('click', function (e) {
            if (e.target.closest('.shift64-woo-search-filter-modal__close') || e.target.closest('.shift64-woo-search-filter-modal__backdrop')) {
                closeFilterModal();
            }
        });

        // ── Mobile: open sort sheet ──
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#shift64-woo-search-mobile-sort-open')) return;
            var sheet = document.getElementById('shift64-woo-search-sort-sheet');
            if (sheet) {
                sheet.classList.add('shift64-woo-search-sort-sheet--open');
                document.body.classList.add('shift64-woo-search-modal-open');
            }
        });

        // Mobile: close sort sheet (X button or backdrop).
        document.addEventListener('click', function (e) {
            if (e.target.closest('.shift64-woo-search-sort-sheet__close') || e.target.closest('.shift64-woo-search-sort-sheet__backdrop')) {
                closeSortSheet();
            }
        });

        // ── Mobile: accordion toggle ──
        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('.shift64-woo-search-filter-modal__section-toggle');
            if (!toggle) return;
            var section = toggle.closest('.shift64-woo-search-filter-modal__section');
            if (section) {
                section.classList.toggle('shift64-woo-search-filter-modal__section--open');
            }
        });

        // ── Mobile: apply filters button ──
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.shift64-woo-search-filter-modal__apply')) return;
            if (isLoading) return;

            applyMobileFilters();
        });

        // ── Mobile: clear filters button ──
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.shift64-woo-search-filter-modal__clear')) return;
            if (isLoading) return;

            closeFilterModal();
            var url = new URL(window.location.href);
            stripFilterParams(url);
            loadPage(url.toString());
        });

        // ── Mobile: sort radio change ──
        document.addEventListener('change', function (e) {
            if (!e.target.matches('.shift64-woo-search-sort-sheet__radio')) return;
            if (isLoading) return;

            var value = e.target.value;
            closeSortSheet();

            var url = new URL(window.location.href);
            url.searchParams.set('orderby', value);
            url.searchParams.delete('paged');
            url.pathname = url.pathname.replace(/\/page\/\d+\/?/, '/');

            // Preserve filter params.
            loadPage(url.toString());
        });

        // Handle browser back/forward — only for search pages.
        window.addEventListener('popstate', function () {
            var wrap = document.querySelector(SELECTORS.productWrap);
            if (!wrap) return;
            // Same ownership rule as the click: when the grid lives inside a
            // Product Collection, that block restores its own state on
            // back/forward. Re-fetching here would duplicate its work.
            if (!ownsPagination(wrap)) return;
            loadPage(window.location.href, true);
        });
    }

    /**
     * Remove all filter_* params, paged param, and /page/N/ from a URL object.
     */
    function stripFilterParams(url) {
        var keysToRemove = [];
        url.searchParams.forEach(function (val, key) {
            if (key.indexOf('filter_') === 0) {
                keysToRemove.push(key);
            }
        });
        keysToRemove.forEach(function (key) {
            url.searchParams.delete(key);
        });
        url.searchParams.delete('paged');
        url.pathname = url.pathname.replace(/\/page\/\d+\/?/, '/');
    }

    /**
     * Read all checked filter checkboxes, build URL params, and load the page.
     */
    function applyFilters() {
        var url = new URL(window.location.href);
        stripFilterParams(url);

        // Build new filter params from checked checkboxes.
        var filterMap = {};
        document.querySelectorAll(SELECTORS.filterCheckbox + ':checked').forEach(function (cb) {
            var taxonomy = cb.getAttribute('data-taxonomy');
            var slug = cb.getAttribute('data-slug');
            var paramKey = 'filter_' + taxonomy;
            if (!filterMap[paramKey]) {
                filterMap[paramKey] = [];
            }
            filterMap[paramKey].push(slug);
        });

        for (var key in filterMap) {
            if (filterMap.hasOwnProperty(key)) {
                url.searchParams.set(key, filterMap[key].join(','));
            }
        }

        loadPage(url.toString());
    }

    /**
     * Close the mobile filter modal and unlock body scroll.
     */
    function closeFilterModal() {
        var modal = document.getElementById('shift64-woo-search-filter-modal');
        if (modal) {
            modal.classList.remove('shift64-woo-search-filter-modal--open');
        }
        document.body.classList.remove('shift64-woo-search-modal-open');
    }

    /**
     * Close the mobile sort bottom sheet and unlock body scroll.
     */
    function closeSortSheet() {
        var sheet = document.getElementById('shift64-woo-search-sort-sheet');
        if (sheet) {
            sheet.classList.remove('shift64-woo-search-sort-sheet--open');
        }
        document.body.classList.remove('shift64-woo-search-modal-open');
    }

    /**
     * Read mobile modal checkboxes, build filter URL, close modal, and load page.
     */
    function applyMobileFilters() {
        closeFilterModal();

        var url = new URL(window.location.href);
        stripFilterParams(url);

        // Build filter params from mobile modal checkboxes.
        var filterMap = {};
        document.querySelectorAll('.shift64-woo-search-filter-modal__checkbox:checked').forEach(function (cb) {
            var taxonomy = cb.getAttribute('data-taxonomy');
            var slug = cb.getAttribute('data-slug');
            var paramKey = 'filter_' + taxonomy;
            if (!filterMap[paramKey]) {
                filterMap[paramKey] = [];
            }
            filterMap[paramKey].push(slug);
        });

        for (var key in filterMap) {
            if (filterMap.hasOwnProperty(key)) {
                url.searchParams.set(key, filterMap[key].join(','));
            }
        }

        loadPage(url.toString());
    }

    /**
     * Fetch a page and swap in the product grid, pagination, result count,
     * and filter sidebar.
     *
     * @param {string}  url        Target URL.
     * @param {boolean} skipPush   If true, don't pushState (used for popstate).
     */
    function loadPage(url, skipPush) {
        isLoading = true;
        var wrap = document.querySelector(SELECTORS.productWrap);

        // Visual loading feedback on product wrap.
        if (wrap) {
            wrap.style.opacity = '0.5';
            wrap.style.pointerEvents = 'none';
            wrap.style.transition = 'opacity 0.2s';
        }

        // Also dim filters during loading.
        var filtersEl = document.querySelector(SELECTORS.filters);
        if (filtersEl) {
            filtersEl.classList.add('shift64-woo-search-filters--loading');
        }

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                // Detect WooCommerce single-result redirect (302 → product page).
                if (res.redirected) {
                    throw new Error('redirected');
                }
                return res.text();
            })
            .then(function (html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');

                // Swap DOM elements.
                // In Kadence, .kwt-products-wrap wraps everything cleanly.
                var kadenceWrap = document.querySelector('.kwt-products-wrap');
                var newKadenceWrap = doc.querySelector('.kwt-products-wrap');
                if (kadenceWrap && newKadenceWrap) {
                    kadenceWrap.innerHTML = newKadenceWrap.innerHTML;
                } else {
                    // Modular swap for standard WooCommerce and block themes.
                    var newGrid = doc.querySelector(SELECTORS.productWrap);
                    if (!newGrid) {
                        throw new Error('missing product wrap');
                    }
                    if (wrap) {
                        wrap.innerHTML = newGrid.innerHTML;
                    }

                    var curFilters = document.querySelector(SELECTORS.filters);
                    var newFilters = doc.querySelector(SELECTORS.filters);
                    if (curFilters && newFilters) {
                        curFilters.outerHTML = newFilters.outerHTML;
                    }

                    var curPag = document.querySelector(SELECTORS.pagination);
                    var newPag = doc.querySelector(SELECTORS.pagination);
                    if (curPag && newPag) {
                        curPag.outerHTML = newPag.outerHTML;
                    } else if (curPag && !newPag) {
                        // The response has a single page of results (typically
                        // after a filter narrows the set), so there is nothing
                        // to page through. Empty the control AND hide it —
                        // an emptied nav still occupies its flex/margin box on
                        // block themes and leaves a visible gap.
                        //
                        // Inline display, not the [hidden] attribute: the UA's
                        // [hidden] { display: none } loses to the theme's
                        // .wp-block-query-pagination { display: flex } rule.
                        // The swap branch above replaces the whole element, so
                        // this style is gone as soon as pagination returns.
                        curPag.innerHTML = '';
                        curPag.style.display = 'none';
                    }

                    var curCount = document.querySelector(SELECTORS.resultCount);
                    var newCount = doc.querySelector(SELECTORS.resultCount);
                    if (curCount && newCount) {
                        curCount.outerHTML = newCount.outerHTML;
                    }
                }

                // Update URL.
                if (!skipPush) {
                    history.pushState(null, '', url);
                }

                // Scroll to top of product grid.
                var scrollTarget = document.querySelector(SELECTORS.productWrap);
                if (scrollTarget) {
                    scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }

                // Restore interactive state.
                wrap = document.querySelector(SELECTORS.productWrap);
                if (wrap) {
                    wrap.style.opacity = '';
                    wrap.style.pointerEvents = '';
                }

                // Ensure mobile modals are closed and body scroll unlocked
                // after AJAX swap (safety net).
                document.body.classList.remove('shift64-woo-search-modal-open');

                isLoading = false;
            })
            .catch(function (err) {
                // On error, fall back to normal navigation.
                isLoading = false;
                console.warn('Shift64 AJAX pagination failed:', err);
                window.location.href = url;
            });
    }

    // Boot.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
