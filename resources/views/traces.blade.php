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
    </style>
</head>
<body>
<div class="wrap">
    <h1>Trace terbaru</h1>

    @if (empty($traces))
        <div class="empty">Belum ada trace yang di-capture.</div>
    @else
        <table>
            <tr><th>Kode</th><th>Catatan</th><th>Konteks</th><th>Oleh</th><th>Waktu</th><th>Langkah</th></tr>
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
                </tr>
            @endforeach
        </table>
    @endif
</div>
</body>
</html>
