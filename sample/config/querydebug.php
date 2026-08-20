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

            // Catatan: varian EXPLAIN ANALYZE (yang benar-benar MENGEKSEKUSI
            // query) sudah DIHAPUS dari package — dianggap terlalu berisiko
            // tidak sengaja dijalankan pada query berat/mengubah data di server
            // testing. Hanya EXPLAIN biasa (read-only, selalu di transaksi
            // rollback + statement_timeout) yang tersisa.

            'timeout_ms' => env('QUERY_DEBUG_EXPLAIN_TIMEOUT_MS', 5000),

            'max_sql_length' => env('QUERY_DEBUG_EXPLAIN_MAX_SQL', 20000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delta polling (/recent)
    |--------------------------------------------------------------------------
    |
    | Sejak v2.3.0 endpoint /recent mengirim DELTA: tiap batch punya nomor urut
    | monotonik (seq), client menyimpan seq tertinggi yang sudah diterima dan
    | hanya minta yang lebih baru (?after=<seq>&gen=<generation>). Server hanya
    | mengembalikan batch dengan seq > after, plus sinyal head/min_seq/generation
    | untuk merge/evict/reset di client. Efeknya: tiap batch dikirim SEKALI, bukan
    | tiap poll — payload polling turun drastis di sesi QA panjang.
    |
    */

    'poll' => [

        // Interval polling panel (ms). Client tetap boleh mempercepat sesaat
        // setelah ajaxComplete.
        'interval_ms' => env('QUERY_DEBUG_POLL_MS', 2500),

        // Batasi jumlah batch yang dikirim dalam satu response saat client
        // minta full snapshot (after=0 / generation berubah), supaya burst
        // pertama tidak membengkak.
        'max_batches_per_response' => env('QUERY_DEBUG_POLL_MAX_BATCHES', 100),

        // (Disiapkan untuk Fitur 1b) pisah ringkasan vs detail berat + endpoint
        // lazy batch/{id}/*. Belum diaktifkan di versi ini.
        'lazy_detail' => env('QUERY_DEBUG_POLL_LAZY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sumber pemanggil query (file:line)
    |--------------------------------------------------------------------------
    |
    | Tiap query menyimpan file & baris kode yang memicunya (à la Telescope
    | FetchesStackTrace) — diambil dari frame pertama di luar vendor/framework
    | dan direktori package ini. Path disimpan RELATIF terhadap base_path()
    | untuk privasi (tidak membocorkan struktur absolut server) & portabilitas.
    |
    */

    'source' => [

        'enabled' => env('QUERY_DEBUG_SOURCE', true),

        // Batas kedalaman debug_backtrace supaya tidak menelusuri stack penuh
        // di halaman dengan banyak query.
        'depth' => env('QUERY_DEBUG_SOURCE_DEPTH', 30),

        // Substring path tambahan yang dianggap "internal" (dilewati saat
        // mencari frame pemanggil). /vendor/ dan direktori package sudah
        // otomatis dilewati.
        'ignore' => [
            // 'app/Http/Kernel.php',
        ],

        // >1 = simpan N frame teratas (mini call-stack) untuk membedah N+1.
        // Default 1 (satu frame) supaya ringan.
        'stack_depth' => env('QUERY_DEBUG_SOURCE_STACK_DEPTH', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampling hasil query (Data Contoh)
    |--------------------------------------------------------------------------
    |
    | Tombol "Sampel Data" per query menjalankan ULANG sebuah SELECT read-only
    | (guard + transaksi rollback + statement_timeout yang SAMA dengan EXPLAIN)
    | lalu menampilkan beberapa baris contoh. Karena membawa potongan data asli
    | (meski dari server testing), kelas risikonya beda dari EXPLAIN/insight:
    | DEFAULT MATI, harus dinyalakan sadar per instalasi lewat .env.
    |
    | Untuk versi awal: semua kolom ditampilkan apa adanya KECUALI yang cocok
    | trace.redact_keys (nama kolom password/token/dll -> [redacted]).
    |
    */

    'sample' => [

        // Kill-switch wajib. Terpisah dari 'enabled' global.
        'enabled' => env('QUERY_DEBUG_SAMPLE', false),

        // Batas baris yang diambil (dibungkus SELECT * FROM (<sql>) AS q LIMIT n).
        'max_rows' => env('QUERY_DEBUG_SAMPLE_MAX_ROWS', 3),

        // null = ikut timeout EXPLAIN (insight.explain.timeout_ms).
        'statement_timeout_ms' => env('QUERY_DEBUG_SAMPLE_TIMEOUT_MS', null),

        // null = ikut trace.max_value_length. Nilai kolom yang sangat panjang
        // tetap dipotong.
        'max_value_length' => null,
    ],

];
