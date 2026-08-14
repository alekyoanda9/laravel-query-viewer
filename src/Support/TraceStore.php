<?php

namespace Sd1\QueryViewer\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Penyimpanan trace PERMANEN (beda dari QueryDebugStore yang cuma ring buffer
 * sementara di cache).
 *
 * Kenapa file JSON, bukan tabel DB:
 *  1. Koneksi DB aplikasi ini ikut cabang terpilih (session('connection')).
 *     Kalau trace disimpan di sana, trace yang di-capture support di cabang 44
 *     TIDAK bisa dibuka dev yang sedang login di cabang lain — persis masalah
 *     yang mau kita hilangkan.
 *  2. Tanpa migration = tidak menyentuh skema DB produksi sama sekali, jadi
 *     package tetap bisa dipasang/dicabut tanpa jejak.
 *  3. Trace itu append-only dan dibaca per-kode; tidak butuh query relasional.
 *
 * Layout: {disk}/{root}/{YYYY-MM}/TRC-YYYYMMDD-XXXX.json
 * Partisi per bulan supaya satu folder tidak menampung ribuan file.
 */
class TraceStore
{
    private static function disk()
    {
        return Storage::disk(config('querydebug.trace.disk', 'local'));
    }

    private static function root(): string
    {
        return trim((string) config('querydebug.trace.path', 'querydebug/traces'), '/');
    }

    private static function pathFor(string $code): string
    {
        // Bulan diturunkan dari kode itu sendiri (TRC-YYYYMMDD-XXXX), jadi
        // lokasi file bisa dihitung tanpa perlu index terpisah.
        $month = substr($code, 4, 6); // YYYYMM
        $month = substr($month, 0, 4) . '-' . substr($month, 4, 2);

        return self::root() . '/' . $month . '/' . $code . '.json';
    }

    /**
     * Kode trace unik, format TRC-YYYYMMDD-XXXX.
     *
     * Bagian acak (bukan counter berurut) dipakai supaya tidak ada race saat
     * dua support capture bersamaan, dan supaya kode tidak bisa ditebak
     * berurutan oleh orang yang cuma tahu satu kode.
     */
    public static function newCode(): string
    {
        $disk = self::disk();

        for ($i = 0; $i < 8; $i++) {
            $code = 'TRC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 4));

            if (! $disk->exists(self::pathFor($code))) {
                return $code;
            }
        }

        return 'TRC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }

    public static function put(array $trace): void
    {
        self::disk()->put(
            self::pathFor($trace['code']),
            json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /** @return array|null */
    public static function find(string $code)
    {
        if (! self::isValidCode($code)) {
            return null;
        }

        $path = self::pathFor($code);
        $disk = self::disk();

        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode($disk->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Validasi bentuk kode SEBELUM dipakai menyusun path.
     * Ini yang menahan path traversal (mis. ../../.env) dari input URL.
     */
    public static function isValidCode($code): bool
    {
        return is_string($code) && preg_match('/^TRC-\d{8}-[A-Z0-9]{4,8}$/', $code) === 1;
    }

    /**
     * Daftar trace terbaru (untuk halaman index dev). Membaca isi file karena
     * judul/nota trace ada di dalamnya; dibatasi $limit supaya tetap murah.
     *
     * @return array<int,array>
     */
    public static function recent(int $limit = 50): array
    {
        $disk  = self::disk();
        $root  = self::root();
        $files = [];

        foreach ($disk->directories($root) as $monthDir) {
            foreach ($disk->files($monthDir) as $file) {
                if (substr($file, -5) === '.json') {
                    $files[] = $file;
                }
            }
        }

        // Nama file mengandung tanggal + kode, jadi urut terbalik secara string
        // sudah cukup mendekati urut waktu tanpa perlu stat tiap file.
        rsort($files);
        $files = array_slice($files, 0, $limit);

        $out = [];
        foreach ($files as $file) {
            $decoded = json_decode($disk->get($file), true);
            if (! is_array($decoded)) {
                continue;
            }

            $out[] = [
                'code'       => isset($decoded['code']) ? $decoded['code'] : null,
                'note'       => isset($decoded['note']) ? $decoded['note'] : '',
                'user'       => isset($decoded['user']) ? $decoded['user'] : null,
                'captured_at' => isset($decoded['captured_at']) ? $decoded['captured_at'] : null,
                'steps'      => isset($decoded['steps']) ? count($decoded['steps']) : 0,
                'context'    => isset($decoded['context']) ? $decoded['context'] : [],
            ];
        }

        return $out;
    }
}
