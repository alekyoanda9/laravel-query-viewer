<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gate dasar
    |--------------------------------------------------------------------------
    */

    // Kill-switch total. Kalau false, middleware & panel benar-benar mati.
    'enabled' => env('QUERY_DEBUG_ENABLED', false),

    // Host tempat fitur boleh hidup (mis. server testing). Kalau null, fitur
    // hidup di host mana pun asal 'enabled' true — HANYA lakukan ini di
    // lingkungan yang benar-benar terkontrol.
    'host' => env('QUERY_DEBUG_HOST'),

    // API key untuk unlock (dicek pakai hash_equals di middleware gate).
    'key' => env('QUERY_DEBUG_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Koneksi & route
    |--------------------------------------------------------------------------
    */

    // Koneksi DB default yang didengarkan kalau app TIDAK mendaftarkan
    // QueryViewer::connectionUsing(). null = koneksi default aplikasi.
    // Untuk app multi-koneksi per-session (mis. IAS-PHP), JANGAN pakai ini —
    // daftarkan closure lewat QueryViewer::connectionUsing().
    'connection' => null,

    'route_prefix' => env('QUERY_DEBUG_PREFIX', 'dev/query-debug'),

    // Suntik panel otomatis sebelum </body> di response HTML, supaya tim tidak
    // perlu mengubah layout Blade sama sekali. Set false kalau mau menaruh
    // @include('querydebug::panel') sendiri di layout.
    'auto_inject' => env('QUERY_DEBUG_AUTO_INJECT', true),

    /*
    |--------------------------------------------------------------------------
    | Penyimpanan & tampilan
    |--------------------------------------------------------------------------
    */

    'slow_ms'     => env('QUERY_DEBUG_SLOW_MS', 500),
    'max_batches' => env('QUERY_DEBUG_MAX_BATCHES', 30),
    'ttl_minutes' => env('QUERY_DEBUG_TTL_MINUTES', 30),

    'download_patterns' => [
        'cetak', 'export', 'download', 'unduh', 'pdf', 'excel', 'xls', 'print',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace (perekam langkah support)
    |--------------------------------------------------------------------------
    |
    | Ring buffer query yang sudah ada dipakai ulang sebagai buffer langkah;
    | "trace" adalah hasil promote buffer itu jadi file permanen berkode,
    | dipicu support lewat tombol "Ambil Kasus" di panel.
    |
    */

    'trace' => [

        'enabled' => env('QUERY_DEBUG_TRACE', true),

        // Disimpan sebagai file JSON, BUKAN tabel DB — koneksi DB app ini ikut
        // cabang terpilih, jadi trace di tabel tidak akan bisa dibuka dev yang
        // sedang login di cabang berbeda.
        'disk' => env('QUERY_DEBUG_TRACE_DISK', 'local'),
        'path' => 'querydebug/traces',

        // Batas atas langkah per trace (buffer sendiri dibatasi max_batches).
        'max_steps' => 40,

        // Input request ikut direkam — inilah yang membuat step bisa
        // direproduksi (filter apa, kode toko mana). Matikan kalau lingkungan
        // tidak mengizinkan payload tersimpan sama sekali.
        'capture_input' => true,

        // Key input yang nilainya diganti '[redacted]'. Cocok secara substring
        // dan case-insensitive.
        'redact_keys' => [
            'password', 'passwd', 'pwd', 'token', 'secret', 'api_key', 'apikey',
            'authorization', 'credit', 'cvv', 'pin',
        ],

        'max_input_keys'    => 40,
        'max_value_length'  => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Export tiket
    |--------------------------------------------------------------------------
    */

    'export' => [

        // Cara TANPA KODE mengisi metadata tiket untuk app yang menyimpan
        // infonya di session: 'session_key' => 'Label di tiket'. Untuk logika
        // lebih kompleks, daftarkan QueryViewer::contextUsing() sebagai gantinya
        // (closure menang atas config ini).
        'extra_session' => [
            // 'nama_cabang' => 'Cabang',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis & Insight
    |--------------------------------------------------------------------------
    */

    'insight' => [

        'enabled' => env('QUERY_DEBUG_INSIGHT', false),

        'n_plus_one_threshold' => env('QUERY_DEBUG_NPLUS1_THRESHOLD', 5),

        'max_findings' => env('QUERY_DEBUG_MAX_FINDINGS', 5),

        'explain' => [

            'enabled' => env('QUERY_DEBUG_EXPLAIN', false),

            // EXPLAIN ANALYZE BENAR-BENAR mengeksekusi query. Dijaga berlapis
            // (whitelist SELECT + transaksi selalu rollback + statement_timeout),
            // tapi tetap default mati.
            'analyze' => env('QUERY_DEBUG_EXPLAIN_ANALYZE', false),

            'timeout_ms' => env('QUERY_DEBUG_EXPLAIN_TIMEOUT_MS', 5000),

            'max_sql_length' => env('QUERY_DEBUG_EXPLAIN_MAX_SQL', 20000),
        ],
    ],

];
