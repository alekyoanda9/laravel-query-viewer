/**
 * Query Viewer — halaman dashboard penuh (/viewer).
 *
 * Berdiri sendiri dari panel FAB (query-debug.js): panel = intip cepat,
 * dashboard = analisis mendalam. Keduanya MEMBACA SUMBER YANG SAMA
 * (QueryDebugStore lewat /recent delta) — dashboard tidak punya pipeline data
 * baru, hanya UI yang lebih besar + tab Payload/Response/Context + lazy response.
 *
 * Gate: halaman ini dibuka lewat navigasi browser (session), jadi request-nya
 * TIDAK membawa X-Query-Debug-Key — cukup cookie session + CSRF untuk POST.
 */
(function () {
    'use strict';

    var CFG = window.QD_VIEWER || {};

    var elLive = document.getElementById('qv-live');
    var elPages = document.getElementById('qv-pages');
    var elContent = document.getElementById('qv-content');
    var elSearch = document.getElementById('qv-search');
    var elFilters = document.getElementById('qv-filters');
    var elClear = document.getElementById('qv-clear');
    var elMode = document.getElementById('qv-mode');
    var elExport = document.getElementById('qv-export');
    var elModal = document.getElementById('qv-modal');
    var elModalText = document.getElementById('qv-modal-text');
    var elModalTitle = document.getElementById('qv-modal-title');

    // ---- state ------------------------------------------------------------
    var lastSeq = 0;
    var generation = '';
    var batchesById = {};

    var selectedOrigin = null;
    var expanded = {};        // batchId -> true
    var activeTab = {};       // batchId -> 'queries'|'payload'|'response'|'context'
    var explainCache = {};    // qid -> {loading|error|plan,elapsed}
    var sampleCache = {};     // qid -> {loading|error|columns,rows,elapsed}
    var responseCache = {};   // batchId -> {loading|error|data}

    var filterMode = 'all';   // all|slow|n1
    var groupMode = 'chrono'; // default: kronologis lintas menu; "Per Menu" = opsi
    var ALL = '::all::';
    var searchText = '';
    var contentSig = '';
    var currentExport = { md: '', filename: 'query-viewer.md' };

    // ---- helpers ----------------------------------------------------------
    // Fungsi bersama (formatter + pembentuk Markdown + SQL highlight) dari
    // query-debug-shared.js — satu sumber kebenaran dengan panel FAB.
    var QS = window.QDShared || {};
    var esc = QS.esc, fence = QS.fence, fmtBytes = QS.fmtBytes;
    var highlightSql = QS.highlightSql, originOf = QS.originOf;
    var pretty = QS.prettyJson, maybePrettyJson = QS.prettyJson;
    var payloadMd = QS.md.payload, responseMd = QS.md.response;
    var metaMd = function (b) { return QS.md.meta(b, { includeEndpoint: true }); };

    function csrf() {
        var m = document.querySelector('meta[name="qd-csrf"]');
        return m ? m.getAttribute('content') : '';
    }

    function jsonHeaders() {
        return { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json', 'Accept': 'application/json' };
    }

    function orderedBatches() {
        var arr = [];
        for (var id in batchesById) {
            if (batchesById.hasOwnProperty(id)) arr.push(batchesById[id]);
        }
        arr.sort(function (a, b) { return (b.seq || 0) - (a.seq || 0); });
        return arr;
    }

    // ---- data fetch (delta) ----------------------------------------------
    // Polling adaptif (item #2) + Page Visibility (item #1): setTimeout
    // rekursif supaya delay bisa melebar saat idle; pollHandle kini handle
    // setTimeout, bukan setInterval.
    var POLL_BASE = CFG.pollMs || 2500;
    var POLL_MAX = 30000;
    var pollIdle = 0;
    var pollHandle = null;
    var locked = false;

    function pollDelay() {
        return Math.min(POLL_BASE * Math.pow(2, Math.min(pollIdle, 4)), POLL_MAX);
    }
    function schedulePoll() {
        if (pollHandle) { clearTimeout(pollHandle); pollHandle = null; }
        if (locked || document.hidden) return;   // terkunci / tab tak terlihat -> diam
        pollHandle = setTimeout(poll, pollDelay());
    }

    function poll() {
        if (locked) return;

        var sep = CFG.recentUrl.indexOf('?') === -1 ? '?' : '&';
        var url = CFG.recentUrl + sep + 'after=' + encodeURIComponent(lastSeq) +
            '&gen=' + encodeURIComponent(generation);

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (r.status === 403) { renderLocked(); return null; }
                return r.ok ? r.json() : null;
            })
            .then(function (json) {
                if (locked) return;   // 403 -> renderLocked sudah hentikan poll
                setLive(true);
                if (json) {
                    var payload = json.data || json;
                    var gotNew = !!(payload.batches && payload.batches.length);
                    pollIdle = gotNew ? 0 : (pollIdle + 1);
                    merge(payload);
                    renderPages();
                    renderContentIfChanged();
                }
                schedulePoll();
            })
            .catch(function () { setLive(false); pollIdle = pollIdle + 1; schedulePoll(); });
    }

    var elLock = document.getElementById('qv-lock');

    function lockViewer() {
        fetch(CFG.lockUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: jsonHeaders()      // sudah bawa X-CSRF-TOKEN dari meta qd-csrf
        }).finally(renderLocked);       // apa pun balasan server, kunci tampilan lokal
    }

    if (elLock) {
        elLock.addEventListener('click', function () {
            if (!confirm('Kunci sesi Query Viewer? Panel dan dashboard akan mati sampai unlock lagi.')) return;
            lockViewer();
        });
    }

    // Sesi browser di-lock (dari panel FAB atau tab lain) saat dashboard ini
    // masih terbuka: hentikan polling permanen & tampilkan status terkunci,
    // alih-alih diam-diam terus polling tanpa data seperti sebelumnya.
    function renderLocked() {
        if (locked) return;
        locked = true;
        setLive(false);
        if (pollHandle) { clearTimeout(pollHandle); pollHandle = null; }
        var msg = '<div class="qv-empty">Sesi dikunci dari panel query viewer.<br>' +
            'Unlock lagi lewat panel, lalu muat ulang halaman ini.</div>';
        if (elContent) elContent.innerHTML = msg;
        if (elPages) elPages.innerHTML = msg;
    }

    function merge(p) {
        if (!p) return;
        var serverGen = p.generation || '';
        var isFull = !!p.full || (serverGen && serverGen !== generation);

        if (isFull) {
            batchesById = {};
            responseCache = {};
            lastSeq = 0;
        }
        generation = serverGen || generation;

        (p.batches || []).forEach(function (b) {
            var id = b.id || ('seq-' + (b.seq || 0));
            batchesById[id] = b;
        });

        if (typeof p.head === 'number') lastSeq = p.head;

        var minSeq = typeof p.min_seq === 'number' ? p.min_seq : 0;
        if (minSeq > 0) {
            for (var id in batchesById) {
                if (batchesById.hasOwnProperty(id) && (batchesById[id].seq || 0) < minSeq) {
                    delete batchesById[id];
                }
            }
        }
    }

    function setLive(on) {
        if (elLive) elLive.className = 'qv-dot' + (on ? ' live' : '');
    }

    // ---- insight filter helpers ------------------------------------------
    function batchIsSlow(b) {
        return (b.queries || []).some(function (q) {
            return parseFloat(q.time_ms || 0) >= (CFG.slowMs || 500);
        });
    }
    function batchHasN1(b) {
        return !!(b.insight && b.insight.n_plus_one && b.insight.n_plus_one.length);
    }
    function passesFilter(b) {
        if (filterMode === 'slow') return batchIsSlow(b);
        if (filterMode === 'n1') return batchHasN1(b);
        return true;
    }

    // ---- sidebar: Recorded Pages -----------------------------------------
    function pageGroups() {
        var map = {};
        var order = [];
        orderedBatches().forEach(function (b) {
            var o = originOf(b);
            if (!map[o]) { map[o] = { origin: o, reqs: 0, queries: 0, last: b.at, error: false }; order.push(o); }
            map[o].reqs++;
            map[o].queries += (b.queries || []).length;
            map[o].error = map[o].error || !!b.error;
        });
        return order.map(function (o) { return map[o]; });
    }

    function renderPages() {
        var groups = pageGroups().filter(function (g) {
            if (!searchText) return true;
            return g.origin.toLowerCase().indexOf(searchText) !== -1;
        });

        var html = '';

        // Di mode kronologis, tambahkan entri "Semua" di atas — memilihnya
        // menampilkan SATU aliran lintas menu urut waktu (dengan chip menu).
        if (groupMode === 'chrono') {
            var totalReq = orderedBatches().length;
            html += '<div class="qv-page all' + (selectedOrigin === ALL ? ' sel' : '') + '" data-origin="' + ALL + '">' +
                '<div class="qv-page-path">\u23f1 Semua (kronologis)</div>' +
                '<div class="qv-page-meta"><span>' + totalReq + ' req</span><span>lintas menu</span></div>' +
                '</div>';
        }

        if (!groups.length && groupMode !== 'chrono') {
            elPages.innerHTML = '<div class="qv-empty">Belum ada halaman terekam.</div>';
            return;
        }

        var origins = groups.map(function (g) { return g.origin; });
        // pertahankan seleksi kalau masih valid; kalau tidak, pilih default.
        var validSel = selectedOrigin === ALL ? (groupMode === 'chrono') : (origins.indexOf(selectedOrigin) !== -1);
        if (!validSel) {
            selectedOrigin = groupMode === 'chrono' ? ALL : (origins[0] || null);
        }

        html += groups.map(function (g) {
            var cls = 'qv-page' + (g.origin === selectedOrigin ? ' sel' : '') + (g.error ? ' has-error' : '');
            return '<div class="' + cls + '" data-origin="' + esc(g.origin) + '">' +
                '<div class="qv-page-path">/' + esc(g.origin) + '</div>' +
                '<div class="qv-page-meta"><span>' + g.reqs + ' req</span><span>' + g.queries + ' q</span>' +
                (g.error ? '<span style="color:#f87171">error</span>' : '') + '</div>' +
                '</div>';
        }).join('');

        elPages.innerHTML = html;
    }

    // ---- content: requests for selected origin ---------------------------
    function selectedBatches() {
        return orderedBatches().filter(function (b) {
            if (!passesFilter(b)) return false;
            if (selectedOrigin === ALL) {
                // aliran lintas menu — hormati kotak cari (origin/path/route).
                if (!searchText) return true;
                var hay = (originOf(b) + ' ' + (b.path || '') + ' ' + (b.route || '')).toLowerCase();
                return hay.indexOf(searchText) !== -1;
            }
            return originOf(b) === selectedOrigin;
        });
    }

    function contentSignature() {
        // supaya polling idle tidak memaksa re-render (yang menghapus scroll).
        return [selectedOrigin, filterMode, groupMode].concat(selectedBatches().map(function (b) {
            return b.id + ':' + (b.queries || []).length + ':' + (b.response ? (b.response.status || '') : '');
        })).join('|');
    }

    function renderContentIfChanged() {
        var sig = contentSignature();
        if (sig !== contentSig) {
            contentSig = sig;
            renderContent();
        }
    }

    function renderContent() {
        if (!selectedOrigin) { elContent.innerHTML = '<div class="qv-note">Pilih halaman di kiri.</div>'; return; }

        var batches = selectedBatches();
        var isAll = selectedOrigin === ALL;
        var title = isAll ? '\u23f1 Semua request (kronologis)' : '/' + esc(selectedOrigin);
        var html = '<div class="qv-content-head"><div class="p">' + title + '</div>' +
            '<div class="m">' + batches.length + ' request' +
            (isAll ? ' \u00b7 urut waktu, lintas menu' : '') +
            (filterMode !== 'all' ? ' \u00b7 filter: ' + esc(filterMode) : '') + '</div></div>';

        if (!batches.length) {
            html += '<div class="qv-empty">Tidak ada request yang cocok filter.</div>';
            elContent.innerHTML = html;
            return;
        }

        batches.forEach(function (b) { html += renderRequest(b); });
        elContent.innerHTML = html;
    }

    function statusClass(s) {
        if (!s) return '';
        if (s >= 500) return 'st5';
        if (s >= 400) return 'st4';
        return 'st2';
    }

    function renderRequest(b) {
        var id = b.id;
        var open = !!expanded[id];
        var queries = b.queries || [];
        var totalMs = queries.reduce(function (a, q) { return a + parseFloat(q.time_ms || 0); }, 0);
        var tab = activeTab[id] || 'queries';

        var head = '<div class="qv-req-head" data-req="' + esc(id) + '">' +
            '<span class="qv-caret">' + (open ? '\u25be' : '\u25b8') + '</span>' +
            '<span class="qv-method ' + (b.is_ajax ? 'ajax' : '') + '">' + esc(b.method) + (b.is_ajax ? ' \u00b7 AJAX' : '') + '</span>' +
            (groupMode === 'chrono' ? '<span class="qv-origin-chip" title="/' + esc(originOf(b)) + '">/' + esc(originOf(b)) + '</span>' : '') +
            '<span class="qv-req-path">' + (b.route ? esc(b.route) : '/' + esc(b.path)) + '</span>' +
            (b.status ? '<span class="qv-badge ' + statusClass(b.status) + '">' + b.status + '</span>' : '') +
            (b.error ? '<span class="qv-badge err">ERROR</span>' : '') +
            (batchHasN1(b) ? '<span class="qv-badge n1">N+1</span>' : '') +
            '<span class="qv-badge q">' + queries.length + ' q \u00b7 ' + totalMs.toFixed(1) + ' ms</span>' +
            '<button class="qv-mini" data-act="export-one" data-bid="' + esc(id) + '" title="Export request ini ke Markdown">MD</button>' +
            '</div>';

        if (!open) {
            return '<div class="qv-req' + (b.error ? ' has-error' : '') + '">' + head + '</div>';
        }

        var respMeta = b.response || null;
        var tabs = '<div class="qv-tabs">' +
            tabBtn(id, 'queries', 'DB Queries', queries.length) +
            tabBtn(id, 'payload', 'Payload', null) +
            (CFG.response ? tabBtn(id, 'response', 'Response', null) : '') +
            tabBtn(id, 'context', 'Context', null) +
            '</div>';

        var body = '<div class="qv-tabbody">' + renderTab(b, tab) + '</div>';

        return '<div class="qv-req' + (b.error ? ' has-error' : '') + '">' + head + tabs + body + '</div>';
    }

    function tabBtn(id, key, label, count) {
        var on = (activeTab[id] || 'queries') === key;
        return '<div class="qv-tab' + (on ? ' on' : '') + '" data-tab="' + key + '" data-req="' + esc(id) + '">' +
            esc(label) + (count != null ? ' <span class="c">' + count + '</span>' : '') + '</div>';
    }

    function renderTab(b, tab) {
        if (tab === 'payload') return renderPayload(b);
        if (tab === 'response') return renderResponse(b);
        if (tab === 'context') return renderContext(b);
        return renderQueries(b);
    }

    // ---- tab: DB Queries --------------------------------------------------
    function renderQueries(b) {
        var queries = b.queries || [];
        if (b.error) {
            var eh = '<div class="qv-q-error"><b>' + esc(b.error.class || 'Exception') + ':</b> ' +
                esc(b.error.message || '(tanpa pesan)') + '</div>';
        }
        var out = b.insight ? renderInsight(b.insight) : '';
        if (b.error) out += '<div class="qv-q failed">' + eh + '</div>';

        if (!queries.length) {
            out += '<div class="qv-empty">(tidak ada query di request ini)</div>';
            return out;
        }

        var counts = {};
        queries.forEach(function (q) { counts[q.raw] = (counts[q.raw] || 0) + 1; });

        queries.forEach(function (q, qi) {
            var qid = q.id || '';
            var ms = parseFloat(q.time_ms || 0);
            var slow = ms >= (CFG.slowMs || 500);
            var dup = counts[q.raw] > 1;

            out += '<div class="qv-q' + (q.failed ? ' failed' : '') + '">' +
                (q.failed ? '<div class="qv-q-error"><b>Query gagal:</b> ' + esc(q.error || '(tanpa pesan)') + '</div>' : '') +
                '<div class="qv-q-meta">' +
                (q.failed ? '<span class="qv-badge err">GAGAL</span>'
                    : '<span class="qv-ms ' + (slow ? 'slow' : '') + '">' + ms.toFixed(1) + ' ms</span>') +
                (q.op ? '<span class="qv-op">' + esc(q.op) + (q.table ? ' ' + esc(q.table) : '') + '</span>' : '') +
                (dup ? '<span class="qv-dup">\u00d7' + counts[q.raw] + '</span>' : '') +
                (q.file ? '<span class="qv-src" title="pemicu query">' + esc(q.file) + (q.line ? ':' + q.line : '') + '</span>' : '') +
                '<span class="qv-q-actions">' +
                (CFG.explain && qid && !q.failed ? '<button class="qv-mini" data-act="explain" data-bid="' + esc(b.id) + '" data-qi="' + qi + '" data-qid="' + esc(qid) + '">Explain</button>' : '') +
                (CFG.sample && qid && !q.failed ? '<button class="qv-mini" data-act="sample" data-bid="' + esc(b.id) + '" data-qi="' + qi + '" data-qid="' + esc(qid) + '">Sampel</button>' : '') +
                '<button class="qv-mini" data-act="copy-sql">Copy</button>' +
                '</span></div>' +
                '<pre class="qv-sql">' + highlightSql(q.raw || '') + '</pre>' +
                explainOut(qid) + sampleOut(qid) +
                '</div>';
        });

        return out;
    }

    function renderInsight(ins) {
        if (!ins) return '';
        var bits = [];
        if (ins.slow_count) bits.push(ins.slow_count + ' lambat');
        if (ins.redundant_count) bits.push(ins.redundant_count + ' redundan');
        if (ins.n_plus_one && ins.n_plus_one.length) {
            ins.n_plus_one.forEach(function (n) {
                bits.push('N+1 \u00d7' + n.count + (n.table ? ' di ' + esc(n.table) : ''));
            });
        }
        if (!bits.length) return '';
        return '<div class="qv-insight"><b>Insight:</b> ' + bits.join(' \u00b7 ') + '</div>';
    }

    // ---- tab: Payload -----------------------------------------------------
    function renderPayload(b) {
        var input = b.input || {};
        if (!input || (typeof input === 'object' && !Object.keys(input).length)) {
            return '<div class="qv-empty">(tidak ada payload request)</div>';
        }
        return '<pre class="qv-json">' + esc(pretty(input)) + '</pre>';
    }

    // ---- tab: Context -----------------------------------------------------
    function renderContext(b) {
        var rows = '';
        (b.context || []).forEach(function (c) {
            rows += '<tr><td class="k">' + esc(c.label) + '</td><td>' + esc(c.value) + '</td></tr>';
        });
        rows += '<tr><td class="k">Koneksi</td><td>' + esc(b.conn || '(default)') + '</td></tr>';
        rows += '<tr><td class="k">Waktu</td><td>' + esc(b.at || '') + '</td></tr>';
        if (b.dur_ms != null) rows += '<tr><td class="k">Durasi</td><td>' + esc(b.dur_ms) + ' ms</td></tr>';
        return '<table class="qv-kv">' + rows + '</table>';
    }

    // ---- tab: Response (lazy) --------------------------------------------
    function renderResponse(b) {
        var id = b.id;
        var meta = b.response || null;

        if (!meta) return '<div class="qv-empty">(response tidak ditangkap untuk request ini)</div>';

        // binary/download -> metadata saja
        if (meta.kind === 'binary' || meta.kind === 'skipped') {
            return '<div class="qv-resp-meta">' +
                '<b>' + esc(meta.content_type || 'unknown') + '</b>' +
                (meta.size != null ? ' \u00b7 ' + fmtBytes(meta.size) : '') +
                (meta.filename ? ' \u00b7 ' + esc(meta.filename) : '') +
                (meta.kind === 'binary' ? ' \u00b7 (download/stream — body tidak ditangkap)' : ' \u00b7 (tipe tidak ditangkap)') +
                '</div>';
        }

        var cached = responseCache[id];
        if (!cached) { fetchResponse(id); return '<div class="qv-empty">Memuat response…</div>'; }
        if (cached.loading) return '<div class="qv-empty">Memuat response…</div>';
        if (cached.error) return '<div class="qv-out err"><pre>' + esc(cached.error) + '</pre></div>';

        var d = cached.data || {};
        if (!d.captured) return '<div class="qv-empty">(response tidak ditangkap)</div>';

        var head = '<div class="qv-resp-meta"><b>' + esc(d.content_type || '') + '</b>' +
            (d.status ? ' \u00b7 ' + d.status : '') +
            (d.size != null ? ' \u00b7 ' + fmtBytes(d.size) : '') + '</div>';

        var trunc = d.truncated ? '<div class="qv-trunc">dipotong (asli: ' + fmtBytes(d.size) + ')</div>' : '';
        var body = d.body != null && d.body !== ''
            ? '<pre class="qv-json">' + esc(maybePrettyJson(d.body)) + '</pre>'
            : '<div class="qv-empty">(body kosong)</div>';

        return head + '<div class="qv-out"><div class="qv-out-head"><b>Response body</b>' +
            '<button class="qv-mini" data-act="copy-resp" data-bid="' + esc(id) + '">Copy</button></div>' +
            trunc + body + '</div>';
    }

    function fetchResponse(id) {
        responseCache[id] = { loading: true };
        fetch(CFG.batchUrl + '/' + encodeURIComponent(id) + '/response', {
            credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
            .then(function (r) {
                if (!r.ok) responseCache[id] = { error: (r.json && r.json.message) || 'Gagal memuat response.' };
                else responseCache[id] = { data: (r.json && r.json.data) || {} };
                contentSig = ''; renderContentIfChanged();
            }).catch(function () {
                responseCache[id] = { error: 'Gagal menghubungi server.' };
                contentSig = ''; renderContentIfChanged();
            });
    }

    // ---- EXPLAIN / Sampel -------------------------------------------------
    function runExplain(bid, qi, qid) {
        explainCache[qid] = { loading: true };
        contentSig = ''; renderContentIfChanged();
        fetch(CFG.explainUrl, {
            method: 'POST', headers: jsonHeaders(), credentials: 'same-origin',
            body: JSON.stringify({ bid: bid, query: qi, id: qid })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
            .then(function (r) {
                if (!r.ok) explainCache[qid] = { error: (r.json && r.json.message) || 'EXPLAIN gagal.' };
                else { var d = (r.json && r.json.data) || {}; explainCache[qid] = { plan: d.plan || '(plan kosong)', elapsed: d.elapsed_ms }; }
                contentSig = ''; renderContentIfChanged();
            }).catch(function () { explainCache[qid] = { error: 'Gagal menghubungi server.' }; contentSig = ''; renderContentIfChanged(); });
    }

    function runSample(bid, qi, qid) {
        sampleCache[qid] = { loading: true };
        contentSig = ''; renderContentIfChanged();
        fetch(CFG.sampleUrl, {
            method: 'POST', headers: jsonHeaders(), credentials: 'same-origin',
            body: JSON.stringify({ bid: bid, query: qi, id: qid })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
            .then(function (r) {
                if (!r.ok) sampleCache[qid] = { error: (r.json && r.json.message) || 'Sampel gagal.' };
                else { var d = (r.json && r.json.data) || {}; sampleCache[qid] = { columns: d.columns || [], rows: d.rows || [], elapsed: d.elapsed_ms }; }
                contentSig = ''; renderContentIfChanged();
            }).catch(function () { sampleCache[qid] = { error: 'Gagal menghubungi server.' }; contentSig = ''; renderContentIfChanged(); });
    }

    function explainOut(qid) {
        var s = explainCache[qid];
        if (!s) return '';
        if (s.loading) return '<div class="qv-out"><div class="qv-out-head"><b>EXPLAIN</b><span>menjalankan…</span></div></div>';
        if (s.error) return '<div class="qv-out err"><div class="qv-out-head"><b>EXPLAIN</b><button class="qv-mini" data-act="explain-close" data-qid="' + esc(qid) + '">tutup</button></div><pre>' + esc(s.error) + '</pre></div>';
        return '<div class="qv-out"><div class="qv-out-head"><b>EXPLAIN</b><span>' + Number(s.elapsed || 0).toFixed(1) + ' ms</span>' +
            '<button class="qv-mini" data-act="explain-close" data-qid="' + esc(qid) + '">tutup</button></div><pre>' + esc(s.plan) + '</pre></div>';
    }

    function sampleOut(qid) {
        var s = sampleCache[qid];
        if (!s) return '';
        if (s.loading) return '<div class="qv-out sample"><div class="qv-out-head"><b>Sampel Data</b><span>menjalankan…</span></div></div>';
        if (s.error) return '<div class="qv-out sample err"><div class="qv-out-head"><b>Sampel Data</b><button class="qv-mini" data-act="sample-close" data-qid="' + esc(qid) + '">tutup</button></div><pre>' + esc(s.error) + '</pre></div>';

        var cols = s.columns || [], rows = s.rows || [];
        var head = '<div class="qv-out sample"><div class="qv-out-head"><b>Sampel Data</b><span>' + rows.length + ' baris \u00b7 ' + Number(s.elapsed || 0).toFixed(1) + ' ms</span>' +
            '<button class="qv-mini" data-act="sample-close" data-qid="' + esc(qid) + '">tutup</button></div>';
        if (!cols.length) return head + '<div class="qv-empty">(tidak ada kolom)</div></div>';

        var t = '<div class="qv-sscroll"><table class="qv-stable"><thead><tr>';
        cols.forEach(function (c) { t += '<th>' + esc(c) + '</th>'; });
        t += '</tr></thead><tbody>';
        if (!rows.length) t += '<tr><td colspan="' + cols.length + '" style="text-align:center;color:#64748b">(0 baris)</td></tr>';
        else rows.forEach(function (row) {
            t += '<tr>';
            for (var i = 0; i < cols.length; i++) {
                var v = row[i];
                t += '<td>' + (v == null ? '<i>NULL</i>' : esc(v)) + '</td>';
            }
            t += '</tr>';
        });
        t += '</tbody></table></div>';
        return head + t + '</div>';
    }

    // ---- misc formatting --------------------------------------------------
    function copyText(text, btn) {
        function ok() { if (btn) { var o = btn.textContent; btn.textContent = 'tersalin'; btn.classList.add('ok'); setTimeout(function () { btn.textContent = o; btn.classList.remove('ok'); }, 1000); } }
        if (navigator.clipboard) navigator.clipboard.writeText(text).then(ok).catch(function () { fallbackCopy(text, ok); });
        else fallbackCopy(text, ok);
    }
    function fallbackCopy(text, ok) {
        var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
        ta.select(); try { document.execCommand('copy'); ok(); } catch (e) { } document.body.removeChild(ta);
    }

    // ---- Export Markdown (payload + query + response) ---------------------
    // Builder MD (fence/metaMd/payloadMd/responseMd/query) hidup di QDShared;
    // di sini tinggal merangkai. queriesMd memakai QS.md.query + hitung duplikat.
    function queriesMd(b) {
        var queries = b.queries || [];
        if (!queries.length) return '_(tidak ada query)_';
        var counts = QS.dupCounts(b);
        return queries.map(function (q) { return QS.md.query(q, counts[q.raw]); }).join('\n\n');
    }

    // Ambil response body satu batch (lazy) untuk export. Selalu resolve.
    function fetchResponseData(id) {
        if (!CFG.response || !id) return Promise.resolve(null);
        return fetch(CFG.batchUrl + '/' + encodeURIComponent(id) + '/response', {
            credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { return (j && j.data) || null; })
            .catch(function () { return null; });
    }

    function stamp(at) {
        var s = String(at || '').replace(/[^0-9]/g, '');
        return s ? s.slice(0, 14) : String(Date.now());
    }

    // Rangkai MD dari sekumpulan batch (urut kronologis: paling lama dulu).
    function exportBatches(batches, title, filename) {
        if (!batches.length) return;
        var chron = batches.slice().reverse(); // selectedBatches() terbaru-dulu

        openModal('Menyiapkan…', '# ' + title + '\n\n_Mengambil response…_', filename);

        Promise.all(chron.map(function (b) { return fetchResponseData(b.id); })).then(function (resps) {
            var totalQ = 0, totalMs = 0;
            chron.forEach(function (b) {
                (b.queries || []).forEach(function (q) { totalQ++; totalMs += parseFloat(q.time_ms || 0); });
            });

            var md = '# ' + title + '\n\n' +
                '_' + chron.length + ' request, ' + totalQ + ' query, total ' + totalMs.toFixed(1) + ' ms_\n\n';

            chron.forEach(function (b, i) {
                var pl = payloadMd(b), rp = responseMd(resps[i]);
                md += '---\n\n## ' + (i + 1) + '. ' + b.method + ' /' + b.path + (b.is_ajax ? ' (AJAX)' : '') + '\n\n' +
                    metaMd(b) + '\n\n' +
                    (pl ? pl + '\n\n' : '') +
                    '**Query:**\n\n' + queriesMd(b) + '\n\n' +
                    (rp ? rp + '\n\n' : '');
            });

            openModal(title, md, filename);
        });
    }

    function exportOne(id) {
        var b = batchesById[id];
        if (!b) return;
        exportBatches([b], (b.route || '/' + originOf(b)), 'qd-request-' + stamp(b.at) + '.md');
    }

    function exportCurrentView() {
        var batches = selectedBatches();
        if (!batches.length) return;
        var title = selectedOrigin === ALL
            ? 'Query Viewer \u2014 semua request (kronologis)'
            : 'Query Viewer \u2014 halaman /' + selectedOrigin;
        var fn = selectedOrigin === ALL ? 'qd-kronologis-' + stamp() + '.md' : 'qd-halaman-' + stamp() + '.md';
        exportBatches(batches, title, fn);
    }

    function openModal(title, md, filename) {
        currentExport = { md: md, filename: filename || 'query-viewer.md' };
        if (elModalTitle) elModalTitle.textContent = title || 'Export Markdown';
        if (elModalText) elModalText.value = md;
        if (elModal) elModal.removeAttribute('hidden');
        if (elModalText) { elModalText.focus(); elModalText.setSelectionRange(0, 0); }
    }
    function closeModal() { if (elModal) elModal.setAttribute('hidden', ''); }
    function downloadMd(text, filename) {
        try {
            var blob = new Blob([text], { type: 'text/markdown;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = filename || 'query-viewer.md';
            document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(url); if (a.parentNode) a.parentNode.removeChild(a); }, 100);
        } catch (e) { }
    }

    // ---- events -----------------------------------------------------------
    elPages.addEventListener('click', function (e) {
        var p = e.target.closest ? e.target.closest('.qv-page') : null;
        if (!p) return;
        selectedOrigin = p.getAttribute('data-origin');
        renderPages();
        contentSig = ''; renderContent();
    });

    elContent.addEventListener('click', function (e) {
        var t = e.target;
        var actEl = t.closest ? t.closest('[data-act]') : null;

        if (actEl) {
            var act = actEl.getAttribute('data-act');
            if (act === 'explain') { runExplain(actEl.getAttribute('data-bid'), parseInt(actEl.getAttribute('data-qi'), 10), actEl.getAttribute('data-qid')); return; }
            if (act === 'sample') { runSample(actEl.getAttribute('data-bid'), parseInt(actEl.getAttribute('data-qi'), 10), actEl.getAttribute('data-qid')); return; }
            if (act === 'explain-close') { delete explainCache[actEl.getAttribute('data-qid')]; contentSig = ''; renderContent(); return; }
            if (act === 'sample-close') { delete sampleCache[actEl.getAttribute('data-qid')]; contentSig = ''; renderContent(); return; }
            if (act === 'copy-sql') { var pre = actEl.closest('.qv-q').querySelector('pre.qv-sql'); copyText(pre ? pre.textContent : '', actEl); return; }
            if (act === 'copy-resp') { var rp = actEl.closest('.qv-out').querySelector('pre'); copyText(rp ? rp.textContent : '', actEl); return; }
            if (act === 'export-one') { exportOne(actEl.getAttribute('data-bid')); return; }
        }

        var tabEl = t.closest ? t.closest('.qv-tab') : null;
        if (tabEl) {
            activeTab[tabEl.getAttribute('data-req')] = tabEl.getAttribute('data-tab');
            contentSig = ''; renderContent();
            return;
        }

        var headEl = t.closest ? t.closest('.qv-req-head') : null;
        if (headEl) {
            var id = headEl.getAttribute('data-req');
            expanded[id] = !expanded[id];
            contentSig = ''; renderContent();
            return;
        }
    });

    elFilters.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-f]') : null;
        if (!b) return;
        filterMode = b.getAttribute('data-f');
        [].forEach.call(elFilters.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
        contentSig = ''; renderContent();
    });

    if (elMode) {
        elMode.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-m]') : null;
            if (!b) return;
            groupMode = b.getAttribute('data-m');
            [].forEach.call(elMode.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
            // masuk kronologis -> default "Semua"; balik ke per-menu -> menu pertama.
            selectedOrigin = groupMode === 'chrono' ? ALL : null;
            renderPages();
            contentSig = ''; renderContent();
        });
    }

    if (elExport) {
        elExport.addEventListener('click', function () { exportCurrentView(); });
    }
    if (elModal) {
        document.getElementById('qv-modal-close').addEventListener('click', closeModal);
        document.getElementById('qv-modal-copy').addEventListener('click', function () {
            if (elModalText) { elModalText.focus(); elModalText.select(); }
            copyText(currentExport.md, this);
        });
        document.getElementById('qv-modal-download').addEventListener('click', function () {
            downloadMd(currentExport.md, currentExport.filename);
        });
        elModal.addEventListener('click', function (e) { if (e.target === elModal) closeModal(); });
    }

    var searchTimer = null;
    elSearch.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            searchText = elSearch.value.trim().toLowerCase();
            renderPages();
            contentSig = ''; renderContent();
        }, 150);
    });

    elClear.addEventListener('click', function () {
        if (!confirm('Bersihkan semua batch terekam?')) return;
        fetch(CFG.clearUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .finally(function () {
                batchesById = {}; responseCache = {}; explainCache = {}; sampleCache = {};
                lastSeq = 0; generation = '';
                renderPages(); contentSig = ''; renderContent();
            });
    });

    // ---- go ---------------------------------------------------------------
    // Item #1 — Page Visibility: tab disembunyikan -> stop poll; kembali
    // terlihat (dan belum locked) -> satu poll cepat lalu backoff lagi.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (pollHandle) { clearTimeout(pollHandle); pollHandle = null; }
        } else if (!locked) {
            pollIdle = 0;
            poll();
        }
    });

    poll();   // poll() menjadwalkan siklus berikutnya sendiri (schedulePoll)
})();