/**
 * Shift64 Woo Search — Autocomplete (Vanilla JS)
 *
 * Attaches to search inputs matching the configured CSS selector.
 * Debounced fetch → SHORTINIT endpoint → dropdown rendering → keyboard nav.
 *
 * @package Shift64_Woo_Search
 */
(function () {
    'use strict';

    var config = window.shift64_woo_search_config || {};
    if (!config.endpoint || !config.selectors) return;

    // ── State ──────────────────────────────────────────────────
    var instances = [];

    // ── Init ───────────────────────────────────────────────────
    function init() {
        var inputs = document.querySelectorAll(config.selectors);
        inputs.forEach(function (input) {
            instances.push(new Shift64WooSearch(input));
        });
    }

    // ── Constructor ────────────────────────────────────────────
    function Shift64WooSearch(input) {
        this.input = input;
        this.debounceTimer = null;
        this.abortController = null;
        this.activeIndex = -1;
        this.results = [];
        this.lastQuery = '';
        this.isFocusRequest = false;

        this.dropdown = null;
        this.overlay = null;
        this.redisDown = false;

        this.createDropdown();
        this.bindEvents();
    }

    // ── Dropdown DOM ───────────────────────────────────────────
    Shift64WooSearch.prototype.createDropdown = function () {
        // Use the outer search wrapper as positioning context — .shift64-woo-search-field
        // has overflow:hidden (for border-radius) which clips the dropdown.
        var wrapper = this.input.closest('.shift64-woo-search-main-header__search, .shift64-woo-search-header-search')
            || this.input.closest('.shift64-woo-search-field')
            || this.input.parentElement;
        wrapper.style.position = 'relative';

        this.dropdown = document.createElement('div');
        this.dropdown.className = 'shift64-woo-search-results';
        this.dropdown.setAttribute('role', 'listbox');
        this.dropdown.setAttribute('aria-label', 'Search results');

        this.wrapper = wrapper;
        wrapper.appendChild(this.dropdown);

        // Overlay for click-to-close. The search field is raised above the
        // overlay via z-index in CSS, so mouse events on the input/buttons
        // reach them directly — overlay only catches clicks on the rest of
        // the page and uses them to close the tray.
        this.overlay = document.createElement('div');
        this.overlay.className = 'shift64-woo-search-results__overlay';
        var self = this;
        this.overlay.addEventListener('click', function () {
            self.close();
        });
        wrapper.appendChild(this.overlay);
    };

    // ── Events ─────────────────────────────────────────────────
    Shift64WooSearch.prototype.bindEvents = function () {
        var self = this;

        this.input.addEventListener('input', function () {
            self.onInput();
        });

        this.input.addEventListener('keydown', function (e) {
            self.onKeydown(e);
        });

        this.input.addEventListener('focus', function () {
            // Expand wrapper immediately on focus.
            self.wrapper.classList.add('shift64-woo-search--expanded');
            if (!self.input.value.trim()) {
                // Empty input — fetch random suggestions on focus.
                self.fetchFocusSuggestions();
            } else if (self.results.length > 0 && self.input.value.length >= (config.minQueryLength || 2)) {
                self.show();
            }
        });

        // Close on click outside. Check the entire wrapper (which contains
        // the input + clear button + search button) so that interactions with
        // any part of the search field don't accidentally close the tray.
        document.addEventListener('click', function (e) {
            if (!self.wrapper.contains(e.target) && !self.dropdown.contains(e.target)) {
                self.close();
            }
        });

        // Search button — redirect to full search on click.
        if (config.searchButtonSelector) {
            var form = self.input.closest('form');
            var scope = form || document;
            var btn = scope.querySelector(config.searchButtonSelector);
            if (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var query = self.input.value.trim();
                    if (query.length >= (config.minQueryLength || 2)) {
                        self.redirectToSearch(query);
                    }
                });
            }
        }

        // Also prevent native form submit — use our redirect instead.
        var parentForm = self.input.closest('form');
        if (parentForm) {
            parentForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var query = self.input.value.trim();
                if (query.length >= (config.minQueryLength || 2)) {
                    self.redirectToSearch(query);
                }
            });
        }
    };

    // ── Input Handler ──────────────────────────────────────────
    Shift64WooSearch.prototype.onInput = function () {
        var self = this;
        var query = this.input.value.trim();

        // Keep wrapper expanded while the input is active (focused). Never
        // collapse here — clearing the input via the X button shouldn't shrink
        // the field. Collapse only happens in close() when the user clicks
        // outside the search area.
        this.wrapper.classList.add('shift64-woo-search--expanded');

        clearTimeout(this.debounceTimer);

        if (query.length < (config.minQueryLength || 2)) {
            this.results = [];
            if (!query.length) {
                // Input cleared — re-show focus suggestions.
                this.fetchFocusSuggestions();
            }
            // Don't close for short queries — keep current suggestions visible.
            return;
        }

        this.debounceTimer = setTimeout(function () {
            self.search(query);
        }, config.debounce || 150);
    };

    // ── Keyboard Navigation ────────────────────────────────────
    Shift64WooSearch.prototype.onKeydown = function (e) {
        var items = this.dropdown.querySelectorAll('.shift64-woo-search-result');
        if (!items.length && e.key !== 'Enter') return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.activeIndex = Math.min(this.activeIndex + 1, items.length - 1);
                this.highlightItem(items);
                break;

            case 'ArrowUp':
                e.preventDefault();
                this.activeIndex = Math.max(this.activeIndex - 1, -1);
                this.highlightItem(items);
                break;

            case 'Enter':
                e.preventDefault();
                if (this.activeIndex >= 0 && items[this.activeIndex]) {
                    var url = items[this.activeIndex].getAttribute('data-url');
                    if (url) window.location.href = url;
                } else {
                    // No item selected — do full search redirect.
                    this.redirectToSearch(this.input.value.trim());
                }
                break;

            case 'Escape':
                this.close();
                this.input.blur();
                break;
        }
    };

    Shift64WooSearch.prototype.highlightItem = function (items) {
        items.forEach(function (item, i) {
            item.classList.toggle('shift64-woo-search-result--active', i === this.activeIndex);
        }.bind(this));

        // Scroll active item into view.
        if (this.activeIndex >= 0 && items[this.activeIndex]) {
            items[this.activeIndex].scrollIntoView({ block: 'nearest' });
        }
    };

    // ── Search Request ─────────────────────────────────────────
    Shift64WooSearch.prototype.search = function (query) {
        var self = this;

        // Redis known to be down — don't attempt, let user use normal search.
        if (this.redisDown) return;

        // Abort previous request.
        clearTimeout(this.showTimer);
        clearTimeout(this.searchTimeout);
        if (this.abortController) {
            this.abortController.abort();
        }

        this.lastQuery = query;

        // Delay showing tray — avoids flash if Redis responds quickly with error.
        this.showTimer = setTimeout(function () {
            self.dropdown.innerHTML = '<div class="shift64-woo-search-results__loading">Searching...</div>';
            self.show();
        }, 150);

        // Timeout — if no response in 5s, show error.
        this.searchTimeout = setTimeout(function () {
            if (self.abortController) {
                self.abortController.abort();
                self.abortController = null;
            }
            self.redisDown = true;
            self.results = [];
            clearTimeout(self.showTimer);
            self.dropdown.innerHTML = '<div class="shift64-woo-search-results__empty">'
                + escapeHtml(config.timeoutText || 'Quick search timed out. Use the search button.')
                + '</div>';
            self.show();
        }, 5000);

        this.abortController = new AbortController();

        var url = config.endpoint
            + '?q=' + encodeURIComponent(query)
            + '&mode=autocomplete'
            + '&limit=' + (config.limit || 7);

        fetch(url, { signal: this.abortController.signal })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self.abortController = null;
                clearTimeout(self.showTimer);
                clearTimeout(self.searchTimeout);

                if (!data.success && data.fallback) {
                    // Redis unavailable — hide tray, stop trying until page reload.
                    self.redisDown = true;
                    self.results = [];
                    self.close();
                    return;
                }

                if (!data.success && data.error) {
                    self.results = [];
                    self.renderResults({ results: [], _error: data.error });
                    return;
                }

                self.results = data.results || [];
                self.renderResults(data);
            })
            .catch(function (err) {
                clearTimeout(self.showTimer);
                clearTimeout(self.searchTimeout);
                if (err.name === 'AbortError') return;
                // On network error — hide tray, let user use normal search.
                self.close();
            });
    };

    // ── Search icon SVG for suggestion items ─────────────────
    var searchIconSvg = '<svg class="shift64-woo-search-result__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';

    // ── Render Results (3-section dropdown) ───────────────────
    Shift64WooSearch.prototype.renderResults = function (data) {
        this.activeIndex = -1;
        var html = '';
        var hasSuggestions = data.suggestions && data.suggestions.length > 0;
        var hasCategories  = data.categories && data.categories.length > 0;
        var hasProducts    = data.results && data.results.length > 0;
        var hasAnything    = hasSuggestions || hasCategories || hasProducts;

        // Scrollable content wrapper.
        html += '<div class="shift64-woo-search-results__scroll">';

        if (data._error) {
            html += '<div class="shift64-woo-search-results__empty">'
                + escapeHtml(config.errorText || 'Something went wrong. Please try again.')
                + '</div>';
        } else if (!hasAnything) {
            html += '<div class="shift64-woo-search-results__empty">'
                + escapeHtml(config.noResultsText || 'No products found')
                + '</div>';
        } else {
            // Section 1: Suggestions.
            if (hasSuggestions) {
                html += '<div class="shift64-woo-search-section shift64-woo-search-section--suggestions">';
                html += '<div class="shift64-woo-search-section__header">' + escapeHtml(config.suggestionsHeaderText || 'SEARCH SUGGESTIONS') + '</div>';
                for (var s = 0; s < data.suggestions.length; s++) {
                    var sugUrl = (config.fallbackUrl || '/?s={query}&post_type=product')
                        .replace('{query}', encodeURIComponent(data.suggestions[s]));
                    html += '<div class="shift64-woo-search-result shift64-woo-search-result--suggestion" role="option" data-url="' + escapeAttr(sugUrl) + '">';
                    html += searchIconSvg;
                    html += '<span class="shift64-woo-search-result__text">' + escapeHtml(data.suggestions[s]) + '</span>';
                    html += '</div>';
                }
                html += '</div>';
            }

            // Section 2: Categories.
            if (hasCategories) {
                html += '<div class="shift64-woo-search-section shift64-woo-search-section--categories">';
                html += '<div class="shift64-woo-search-section__header">' + escapeHtml(config.categoriesHeaderText || 'CATEGORIES') + '</div>';
                for (var c = 0; c < data.categories.length; c++) {
                    var cat = data.categories[c];
                    html += '<div class="shift64-woo-search-result shift64-woo-search-result--category" role="option" data-url="' + escapeAttr(cat.url) + '">';
                    html += '<span class="shift64-woo-search-result__text">' + escapeHtml(cat.name) + '</span>';
                    html += '</div>';
                }
                html += '</div>';
            }

            // Section 3: Products.
            if (hasProducts) {
                html += '<div class="shift64-woo-search-section shift64-woo-search-section--products">';
                html += '<div class="shift64-woo-search-section__header">' + escapeHtml(config.productsHeaderText || 'PRODUCTS') + '</div>';
                for (var i = 0; i < data.results.length; i++) {
                    html += this.renderItem(data.results[i]);
                }
                html += '</div>';
            }
        }

        html += '</div>'; // close __scroll

        // "See all results" — sticky footer (only when there's a query).
        var query = data.query || this.lastQuery;
        if (query) {
            var seeAllUrl = '/?s=' + encodeURIComponent(query) + '&post_type=product';
            html += '<a class="shift64-woo-search-results__all" href="' + escapeAttr(seeAllUrl) + '">'
                + escapeHtml(config.seeAllText || 'See all results')
                + ' &rarr;</a>';
        }

        this.dropdown.innerHTML = html;
        this.show();

        // Bind click events on all navigable items.
        var self = this;
        this.dropdown.querySelectorAll('.shift64-woo-search-result').forEach(function (item) {
            item.addEventListener('click', function () {
                var url = item.getAttribute('data-url');
                if (url) window.location.href = url;
            });
        });
    };

    Shift64WooSearch.prototype.renderItem = function (item) {
        var html = '<div class="shift64-woo-search-result" role="option" data-url="' + escapeAttr(item.url) + '">';

        // Image.
        if (config.showImage !== false && item.image) {
            html += '<img class="shift64-woo-search-result__image" src="' + escapeAttr(item.image) + '" alt="" loading="lazy" />';
        }

        html += '<div class="shift64-woo-search-result__content">';

        // Split title by first comma: main title + subtitle (details after comma).
        var commaIdx = item.title.indexOf(',');
        var mainTitle = commaIdx !== -1 ? item.title.substring(0, commaIdx).trim() : item.title;
        var subtitle  = commaIdx !== -1 ? item.title.substring(commaIdx + 1).trim() : '';

        html += '<span class="shift64-woo-search-result__title">' + escapeHtml(mainTitle) + '</span>';

        // Meta line (SKU | subtitle).
        var metaParts = [];
        if (config.showSku !== false && item.sku) {
            metaParts.push('<span class="shift64-woo-search-result__sku">' + escapeHtml(item.sku) + '</span>');
        }
        if (subtitle) {
            metaParts.push('<span class="shift64-woo-search-result__subtitle">' + escapeHtml(subtitle) + '</span>');
        }

        if (metaParts.length > 0) {
            html += '<div class="shift64-woo-search-result__meta">'
                + metaParts.join('<span class="shift64-woo-search-result__meta-sep">|</span>')
                + '</div>';
        }

        html += '</div></div>';
        return html;
    };

    // ── Show / Close ───────────────────────────────────────────
    Shift64WooSearch.prototype.show = function () {
        this.dropdown.classList.add('shift64-woo-search-results--visible');
        this.overlay.classList.add('shift64-woo-search-results__overlay--visible');
    };

    Shift64WooSearch.prototype.close = function () {
        this.dropdown.classList.remove('shift64-woo-search-results--visible');
        this.overlay.classList.remove('shift64-woo-search-results__overlay--visible');
        this.activeIndex = -1;
        // Collapse wrapper back when input is empty.
        if (!this.input.value.trim()) {
            this.wrapper.classList.remove('shift64-woo-search--expanded');
        }
    };

    // ── Focus Suggestions ───────────────────────────────────────
    Shift64WooSearch.prototype.fetchFocusSuggestions = function () {
        var self = this;
        if (this.redisDown) return;

        this.isFocusRequest = true;
        var url = config.endpoint + '?mode=suggestions';

        fetch(url)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                // Discard if user started typing while request was in flight.
                if (self.input.value.trim()) {
                    self.isFocusRequest = false;
                    return;
                }
                self.isFocusRequest = false;
                if (data.success && data.suggestions && data.suggestions.length > 0) {
                    self.renderResults({
                        suggestions: data.suggestions,
                        categories: [],
                        results: [],
                        query: ''
                    });
                }
            })
            .catch(function () {
                self.isFocusRequest = false;
            });
    };

    // ── Fallback Redirect ──────────────────────────────────────
    Shift64WooSearch.prototype.redirectToSearch = function (query) {
        var url = (config.fallbackUrl || '/?s={query}&post_type=product')
            .replace('{query}', encodeURIComponent(query));
        window.location.href = url;
    };

    // ── Utilities ──────────────────────────────────────────────
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escapeAttr(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // ── Boot ───────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
