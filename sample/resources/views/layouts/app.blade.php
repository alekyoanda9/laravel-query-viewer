<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Sample - laravel-query-viewer</title>
    <style>
        body { font-family: sans-serif; max-width: 760px; margin: 40px auto; color: #222; }
        table { border-collapse: collapse; width: 100%; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 14px; }
        th { background: #f5f5f5; }
        .box { background: #f8f8f8; border: 1px solid #eee; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
        nav a { margin-right: 12px; }
        code { background: #eee; padding: 1px 5px; border-radius: 3px; }
    </style>
</head>
<body>
    <h2>Sample App &mdash; laravel-query-viewer</h2>
    <p>Panel query viewer (FAB <code>&lt;/&gt;</code>) otomatis muncul di kanan-bawah kalau
    <code>QUERY_DEBUG_ENABLED=true</code>. Unlock pakai key dari <code>.env</code>
    (<code>QUERY_DEBUG_KEY</code>).</p>

    @yield('content')

    {{-- Panel disuntik otomatis lewat middleware InjectQueryViewer (auto_inject=true).
         Tidak perlu @include manual di sini. --}}
</body>
</html>
