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
        'cetak',
        'export',
        'download',
        'unduh',
        'pdf',
        'excel',
        'xls',
        'print',
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
            'password',
            'passwd',
            'pwd',
            'token',
            'secret',
            'api_key',
            'apikey',
            'authorization',
            'credit',
            'cvv',
            'pin',
        ],

        'max_input_keys'    => 40,
        'max_value_length'  => 300,

        /*
        | Kategori bug yang bisa dipilih support saat capture. Dibedakan
        | karena penanganan devnya beda: 'error' biasanya sudah kelihatan dari
        | status/exception; 'perilaku-salah' & 'aksi-hilang' butuh dev baca
        | urutan step + lampiran visual, karena tidak ada exception yang
        | menandai di mana persisnya masalah terjadi.
        */
        'categories' => [
            'error'          => 'Error / muncul pesan gagal',
            'perilaku-salah' => 'Hasil tidak sesuai (nyangkut, tidak sinkron, dsb)',
            'aksi-hilang'    => 'Aksi yang seharusnya terjadi tidak terjadi',
            'lambat'         => 'Lambat / timeout',
            'lainnya'        => 'Lainnya',
        ],

        // Lampiran gambar/video. Video besar sebaiknya di-host di luar (link),
        // bukan upload — server testing bukan tempat penyimpanan media.
        'max_attachments'     => 6,
        'max_upload_kb'       => env('QUERY_DEBUG_TRACE_MAX_UPLOAD_KB', 5120), // 5 MB/file
        'allowed_upload_mime' => [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'video/mp4',
            'video/webm',
        ],

        // Dipakai default oleh Artisan command querydebug:prune-traces kalau
        // dijalankan tanpa --days=. Jadwalkan sendiri di App\Console\Kernel
        // (mis. mingguan), karena package ini tidak mendaftarkan schedule apa
        // pun secara otomatis.
        'retention_days' => env('QUERY_DEBUG_TRACE_RETENTION_DAYS', 90),
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
