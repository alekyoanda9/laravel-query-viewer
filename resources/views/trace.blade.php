<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $trace['code'] }} — Trace</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.55;
            color: #1c1c1c;
            background: #f6f6f4;
        }

        .wrap {
            max-width: 980px;
            margin: 0 auto;
        }

        .head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 14px;
        }

        .code {
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 18px;
            font-weight: 600;
        }

        .dim {
            color: #78786f;
            font-size: 12px;
        }

        .srcbadge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11px;
            color: #4b5563;
            background: #eef0ec;
            border: 1px solid #d8dad2;
            border-radius: 3px;
            padding: 0 5px;
            white-space: nowrap;
        }

        a.btn {
            display: inline-block;
            text-decoration: none;
            color: #1c1c1c;
            background: #fff;
            border: 1px solid #c9c9c1;
            border-radius: 4px;
            padding: 6px 12px;
            font-size: 13px;
        }

        .cat {
            display: inline-block;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 3px;
            background: #eceae2;
            color: #55554e;
            margin-bottom: 8px;
        }

        .cat.err,
        .cat.perilaku-salah,
        .cat.aksi-hilang {
            background: #fde8e8;
            color: #a12b2b;
        }

        .desc {
            background: #fff4e5;
            border-left: 3px solid #d98324;
            padding: 10px 12px;
            margin-bottom: 14px;
            white-space: pre-wrap;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            background: #fff;
            border: 1px solid #e2e2dd;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }

        .meta div span {
            display: block;
            font-size: 11px;
            color: #78786f;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .meta div b {
            font-size: 14px;
            font-weight: 600;
        }

        .att {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .att a {
            display: block;
            border: 1px solid #e2e2dd;
            border-radius: 6px;
            overflow: hidden;
            background: #fff;
        }

        .att img,
        .att video {
            display: block;
            max-width: 220px;
            max-height: 160px;
        }

        .group {
            border: 1px solid #e2e2dd;
            border-radius: 6px;
            margin-bottom: 14px;
            overflow: hidden;
            background: #fff;
        }

        .group.failed {
            border-color: #d14343;
            border-width: 2px;
        }

        .ghead {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: #f0efe9;
            font-weight: 600;
        }

        .group.failed .ghead {
            background: #fde8e8;
            color: #8c2020;
        }

        .gtag {
            margin-left: auto;
            font-size: 11px;
            font-weight: 400;
            padding: 2px 8px;
            border-radius: 3px;
            background: #d14343;
            color: #fff;
        }

        .step {
            padding: 10px 14px;
            border-top: 1px solid #eceae2;
        }

        .step.fp {
            background: #fff5f5;
        }

        .step.excluded {
            opacity: .55;
        }

        .step-head {
            display: flex;
            align-items: baseline;
            gap: 8px;
            flex-wrap: wrap;
        }

        .no {
            font-weight: 600;
            color: #78786f;
            min-width: 22px;
        }

        .method {
            font-family: ui-monospace, monospace;
            font-size: 11px;
            font-weight: 600;
            background: #eceae2;
            padding: 2px 6px;
            border-radius: 3px;
        }

        .method.POST,
        .method.PUT,
        .method.DELETE {
            background: #fde8e8;
            color: #a12b2b;
        }

        .path {
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 13px;
            word-break: break-all;
        }

        .badge {
            font-size: 11px;
            padding: 1px 6px;
            border-radius: 3px;
            background: #eceae2;
            color: #55554e;
        }

        .badge.bad {
            background: #fde8e8;
            color: #a12b2b;
        }

        .badge.fp {
            background: #d14343;
            color: #fff;
        }

        .kv {
            margin-top: 8px;
            font-size: 12.5px;
        }

        .kv code {
            background: #f2f1ec;
            padding: 1px 5px;
            border-radius: 3px;
            font-family: ui-monospace, monospace;
            word-break: break-all;
        }

        pre {
            background: #1f1f1c;
            color: #e6e4dc;
            padding: 10px 12px;
            border-radius: 4px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            font-size: 12px;
            line-height: 1.5;
            margin: 6px 0 0;
        }

        pre.failed {
            background: #3a1c1c;
            color: #ffd9d9;
        }

        pre.resp {
            background: #16261c;
            color: #d6efdf;
            max-height: 340px;
        }

        details.respbox>summary {
            color: #4b7a5a;
        }

        details>summary {
            cursor: pointer;
            margin-top: 8px;
            font-size: 12.5px;
            color: #55554e;
            user-select: none;
        }

        .errbox {
            margin-top: 8px;
            background: #fde8e8;
            border-radius: 4px;
            padding: 8px 10px;
            font-size: 12.5px;
            color: #8c2020;
        }

        .excl-note {
            text-align: center;
            font-size: 12px;
            color: #78786f;
            padding: 8px;
        }

        .head-actions {
            display: flex;
            gap: 8px;
        }

        button.btn {
            font: inherit;
            font-size: 13px;
            cursor: pointer;
        }

        .btn.danger {
            color: #a12b2b;
            border-color: #f3c2c2;
            background: #fde8e8;
        }

        .flash {
            padding: 9px 12px;
            border-radius: 6px;
            margin-bottom: 14px;
            font-size: 13px;
        }

        .flash.ok {
            background: #e9f6ec;
            color: #1e6b34;
            border: 1px solid #b9e3c3;
        }

        .flash.err {
            background: #fde8e8;
            color: #a12b2b;
            border: 1px solid #f3c2c2;
        }
    </style>
</head>

<body>
    <div class="wrap">

        @php
            $prefix = config('querydebug.route_prefix', 'dev/query-debug');
            $catClass = $trace['category'] ?? 'lainnya';
            // Grup: index -> daftar step (yang disertakan). Step excluded dikumpulkan terpisah.
            $included = array_filter($trace['steps'], function ($s) {
                return !empty($s['included']); });
            $excluded = array_filter($trace['steps'], function ($s) {
                return empty($s['included']); });
            $byGroup = [];
            foreach ($included as $s) {
                $byGroup[(int) $s['group']][] = $s;
            }
        @endphp

        <div class="head">
            <div>
                <div class="dim">Trace</div>
                <div class="code">{{ $trace['code'] }}</div>
                <div class="dim">
                    Diambil {{ $trace['captured_at'] }} oleh {{ $trace['user'] }}
                    @if (!empty($trace['prpk'])) &middot; {{ $trace['prpk'] }} @endif
                </div>
            </div>
            <div class="head-actions">
                <a class="btn" href="{{ url($prefix . '/trace/' . $trace['code'] . '/json') }}">JSON</a>
                <form method="POST" action="{{ url($prefix . '/trace/' . $trace['code'] . '/delete') }}"
                      onsubmit="return confirm('Hapus trace {{ $trace['code'] }}? Tindakan ini tidak bisa dibatalkan.');">
                    @csrf
                    <input type="hidden" name="back" value="{{ url($prefix . '/trace') }}">
                    <button type="submit" class="btn danger">Hapus</button>
                </form>
            </div>
        </div>

        @if (session('querydebug_status'))
            <div class="flash ok">{{ session('querydebug_status') }}</div>
        @endif
        @if (session('querydebug_error'))
            <div class="flash err">{{ session('querydebug_error') }}</div>
        @endif

        <span class="cat {{ $catClass }}">{{ $trace['category_label'] ?? $catClass }}</span>

        @if (!empty($trace['description']))
            <div class="desc">{{ $trace['description'] }}</div>
        @endif

        <div class="meta">
            <div><span>Koneksi</span><b>{{ $trace['conn'] ?: '-' }}</b></div>
            @foreach (($trace['context'] ?? []) as $row)
                <div><span>{{ $row['label'] }}</span><b>{{ $row['value'] }}</b></div>
            @endforeach
            <div><span>Host</span><b>{{ $trace['app']['host'] ?? '-' }}</b></div>
        </div>

        @if (!empty($trace['attachments']))
            <div class="att">
                @foreach ($trace['attachments'] as $a)
                    @php $furl = url($prefix . '/trace/' . $trace['code'] . '/file/' . ($a['idx'] ?? 0)); @endphp
                    <a href="{{ $furl }}" target="_blank" rel="noopener">
                        @if (($a['type'] ?? '') === 'video')
                            <video src="{{ $furl }}" controls muted></video>
                        @else
                            <img src="{{ $furl }}" alt="{{ $a['name'] ?? 'lampiran' }}">
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

        @foreach ($trace['groups'] as $gi => $group)
            @php $gsteps = $byGroup[$gi] ?? []; @endphp
            @if (!empty($gsteps))
                <div class="group {{ !empty($group['failed']) ? 'failed' : '' }}">
                    <div class="ghead">
                        {{ $group['label'] }}
                        @if (!empty($group['failed']))<span class="gtag">gagal di sini</span>@endif
                    </div>

                    @foreach ($gsteps as $step)
                        @php $hasError = !empty($step['error']) || (isset($step['status']) && $step['status'] >= 400); @endphp
                        <div class="step {{ !empty($step['fail_point']) ? 'fp' : '' }}">
                            <div class="step-head">
                                <span class="no">{{ $step['no'] }}.</span>
                                <span class="method {{ $step['method'] }}">{{ $step['method'] }}</span>
                                <span class="path">/{{ $step['path'] }}</span>
                                @if (!empty($step['is_ajax']))<span class="badge">ajax</span>@endif
                                @if (!empty($step['status']))
                                    <span class="badge {{ $step['status'] >= 400 ? 'bad' : '' }}">{{ $step['status'] }}</span>
                                @endif
                                @if (!empty($step['fail_point']))<span class="badge fp">titik gagal</span>@endif
                                <span class="dim" style="margin-left:auto">
                                    {{ $step['at'] }}@if (isset($step['dur_ms']) && $step['dur_ms'] !== null) &middot;
                                    {{ $step['dur_ms'] }} ms @endif
                                </span>
                            </div>

                            @if (!empty($step['input']))
                                <div class="kv">
                                    @foreach ($step['input'] as $k => $v)
                                        <code>{{ $k }} = {{ is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v }}</code>
                                    @endforeach
                                </div>
                            @endif

                            @if (!empty($step['error']))
                                <div class="errbox">
                                    <b>{{ $step['error']['class'] ?? 'Error' }}</b><br>{{ $step['error']['message'] ?? '' }}</div>
                            @endif

                            @if (!empty($step['queries']))
                                <details @if (!empty($step['fail_point']) || $hasError) open @endif>
                                    <summary>{{ count($step['queries']) }} query — klik untuk lihat SQL siap-copy ke DBeaver</summary>
                                    @foreach ($step['queries'] as $q)
                                        <pre class="{{ !empty($q['failed']) ? 'failed' : '' }}">{{ $q['raw'] }}</pre>
                                        <div class="dim">
                                            {{ isset($q['ms']) && $q['ms'] !== null ? $q['ms'] . ' ms' : '' }}
                                            @if (!empty($q['file']))
                                                &middot; <span class="srcbadge">{{ $q['file'] }}{{ isset($q['line']) ? ':' . $q['line'] : '' }}</span>
                                            @endif
                                            @if (!empty($q['failed'])) &middot; GAGAL: {{ $q['error'] }} @endif
                                        </div>
                                    @endforeach
                                </details>
                            @endif

                            @if (!empty($step['response']))
                                @php $resp = $step['response']; @endphp
                                <details class="respbox">
                                    <summary>Response
                                        @if (!empty($resp['content_type'])) &middot; {{ $resp['content_type'] }} @endif
                                        @if (isset($resp['status'])) &middot; {{ $resp['status'] }} @endif
                                    </summary>
                                    @if (($resp['kind'] ?? '') === 'body' && isset($resp['body']) && $resp['body'] !== null)
                                        @if (!empty($resp['truncated']))
                                            <div class="dim">dipotong (asli: {{ isset($resp['size']) ? round($resp['size']/1024, 1) . ' KB' : '?' }})</div>
                                        @endif
                                        <pre class="resp">{{ $resp['body'] }}</pre>
                                    @else
                                        <div class="dim">
                                            {{ ($resp['kind'] ?? '') === 'binary' ? 'download/stream — body tidak ditangkap' : 'tipe tidak ditangkap' }}
                                            @if (!empty($resp['filename'])) &middot; {{ $resp['filename'] }} @endif
                                            @if (isset($resp['size'])) &middot; {{ round($resp['size']/1024, 1) }} KB @endif
                                        </div>
                                    @endif
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endforeach

        @if (!empty($excluded))
            <details>
                <summary class="excl-note">{{ count($excluded) }} langkah dikecualikan support — klik untuk lihat</summary>
                <div class="group">
                    @foreach ($excluded as $step)
                        <div class="step excluded">
                            <div class="step-head">
                                <span class="method {{ $step['method'] }}">{{ $step['method'] }}</span>
                                <span class="path">/{{ $step['path'] }}</span>
                                <span class="dim" style="margin-left:auto">{{ $step['at'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

    </div>
</body>

</html>