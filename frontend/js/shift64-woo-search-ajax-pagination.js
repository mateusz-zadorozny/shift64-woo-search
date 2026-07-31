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
        breadcrumbs:    '.woocommerce-breadcrumb, .shift64-woo-search-header__breadcrumbs',
        // WooCommerce's standard Product Collection block. Pagination rendered
        // inside it is NOT ours — see ownsPagination() below.
        productCollection: '.wp-block-woocommerce-product-collection'
    };

    // Heading of the opt-in debug panel. Mirrors the literal the PHP renderer
    // emits, and is only ever used when a filter change has to rebuild a bar the
    // initial page render did not produce.
    var DEBUG_BAR_TITLE = 'Shift64 Archive Debug';
    var HISTORY_STATE_KEY = 'shift64WooSearchCatalog';

    var isLoading = false;
    var ownsCurrentHistoryEntry = isPluginHistoryState(window.history.state);

    /**
     * Whether a browser-history entry was created by this script's AJAX swap.
     *
     * Product Collection pagination writes its own router entries. Facet,
     * ordering, and classic-pagination swaps are written here instead, so
     * popstate must restore them here even when the visible grid lives inside
     * a Product Collection.
     *
     * @param {*} state Browser history state.
     * @return {boolean} True for a plugin-owned catalog entry.
     */
    function isPluginHistoryState(state) {
        return Boolean(state && state[HISTORY_STATE_KEY] === true);
    }

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

        // Only meaningful once the load event has landed, since the navigation
        // entry is not final before then. No panel, nothing to report.
        if (document.querySelector('.shift64-woo-search-debug-bar')) {
            if (document.readyState === 'complete') {
                reportNavigationTiming();
            } else {
                window.addEventListener('load', function () {
                    // The entry's own timings settle a tick after the event fires.
                    setTimeout(reportNavigationTiming, 0);
                });
            }
        }
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

            // Preserve filter params from current URL.
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.forEach(function (val, key) {
                if (key.indexOf('filter_') === 0) {
                    params.set(key, val);
                }
            });

            var url = new URL(window.location.href);
            url.search = params.toString();
            stripPagingState(url);
            loadPage(url.toString());
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
            stripPagingState(url);

            // Preserve filter params.
            loadPage(url.toString());
        });

        // Handle browser back/forward — only for search pages.
        window.addEventListener('popstate', function (event) {
            var wrap = document.querySelector(SELECTORS.productWrap);
            if (!wrap) return;

            var leftPluginEntry = ownsCurrentHistoryEntry;
            var enteredPluginEntry = isPluginHistoryState(event.state);
            ownsCurrentHistoryEntry = enteredPluginEntry;

            // The destination state alone is insufficient on Back: the browser
            // reports the entry being entered, while the stale DOM belongs to
            // the plugin entry being left. Restore either side of a transition
            // involving one of our AJAX swaps.
            if (leftPluginEntry || enteredPluginEntry) {
                loadPage(window.location.href, true);
                return;
            }

            // Same ownership rule as the click: when the grid lives inside a
            // Product Collection, that block restores its own state on
            // back/forward. Re-fetching here would duplicate its work.
            if (!ownsPagination(wrap)) return;
            loadPage(window.location.href, true);
        });
    }

    /**
     * Remove every paging form recognized by WordPress and Product Collection.
     */
    function stripPagingState(url) {
        var keysToRemove = [];
        url.searchParams.forEach(function (val, key) {
            if (key === 'page' || key === 'paged' || key === 'query-page' || /^query-\d+-page$/.test(key)) {
                keysToRemove.push(key);
            }
        });
        keysToRemove.forEach(function (key) {
            url.searchParams.delete(key);
        });
        url.pathname = url.pathname.replace(/\/page\/\d+\/?$/, '/');
    }

    /**
     * Remove all filter_* params and reset pagination from a URL object.
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
        stripPagingState(url);
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
     * Write the browser-side half of the debug panel.
     *
     * The server lines stop at "PHP finished". Everything after that — waiting on
     * the wire, downloading the HTML, parsing it, painting — is invisible from
     * PHP, which is what makes a 3ms search look like a 500ms page in the network
     * tab. These lines close that gap.
     *
     * Lives in its own container so the server-line refresh cannot wipe it.
     *
     * @param {string[]} lines Formatted client-timing lines.
     */
    function writeClientTimings(lines) {
        var bar = document.querySelector('.shift64-woo-search-debug-bar');
        if (!bar || !lines.length) return;

        var host = bar.querySelector('.shift64-woo-search-debug-bar__client');
        if (!host) {
            host = document.createElement('span');
            host.className = 'shift64-woo-search-debug-bar__client';
            bar.appendChild(host);
        }

        host.textContent = '';
        lines.forEach(function (line) {
            host.appendChild(document.createTextNode(line));
            host.appendChild(document.createElement('br'));
        });
    }

    /**
     * Round to one decimal, matching the server lines' format.
     *
     * @param {number} ms Duration in milliseconds.
     * @return {string} Formatted duration.
     */
    function ms(value) {
        return (Math.round(value * 10) / 10) + 'ms';
    }

    /**
     * Report how the initial page load actually spent its time.
     *
     * Navigation Timing splits the request the way a network tab does, so the
     * panel can say which part of the wall clock was the server thinking, which
     * was transfer, and which was the browser building the page.
     */
    function reportNavigationTiming() {
        if (!window.performance || typeof performance.getEntriesByType !== 'function') return;

        var nav = performance.getEntriesByType('navigation')[0];
        if (!nav) return;

        // requestStart → responseStart is the server's think time as the browser
        // sees it, so it includes latency the server itself cannot measure.
        var wait = nav.responseStart - nav.requestStart;
        var transfer = nav.responseEnd - nav.responseStart;

        var line = '[browser] load ' + ms(nav.duration) +
            ' → wait ' + ms(wait) +
            ' · transfer ' + ms(transfer) +
            ' · dom ' + ms(nav.domContentLoadedEventEnd - nav.responseEnd);

        if (nav.transferSize) {
            line += ' · ' + Math.round(nav.transferSize / 1024) + 'kB';
        }

        writeClientTimings([line]);
    }

    /**
     * Report a filter/pagination swap the same way.
     *
     * `fetchMs` is measured around the fetch itself; the Resource Timing entry
     * for the same URL breaks that down further when the browser exposes it.
     *
     * @param {string} url     The URL that was fetched.
     * @param {number} fetchMs Wall-clock milliseconds spent in fetch().
     * @param {number} swapMs  Wall-clock milliseconds spent swapping the DOM.
     */
    function reportSwapTiming(url, fetchMs, swapMs) {
        var line = '[browser] swap ' + ms(fetchMs + swapMs) +
            ' → fetch ' + ms(fetchMs) + ' · dom ' + ms(swapMs);

        if (window.performance && typeof performance.getEntriesByName === 'function') {
            var entries = performance.getEntriesByName(url);
            var entry = entries && entries[entries.length - 1];
            if (entry) {
                line += ' · wait ' + ms(entry.responseStart - entry.requestStart) +
                    ' · transfer ' + ms(entry.responseEnd - entry.responseStart);
            }
        }

        writeClientTimings([line]);
    }

    /**
     * Read the debug lines out of a fetched response, whichever shape it has.
     *
     * Two response shapes carry them, and they separate lines differently. The
     * Kadence partial appends a hidden `.shift64-woo-search-archive-debug` block
     * that is plain text with a newline per entry. Every other theme gets a full
     * page back, so the response already contains a rendered
     * `.shift64-woo-search-debug-bar` whose lines container separates entries
     * with `<br>` elements.
     *
     * Hence the walk instead of a plain `textContent` read: `textContent`
     * discards `<br>`, so the full-page shape would collapse into one run-on
     * line.
     *
     * @param {Document} doc Parsed response document.
     * @return {string[]|null} Debug lines, or null when the response carries none.
     */
    function extractDebugLines(doc) {
        var partial = doc.querySelector('.shift64-woo-search-archive-debug');
        var source = partial || doc.querySelector('.shift64-woo-search-debug-bar__lines');
        if (!source) return null;

        var lines = [];
        var current = '';

        Array.prototype.forEach.call(source.childNodes, function (node) {
            if (node.nodeType === 1 && node.tagName === 'BR') {
                lines.push(current);
                current = '';
                return;
            }
            current += node.textContent || '';
        });
        lines.push(current);

        // The partial shape puts every entry in one text node, so split those too.
        return lines
            .reduce(function (all, line) { return all.concat(line.split('\n')); }, [])
            .map(function (line) { return line.trim(); })
            .filter(function (line) { return line !== ''; });
    }

    /**
     * Point the debug bar at the query that produced the results now on screen.
     *
     * The bar is only ever present when a shop manager has switched the panel on,
     * so all three branches here are no-ops on a normal storefront: absent stays
     * absent, and a response with no debug payload removes a stale bar rather
     * than leaving it showing the wrong query.
     *
     * Lines are written as text nodes, never as markup — they embed the raw
     * search term, which is shopper-controlled input.
     *
     * @param {Document} doc Parsed response document.
     */
    function refreshDebugBar(doc) {
        var bar = document.querySelector('.shift64-woo-search-debug-bar');
        var lines = extractDebugLines(doc);

        if (!lines || !lines.length) {
            if (bar) bar.remove();
            return;
        }

        // The panel was switched on after this page rendered, so there is no bar
        // to update. Rebuild it — styling comes from the stylesheet, so the class
        // alone is enough.
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'shift64-woo-search-debug-bar';

            var heading = document.createElement('strong');
            heading.textContent = DEBUG_BAR_TITLE;
            bar.appendChild(heading);
            bar.appendChild(document.createElement('br'));

            var lineHost = document.createElement('span');
            lineHost.className = 'shift64-woo-search-debug-bar__lines';
            bar.appendChild(lineHost);

            document.body.appendChild(bar);
        }

        var container = bar.querySelector('.shift64-woo-search-debug-bar__lines');
        if (!container) return;

        container.textContent = '';
        lines.forEach(function (line) {
            container.appendChild(document.createTextNode(line));
            container.appendChild(document.createElement('br'));
        });
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

        var fetchStart = (window.performance && performance.now) ? performance.now() : 0;
        var fetchMs = 0;

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
                fetchMs = ((window.performance && performance.now) ? performance.now() : 0) - fetchStart;

                var swapStart = (window.performance && performance.now) ? performance.now() : 0;
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

                // Breadcrumbs live outside both swap wrappers. Refresh them
                // separately so a state change from page 2 to a one-page result
                // cannot leave a stale "Page 2" trail above the updated grid.
                var curBreadcrumbs = document.querySelector(SELECTORS.breadcrumbs);
                var newBreadcrumbs = doc.querySelector(SELECTORS.breadcrumbs);
                if (curBreadcrumbs && newBreadcrumbs) {
                    curBreadcrumbs.outerHTML = newBreadcrumbs.outerHTML;
                }

                // The debug bar sits outside every wrapper swapped above, so
                // without this it would keep showing the timings of the query
                // that first loaded the page while the grid below it shows
                // filtered results.
                refreshDebugBar(doc);

                // Measured after the swap so "dom" covers the work the user
                // actually waited on, not just the parse.
                if (document.querySelector('.shift64-woo-search-debug-bar')) {
                    var swapMs = ((window.performance && performance.now) ? performance.now() : 0) - swapStart;
                    reportSwapTiming(url, fetchMs, swapMs);
                }

                // Update URL.
                if (!skipPush) {
                    history.pushState({ shift64WooSearchCatalog: true }, '', url);
                    ownsCurrentHistoryEntry = true;
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
