<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $trace['code'] }} — Trace</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px;
            font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 14px; line-height: 1.55;
            color: #1c1c1c; background: #f6f6f4;
        }
        .wrap { max-width: 960px; margin: 0 auto; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
        .code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 18px; font-weight: 600; }
        .note { background: #fff4e5; border-left: 3px solid #d98324; padding: 10px 12px; margin-bottom: 16px; }
        .meta { display: flex; flex-wrap: wrap; gap: 20px; background: #fff; border: 1px solid #e2e2dd;
                border-radius: 6px; padding: 12px 16px; margin-bottom: 24px; }
        .meta div span { display: block; font-size: 11px; color: #78786f; text-transform: uppercase; letter-spacing: .04em; }
        .meta div b { font-size: 14px; font-weight: 600; }
        .step { background: #fff; border: 1px solid #e2e2dd; border-radius: 6px; padding: 12px 16px; margin-bottom: 10px; }
        .step.suspect { border-color: #d14343; border-width: 2px; }
        .step.err { border-color: #d14343; }
        .step-head { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
        .no { font-weight: 600; color: #78786f; min-width: 24px; }
        .method { font-family: ui-monospace, monospace; font-size: 11px; font-weight: 600;
                  background: #eceae2; padding: 2px 6px; border-radius: 3px; }
        .method.POST, .method.PUT, .method.DELETE { background: #fde8e8; color: #a12b2b; }
        .path { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; word-break: break-all; }
        .dim { color: #78786f; font-size: 12px; }
        .badge { font-size: 11px; padding: 1px 6px; border-radius: 3px; background: #eceae2; color: #55554e; }
        .badge.bad { background: #fde8e8; color: #a12b2b; }
        .kv { margin-top: 8px; font-size: 12.5px; }
        .kv code { background: #f2f1ec; padding: 1px 5px; border-radius: 3px;
                   font-family: ui-monospace, monospace; word-break: break-all; }
        pre { background: #1f1f1c; color: #e6e4dc; padding: 10px 12px; border-radius: 4px;
              overflow-x: auto; font-size: 12px; line-height: 1.5; margin: 6px 0 0; }
        pre.failed { background: #3a1c1c; color: #ffd9d9; }
        details > summary { cursor: pointer; margin-top: 8px; font-size: 12.5px; color: #55554e; user-select: none; }
        .errbox { margin-top: 8px; background: #fde8e8; border-radius: 4px; padding: 8px 10px;
                  font-size: 12.5px; color: #8c2020; }
        a.btn { display: inline-block; text-decoration: none; color: #1c1c1c; background: #fff;
                border: 1px solid #c9c9c1; border-radius: 4px; padding: 6px 12px; font-size: 13px; }
    </style>
</head>
<body>
<div class="wrap">

    <div class="head">
        <div>
            <div class="dim">Trace</div>
            <div class="code">{{ $trace['code'] }}</div>
            <div class="dim">
                Diambil {{ $trace['captured_at'] }} oleh {{ $trace['user'] }} &middot;
                {{ count($trace['steps']) }} langkah
            </div>
        </div>
        <a class="btn" href="{{ url(config('querydebug.route_prefix', 'dev/query-debug') . '/trace/' . $trace['code'] . '/json') }}">JSON</a>
    </div>

    @if (! empty($trace['note']))
        <div class="note"><b>Catatan support:</b> {{ $trace['note'] }}</div>
    @endif

    {{--
        Blok metadata ini sengaja ditaruh paling atas dan mencolok.
        Untuk IAS-PHP isinya cabang/IGR — penyebab nomor satu "kok di saya
        nggak bisa reproduce": dev menguji di cabang yang berbeda.
    --}}
    <div class="meta">
        <div><span>Koneksi</span><b>{{ $trace['conn'] ?: '-' }}</b></div>
        @foreach ($trace['context'] as $row)
            <div><span>{{ $row['label'] }}</span><b>{{ $row['value'] }}</b></div>
        @endforeach
        <div><span>Host</span><b>{{ isset($trace['app']['host']) ? $trace['app']['host'] : '-' }}</b></div>
    </div>

    @foreach ($trace['steps'] as $step)
        @php
            $isSuspect = $step['no'] === $trace['suspect'];
            $hasError  = ! empty($step['error']) || (isset($step['status']) && $step['status'] >= 400);
        @endphp

        <div class="step {{ $isSuspect ? 'suspect' : '' }} {{ $hasError ? 'err' : '' }}">
            <div class="step-head">
                <span class="no">{{ $step['no'] }}.</span>
                <span class="method {{ $step['method'] }}">{{ $step['method'] }}</span>
                <span class="path">/{{ $step['path'] }}</span>
                @if ($step['is_ajax'])<span class="badge">ajax</span>@endif
                @if ($step['status'])
                    <span class="badge {{ $step['status'] >= 400 ? 'bad' : '' }}">{{ $step['status'] }}</span>
                @endif
                @if ($isSuspect)<span class="badge bad">disorot support</span>@endif
                <span class="dim" style="margin-left:auto">
                    {{ $step['at'] }}@if ($step['dur_ms'] !== null) &middot; {{ $step['dur_ms'] }} ms @endif
                </span>
            </div>

            @if ($step['route'])
                <div class="dim">route: {{ $step['route'] }}</div>
            @endif

            @if (! empty($step['input']))
                <div class="kv">
                    @foreach ($step['input'] as $k => $v)
                        <code>{{ $k }} = {{ is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v }}</code>
                    @endforeach
                </div>
            @endif

            @if (! empty($step['error']))
                <div class="errbox">
                    <b>{{ $step['error']['class'] }}</b><br>{{ $step['error']['message'] }}
                </div>
            @endif

            @if (! empty($step['queries']))
                <details @if ($isSuspect) open @endif>
                    <summary>{{ count($step['queries']) }} query — klik untuk lihat SQL siap-copy ke DBeaver</summary>
                    @foreach ($step['queries'] as $q)
                        <pre class="{{ $q['failed'] ? 'failed' : '' }}">{{ $q['raw'] }}</pre>
                        <div class="dim">
                            {{ $q['ms'] !== null ? $q['ms'] . ' ms' : '' }}
                            @if ($q['failed']) &middot; GAGAL: {{ $q['error'] }} @endif
                        </div>
                    @endforeach
                </details>
            @endif
        </div>
    @endforeach

</div>
</body>
</html>
