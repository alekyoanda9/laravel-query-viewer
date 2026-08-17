<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trace terbaru</title>
    <style>
        body { margin:0; padding:24px; font-family:-apple-system,"Segoe UI",Roboto,sans-serif;
               font-size:14px; color:#1c1c1c; background:#f6f6f4; }
        .wrap { max-width:960px; margin:0 auto; }
        h1 { font-size:18px; margin:0 0 16px; }
        table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e2e2dd; border-radius:6px; }
        th, td { text-align:left; padding:9px 12px; border-bottom:1px solid #eceae2; font-size:13px; vertical-align:top; }
        th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#78786f; }
        tr:last-child td { border-bottom:none; }
        a { color:#1a5fb4; text-decoration:none; font-family:ui-monospace,Menlo,Consolas,monospace; }
        a:hover { text-decoration:underline; }
        .dim { color:#78786f; }
        .empty { padding:24px; text-align:center; color:#78786f; background:#fff;
                 border:1px solid #e2e2dd; border-radius:6px; }
        .flash { padding:9px 12px; border-radius:6px; margin-bottom:14px; font-size:13px; }
        .flash.ok { background:#e9f6ec; color:#1e6b34; border:1px solid #b9e3c3; }
        .flash.err { background:#fde8e8; color:#a12b2b; border:1px solid #f3c2c2; }
        .del-btn { background:none; border:none; padding:0; font:inherit; font-size:12px;
                   color:#a12b2b; cursor:pointer; text-decoration:underline; }
        .prune-bar { margin-top:16px; display:flex; align-items:center; gap:8px;
                     font-size:13px; color:#55554e; }
        .prune-bar input[type="number"] { width:64px; padding:4px 6px; border:1px solid #c9c9c1;
                     border-radius:4px; font-size:13px; }
        .prune-bar button { padding:5px 12px; font-size:13px; border:1px solid #c9c9c1;
                     border-radius:4px; background:#fff; cursor:pointer; }
        .prune-bar button:hover { background:#f0efe9; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Trace terbaru</h1>

    @if (session('querydebug_status'))
        <div class="flash ok">{{ session('querydebug_status') }}</div>
    @endif
    @if (session('querydebug_error'))
        <div class="flash err">{{ session('querydebug_error') }}</div>
    @endif

    @if (empty($traces))
        <div class="empty">Belum ada trace yang di-capture.</div>
    @else
        <table>
            <tr><th>Kode</th><th>Catatan</th><th>Konteks</th><th>Oleh</th><th>Waktu</th><th>Langkah</th><th></th></tr>
            @foreach ($traces as $t)
                <tr>
                    <td><a href="{{ url(config('querydebug.route_prefix', 'dev/query-debug') . '/trace/' . $t['code']) }}">{{ $t['code'] }}</a></td>
                    <td>{{ $t['note'] ?: '—' }}</td>
                    <td class="dim">
                        @foreach ($t['context'] as $row){{ $row['label'] }}: {{ $row['value'] }}@if (! $loop->last) &middot; @endif @endforeach
                    </td>
                    <td class="dim">{{ $t['user'] }}</td>
                    <td class="dim">{{ $t['captured_at'] }}</td>
                    <td class="dim">{{ $t['steps'] }}</td>
                    <td>
                        <form method="POST" action="{{ url(config('querydebug.route_prefix', 'dev/query-debug') . '/trace/' . $t['code'] . '/delete') }}"
                              onsubmit="return confirm('Hapus trace {{ $t['code'] }}? Tindakan ini tidak bisa dibatalkan.');">
                            @csrf
                            <button type="submit" class="del-btn">Hapus</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <form class="prune-bar" method="POST"
          action="{{ url(config('querydebug.route_prefix', 'dev/query-debug') . '/trace/prune') }}"
          onsubmit="return confirm('Hapus semua trace lebih lama dari input di bawah? Tindakan ini tidak bisa dibatalkan.');">
        @csrf
        <span>Bersihkan trace lebih lama dari</span>
        <input type="number" name="days" min="1" value="90" required>
        <span>hari</span>
        <button type="submit">Bersihkan</button>
    </form>
</div>
</body>
</html>