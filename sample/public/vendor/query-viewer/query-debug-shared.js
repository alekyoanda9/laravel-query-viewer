/**
 * Query Viewer — modul BERSAMA panel FAB (query-debug.js) & dashboard
 * (query-debug-viewer.js).
 *
 * Isinya fungsi MURNI (tanpa DOM/state): pembentuk Markdown export, formatter,
 * dan SQL highlight. Dulu tiap surface punya salinan `fence`/`payloadMd`/
 * `responseMd`/`queryBlock`/`metaBlock` sendiri yang gampang menyimpang satu
 * sama lain (mis. panel export vs dashboard export beda format). Sekarang SATU
 * sumber kebenaran — perbaiki di sini, kedua surface ikut.
 *
 * Dipublish sebagai window.QDShared dan WAJIB dimuat SEBELUM kedua file di atas.
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // Pagar kode Markdown yang aman: kalau konten mengandung ``` naikkan ke ~~~~.
    function fence(lang, content) {
        var f = (String(content).indexOf('```') !== -1) ? '~~~~' : '```';
        return f + (lang || '') + '\n' + content + '\n' + f;
    }

    function fmtBytes(n) {
        n = Number(n || 0);
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1024 / 1024).toFixed(1) + ' MB';
    }

    // Coba rapikan string JSON; kalau bukan JSON, kembalikan apa adanya.
    function prettyJson(s) {
        if (typeof s !== 'string') {
            try { return JSON.stringify(s, null, 2); } catch (e) { return String(s); }
        }
        var t = s.trim();
        if (t && (t[0] === '{' || t[0] === '[')) {
            try { return JSON.stringify(JSON.parse(t), null, 2); } catch (e) { return s; }
        }
        return s;
    }

    // ---- SQL syntax highlight (tokenizer sederhana, aman dari keyword-di-string) --
    var KEYWORDS = {};
    ('select from where and or not in is null as join left right inner outer on group by order having limit offset ' +
     'insert into values update set delete distinct union all count sum avg min max case when then else end ' +
     'asc desc between like ilike exists with returning coalesce cast over partition').split(' ')
        .forEach(function (k) { KEYWORDS[k] = 1; });

    function highlightSql(sql) {
        sql = String(sql || '');
        var out = '', i = 0, n = sql.length;
        while (i < n) {
            var ch = sql[i];
            if (ch === "'") {
                var j = i + 1, str = "'";
                while (j < n) {
                    str += sql[j];
                    if (sql[j] === "'") {
                        if (j + 1 < n && sql[j + 1] === "'") { str += "'"; j += 2; continue; }
                        j++; break;
                    }
                    j++;
                }
                out += '<span class="s">' + esc(str) + '</span>'; i = j; continue;
            }
            if (ch === '-' && i + 1 < n && sql[i + 1] === '-') {
                var k = i; while (k < n && sql[k] !== '\n') k++;
                out += '<span class="c">' + esc(sql.slice(i, k)) + '</span>'; i = k; continue;
            }
            if (/[A-Za-z_]/.test(ch)) {
                var w = ''; while (i < n && /[A-Za-z0-9_]/.test(sql[i])) { w += sql[i]; i++; }
                out += KEYWORDS[w.toLowerCase()] ? '<span class="k">' + esc(w) + '</span>' : esc(w);
                continue;
            }
            if (/[0-9]/.test(ch)) {
                var num = ''; while (i < n && /[0-9.]/.test(sql[i])) { num += sql[i]; i++; }
                out += '<span class="n">' + esc(num) + '</span>'; continue;
            }
            out += esc(ch); i++;
        }
        return out;
    }

    function originOf(b) { return b.origin || b.path || '(tanpa path)'; }

    // ==== Pembentuk Markdown =============================================

    // Blok metadata (bold) satu request: konteks app, koneksi, menu, endpoint,
    // waktu, status, server, error. opts: {includeEndpoint (default true), host}.
    function mdMeta(b, opts) {
        opts = opts || {};
        var lines = [];
        (b.context || []).forEach(function (e) { lines.push('**' + e.label + ':** ' + e.value); });
        lines.push('**Koneksi DB:** ' + (b.conn || b.connection || '-'));
        lines.push('**Menu:** ' + (b.route || ('/' + originOf(b))));
        if (opts.includeEndpoint !== false) {
            lines.push('**Endpoint:** ' + b.method + ' /' + b.path + (b.is_ajax ? ' (AJAX)' : ''));
        }
        lines.push('**Waktu:** ' + (b.at || '-'));
        if (b.status) lines.push('**Status:** ' + b.status);
        if (opts.host) lines.push('**Server:** ' + opts.host);
        if (b.error) lines.push('**Error:** ' + (b.error.class || 'Exception') + ' \u2014 ' + (b.error.message || ''));
        return lines.join('\n');
    }

    // Request payload (input teredaksi) — hanya payload, bukan response.
    function mdPayload(b) {
        var input = b.input;
        if (!input || typeof input !== 'object' || !Object.keys(input).length) return '';
        var lines = Object.keys(input).map(function (k) {
            var v = input[k];
            return k + ' = ' + ((v !== null && typeof v === 'object') ? JSON.stringify(v) : String(v));
        });
        return '**Request payload:**\n\n' + fence('', lines.join('\n'));
    }

    // Response yang ditangkap. Body hanya untuk JSON; binary/skip = metadata.
    function mdResponse(resp) {
        if (!resp || !resp.captured) return '';
        var head = '**Response:** ' + (resp.content_type || '-') +
            (resp.status ? ' \u00b7 ' + resp.status : '') +
            (resp.truncated ? ' \u00b7 (dipotong)' : '');
        if (resp.kind === 'body' && resp.body != null && resp.body !== '') {
            var lang = (String(resp.content_type || '').indexOf('json') !== -1) ? 'json' : '';
            return head + '\n\n' + fence(lang, prettyJson(resp.body));
        }
        if (resp.kind === 'binary') {
            return head + '\n\n_(download/stream \u2014 body tidak ditangkap' +
                (resp.filename ? ': ' + resp.filename : '') + ')_';
        }
        return head + '\n\n_(tipe tidak ditangkap)_';
    }

    // Satu blok query: op/table, ms atau GAGAL, duplikat, file:line, SQL, error,
    // dan (opsional) hasil EXPLAIN. opts: {explain: <entry {plan,elapsed,analyze}>}.
    function mdQuery(q, dup, opts) {
        opts = opts || {};
        var head = (q.op || 'QUERY') + (q.table ? ' \u00b7 ' + q.table : '');
        var out = '**' + head + '**';

        out += q.failed ? ' \u2014 **GAGAL**' : (' \u2014 ' + Number(q.time_ms || 0).toFixed(1) + ' ms');
        if (dup > 1) out += ' \u00b7 dijalankan ' + dup + '\u00d7 identik dalam request ini';
        if (q.file) out += ' \u00b7 ' + q.file + (q.line ? ':' + q.line : '');

        out += '\n\n' + fence('sql', q.raw);

        if (q.failed && q.error) out += '\n\n> **Error:** ' + q.error;

        var ex = opts.explain;
        if (ex && ex.plan) {
            out += '\n\n_EXPLAIN' + (ex.analyze ? ' ANALYZE' : '') +
                ' (' + Number(ex.elapsed || 0).toFixed(1) + ' ms):_\n\n' + fence('text', ex.plan);
        }
        return out;
    }

    // Hitung berapa kali tiap SQL identik muncul dalam satu request (untuk anotasi
    // duplikat di export). Dipakai kedua surface supaya konsisten.
    function dupCounts(batch) {
        var counts = {};
        (batch.queries || []).forEach(function (q) { counts[q.raw] = (counts[q.raw] || 0) + 1; });
        return counts;
    }

    window.QDShared = {
        esc: esc,
        fence: fence,
        fmtBytes: fmtBytes,
        prettyJson: prettyJson,
        highlightSql: highlightSql,
        originOf: originOf,
        dupCounts: dupCounts,
        md: {
            meta: mdMeta,
            payload: mdPayload,
            response: mdResponse,
            query: mdQuery
        }
    };
})();
