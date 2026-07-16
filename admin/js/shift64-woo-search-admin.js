/**
 * Shift64 Woo Search — Admin JS
 */
(function () {
    'use strict';

    var cfg = window.shift64_woo_search_admin || {};

    document.addEventListener('DOMContentLoaded', function () {
        initTestSearch();
        initTuning();
        initSynonyms();
        initSuggestions();
        initCategoryBoosts();
        initCategoryExclude();
        initStats();
        initWeights();
        initSettings();
        initFilters();
        initIndex();
        initFlagButtons();
    });

    // ── Test Search ────────────────────────────────────────────
    function initTestSearch() {
        var btn = document.getElementById('s64ws-test-run');
        var input = document.getElementById('s64ws-test-query');
        if (!btn || !input) return;
        btn.addEventListener('click', runTest);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runTest(); } });
    }

    function runTest() {
        var query = document.getElementById('s64ws-test-query').value.trim();
        var mode = document.getElementById('s64ws-test-mode').value;
        var limit = document.getElementById('s64ws-test-limit').value;
        if (!query) return;
        document.getElementById('s64ws-test-run').disabled = true;

        ajax('shift64_woo_search_test_query', { query: query, mode: mode, limit: limit }, function (res) {
            document.getElementById('s64ws-test-run').disabled = false;
            if (!res.success) { alert(res.data ? res.data.message : 'Error'); return; }

            var d = res.data;
            var passEl = document.getElementById('s64ws-test-pass');
            if (passEl && d.search_pass) { passEl.textContent = d.search_pass; passEl.className = 'shift64-woo-search-badge shift64-woo-search-badge--pass-' + d.search_pass; }

            var queriesContainer = document.getElementById('s64ws-test-debug-queries-container');
            if (queriesContainer && d.debug_queries) {
                var html = '';
                for (var pass in d.debug_queries) { html += '<div class="shift64-woo-search-test__debug-row"><span class="label">' + esc(pass) + ':</span> <code>' + esc(d.debug_queries[pass]) + '</code></div>'; }
                queriesContainer.innerHTML = html;
            }

            var ftQueryEl = document.getElementById('s64ws-test-ft-query');
            if (ftQueryEl && d.debug_queries) { ftQueryEl.textContent = d.debug_queries[d.search_pass] || ''; }

            document.getElementById('s64ws-test-time').textContent = d.time_ms + 'ms';
            document.getElementById('s64ws-test-count').textContent = d.count + (d.outofstock_mode === 'demote' ? ' (demote: ' + d.demote_factor + ')' : ' (oos: ' + d.outofstock_mode + ')');
            document.getElementById('s64ws-test-debug').style.display = '';

            var tbody = document.getElementById('s64ws-test-results-body');
            var table = document.getElementById('s64ws-test-results');
            var empty = document.getElementById('s64ws-test-empty');

            if (!d.results || d.results.length === 0) { table.style.display = 'none'; empty.style.display = ''; tbody.innerHTML = ''; return; }
            empty.style.display = 'none'; table.style.display = '';

            var rowsHtml = '';
            for (var i = 0; i < d.results.length; i++) {
                var r = d.results[i];
                var isDemoted = r.raw_score && r.score < r.raw_score;
                var stockClass = r.stock_status === 'outofstock' ? 'shift64-woo-search-stock--out' : '';
                var rowClass = isDemoted ? 'shift64-woo-search-row--demoted' : '';
                rowsHtml += '<tr class="' + rowClass + '">';
                rowsHtml += '<td>' + (i + 1) + '</td>';
                rowsHtml += '<td>' + esc(r.id) + '</td>';
                rowsHtml += '<td><a href="/wp-admin/post.php?post=' + r.id + '&action=edit" target="_blank">' + esc(r.title) + '</a></td>';
                rowsHtml += '<td><code>' + esc(r.sku) + '</code></td>';
                rowsHtml += '<td>' + esc((r.category || '').split('|')[0]) + '</td>';
                rowsHtml += '<td class="' + stockClass + '">' + esc(r.stock_status || 'instock') + '</td>';
                rowsHtml += '<td>' + (r.raw_score !== undefined ? round(r.raw_score) : '-') + '</td>';
                rowsHtml += '<td>' + round(r.score) + (isDemoted ? ' <span class="shift64-woo-search-demoted-tag">demoted</span>' : '') + '</td>';
                rowsHtml += '<td>' + flagButtons(r.id, r.promoted, r.excluded) + '</td>';
                rowsHtml += '</tr>';
            }
            tbody.innerHTML = rowsHtml;
        });
    }

    // ── Tuning ─────────────────────────────────────────────────
    function initTuning() {
        var btn = document.getElementById('s64ws-tuning-run');
        var input = document.getElementById('s64ws-tuning-query');
        if (!btn || !input) return;
        btn.addEventListener('click', runTuning);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runTuning(); } });
    }

    function runTuning() {
        var query = document.getElementById('s64ws-tuning-query').value.trim();
        var limit = document.getElementById('s64ws-tuning-limit').value;
        if (!query) return;
        document.getElementById('s64ws-tuning-run').disabled = true;

        ajax('shift64_woo_search_tuning_query', { query: query, limit: limit }, function (res) {
            document.getElementById('s64ws-tuning-run').disabled = false;
            if (!res.success) { alert(res.data ? res.data.message : 'Error'); return; }

            var d = res.data;
            var grid = document.getElementById('s64ws-tuning-grid');
            var container = document.getElementById('s64ws-tuning-results');
            document.getElementById('s64ws-tuning-empty').style.display = 'none';
            container.style.display = '';

            var passNames = ['strict', 'token_reduced', 'or_prefix', 'fuzzy'];
            var passLabels = { strict: 'Strict (prefix)', token_reduced: 'Token Reduced', or_prefix: 'OR Prefix', fuzzy: 'Fuzzy (fallback)' };
            var html = '';

            for (var p = 0; p < passNames.length; p++) {
                var name = passNames[p];
                var pass = d.passes[name];
                if (!pass) continue;

                html += '<div class="shift64-woo-search-tuning__card">';
                html += '<h4 class="shift64-woo-search-tuning__card-title"><span class="shift64-woo-search-badge shift64-woo-search-badge--pass-' + name + '">' + passLabels[name] + '</span>';
                html += ' <span class="shift64-woo-search-tuning__meta">' + pass.count + ' results, ' + pass.time_ms + 'ms</span></h4>';
                html += '<div class="shift64-woo-search-tuning__query"><code>' + esc(pass.ft_query) + '</code></div>';

                if (pass.results && pass.results.length > 0) {
                    html += '<table class="wp-list-table widefat fixed striped shift64-woo-search-tuning__table">';
                    html += '<thead><tr><th>#</th><th>ID</th><th>Title</th><th>SKU</th><th>Score</th><th>Actions</th></tr></thead><tbody>';
                    for (var i = 0; i < pass.results.length; i++) {
                        var r = pass.results[i];
                        html += '<tr><td>' + (i + 1) + '</td><td>' + esc(r.id) + '</td><td>' + esc(r.title) + '</td>';
                        html += '<td><code>' + esc(r.sku) + '</code></td><td>' + round(r.score) + '</td>';
                        html += '<td>' + flagButtons(r.id, r.promoted, r.excluded) + '</td></tr>';
                    }
                    html += '</tbody></table>';
                } else {
                    html += '<p class="shift64-woo-search-tuning__no-results">No results</p>';
                }
                html += '</div>';
            }
            grid.innerHTML = html;
        });
    }

    // ── Flag Buttons (Promoted/Excluded) ───────────────────────
    function flagButtons(id, promoted, excluded) {
        return '<button class="s64ws-flag-btn' + (promoted ? ' s64ws-flag-btn--promoted-active' : '') + '" data-id="' + id + '" data-flag="promoted" data-value="' + (promoted ? '0' : '1') + '" title="Toggle promoted">&#9733;</button>'
             + '<button class="s64ws-flag-btn' + (excluded ? ' s64ws-flag-btn--excluded-active' : '') + '" data-id="' + id + '" data-flag="excluded" data-value="' + (excluded ? '0' : '1') + '" title="Toggle excluded">&#10005;</button>';
    }

    function initFlagButtons() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.s64ws-flag-btn');
            if (!btn) return;
            e.preventDefault();
            btn.disabled = true;

            ajax('shift64_woo_search_toggle_product_flag', {
                product_id: btn.dataset.id,
                flag: btn.dataset.flag,
                value: btn.dataset.value
            }, function (res) {
                btn.disabled = false;
                if (!res.success) return;
                var isActive = res.data.value;
                var activeClass = 's64ws-flag-btn--' + res.data.flag + '-active';
                if (isActive) { btn.classList.add(activeClass); btn.dataset.value = '0'; }
                else { btn.classList.remove(activeClass); btn.dataset.value = '1'; }
            });
        });
    }

    // ── Synonyms ───────────────────────────────────────────────
    function initSynonyms() {
        var addBtn = document.getElementById('s64ws-syn-add');
        if (!addBtn) return;

        addBtn.addEventListener('click', function () {
            var input = document.getElementById('s64ws-syn-input');
            var terms = input.value.trim();
            if (!terms) return;

            ajax('shift64_woo_search_synonys64ws_add', { terms: terms }, function (res) {
                if (!res.success) { showStatus('s64ws-syn-status', res); return; }
                input.value = '';
                renderSynonyms(res.data.synonyms);
                showStatus('s64ws-syn-status', res);
            });
        });

        // Export.
        var exportBtn = document.getElementById('s64ws-syn-export');
        if (exportBtn) {
            exportBtn.addEventListener('click', function () {
                ajax('shift64_woo_search_synonys64ws_export', {}, function (res) {
                    if (!res.success) return;
                    var blob = new Blob([res.data.text], { type: 'text/plain;charset=utf-8' });
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'shift64-woo-search-synonyms.txt';
                    a.click();
                    URL.revokeObjectURL(a.href);
                });
            });
        }

        // Import toggle + file loader.
        var importToggle = document.getElementById('s64ws-syn-import-toggle');
        if (importToggle) {
            importToggle.addEventListener('click', function () {
                var area = document.getElementById('s64ws-syn-import-area');
                area.style.display = area.style.display === 'none' ? '' : 'none';
            });
        }
        var importFile = document.getElementById('s64ws-syn-import-file');
        if (importFile) {
            importFile.addEventListener('change', function () {
                var file = this.files[0];
                if (!file) return;
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('s64ws-syn-import-text').value = e.target.result;
                    document.getElementById('s64ws-syn-import-filename').textContent = file.name;
                };
                reader.readAsText(file, 'UTF-8');
            });
        }
        var importRun = document.getElementById('s64ws-syn-import-run');
        if (importRun) {
            importRun.addEventListener('click', function () {
                var text = document.getElementById('s64ws-syn-import-text').value.trim();
                if (!text) return;
                var replace = document.getElementById('s64ws-syn-import-replace').checked;
                ajax('shift64_woo_search_synonys64ws_import', { text: text, replace: replace ? '1' : '' }, function (res) {
                    if (!res.success) { showStatus('s64ws-syn-import-status', res); return; }
                    renderSynonyms(res.data.synonyms);
                    var msg = 'Imported ' + res.data.imported + ' groups.';
                    if (res.data.skipped.length) {
                        msg += ' Skipped lines: ' + res.data.skipped.join(', ') + '.';
                    }
                    showStatus('s64ws-syn-import-status', { success: true, data: { message: msg } });
                    document.getElementById('s64ws-syn-import-text').value = '';
                });
            });
        }

        document.getElementById('s64ws-syn-table').addEventListener('click', function (e) {
            var row = e.target.closest('tr[data-index]');
            if (!row) return;
            var index = parseInt(row.dataset.index);

            if (e.target.closest('.s64ws-syn-save')) {
                var input = row.querySelector('.s64ws-syn-terms');
                ajax('shift64_woo_search_synonys64ws_update', { index: index, terms: input.value }, function (res) {
                    if (res.success) renderSynonyms(res.data.synonyms);
                    showStatus('s64ws-syn-status', res);
                });
            } else if (e.target.closest('.s64ws-syn-delete')) {
                ajax('shift64_woo_search_synonys64ws_remove', { index: index }, function (res) {
                    if (res.success) renderSynonyms(res.data.synonyms);
                    showStatus('s64ws-syn-status', res);
                });
            }
        });
    }

    function renderSynonyms(synonyms) {
        var tbody = document.getElementById('s64ws-syn-body');
        if (!synonyms || synonyms.length === 0) {
            tbody.innerHTML = '<tr class="s64ws-syn-empty"><td colspan="3">No synonym groups defined.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < synonyms.length; i++) {
            var display;
            if (synonyms[i].from && synonyms[i].to) {
                display = synonyms[i].from.join(', ') + ' => ' + synonyms[i].to.join(', ');
            } else {
                display = synonyms[i].join(', ');
            }
            html += '<tr data-index="' + i + '"><td>' + (i + 1) + '</td>';
            html += '<td><input type="text" class="regular-text s64ws-syn-terms" value="' + esc(display) + '" /></td>';
            html += '<td><button type="button" class="button s64ws-syn-save">Save</button> <button type="button" class="button s64ws-syn-delete">Delete</button></td></tr>';
        }
        tbody.innerHTML = html;
    }

    // ── Suggestions ───────────────────────────────────────────
    function initSuggestions() {
        var addBtn = document.getElementById('s64ws-sug-add');
        if (!addBtn) return;

        addBtn.addEventListener('click', function () {
            var input = document.getElementById('s64ws-sug-input');
            var text = input.value.trim();
            if (!text) return;

            ajax('shift64_woo_search_suggestions_add', { text: text }, function (res) {
                if (!res.success) { showStatus('s64ws-sug-status', res); return; }
                input.value = '';
                renderSuggestions(res.data.suggestions);
                showStatus('s64ws-sug-status', res);
            });
        });

        // Export.
        var exportBtn = document.getElementById('s64ws-sug-export');
        if (exportBtn) {
            exportBtn.addEventListener('click', function () {
                ajax('shift64_woo_search_suggestions_export', {}, function (res) {
                    if (!res.success) return;
                    var blob = new Blob([res.data.text], { type: 'text/plain;charset=utf-8' });
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'shift64-woo-search-suggestions.txt';
                    a.click();
                    URL.revokeObjectURL(a.href);
                });
            });
        }

        // Import toggle + file loader.
        var importToggle = document.getElementById('s64ws-sug-import-toggle');
        if (importToggle) {
            importToggle.addEventListener('click', function () {
                var area = document.getElementById('s64ws-sug-import-area');
                area.style.display = area.style.display === 'none' ? '' : 'none';
            });
        }
        var importFile = document.getElementById('s64ws-sug-import-file');
        if (importFile) {
            importFile.addEventListener('change', function () {
                var file = this.files[0];
                if (!file) return;
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('s64ws-sug-import-text').value = e.target.result;
                    document.getElementById('s64ws-sug-import-filename').textContent = file.name;
                };
                reader.readAsText(file, 'UTF-8');
            });
        }
        var importRun = document.getElementById('s64ws-sug-import-run');
        if (importRun) {
            importRun.addEventListener('click', function () {
                var text = document.getElementById('s64ws-sug-import-text').value.trim();
                if (!text) return;
                var replace = document.getElementById('s64ws-sug-import-replace').checked;
                ajax('shift64_woo_search_suggestions_import', { text: text, replace: replace ? '1' : '' }, function (res) {
                    if (!res.success) { showStatus('s64ws-sug-import-status', res); return; }
                    renderSuggestions(res.data.suggestions);
                    showStatus('s64ws-sug-import-status', { success: true, data: { message: 'Imported ' + res.data.imported + ' suggestions.' } });
                    document.getElementById('s64ws-sug-import-text').value = '';
                });
            });
        }

        document.getElementById('s64ws-sug-table').addEventListener('click', function (e) {
            var row = e.target.closest('tr[data-index]');
            if (!row) return;
            var index = parseInt(row.dataset.index);

            if (e.target.closest('.s64ws-sug-save')) {
                var input = row.querySelector('.s64ws-sug-text');
                ajax('shift64_woo_search_suggestions_update', { index: index, text: input.value }, function (res) {
                    if (res.success) renderSuggestions(res.data.suggestions);
                    showStatus('s64ws-sug-status', res);
                });
            } else if (e.target.closest('.s64ws-sug-delete')) {
                ajax('shift64_woo_search_suggestions_remove', { index: index }, function (res) {
                    if (res.success) renderSuggestions(res.data.suggestions);
                    showStatus('s64ws-sug-status', res);
                });
            }
        });
    }

    function renderSuggestions(suggestions) {
        var tbody = document.getElementById('s64ws-sug-body');
        if (!suggestions || suggestions.length === 0) {
            tbody.innerHTML = '<tr class="s64ws-sug-empty"><td colspan="3">No suggestions configured.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < suggestions.length; i++) {
            html += '<tr data-index="' + i + '"><td>' + (i + 1) + '</td>';
            html += '<td><input type="text" class="regular-text s64ws-sug-text" value="' + esc(suggestions[i]) + '" /></td>';
            html += '<td><button type="button" class="button s64ws-sug-save">Save</button> <button type="button" class="button s64ws-sug-delete">Delete</button></td></tr>';
        }
        tbody.innerHTML = html;
    }

    // ── Category Boosts ────────────────────────────────────────
    function initCategoryBoosts() {
        var addBtn = document.getElementById('s64ws-cb-add');
        if (!addBtn) return;

        addBtn.addEventListener('click', function () {
            var input = document.getElementById('s64ws-cb-input');
            var factor = document.getElementById('s64ws-cb-factor');
            var term = input.value.trim();
            if (!term) return;

            ajax('shift64_woo_search_save_category_boost', { term: term, factor: factor.value }, function (res) {
                if (res.success) {
                    input.value = '';
                    renderCategoryBoosts(res.data.rows);
                }
                showStatus('s64ws-cb-status', res);
            });
        });

        document.getElementById('s64ws-cb-table').addEventListener('click', function (e) {
            var row = e.target.closest('tr[data-term-id]');
            if (!row) return;
            var termId = parseInt(row.dataset.termId);

            if (e.target.closest('.s64ws-cb-save')) {
                var name = row.querySelector('td').textContent;
                var value = row.querySelector('.s64ws-cb-value').value;
                ajax('shift64_woo_search_save_category_boost', { term: termId, factor: value }, function (res) {
                    if (res.success) renderCategoryBoosts(res.data.rows);
                    showStatus('s64ws-cb-status', res);
                });
            } else if (e.target.closest('.s64ws-cb-delete')) {
                ajax('shift64_woo_search_remove_category_boost', { term_id: termId }, function (res) {
                    if (res.success) renderCategoryBoosts(res.data.rows);
                    showStatus('s64ws-cb-status', res);
                });
            }
        });
    }

    function renderCategoryBoosts(rows) {
        var tbody = document.getElementById('s64ws-cb-body');
        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr class="s64ws-cb-empty"><td colspan="3">No category boosts configured.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            html += '<tr data-term-id="' + rows[i].term_id + '"><td>' + esc(rows[i].name) + '</td>';
            html += '<td><input type="number" class="s64ws-cb-value" step="0.1" min="0.1" value="' + esc(rows[i].factor) + '" style="width:90px" /></td>';
            html += '<td><button type="button" class="button s64ws-cb-save">Save</button> <button type="button" class="button s64ws-cb-delete">Delete</button></td></tr>';
        }
        tbody.innerHTML = html;
    }

    // ── Category Exclusions (hide from suggestions) ────────────
    function initCategoryExclude() {
        var addBtn = document.getElementById('s64ws-cx-add');
        if (!addBtn) return;

        addBtn.addEventListener('click', function () {
            var input = document.getElementById('s64ws-cx-input');
            var term = input.value.trim();
            if (!term) return;

            ajax('shift64_woo_search_add_category_exclude', { term: term }, function (res) {
                if (res.success) {
                    input.value = '';
                    renderCategoryExclude(res.data.rows);
                }
                showStatus('s64ws-cx-status', res);
            });
        });

        document.getElementById('s64ws-cx-table').addEventListener('click', function (e) {
            var row = e.target.closest('tr[data-term-id]');
            if (!row) return;
            if (!e.target.closest('.s64ws-cx-delete')) return;
            var termId = parseInt(row.dataset.termId);
            ajax('shift64_woo_search_remove_category_exclude', { term_id: termId }, function (res) {
                if (res.success) renderCategoryExclude(res.data.rows);
                showStatus('s64ws-cx-status', res);
            });
        });
    }

    function renderCategoryExclude(rows) {
        var tbody = document.getElementById('s64ws-cx-body');
        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr class="s64ws-cx-empty"><td colspan="4">No hidden categories.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            html += '<tr data-term-id="' + rows[i].term_id + '"><td>' + esc(rows[i].path) + '</td>';
            html += '<td><code>' + esc(rows[i].slug) + '</code></td>';
            html += '<td>' + esc(rows[i].term_id) + '</td>';
            html += '<td><button type="button" class="button s64ws-cx-delete">Restore</button></td></tr>';
        }
        tbody.innerHTML = html;
    }

    // ── Stats ──────────────────────────────────────────────────
    function initStats() {
        var refreshBtn = document.getElementById('s64ws-stats-refresh');
        if (!refreshBtn) return;

        refreshBtn.addEventListener('click', loadStats);
        document.getElementById('s64ws-stats-period').addEventListener('change', loadStats);
        document.getElementById('s64ws-stats-cleanup').addEventListener('click', function () {
            if (!confirm('Delete stats older than 90 days?')) return;
            ajax('shift64_woo_search_cleanup_stats', { mode: '90' }, function (res) {
                showStatus('s64ws-stats-status', res);
                if (res.success) loadStats();
            });
        });

        var cleanAllBtn = document.getElementById('s64ws-stats-cleanup-all');
        if (cleanAllBtn) {
            cleanAllBtn.addEventListener('click', function () {
                if (!confirm('Delete ALL stats? This cannot be undone.')) return;
                ajax('shift64_woo_search_cleanup_stats', { mode: 'all' }, function (res) {
                    showStatus('s64ws-stats-status', res);
                    if (res.success) loadStats();
                });
            });
        }
    }

    function loadStats() {
        var days = document.getElementById('s64ws-stats-period').value;
        ajax('shift64_woo_search_get_stats', { days: days }, function (res) {
            if (!res.success) return;
            var d = res.data;
            document.getElementById('s64ws-stats-total-7d').textContent = d.total_7d;
            document.getElementById('s64ws-stats-total').textContent = d.total;
            document.getElementById('s64ws-stats-avg-time').textContent = d.avg_time + 'ms';
            document.getElementById('s64ws-stats-zero-rate').textContent = d.zero_rate + '%';

            // Chart.
            var chart = document.getElementById('s64ws-stats-chart');
            if (d.daily && d.daily.length > 0) {
                var max = 1;
                for (var i = 0; i < d.daily.length; i++) { if (parseInt(d.daily[i].search_count) > max) max = parseInt(d.daily[i].search_count); }
                var barsHtml = '<div class="shift64-woo-search-stats__bars">';
                for (var j = 0; j < d.daily.length; j++) {
                    var pct = Math.round(parseInt(d.daily[j].search_count) / max * 100);
                    barsHtml += '<div class="shift64-woo-search-stats__bar-wrap" title="' + d.daily[j].search_date + ': ' + d.daily[j].search_count + '"><div class="shift64-woo-search-stats__bar" style="height:' + pct + '%"></div></div>';
                }
                barsHtml += '</div>';
                chart.innerHTML = barsHtml;
            } else { chart.innerHTML = '<p class="description">No data for this period.</p>'; }

            // Top.
            var topBody = document.getElementById('s64ws-stats-top-body');
            if (d.top && d.top.length > 0) {
                var topHtml = '';
                for (var t = 0; t < d.top.length; t++) { topHtml += '<tr><td>' + esc(d.top[t].query_normalized) + '</td><td>' + d.top[t].search_count + '</td><td>' + Math.round(d.top[t].avg_results) + '</td></tr>'; }
                topBody.innerHTML = topHtml;
            } else { topBody.innerHTML = '<tr><td colspan="3">No data.</td></tr>'; }

            // Zero.
            var zeroBody = document.getElementById('s64ws-stats-zero-body');
            if (d.zero && d.zero.length > 0) {
                var zeroHtml = '';
                for (var z = 0; z < d.zero.length; z++) { zeroHtml += '<tr><td>' + esc(d.zero[z].query_normalized) + '</td><td>' + d.zero[z].search_count + '</td></tr>'; }
                zeroBody.innerHTML = zeroHtml;
            } else { zeroBody.innerHTML = '<tr><td colspan="2">No data.</td></tr>'; }
        });
    }

    // ── Weights ────────────────────────────────────────────────
    function initWeights() {
        var applyBtn = document.getElementById('s64ws-weights-apply');
        if (!applyBtn) return;

        // Slider real-time value display.
        document.querySelectorAll('.s64ws-weight-slider').forEach(function (slider) {
            slider.addEventListener('input', function () {
                slider.parentElement.querySelector('.shift64-woo-search-weights__value').textContent = slider.value;
            });
        });

        // Reset to defaults.
        document.getElementById('s64ws-weights-reset').addEventListener('click', function () {
            var defaults = cfg.default_weights || {};
            document.querySelectorAll('.s64ws-weight-slider').forEach(function (slider) {
                var field = slider.dataset.field;
                if (defaults[field] !== undefined) {
                    slider.value = defaults[field];
                    slider.parentElement.querySelector('.shift64-woo-search-weights__value').textContent = defaults[field];
                }
            });
        });

        // Apply & Rebuild.
        applyBtn.addEventListener('click', function () {
            var weights = {};
            document.querySelectorAll('.s64ws-weight-slider').forEach(function (slider) {
                weights[slider.dataset.field] = slider.value;
            });

            applyBtn.disabled = true;
            var bar = document.getElementById('s64ws-weights-bar');
            var progressWrap = document.getElementById('s64ws-weights-progress');
            var progressText = document.getElementById('s64ws-weights-progress-text');
            progressWrap.style.display = '';
            bar.style.width = '0';
            progressText.textContent = 'Saving weights...';

            ajax('shift64_woo_search_save_weights', { weights: weights }, function (res) {
                if (!res.success) {
                    applyBtn.disabled = false;
                    showStatus('s64ws-weights-status', res);
                    return;
                }

                progressText.textContent = 'Preparing rebuild...';
                ajax('shift64_woo_search_rebuild_index', { step: 'prepare' }, function (prepRes) {
                    if (!prepRes.success) {
                        applyBtn.disabled = false;
                        progressText.textContent = 'Error: ' + (prepRes.data ? prepRes.data.message : 'unknown');
                        return;
                    }
                    var total = prepRes.data.total;
                    processBatchWeights(0, total, bar, progressText, applyBtn);
                });
            });
        });

        function processBatchWeights(offset, total, bar, progressText, applyBtn) {
            ajax('shift64_woo_search_rebuild_index', { step: 'index', offset: offset, total: total }, function (res) {
                if (!res.success) {
                    applyBtn.disabled = false;
                    progressText.textContent = 'Error: ' + (res.data ? res.data.message : 'unknown');
                    return;
                }
                var d = res.data;
                var pct = total > 0 ? Math.min(100, Math.round((d.indexed / total) * 100)) : 100;
                bar.style.width = pct + '%';

                if (d.step === 'done') {
                    applyBtn.disabled = false;
                    bar.style.width = '100%';
                    progressText.textContent = d.message;
                    showStatus('s64ws-weights-status', { success: true, data: { message: 'Weights applied and index rebuilt.' } });
                } else {
                    progressText.textContent = 'Indexing ' + d.indexed + ' / ' + total + '...';
                    processBatchWeights(d.offset, total, bar, progressText, applyBtn);
                }
            });
        }
    }

    // ── Filters ────────────────────────────────────────────────
    function initFilters() {
        var form = document.getElementById('s64ws-filters-form');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var selected = [];
            form.querySelectorAll('input[name="filter_attributes[]"]:checked').forEach(function (cb) {
                selected.push(cb.value);
            });

            var catEnabled = 'no';
            var catCb = form.querySelector('#shift64_woo_search_filter_categories_enabled');
            if (catCb) {
                catEnabled = catCb.checked ? 'yes' : 'no';
            }

            var data = {
                filter_attributes: selected,
                shift64_woo_search_filter_categories_enabled: catEnabled
            };

            var excludedInput = form.querySelector('#shift64_woo_search_filter_categories_excluded_input');
            if (excludedInput) {
                data.shift64_woo_search_filter_categories_excluded = excludedInput.value.trim();
            }

            ajax('shift64_woo_search_save_filters', data, function (res) {
                showStatus('s64ws-filters-status', res);
            });
        });
    }

    // ── Settings ───────────────────────────────────────────────
    function initSettings() {
        var form = document.getElementById('s64ws-settings-form');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = {};
            form.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (!el.name) return;
                if (el.disabled) return;
                if (el.type === 'checkbox') {
                    if (el.name.slice(-2) === '[]') {
                        var base = el.name.slice(0, -2);
                        if (!Array.isArray(data[base])) data[base] = [];
                        if (el.checked) data[base].push(el.value);
                    } else {
                        data[el.name] = el.checked ? 'yes' : 'no';
                    }
                } else if (el.type === 'hidden' && form.querySelector('input[type="checkbox"][name="' + el.name + '"]')) { return; }
                else { data[el.name] = el.value; }
            });
            ajax('shift64_woo_search_save_setting', { settings: data }, function (res) { showStatus('s64ws-settings-status', res); });
        });

        // Toggle visibility + disabled state of Redis auth fields so they're absent from the
        // submitted payload (and invisible to browser autofill) when auth is turned off.
        var authToggle = document.getElementById('shift64_woo_search_redis_auth_enabled');
        if (authToggle) {
            var applyAuthState = function () {
                var on = authToggle.checked;
                form.querySelectorAll('.s64ws-redis-auth-row').forEach(function (row) {
                    row.hidden = !on;
                    row.querySelectorAll('input').forEach(function (inp) { inp.disabled = !on; });
                });
            };
            authToggle.addEventListener('change', applyAuthState);
            applyAuthState();
        }

        var testBtn = document.getElementById('s64ws-test-connection');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                var data = {};
                form.querySelectorAll('input[name^="shift64_woo_search_redis"]').forEach(function (el) {
                    if (el.disabled) return;
                    if (el.type === 'checkbox') { data[el.name] = el.checked ? 'yes' : 'no'; return; }
                    if (el.type === 'hidden' && form.querySelector('input[type="checkbox"][name="' + el.name + '"]')) return;
                    data[el.name] = el.value;
                });
                ajax('shift64_woo_search_test_connection', data, function (res) { showStatus('s64ws-connection-status', res); });
            });
        }

        var regenBtn = document.getElementById('s64ws-regen-config');
        if (regenBtn) { regenBtn.addEventListener('click', function () { ajax('shift64_woo_search_regen_config', {}, function (res) { showStatus('s64ws-connection-status', res); }); }); }
    }

    // ── Index (batched rebuild) ───────────────────────────────
    function initIndex() {
        var btn = document.getElementById('s64ws-rebuild-index');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!confirm('This will drop and rebuild the entire index. Continue?')) return;
            btn.disabled = true;
            var bar = document.getElementById('s64ws-rebuild-bar');
            var progressWrap = document.getElementById('s64ws-rebuild-progress');
            var progressText = document.getElementById('s64ws-rebuild-progress-text');
            progressWrap.style.display = ''; bar.style.width = '0'; progressText.textContent = 'Preparing...';

            ajax('shift64_woo_search_rebuild_index', { step: 'prepare' }, function (res) {
                if (!res.success) { btn.disabled = false; progressText.textContent = 'Error: ' + (res.data ? res.data.message : 'unknown'); return; }
                var total = res.data.total;
                progressText.textContent = 'Indexing 0 / ' + total + '...';
                processBatch(0, total);
            });

            function processBatch(offset, total) {
                ajax('shift64_woo_search_rebuild_index', { step: 'index', offset: offset, total: total }, function (res) {
                    if (!res.success) { btn.disabled = false; progressText.textContent = 'Error: ' + (res.data ? res.data.message : 'unknown'); return; }
                    var d = res.data;
                    var pct = total > 0 ? Math.min(100, Math.round((d.indexed / total) * 100)) : 100;
                    bar.style.width = pct + '%';
                    if (d.step === 'done') { btn.disabled = false; bar.style.width = '100%'; progressText.textContent = d.message; showStatus('s64ws-rebuild-status', res); }
                    else { progressText.textContent = 'Indexing ' + d.indexed + ' / ' + total + '...'; processBatch(d.offset, total); }
                });
            }
        });
    }

    // ── Utilities ──────────────────────────────────────────────
    function ajax(action, data, callback) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', cfg.nonce);
        for (var key in data) {
            if (typeof data[key] === 'object' && data[key] !== null) {
                for (var sub in data[key]) {
                    var sv = data[key][sub];
                    if (Array.isArray(sv)) {
                        if (sv.length === 0) {
                            // Empty marker so PHP receives the key (and clears the option)
                            // only when the form actually included this array field.
                            body.append(key + '[' + sub + '][]', '');
                        } else {
                            sv.forEach(function (v, i) { body.append(key + '[' + sub + '][' + i + ']', v); });
                        }
                    } else {
                        body.append(key + '[' + sub + ']', sv);
                    }
                }
            } else { body.append(key, data[key]); }
        }
        fetch(cfg.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(callback)
            .catch(function (err) { callback({ success: false, data: { message: err.message } }); });
    }

    function showStatus(id, res) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = res.data ? res.data.message : (res.success ? 'OK' : 'Error');
        el.className = 'shift64-woo-search-status ' + (res.success ? 'shift64-woo-search-status--ok' : 'shift64-woo-search-status--error');
        setTimeout(function () { el.textContent = ''; el.className = 'shift64-woo-search-status'; }, 5000);
    }

    function esc(str) { if (!str) return ''; var div = document.createElement('div'); div.appendChild(document.createTextNode(String(str))); return div.innerHTML; }
    function round(n) { return Math.round(parseFloat(n) * 100) / 100; }
})();
