<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="qd-csrf" content="{{ csrf_token() }}">
    <title>Query Viewer — Dashboard</title>
    <style>
        :root {
            --bg: #0b1120;
            --panel: #0f172a;
            --panel2: #111c30;
            --line: #1e293b;
            --line2: #24324a;
            --text: #e2e8f0;
            --dim: #94a3b8;
            --dim2: #64748b;
            --accent: #38bdf8;
            --ok: #4ade80;
            --warn: #fbbf24;
            --err: #f87171;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 13px;
        }

        code, pre, .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        .qv-app {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        /* ---- header ---- */
        .qv-top {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            background: var(--panel);
            border-bottom: 1px solid var(--line);
            flex: 0 0 auto;
        }
        .qv-top h1 {
            font-size: 15px;
            margin: 0;
            font-weight: 600;
            letter-spacing: .2px;
        }
        .qv-top h1 span { color: var(--accent); }
        .qv-spacer { flex: 1; }

        .qv-search {
            background: var(--panel2);
            border: 1px solid var(--line2);
            border-radius: 6px;
            color: var(--text);
            padding: 6px 10px;
            width: 240px;
            font-size: 12px;
        }
        .qv-search::placeholder { color: var(--dim2); }

        .qv-filters { display: flex; gap: 4px; }
        .qv-filters button,
        .qv-btn {
            background: var(--panel2);
            border: 1px solid var(--line2);
            border-radius: 6px;
            color: var(--dim);
            padding: 6px 12px;
            font-size: 12px;
            cursor: pointer;
        }
        .qv-filters button.on { background: #0c4a6e; color: #bae6fd; border-color: #0369a1; }
        .qv-btn:hover, .qv-filters button:hover { color: var(--text); }

        .qv-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--dim2); display: inline-block; margin-right: 6px;
        }
        .qv-dot.live { background: var(--ok); box-shadow: 0 0 6px var(--ok); }

        /* ---- body split ---- */
        .qv-main {
            flex: 1;
            display: flex;
            min-height: 0;
        }

        .qv-side {
            width: 300px;
            flex: 0 0 300px;
            background: var(--panel);
            border-right: 1px solid var(--line);
            overflow-y: auto;
        }
        .qv-side-head {
            padding: 10px 14px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--dim2);
            border-bottom: 1px solid var(--line);
            position: sticky; top: 0; background: var(--panel);
        }
        .qv-page {
            padding: 10px 14px;
            border-bottom: 1px solid var(--line);
            cursor: pointer;
        }
        .qv-page:hover { background: var(--panel2); }
        .qv-page.sel { background: #0c1a2e; border-left: 3px solid var(--accent); padding-left: 11px; }
        .qv-page.has-error { border-left: 3px solid var(--err); padding-left: 11px; }
        .qv-page-path {
            font-size: 12px; color: var(--text);
            word-break: break-all; margin-bottom: 4px;
        }
        .qv-page-meta { font-size: 11px; color: var(--dim2); display: flex; gap: 8px; }

        .qv-content { flex: 1; overflow-y: auto; padding: 0 0 60px; }
        .qv-content-head {
            padding: 12px 18px; border-bottom: 1px solid var(--line);
            position: sticky; top: 0; background: var(--bg); z-index: 2;
        }
        .qv-content-head .p { font-size: 14px; color: var(--text); word-break: break-all; }
        .qv-content-head .m { font-size: 11px; color: var(--dim2); margin-top: 4px; }

        /* ---- request cards ---- */
        .qv-req {
            margin: 12px 18px;
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
            background: var(--panel);
        }
        .qv-req.has-error { border-color: #7f1d1d; }
        .qv-req-head {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 12px; cursor: pointer;
            background: var(--panel2);
        }
        .qv-req-head:hover { background: #14233b; }
        .qv-caret { color: var(--dim2); width: 12px; }
        .qv-method {
            font-size: 11px; font-weight: 600; padding: 1px 7px;
            border-radius: 4px; background: #1e293b; color: #cbd5e1;
        }
        .qv-method.ajax { background: #0c4a6e; color: #bae6fd; }
        .qv-req-path { flex: 1; font-size: 12.5px; color: var(--text); word-break: break-all; }
        .qv-badge { font-size: 10.5px; padding: 1px 7px; border-radius: 4px; white-space: nowrap; }
        .qv-badge.q { background: #1e293b; color: var(--dim); }
        .qv-badge.slow { background: #7f1d1d; color: #fecaca; }
        .qv-badge.st2 { background: #14532d; color: #bbf7d0; }
        .qv-badge.st4, .qv-badge.st5 { background: #7f1d1d; color: #fecaca; }
        .qv-badge.err { background: #7f1d1d; color: #fecaca; }
        .qv-badge.n1 { background: #78350f; color: #fde68a; }

        .qv-tabs { display: flex; gap: 2px; padding: 8px 12px 0; border-bottom: 1px solid var(--line); }
        .qv-tab {
            padding: 6px 12px; font-size: 12px; cursor: pointer;
            color: var(--dim); border: 1px solid transparent; border-bottom: none;
            border-radius: 6px 6px 0 0;
        }
        .qv-tab:hover { color: var(--text); }
        .qv-tab.on { color: var(--accent); background: var(--bg); border-color: var(--line); }
        .qv-tab .c { color: var(--dim2); font-size: 10px; }

        .qv-tabbody { padding: 12px; }

        /* ---- query rows ---- */
        .qv-q { margin-bottom: 12px; border: 1px solid var(--line); border-radius: 6px; overflow: hidden; }
        .qv-q.failed { border-color: #7f1d1d; }
        .qv-q-meta {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            font-size: 11px; color: var(--dim); padding: 6px 10px; background: var(--panel2);
        }
        .qv-ms { padding: 1px 6px; border-radius: 4px; background: #14532d; color: #bbf7d0; }
        .qv-ms.slow { background: #7f1d1d; color: #fecaca; }
        .qv-op { color: var(--dim); }
        .qv-dup { padding: 1px 6px; border-radius: 4px; background: #78350f; color: #fde68a; }
        .qv-src {
            padding: 1px 6px; border-radius: 4px; background: #1e293b; color: #cbd5e1;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .qv-q-actions { margin-left: auto; display: flex; gap: 4px; }
        .qv-mini {
            background: #1e293b; color: #cbd5e1; border: none; border-radius: 4px;
            padding: 2px 8px; font-size: 11px; cursor: pointer;
        }
        .qv-mini:hover { background: #334155; }
        .qv-mini.ok { background: #14532d; color: #bbf7d0; }

        pre.qv-sql {
            margin: 0; padding: 10px; background: #020617; color: #e2e8f0;
            font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word;
            overflow-x: auto;
        }
        pre.qv-sql .k { color: #7dd3fc; font-weight: 600; }
        pre.qv-sql .s { color: #86efac; }
        pre.qv-sql .n { color: #fca5a5; }
        pre.qv-sql .c { color: var(--dim2); font-style: italic; }

        .qv-q-error { padding: 6px 10px; background: #450a0a; color: #fecaca; font-size: 12px; }

        .qv-out { border-top: 1px solid var(--line); }
        .qv-out-head {
            display: flex; align-items: center; gap: 6px; padding: 5px 10px;
            background: #172033; font-size: 10.5px; color: var(--dim);
        }
        .qv-out-head b { flex: 1; color: var(--accent); }
        .qv-out.sample .qv-out-head b { color: var(--warn); }
        .qv-out pre {
            margin: 0; padding: 8px 10px; background: #020617; color: #d1fae5;
            font-size: 11.5px; white-space: pre; overflow: auto; max-height: 280px;
        }
        .qv-out.err pre { color: #fecaca; white-space: pre-wrap; }

        /* sample table */
        .qv-stable { border-collapse: collapse; width: 100%; font-size: 11.5px; }
        .qv-stable th, .qv-stable td {
            border: 1px solid var(--line); padding: 3px 8px; text-align: left;
            white-space: nowrap; max-width: 280px; overflow: hidden; text-overflow: ellipsis; vertical-align: top;
        }
        .qv-stable th { position: sticky; top: 0; background: #172033; color: #93c5fd; }
        .qv-stable td i { color: var(--dim2); }
        .qv-sscroll { max-height: 280px; overflow: auto; background: #020617; }

        /* payload / context / response */
        pre.qv-json {
            margin: 0; padding: 10px; background: #020617; color: #cbd5e1;
            font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word;
            max-height: 420px; overflow: auto;
        }
        .qv-kv { width: 100%; border-collapse: collapse; font-size: 12px; }
        .qv-kv td { padding: 5px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .qv-kv td.k { color: var(--dim); width: 200px; }
        .qv-resp-meta { font-size: 12px; color: var(--dim); padding: 4px 0 10px; }
        .qv-resp-meta b { color: var(--text); }

        .qv-empty { text-align: center; color: var(--dim2); padding: 30px 12px; font-size: 12px; }
        .qv-note { padding: 40px; text-align: center; color: var(--dim2); }
        .qv-trunc { font-size: 11px; color: var(--warn); padding: 4px 10px; background: #1f1600; }

        .qv-insight {
            margin: 0 0 10px; padding: 8px 10px; border: 1px solid #78350f;
            border-radius: 6px; background: #1c1204; font-size: 11.5px; color: #fde68a;
        }
        .qv-insight b { color: #fcd34d; }
    </style>
</head>
<body>
    <div class="qv-app">
        <div class="qv-top">
            <h1>Query <span>Viewer</span></h1>
            <span title="polling"><span class="qv-dot" id="qv-live"></span></span>
            <div class="qv-spacer"></div>
            <div class="qv-filters" id="qv-filters">
                <button data-f="all" class="on">All</button>
                <button data-f="slow">Slow &gt;{{ $slow_ms }}ms</button>
                <button data-f="n1">N+1</button>
            </div>
            <input class="qv-search" id="qv-search" placeholder="Cari URL / route…" spellcheck="false">
            <button class="qv-btn" id="qv-clear">Clear</button>
        </div>
        <div class="qv-main">
            <div class="qv-side">
                <div class="qv-side-head">Recorded Pages</div>
                <div id="qv-pages"></div>
            </div>
            <div class="qv-content" id="qv-content">
                <div class="qv-note">Memuat…</div>
            </div>
        </div>
    </div>

    <script>
        window.QD_VIEWER = {
            recentUrl:   '{{ url($prefix . '/viewer/recent') }}',
            batchUrl:    '{{ url($prefix . '/viewer/batch') }}',
            explainUrl:  '{{ url($prefix . '/viewer/explain') }}',
            sampleUrl:   '{{ url($prefix . '/viewer/sample') }}',
            clearUrl:    '{{ url($prefix . '/viewer/clear') }}',
            slowMs:      {{ $slow_ms }},
            pollMs:      {{ $poll_ms }},
            insight:     {{ $insight_enabled ? 'true' : 'false' }},
            explain:     {{ $explain_enabled ? 'true' : 'false' }},
            sample:      {{ $sample_enabled ? 'true' : 'false' }},
            response:    {{ $response_enabled ? 'true' : 'false' }}
        };
    </script>
    <script src="{{ asset('vendor/query-viewer/query-debug-viewer.js') }}"></script>
</body>
</html>
