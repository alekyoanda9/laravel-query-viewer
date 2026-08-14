(function () {
    'use strict';

    var CFG = window.QUERY_DEBUG || {};
    var KEY_STORAGE = 'qd_key';
    var OPEN_STORAGE = 'qd_open';

    var root = document.getElementById('qd-root');
    if (!root) return;

    var fab = document.getElementById('qd-fab');
    var badge = fab.querySelector('.qd-badge');
    var panel = document.getElementById('qd-panel');
    var body = panel.querySelector('.qd-body');
    var filterInput = panel.querySelector('[data-qd="filter"]');
    var slowOnlyInput = panel.querySelector('[data-qd="slowonly"]');
    var dupOnlyInput = panel.querySelector('[data-qd="duponly"]');
    var modal = panel.querySelector('[data-qd="modal"]');
    var capModal = panel.querySelector('[data-qd="cap-modal"]');
    var modalText = panel.querySelector('[data-qd="md-text"]');

    var pollTimer = null;
    var ajaxDebounce = null;
    var lastBatches = [];
    var filterText = '';
    var slowOnly = false;
    var dupOnly = false;

    var explainCache = {};
    var collapsedGroups = {};
    var collapsedBatches = {};

    // markdown yang sedang ditampilkan di modal (untuk copy/download)
    var currentExport = { md: '', filename: 'query-viewer.md' };

    // ---- helpers -----------------------------------------------------------

    function getKey() { try { return sessionStorage.getItem(KEY_STORAGE) || ''; } catch (e) { return ''; } }
    function setKey(v) { try { sessionStorage.setItem(KEY_STORAGE, v); } catch (e) { } }
    function clearKey() { try { sessionStorage.removeItem(KEY_STORAGE); } catch (e) { } }

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function isOpen() { return !panel.hasAttribute('hidden'); }

    function getCsrf() {
        var meta = document.querySelector('meta[name="qd-csrf"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function batchKey(batch) {
        return (batch.at || '') + '|' + (batch.method || '') + '|' + (batch.path || '');
    }

    function originOf(batch) {
        return batch.origin || batch.path || '(tanpa path)';
    }

    function authHeaders(extra) {
        var h = { 'X-Query-Debug-Key': getKey(), 'Accept': 'application/json' };
        if (extra) { for (var k in extra) { if (extra.hasOwnProperty(k)) h[k] = extra[k]; } }
        return h;
    }

    function dupCounts(batch) {
        var counts = {};
        (batch.queries || []).forEach(function (q) { counts[q.raw] = (counts[q.raw] || 0) + 1; });
        return counts;
    }

    // ---- data fetch --------------------------------------------------------

    function fetchRecent() {
        var key = getKey();
        if (!key) { renderKeyForm(); return; }

        fetch(CFG.recentUrl, {
            headers: authHeaders(),
            credentials: 'same-origin'
        }).then(function (res) {
            if (res.status === 403) { clearKey(); renderKeyForm('API key salah atau kadaluarsa.'); return null; }
            return res.json();
        }).then(function (json) {
            if (!json) return;
            var payload = json.data || json;
            lastBatches = (payload && payload.batches) || [];
            render();
        }).catch(function () {
            body.innerHTML = '<div class="qd-empty">Gagal memuat query.</div>';
        });
    }

    function clearAll() {
        var key = getKey();
        if (!key) return;
        fetch(CFG.clearUrl, {
            headers: authHeaders(),
            credentials: 'same-origin'
        }).then(function () {
            lastBatches = [];
            explainCache = {};
            render();
        });
    }

    function unlockWithKey(key) {
        fetch(CFG.unlockUrl, {
            method: 'POST',
            headers: {
                'X-Query-Debug-Key': key,
                'X-CSRF-TOKEN': getCsrf(),
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        }).then(function (res) {
            if (res.status === 403) { renderKeyForm('API key salah.'); return; }
            if (!res.ok) { renderKeyForm('Gagal unlock (server error), coba lagi.'); return; }
            setKey(key);
            fetchRecent();
            startPolling();
        }).catch(function () {
            renderKeyForm('Gagal menghubungi server.');
        });
    }

    function lockSession() {
        var key = getKey();
        fetch(CFG.lockUrl, {
            method: 'POST',
            headers: {
                'X-Query-Debug-Key': key || '',
                'X-CSRF-TOKEN': getCsrf(),
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        }).finally(function () {
            clearKey();
            lastBatches = [];
            explainCache = {};
            stopPolling();
            renderKeyForm();
        });
    }

    function runExplain(batchIndex, queryIndex, qid, analyze) {
        explainCache[qid] = { loading: true, analyze: analyze };
        render();

        fetch(CFG.explainUrl, {
            method: 'POST',
            headers: authHeaders({
                'X-CSRF-TOKEN': getCsrf(),
                'Content-Type': 'application/json'
            }),
            credentials: 'same-origin',
            body: JSON.stringify({
                batch: batchIndex,
                query: queryIndex,
                id: qid,
                analyze: analyze ? 1 : 0
            })
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        }).then(function (r) {
            if (!r.ok) {
                explainCache[qid] = {
                    error: (r.json && r.json.message) || 'EXPLAIN gagal.',
                    analyze: analyze
                };
            } else {
                var data = (r.json && r.json.data) || {};
                explainCache[qid] = {
                    plan: data.plan || '(plan kosong)',
                    elapsed: data.elapsed_ms,
                    analyze: !!data.analyze
                };
            }
            render();
        }).catch(function () {
            explainCache[qid] = { error: 'Gagal menghubungi server.', analyze: analyze };
            render();
        });
    }

    // ---- export tiket (markdown) ------------------------------------------
    //
    // Semua dibangun dari data yang sudah ada di panel (lastBatches) + hasil
    // EXPLAIN yang tersimpan di explainCache. Tidak ada request ke server.

    function fence(kind, content) {
        var f = (String(content).indexOf('```') !== -1) ? '~~~~' : '```';
        return f + kind + '\n' + content + '\n' + f;
    }

    function metaBlock(batch, includeEndpoint) {
        var lines = [];

        // Metadata konteks (cabang, IGR, user, dsb) — isinya ditentukan tiap
        // app lewat QueryViewer::contextUsing()/config export.extra_session,
        // jadi package tidak menebak field khusus aplikasi tertentu.
        (batch.context || []).forEach(function (e) {
            lines.push('**' + e.label + ':** ' + e.value);
        });

        lines.push('**Koneksi DB:** ' + (batch.conn || batch.connection || '-'));
        lines.push('**Menu:** ' + (batch.route || ('/' + originOf(batch))));

        if (includeEndpoint) {
            lines.push('**Endpoint:** ' + batch.method + ' /' + batch.path + (batch.is_ajax ? ' (AJAX)' : ''));
        }

        lines.push('**Waktu:** ' + (batch.at || '-'));
        if (CFG.host) lines.push('**Server:** ' + CFG.host);

        if (batch.error) {
            lines.push('**Status:** ERROR \u2014 ' + (batch.error.class || 'Exception'));
        }

        return lines.join('\n');
    }

    function queryBlock(q, dup) {
        var head = (q.op || 'QUERY') + (q.table ? ' \u00b7 ' + q.table : '');
        var out = '**' + head + '**';

        if (q.failed) {
            out += ' \u2014 **GAGAL**';
        } else {
            out += ' \u2014 ' + Number(q.time_ms || 0).toFixed(1) + ' ms';
        }

        if (dup > 1) {
            out += ' \u00b7 dijalankan ' + dup + '\u00d7 identik dalam request ini';
        }

        out += '\n\n' + fence('sql', q.raw);

        if (q.failed && q.error) {
            out += '\n\n> **Error:** ' + q.error;
        }

        var ex = explainCache[q.id];
        if (ex && ex.plan) {
            out += '\n\n_EXPLAIN' + (ex.analyze ? ' ANALYZE' : '') +
                ' (' + Number(ex.elapsed || 0).toFixed(1) + ' ms):_\n\n' + fence('text', ex.plan);
        }

        return out;
    }

    function insightNote(batch) {
        var parts = [];

        if (batch.error) {
            parts.push('request error (' + (batch.error.class || 'Exception') + ')');
        }

        if (batch.insight) {
            if (batch.insight.failed_count) parts.push(batch.insight.failed_count + ' query gagal');
            if (batch.insight.slow_count) parts.push(batch.insight.slow_count + ' query lambat');
            if (batch.insight.redundant_count) parts.push(batch.insight.redundant_count + ' redundan');
            if (batch.insight.n_plus_one && batch.insight.n_plus_one.length) {
                var f = batch.insight.n_plus_one[0];
                parts.push('indikasi N+1' + (f.table ? ' di ' + f.table : '') + ' (\u00d7' + f.count + ')');
            }
        }

        return parts.length ? ' \u2014 ' + parts.join(', ') : '';
    }

    function slug(s) {
        return String(s || 'qd').toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 50) || 'qd';
    }

    function stamp(at) {
        var digits = String(at || '').replace(/[^0-9]/g, '').slice(0, 14);
        if (digits) return digits;
        return String(Date.now());
    }

    function filenameFor(batch, extra) {
        return 'qd-' + slug(batch.route || originOf(batch)) +
            (extra ? '-' + slug(extra) : '') + '-' + stamp(batch.at) + '.md';
    }

    function exportQuery(bi, qi) {
        var batch = lastBatches[bi];
        if (!batch || !batch.queries || !batch.queries[qi]) return;
        var q = batch.queries[qi];
        var counts = dupCounts(batch);

        var md = '## Query Viewer \u2014 ' + (q.op || 'QUERY') + (q.table ? ' ' + q.table : '') + '\n\n' +
            metaBlock(batch, true) + '\n\n' +
            queryBlock(q, counts[q.raw]);

        openModal(md, filenameFor(batch, q.table));
    }

    function errorBlock(batch) {
        if (!batch.error) return '';
        return '> **Request error (' + (batch.error.class || 'Exception') + '):** ' +
            (batch.error.message || '(tanpa pesan)');
    }

    function exportBatch(bi) {
        var batch = lastBatches[bi];
        if (!batch) return;
        var counts = dupCounts(batch);
        var queries = batch.queries || [];
        var totalMs = queries.reduce(function (a, q) { return a + parseFloat(q.time_ms || 0); }, 0);
        var err = errorBlock(batch);

        var md = '## Query Viewer \u2014 ' + (batch.route || ('/' + originOf(batch))) + '\n\n' +
            metaBlock(batch, true) + '\n\n' +
            (err ? err + '\n\n' : '') +
            '_' + queries.length + ' query, total ' + totalMs.toFixed(1) + ' ms' + insightNote(batch) + '_\n\n' +
            (queries.length
                ? queries.map(function (q) { return queryBlock(q, counts[q.raw]); }).join('\n\n---\n\n')
                : '_(request ini error sebelum sempat menjalankan query apa pun)_');

        openModal(md, filenameFor(batch));
    }

    function exportGroup(gkey) {
        // Kumpulkan semua batch di halaman ini, urut kronologis (lastBatches
        // terbaru-dulu, jadi dibalik) supaya alurnya terbaca: page-load ->
        // AJAX -> cetak. Ini yang berguna untuk case bug pola loop.
        var items = [];
        lastBatches.forEach(function (b) {
            if (originOf(b) === gkey) items.push(b);
        });
        if (!items.length) return;
        items.reverse();

        var totalQ = 0, totalMs = 0;
        items.forEach(function (b) {
            (b.queries || []).forEach(function (q) {
                totalQ++;
                totalMs += parseFloat(q.time_ms || 0);
            });
        });

        var md = '## Query Viewer \u2014 halaman /' + gkey + '\n\n' +
            metaBlock(items[0], false) + '\n\n' +
            '_' + items.length + ' request, ' + totalQ + ' query, total ' + totalMs.toFixed(1) + ' ms_\n\n';

        items.forEach(function (b, idx) {
            var counts = dupCounts(b);
            var itemErr = errorBlock(b);
            md += '### ' + (idx + 1) + '. ' + b.method + ' /' + b.path +
                (b.is_ajax ? ' (AJAX)' : '') + insightNote(b) + '\n\n' +
                (itemErr ? itemErr + '\n\n' : '') +
                ((b.queries || []).length
                    ? (b.queries || []).map(function (q) { return queryBlock(q, counts[q.raw]); }).join('\n\n')
                    : '_(request ini error sebelum sempat menjalankan query apa pun)_') +
                '\n\n';
        });

        openModal(md, 'qd-halaman-' + slug(gkey) + '-' + stamp(items[0].at) + '.md');
    }

    function openModal(md, filename) {
        currentExport = { md: md, filename: filename };
        if (modalText) modalText.value = md;
        if (modal) modal.removeAttribute('hidden');
        if (modalText) { modalText.focus(); modalText.setSelectionRange(0, 0); }
    }

    function closeModal() {
        if (modal) modal.setAttribute('hidden', '');
    }

    function downloadMd(text, filename) {
        try {
            var blob = new Blob([text], { type: 'text/markdown;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename || 'query-viewer.md';
            document.body.appendChild(a);
            a.click();
            setTimeout(function () {
                URL.revokeObjectURL(url);
                if (a.parentNode) a.parentNode.removeChild(a);
            }, 100);
        } catch (e) { }
    }

    // ---- render ------------------------------------------------------------

    function renderKeyForm(err) {
        stopPolling();
        closeModal();
        badge.style.display = 'none';
        body.innerHTML =
            '<div class="qd-keyform">' +
            '<div>Masukkan API key untuk membuka Query Viewer.</div>' +
            '<input type="password" data-qd="key" placeholder="API key" autocomplete="off">' +
            '<div><button data-qd="key-submit">Buka</button></div>' +
            (err ? '<div class="qd-err">' + esc(err) + '</div>' : '') +
            '</div>';
        var input = body.querySelector('[data-qd="key"]');
        if (input) input.focus();
    }

    function matchesFilter(q, dupCount) {
        if (filterText && (q.raw || '').toLowerCase().indexOf(filterText) === -1) return false;
        if (slowOnly && parseFloat(q.time_ms || 0) < (CFG.slowMs || 500)) return false;
        if (dupOnly && dupCount <= 1) return false;
        return true;
    }

    function groupBatches(batches) {
        var groups = [];
        var index = {};
        batches.forEach(function (batch, bi) {
            var key = originOf(batch);
            if (!(key in index)) {
                index[key] = groups.length;
                groups.push({ origin: key, items: [] });
            }
            groups[index[key]].items.push({ batch: batch, bi: bi });
        });
        return groups;
    }

    function renderInsight(insight) {
        if (!CFG.insight || !insight) return '';

        var chips = '';
        chips += '<span class="qd-chip">' + insight.count + ' query \u00b7 ' +
            Number(insight.total_ms || 0).toFixed(1) + ' ms</span>';

        if (insight.unique_count < insight.count) {
            chips += '<span class="qd-chip">' + insight.unique_count + ' unik</span>';
        }
        if (insight.slow_count > 0) {
            chips += '<span class="qd-chip danger">' + insight.slow_count + ' lambat</span>';
        }
        if (insight.failed_count > 0) {
            chips += '<span class="qd-chip danger">' + insight.failed_count + ' gagal</span>';
        }
        if (insight.redundant_count > 0) {
            chips += '<span class="qd-chip warn">' + insight.redundant_count + ' redundan</span>';
        }
        if (insight.n_plus_one && insight.n_plus_one.length) {
            chips += '<span class="qd-chip danger">indikasi N+1</span>';
        }
        if (!insight.slow_count && !insight.failed_count && !insight.redundant_count &&
            (!insight.n_plus_one || !insight.n_plus_one.length)) {
            chips += '<span class="qd-chip ok">bersih</span>';
        }

        (insight.n_plus_one || []).forEach(function (f) {
            chips += '<div class="qd-finding">N+1: ' +
                (f.table ? 'tabel <code>' + esc(f.table) + '</code> ' : '') +
                '\u00d7' + f.count + ' (' + f.distinct + ' binding berbeda, ' +
                Number(f.total_ms || 0).toFixed(1) + ' ms)</div>';
        });

        (insight.repeated || []).forEach(function (f) {
            chips += '<div class="qd-finding">Redundan: ' +
                (f.table ? 'tabel <code>' + esc(f.table) + '</code> ' : '') +
                '\u00d7' + f.count + ' query identik (' +
                Number(f.total_ms || 0).toFixed(1) + ' ms)</div>';
        });

        return '<div class="qd-insight">' + chips + '</div>';
    }

    function renderExplainOutput(qid) {
        var state = explainCache[qid];
        if (!state) return '';

        if (state.loading) {
            return '<div class="qd-explain-out"><div class="qd-explain-head"><b>' +
                (state.analyze ? 'EXPLAIN ANALYZE' : 'EXPLAIN') +
                '</b><span>menjalankan\u2026</span></div></div>';
        }

        if (state.error) {
            return '<div class="qd-explain-out err"><div class="qd-explain-head"><b>' +
                (state.analyze ? 'EXPLAIN ANALYZE' : 'EXPLAIN') +
                '</b><button data-qd="explain-close" data-qid="' + esc(qid) + '">tutup</button></div>' +
                '<pre>' + esc(state.error) + '</pre></div>';
        }

        return '<div class="qd-explain-out">' +
            '<div class="qd-explain-head"><b>' + (state.analyze ? 'EXPLAIN ANALYZE' : 'EXPLAIN') + '</b>' +
            '<span>' + Number(state.elapsed || 0).toFixed(1) + ' ms</span>' +
            '<button data-qd="explain-copy">copy plan</button>' +
            '<button data-qd="explain-close" data-qid="' + esc(qid) + '">tutup</button></div>' +
            '<pre>' + esc(state.plan) + '</pre></div>';
    }

    function renderBatch(batch, bi) {
        var all = batch.queries || [];
        var counts = dupCounts(batch);

        var visible = [];
        all.forEach(function (q, qi) {
            if (matchesFilter(q, counts[q.raw])) visible.push({ q: q, qi: qi });
        });

        // Batch yang error tetap ditampilkan walau tidak ada query yang lolos
        // filter (termasuk kasus request 500 tanpa sempat menjalankan query
        // apa pun) — hanya batch normal-kosong yang di-skip.
        if (!visible.length && !batch.error) return null;

        var totalMs = all.reduce(function (a, q) { return a + parseFloat(q.time_ms || 0); }, 0);
        var bkey = batchKey(batch);
        var collapsed = !!collapsedBatches[bkey];
        var label = batch.route ? esc(batch.route) : ('/' + esc(batch.path));

        var html = '<div class="qd-batch' + (batch.error ? ' has-error' : '') + '" data-bi="' + bi + '">';

        html += '<div class="qd-batch-head" data-qd="toggle" data-bkey="' + esc(bkey) + '">' +
            '<span class="qd-caret">' + (collapsed ? '\u25b8' : '\u25be') + '</span>' +
            '<span class="qd-tag ' + (batch.is_ajax ? 'ajax' : '') + '">' +
            esc(batch.method) + (batch.is_ajax ? ' \u00b7 AJAX' : '') + '</span>' +
            '<span class="qd-path" title="/' + esc(batch.path) + '">' + label + '</span>' +
            (batch.error ? '<span class="qd-fail-badge" title="' + esc(batch.error.message || '') + '">ERROR</span>' : '') +
            '<button class="qd-mini" data-qd="export-batch" data-bi="' + bi + '" title="Export seluruh request ini ke tiket">MD</button>' +
            '<span class="qd-tag">' + all.length + ' q \u00b7 ' + totalMs.toFixed(1) + ' ms</span>' +
            '</div>';

        if (batch.error) {
            html += '<div class="qd-batch-error"><b>' + esc(batch.error.class || 'Exception') + ':</b> ' +
                esc(batch.error.message || '(tanpa pesan)') + '</div>';
        }

        html += renderInsight(batch.insight);

        html += '<div class="qd-batch-queries"' + (collapsed ? ' hidden' : '') + '>';

        if (!visible.length) {
            html += '<div class="qd-empty">(request ini error sebelum sempat menjalankan query apa pun)</div>';
        }

        visible.forEach(function (item) {
            var q = item.q;
            var qid = q.id || '';
            var ms = parseFloat(q.time_ms || 0);
            var slow = ms >= (CFG.slowMs || 500);
            var dup = counts[q.raw] > 1;

            html += '<div class="qd-q' + (q.failed ? ' failed' : '') + '">' +
                (q.failed
                    ? '<div class="qd-q-error"><b>Query ini gagal:</b> ' + esc(q.error || '(tanpa pesan)') + '</div>'
                    : '') +
                '<div class="qd-q-meta">' +
                (q.failed
                    ? '<span class="qd-fail-badge">GAGAL</span>'
                    : '<span class="qd-ms ' + (slow ? 'slow' : '') + '">' + ms.toFixed(1) + ' ms</span>') +
                (q.op ? '<span class="qd-conn">' + esc(q.op) + (q.table ? ' ' + esc(q.table) : '') + '</span>' : '') +
                (dup ? '<span class="qd-dup">\u00d7' + counts[q.raw] + ' duplikat</span>' : '') +
                '<span class="qd-conn">' + esc(q.connection || '') + '</span>' +
                '</div>' +
                '<div class="qd-sql">' +
                '<div class="qd-actions">' +
                '<button data-qd="md" data-bi="' + bi + '" data-qi="' + item.qi + '" title="Export query ini ke tiket">MD</button>' +
                (CFG.explain && qid && !q.failed
                    ? '<button data-qd="explain" data-bi="' + bi + '" data-qi="' + item.qi + '" data-qid="' + esc(qid) + '">Explain</button>'
                    : '') +
                (CFG.explainAnalyze && qid && !q.failed
                    ? '<button data-qd="explain-analyze" data-bi="' + bi + '" data-qi="' + item.qi + '" data-qid="' + esc(qid) + '" title="Menjalankan query-nya sungguhan di dalam transaksi yang langsung di-rollback">Analyze</button>'
                    : '') +
                '<button class="qd-copy" data-qd="copy">Copy</button>' +
                '</div>' +
                '<pre>' + esc(q.raw) + '</pre>' +
                '</div>' +
                renderExplainOutput(qid) +
                '</div>';
        });

        html += '</div></div>';


        return { html: html, count: all.length, ms: totalMs };
    }

    function render() {
        if (!getKey()) { renderKeyForm(); return; }

        var latestCount = lastBatches.length ? (lastBatches[0].queries || []).length : 0;
        badge.textContent = latestCount;
        badge.style.display = latestCount > 0 ? 'block' : 'none';

        if (!lastBatches.length) {
            body.innerHTML = '<div class="qd-empty">Belum ada query. Buka/jalankan sebuah halaman report, lalu Refresh.</div>';
            return;
        }

        var html = '';
        var visibleBatches = 0;

        groupBatches(lastBatches).forEach(function (group) {
            var inner = '';
            var gReq = 0, gQ = 0, gMs = 0;

            group.items.forEach(function (item) {
                var rendered = renderBatch(item.batch, item.bi);
                if (!rendered) return;
                inner += rendered.html;
                gReq++;
                gQ += rendered.count;
                gMs += rendered.ms;
                visibleBatches++;
            });

            if (!inner) return;

            var collapsed = !!collapsedGroups[group.origin];

            html += '<div class="qd-group">' +
                '<div class="qd-group-head" data-qd="gtoggle" data-gkey="' + esc(group.origin) + '">' +
                '<span class="qd-caret">' + (collapsed ? '\u25b8' : '\u25be') + '</span>' +
                '<span class="qd-group-path">/' + esc(group.origin) + '</span>' +
                '<button class="qd-mini" data-qd="export-group" data-gkey="' + esc(group.origin) + '" title="Export seluruh alur halaman ini ke tiket">MD</button>' +
                '<span class="qd-tag">' + gReq + ' req \u00b7 ' + gQ + ' q \u00b7 ' + gMs.toFixed(1) + ' ms</span>' +
                '</div>' +
                '<div class="qd-group-body"' + (collapsed ? ' hidden' : '') + '>' + inner + '</div>' +
                '</div>';
        });

        body.innerHTML = visibleBatches
            ? html
            : '<div class="qd-empty">Tidak ada query yang cocok dengan filter.</div>';
    }

    // ---- polling -----------------------------------------------------------

    function startPolling() {
        stopPolling();
        if (!getKey()) return;
        pollTimer = setInterval(fetchRecent, CFG.pollMs || 2500);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // ---- open / close ------------------------------------------------------

    function open() {
        panel.removeAttribute('hidden');
        try { sessionStorage.setItem(OPEN_STORAGE, '1'); } catch (e) { }
        fetchRecent();
        startPolling();
    }
    function close() {
        panel.setAttribute('hidden', '');
        closeModal();
        try { sessionStorage.removeItem(OPEN_STORAGE); } catch (e) { }
        stopPolling();
    }

    // ---- capture trace -----------------------------------------------------

    /**
     * Buka modal "Ambil Kasus". Dropdown langkah-dicurigai diisi dari batch
     * yang SEDANG tampil, dan default-nya langkah paling akhir — karena
     * support menekan tombol ini tepat setelah melihat hasil yang salah,
     * jadi langkah terakhir hampir selalu yang bermasalah.
     */
    function openCapture() {
        if (!capModal) return;
        var sel = capModal.querySelector('[data-qd="cap-suspect"]');
        var res = capModal.querySelector('[data-qd="cap-result"]');
        if (res) res.innerHTML = '';

        // lastBatches = TERBARU DULU; trace disimpan kronologis, jadi nomor
        // langkah dibalik supaya cocok dengan yang nanti dilihat dev.
        var total = lastBatches.length;
        if (sel) {
            var html = '';
            lastBatches.forEach(function (b, i) {
                var no = total - i;
                html += '<option value="' + no + '"' + (i === 0 ? ' selected' : '') + '>'
                     + no + '. ' + esc((b.method || '') + ' /' + (b.path || '')) + '</option>';
            });
            sel.innerHTML = html || '<option value="-1">(belum ada langkah)</option>';
        }
        capModal.removeAttribute('hidden');
    }

    function closeCapture() { if (capModal) capModal.setAttribute('hidden', ''); }

    function submitCapture() {
        var noteEl = capModal.querySelector('[data-qd="cap-note"]');
        var selEl = capModal.querySelector('[data-qd="cap-suspect"]');
        var res = capModal.querySelector('[data-qd="cap-result"]');
        var note = noteEl ? noteEl.value.trim() : '';

        if (!note) {
            if (res) res.innerHTML = '<div class="qd-err">Isi dulu catatan singkatnya — ini yang dibaca developer duluan.</div>';
            if (noteEl) noteEl.focus();
            return;
        }
        if (res) res.innerHTML = '<div class="qd-empty">Menyimpan...</div>';

        fetch(CFG.captureUrl, {
            method: 'POST',
            headers: authHeaders({ 'X-CSRF-TOKEN': getCsrf(), 'Content-Type': 'application/json' }),
            credentials: 'same-origin',
            body: JSON.stringify({
                note: note,
                suspect: selEl ? parseInt(selEl.value, 10) : -1
            })
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, json: j }; });
        }).then(function (out) {
            if (!out.ok) {
                res.innerHTML = '<div class="qd-err">' + esc(out.json.message || 'Gagal menyimpan trace.') + '</div>';
                return;
            }
            var d = out.json.data || {};
            res.innerHTML =
                '<div style="background:#243024;border-radius:4px;padding:8px 10px">'
                + '<div style="color:#9b9b93;margin-bottom:4px">Kirim kode ini ke developer:</div>'
                + '<div style="font-size:15px;font-weight:600;letter-spacing:.02em">' + esc(d.code || '') + '</div>'
                + '<button data-qd="cap-copy" data-code="' + esc(d.code || '') + '" style="margin-top:6px">Copy kode</button>'
                + '</div>';
        }).catch(function () {
            res.innerHTML = '<div class="qd-err">Gagal menghubungi server.</div>';
        });
    }

    // ---- events ------------------------------------------------------------

    fab.addEventListener('click', function () { isOpen() ? close() : open(); });

    panel.addEventListener('click', function (e) {
        var t = e.target;
        var action = t.getAttribute('data-qd');

        if (action === 'close') return close();
        if (action === 'refresh') return fetchRecent();
        if (action === 'clear') return clearAll();
        if (action === 'lock') return lockSession();
        if (action === 'capture') return openCapture();
        if (action === 'cap-close') return closeCapture();
        if (action === 'cap-submit') return submitCapture();
        if (action === 'cap-copy') { copyText(t.getAttribute('data-code') || '', t); return; }

        // ---- modal export ----
        if (action === 'md-close') return closeModal();
        if (action === 'md-copy') {
            if (modalText) { modalText.focus(); modalText.select(); }
            copyText(currentExport.md, t);
            return;
        }
        if (action === 'md-download') {
            downloadMd(currentExport.md, currentExport.filename);
            return;
        }

        // ---- pemicu export ----
        if (action === 'md') {
            exportQuery(parseInt(t.getAttribute('data-bi'), 10), parseInt(t.getAttribute('data-qi'), 10));
            return;
        }
        if (action === 'export-batch') {
            exportBatch(parseInt(t.getAttribute('data-bi'), 10));
            return;
        }
        if (action === 'export-group') {
            exportGroup(t.getAttribute('data-gkey'));
            return;
        }

        if (action === 'key-submit') {
            var input = body.querySelector('[data-qd="key"]');
            var val = input ? input.value.trim() : '';
            if (!val) {
                var er = body.querySelector('.qd-err');
                if (!er && input) input.insertAdjacentHTML('afterend', '<div class="qd-err">Key wajib diisi.</div>');
                return;
            }
            unlockWithKey(val);
            return;
        }

        if (action === 'copy') {
            var pre = t.parentNode.parentNode.querySelector('pre');
            copyText(pre ? pre.textContent : '', t);
            return;
        }

        if (action === 'explain' || action === 'explain-analyze') {
            runExplain(
                parseInt(t.getAttribute('data-bi'), 10),
                parseInt(t.getAttribute('data-qi'), 10),
                t.getAttribute('data-qid'),
                action === 'explain-analyze'
            );
            return;
        }

        if (action === 'explain-copy') {
            var planPre = t.parentNode.parentNode.querySelector('pre');
            copyText(planPre ? planPre.textContent : '', t);
            return;
        }

        if (action === 'explain-close') {
            delete explainCache[t.getAttribute('data-qid')];
            render();
            return;
        }

        if (action === 'toggle') {
            var bkey = t.getAttribute('data-bkey') ||
                (t.parentNode && t.parentNode.getAttribute('data-bkey'));
            if (bkey) { collapsedBatches[bkey] = !collapsedBatches[bkey]; render(); }
            return;
        }

        if (action === 'gtoggle') {
            var gkey = t.getAttribute('data-gkey') ||
                (t.parentNode && t.parentNode.getAttribute('data-gkey'));
            if (gkey) { collapsedGroups[gkey] = !collapsedGroups[gkey]; render(); }
            return;
        }

        // klik di anak header (caret/tag/path) -> toggle header terdekat.
        // Tombol MD di header sudah ditangani di atas (return), jadi tidak
        // ikut men-toggle.
        var head = t.closest ? t.closest('.qd-batch-head, .qd-group-head') : null;
        if (head) {
            var isGroup = head.classList.contains('qd-group-head');
            var key = head.getAttribute(isGroup ? 'data-gkey' : 'data-bkey');
            if (key) {
                if (isGroup) collapsedGroups[key] = !collapsedGroups[key];
                else collapsedBatches[key] = !collapsedBatches[key];
                render();
            }
        }
    });

    body.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.getAttribute('data-qd') === 'key') {
            var val = e.target.value.trim();
            if (val) unlockWithKey(val);
        }
    });

    filterInput.addEventListener('input', function () {
        filterText = this.value.trim().toLowerCase();
        render();
    });

    if (slowOnlyInput) {
        slowOnlyInput.addEventListener('change', function () { slowOnly = this.checked; render(); });
    }
    if (dupOnlyInput) {
        dupOnlyInput.addEventListener('change', function () { dupOnly = this.checked; render(); });
    }

    function copyText(text, btn) {
        var original = btn.textContent;
        function ok() {
            btn.textContent = 'Tersalin';
            btn.classList.add('ok');
            setTimeout(function () { btn.textContent = original; btn.classList.remove('ok'); }, 1200);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(ok).catch(function () { fallbackCopy(text, ok); });
        } else {
            fallbackCopy(text, ok);
        }
    }
    function fallbackCopy(text, ok) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); ok(); } catch (e) { }
        document.body.removeChild(ta);
    }

    if (window.jQuery) {
        window.jQuery(document).ajaxComplete(function () {
            if (!isOpen()) return;
            clearTimeout(ajaxDebounce);
            ajaxDebounce = setTimeout(fetchRecent, 300);
        });
    }

    try { if (sessionStorage.getItem(OPEN_STORAGE) === '1') open(); } catch (e) { }
})();