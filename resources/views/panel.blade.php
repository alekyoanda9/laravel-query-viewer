@if(config('querydebug.enabled') && request()->getHost() === config('querydebug.host'))
    <style>
        #qd-root {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 99999;
            font-family: -apple-system, Segoe UI, Roboto, sans-serif;
        }

        #qd-fab {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            background: #1f2937;
            color: #fff;
            font-size: 18px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .3);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #qd-fab .qd-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 9px;
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            line-height: 18px;
            text-align: center;
            display: none;
        }

        #qd-panel {
            position: absolute;
            right: 0;
            bottom: 60px;
            width: 520px;
            max-width: 92vw;
            height: 620px;
            max-height: 80vh;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, .45);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #qd-panel[hidden] {
            display: none;
        }

        .qd-head {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
        }

        .qd-head b {
            font-size: 13px;
            flex: 1;
        }

        .qd-head button {
            background: #334155;
            color: #cbd5e1;
            border: none;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 12px;
            cursor: pointer;
        }

        .qd-head button:hover {
            background: #475569;
        }

        .qd-search {
            padding: 8px 12px;
            background: #0f172a;
            border-bottom: 1px solid #1e293b;
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .qd-search input[type="text"] {
            flex: 1;
            min-width: 0;
            box-sizing: border-box;
            background: #1e293b;
            color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 6px;
            padding: 6px 8px;
            font-size: 12px;
        }

        .qd-search label {
            font-size: 11px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            cursor: pointer;
        }

        .qd-body {
            flex: 1;
            overflow-y: auto;
            padding: 8px 10px;
        }

        .qd-keyform {
            padding: 20px;
            text-align: center;
        }

        .qd-keyform input {
            width: 100%;
            box-sizing: border-box;
            margin: 10px 0;
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #334155;
            background: #1e293b;
            color: #fff;
        }

        .qd-keyform button {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
        }

        .qd-err {
            color: #f87171;
            font-size: 12px;
            margin-top: 6px;
        }

        /* ---- grup per halaman asal ---- */

        .qd-group {
            margin-bottom: 12px;
        }

        .qd-group-head {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            background: #172033;
            border: 1px solid #334155;
            border-radius: 7px;
            cursor: pointer;
            font-size: 12px;
        }

        .qd-group-head .qd-caret {
            color: #64748b;
            font-size: 10px;
            width: 10px;
        }

        .qd-group-path {
            flex: 1;
            color: #7dd3fc;
            font-weight: 600;
            word-break: break-all;
        }

        .qd-group-body {
            padding: 8px 0 0 8px;
        }

        .qd-group-body[hidden] {
            display: none;
        }

        /* ---- batch ---- */

        .qd-batch {
            border: 1px solid #1e293b;
            border-radius: 8px;
            margin-bottom: 10px;
            overflow: hidden;
        }

        .qd-batch-head {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            background: #1e293b;
            cursor: pointer;
            font-size: 12px;
        }

        .qd-batch-head .qd-path {
            flex: 1;
            color: #93c5fd;
            word-break: break-all;
        }

        .qd-batch-queries[hidden] {
            display: none;
        }

        .qd-tag {
            font-size: 10px;
            padding: 1px 6px;
            border-radius: 4px;
            background: #334155;
            color: #cbd5e1;
        }

        .qd-tag.ajax {
            background: #4c1d95;
            color: #ddd6fe;
        }

        /* ---- insight ---- */

        .qd-insight {
            padding: 7px 10px;
            background: #101a2e;
            border-top: 1px solid #1e293b;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .qd-chip {
            font-size: 10.5px;
            padding: 2px 7px;
            border-radius: 10px;
            background: #1e293b;
            color: #cbd5e1;
        }

        .qd-chip.warn {
            background: #78350f;
            color: #fde68a;
        }

        .qd-chip.danger {
            background: #7f1d1d;
            color: #fecaca;
        }

        .qd-chip.ok {
            background: #14532d;
            color: #bbf7d0;
        }

        .qd-finding {
            width: 100%;
            font-size: 10.5px;
            color: #94a3b8;
            padding-left: 2px;
            word-break: break-all;
        }

        .qd-finding code {
            color: #cbd5e1;
        }

        /* ---- query ---- */

        .qd-q {
            border-top: 1px solid #1e293b;
            padding: 8px 10px;
        }

        .qd-q-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            color: #94a3b8;
            margin-bottom: 5px;
        }

        .qd-ms {
            padding: 1px 6px;
            border-radius: 4px;
            background: #14532d;
            color: #bbf7d0;
        }

        .qd-ms.slow {
            background: #7f1d1d;
            color: #fecaca;
        }

        .qd-dup {
            padding: 1px 6px;
            border-radius: 4px;
            background: #78350f;
            color: #fde68a;
        }

        /* file:line pemanggil query (Fitur 2) */
        .qd-src {
            padding: 1px 6px;
            border-radius: 4px;
            background: #1e293b;
            color: #cbd5e1;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .qd-fail-badge {
            padding: 1px 6px;
            border-radius: 4px;
            background: #7f1d1d;
            color: #fecaca;
            font-weight: 600;
        }

        .qd-q.failed {
            background: rgba(127, 29, 29, .12);
        }

        .qd-q-error {
            font-size: 10.5px;
            color: #fca5a5;
            background: #1a0f12;
            border: 1px solid #7f1d1d;
            border-radius: 6px;
            padding: 5px 7px;
            margin-bottom: 5px;
            word-break: break-word;
        }

        .qd-batch-error {
            padding: 7px 10px;
            background: #1a0f12;
            border-top: 1px solid #7f1d1d;
            border-bottom: 1px solid #7f1d1d;
            font-size: 11px;
            color: #fecaca;
        }

        .qd-batch-error b {
            color: #fca5a5;
        }

        .qd-conn {
            color: #64748b;
        }

        .qd-sql {
            position: relative;
        }

        .qd-sql pre {
            margin: 0;
            background: #020617;
            border: 1px solid #1e293b;
            border-radius: 6px;
            padding: 8px 8px;
            font-size: 11.5px;
            line-height: 1.5;
            color: #e2e8f0;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 220px;
            overflow: auto;
        }

        .qd-actions {
            position: absolute;
            top: 6px;
            right: 6px;
            display: flex;
            gap: 4px;
        }

        .qd-actions button {
            background: #334155;
            color: #cbd5e1;
            border: none;
            border-radius: 5px;
            padding: 3px 8px;
            font-size: 11px;
            cursor: pointer;
        }

        .qd-actions button:hover {
            background: #475569;
        }

        .qd-copy.ok {
            background: #16a34a;
            color: #fff;
        }

        .qd-explain-out {
            margin-top: 6px;
            border: 1px solid #1e293b;
            border-radius: 6px;
            overflow: hidden;
        }

        .qd-explain-out .qd-explain-head {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            background: #172033;
            font-size: 10.5px;
            color: #94a3b8;
        }

        .qd-explain-out .qd-explain-head b {
            flex: 1;
            color: #7dd3fc;
            font-weight: 600;
        }

        .qd-explain-out pre {
            margin: 0;
            background: #020617;
            border: none;
            border-radius: 0;
            padding: 8px;
            font-size: 11px;
            line-height: 1.45;
            color: #d1fae5;
            white-space: pre;
            overflow: auto;
            max-height: 260px;
        }

        .qd-explain-out.err pre {
            color: #fecaca;
            white-space: pre-wrap;
        }

        /* ---- Sampel Data (Fitur 5) ---- */

        .qd-sample-out {
            margin-top: 6px;
            border: 1px solid #1e293b;
            border-radius: 6px;
            overflow: hidden;
        }

        .qd-sample-out .qd-explain-head b {
            color: #fbbf24;
        }

        .qd-sample-out.err pre {
            margin: 0;
            background: #020617;
            border: none;
            padding: 8px;
            font-size: 11px;
            color: #fecaca;
            white-space: pre-wrap;
        }

        .qd-sample-scroll {
            background: #020617;
            max-height: 260px;
            overflow: auto;
        }

        .qd-sample-table {
            border-collapse: collapse;
            width: 100%;
            font-size: 11px;
            color: #e2e8f0;
        }

        .qd-sample-table th,
        .qd-sample-table td {
            border: 1px solid #1e293b;
            padding: 3px 8px;
            text-align: left;
            white-space: nowrap;
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: top;
        }

        .qd-sample-table th {
            position: sticky;
            top: 0;
            background: #172033;
            color: #93c5fd;
            font-weight: 600;
        }

        .qd-sample-table td i {
            color: #64748b;
        }

        .qd-sample-empty {
            text-align: center;
            color: #64748b;
        }

        .qd-empty {
            text-align: center;
            color: #64748b;
            font-size: 12px;
            padding: 30px 10px;
        }

        /* ---- tombol mini export di header ---- */

        .qd-mini {
            background: #334155;
            color: #cbd5e1;
            border: none;
            border-radius: 5px;
            padding: 1px 7px;
            font-size: 10.5px;
            cursor: pointer;
        }

        .qd-mini:hover {
            background: #475569;
        }

        /* ---- modal export tiket ---- */

        .qd-modal {
            position: absolute;
            inset: 0;
            background: rgba(2, 6, 23, .85);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            z-index: 5;
        }

        .qd-modal[hidden] {
            display: none;
        }

        .qd-modal-card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .qd-modal-head {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            font-size: 12px;
        }

        .qd-modal-head b {
            flex: 1;
        }

        .qd-modal-head button {
            background: #334155;
            color: #cbd5e1;
            border: none;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        .qd-modal-head button:hover {
            background: #475569;
        }

        .qd-modal-head button.ok {
            background: #16a34a;
            color: #fff;
        }

        .qd-modal textarea {
            flex: 1;
            border: none;
            resize: none;
            background: #020617;
            color: #e2e8f0;
            padding: 10px;
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 11.5px;
            line-height: 1.5;
            outline: none;
        }

        /* ---- layar review "Ambil Kasus" ---- */
        .qd-cap-card {
            width: 460px;
            max-width: 94vw;
            max-height: 88vh;
            display: flex;
            flex-direction: column;
        }

        .qd-cap-scroll {
            padding: 10px 12px;
            overflow-y: auto;
            flex: 1;
        }

        .qd-cap-hint {
            font-size: 11px;
            color: #94a3b8;
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .qd-cap-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            margin: 10px 0 3px;
            color: #e2e8f0;
        }

        .qd-cap-opt {
            font-weight: 400;
            color: #64748b;
        }

        .qd-cap-input {
            width: 100%;
            padding: 6px 8px;
            box-sizing: border-box;
            font-size: 12px;
            background: #1e293b;
            color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 4px;
            font-family: inherit;
        }

        textarea.qd-cap-input {
            resize: vertical;
        }

        .qd-cap-steps {
            margin-top: 4px;
        }

        .qd-cap-group {
            border: 1px solid #334155;
            border-radius: 5px;
            margin-bottom: 8px;
            overflow: hidden;
        }

        .qd-cap-group.failed {
            border-color: #7f1d1d;
        }

        .qd-cap-ghead {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 8px;
            background: #172033;
        }

        .qd-cap-glabel {
            flex: 1;
            padding: 3px 6px;
            font-size: 11.5px;
            font-weight: 600;
            background: #0f172a;
            color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 3px;
        }

        .qd-cap-fail {
            font-size: 10.5px;
            color: #fca5a5;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 3px;
            cursor: pointer;
        }

        .qd-cap-step {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-top: 1px solid #1e293b;
        }

        .qd-cap-step.off {
            opacity: .4;
        }

        .qd-cap-step.fp {
            background: rgba(127, 29, 29, .18);
        }

        .qd-cap-inc {
            display: flex;
        }

        .qd-cap-m {
            font-family: ui-monospace, monospace;
            font-size: 10px;
            font-weight: 600;
            padding: 1px 4px;
            border-radius: 3px;
            background: #334155;
            color: #cbd5e1;
        }

        .qd-cap-m.POST,
        .qd-cap-m.PUT,
        .qd-cap-m.DELETE {
            background: #7f1d1d;
            color: #fecaca;
        }

        .qd-cap-p {
            flex: 1;
            font-family: ui-monospace, monospace;
            font-size: 11px;
            color: #cbd5e1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .qd-cap-b {
            font-size: 9px;
            padding: 1px 4px;
            border-radius: 3px;
        }

        .qd-cap-b.bad {
            background: #7f1d1d;
            color: #fecaca;
        }

        .qd-cap-fp {
            font-size: 10px;
            background: transparent;
            border: 1px solid #334155;
            color: #94a3b8;
            border-radius: 3px;
            padding: 1px 6px;
            cursor: pointer;
            white-space: nowrap;
        }

        .qd-cap-step.fp .qd-cap-fp {
            border-color: #ef4444;
            color: #fecaca;
        }

        .qd-cap-foot {
            padding: 8px 12px;
            border-top: 1px solid #334155;
        }

        .qd-cap-submit {
            width: 100%;
            padding: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            background: #16a34a;
            color: #fff;
            border: 1px solid #16a34a;
            border-radius: 4px;
        }

        .qd-cap-result {
            margin-top: 10px;
        }

        .qd-cap-ok {
            background: #14532d;
            border-radius: 5px;
            padding: 10px;
        }

        .qd-cap-oklbl {
            font-size: 10.5px;
            color: #94a3b8;
            margin-bottom: 3px;
        }

        .qd-cap-code {
            font-size: 15px;
            font-weight: 600;
            letter-spacing: .02em;
            color: #bbf7d0;
        }

        .qd-cap-url {
            font-size: 10px;
            color: #86efac;
            word-break: break-all;
            margin: 4px 0 6px;
        }

        .qd-cap-okbtns {
            display: flex;
            gap: 6px;
        }

        .qd-cap-okbtns button {
            flex: 1;
            padding: 5px;
            font-size: 11px;
            cursor: pointer;
            background: #166534;
            color: #dcfce7;
            border: 1px solid #16a34a;
            border-radius: 4px;
        }
    </style>

    <div id="qd-root">
        <button id="qd-fab" title="Query Viewer">
            &lt;/&gt;
            <span class="qd-badge">0</span>
        </button>

        <div id="qd-panel" hidden>
            <div class="qd-head">
                <b>Query Viewer</b>
                <button data-qd="refresh">Refresh</button>
                <button data-qd="clear">Clear</button>
                <button data-qd="capture" title="Simpan langkah-langkah terakhir jadi trace untuk dev">Ambil Kasus</button>
                <button data-qd="lock" title="Matikan pengumpulan query untuk sesi ini">Lock</button>
                <button data-qd="close">&times;</button>
            </div>
            <div class="qd-search">
                <input type="text" placeholder="Filter query (tabel, kata kunci)..." data-qd="filter">
                <label title="Hanya tampilkan query di atas ambang slow_ms">
                    <input type="checkbox" data-qd="slowonly"> lambat
                </label>
                <label title="Hanya tampilkan query yang dijalankan lebih dari sekali dalam satu request">
                    <input type="checkbox" data-qd="duponly"> duplikat
                </label>
            </div>
            <div class="qd-body">
                <div class="qd-empty">Memuat...</div>
            </div>

            <div class="qd-modal" data-qd="cap-modal" hidden>
                <div class="qd-modal-card qd-cap-card">
                    <div class="qd-modal-head">
                        <b>Ambil kasus ini</b>
                        <button data-qd="cap-close" title="Tutup">&times;</button>
                    </div>
                    <div class="qd-cap-scroll">
                        <div class="qd-cap-hint">
                            Pilih langkah yang perlu dev jalankan untuk reproduce, kelompokkan
                            kalau ada bagian-bagiannya, lalu tandai di mana yang gagal.
                        </div>

                        <label class="qd-cap-label">Kategori</label>
                        <select data-qd="cap-category" class="qd-cap-input"></select>

                        <label class="qd-cap-label">Deskripsi masalah</label>
                        <textarea data-qd="cap-desc" rows="3" class="qd-cap-input"
                            placeholder="Ceritakan apa yang terjadi vs yang seharusnya. Contoh: setelah add PLU di sheet 1, tombol add di sheet 2 tidak berfungsi."></textarea>

                        <label class="qd-cap-label">No. PRPK / Memo <span class="qd-cap-opt">(opsional)</span></label>
                        <input type="text" data-qd="cap-prpk" class="qd-cap-input" maxlength="60"
                            placeholder="mis. prpk-1202 atau memo-455">

                        <label class="qd-cap-label">Lampiran <span class="qd-cap-opt">(gambar / video —
                                opsional)</span></label>
                        <input type="file" data-qd="cap-files" class="qd-cap-input" multiple
                            accept="image/*,video/mp4,video/webm">
                        <div class="qd-cap-hint" style="margin-top:4px">
                            Video besar sebaiknya tempel link-nya di deskripsi, bukan di-upload.
                        </div>

                        <label class="qd-cap-label">Langkah <span class="qd-cap-opt" data-qd="cap-count"></span></label>
                        <div data-qd="cap-steps" class="qd-cap-steps"></div>

                        <div data-qd="cap-result" class="qd-cap-result"></div>
                    </div>
                    <div class="qd-cap-foot">
                        <button data-qd="cap-submit" class="qd-cap-submit">Simpan trace</button>
                    </div>
                </div>
            </div>

            <div class="qd-modal" data-qd="modal" hidden>
                <div class="qd-modal-card">
                    <div class="qd-modal-head">
                        <b>Export tiket (Markdown)</b>
                        <button data-qd="md-copy">Copy</button>
                        <button data-qd="md-download">Download .md</button>
                        <button data-qd="md-close" title="Tutup">&times;</button>
                    </div>
                    <textarea data-qd="md-text" readonly spellcheck="false"></textarea>
                </div>
            </div>
        </div>
    </div>

    <meta name="qd-csrf" content="{{ csrf_token() }}">
    <script>
        window.QUERY_DEBUG = {
            recentUrl: '{{ url('/dev/query-debug/recent') }}',
            clearUrl: '{{ url('/dev/query-debug/clear') }}',
            unlockUrl: '{{ url('/dev/query-debug/unlock') }}',
            lockUrl: '{{ url('/dev/query-debug/lock') }}',
            explainUrl: '{{ url('/dev/query-debug/explain') }}',
            sampleUrl: '{{ url('/dev/query-debug/sample') }}',
            captureUrl: '{{ url(config('querydebug.route_prefix', 'dev/query-debug') . '/trace/capture') }}',
            traceEnabled: {{ config('querydebug.trace.enabled', true) ? 'true' : 'false' }},
            traceCategories: {!! json_encode(config('querydebug.trace.categories', []), JSON_UNESCAPED_UNICODE) !!},
            maxAttachments: {{ (int) config('querydebug.trace.max_attachments', 6) }},
            maxUploadKb: {{ (int) config('querydebug.trace.max_upload_kb', 5120) }},
            host: '{{ config('querydebug.host') }}',
            slowMs:         {{ (int) config('querydebug.slow_ms', 500) }},
            insight:        {{ config('querydebug.insight.enabled') ? 'true' : 'false' }},
            explain:        {{ config('querydebug.insight.explain.enabled') ? 'true' : 'false' }},
            sample:         {{ config('querydebug.sample.enabled') ? 'true' : 'false' }},
            pollMs: {{ (int) config('querydebug.poll.interval_ms', 2500) }}
        };
    </script>
    <script src="{{ asset('vendor/query-viewer/query-debug.js') }}"></script>
@endif