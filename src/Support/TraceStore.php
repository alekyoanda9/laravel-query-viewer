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
     * List semua file (bukan direktori) di bawah $root secara rekursif,
     * sudah dinormalisasi ke forward-slash.
     *
     * PENTING — kenapa ini tidak pakai $disk->allFiles($root)/directories():
     * di Windows, League\Flysystem v1 Adapter\Local yang dipakai Laravel 5.8
     * punya bug listing rekursif — path level teratas dinormalisasi ke '/'
     * tapi path level di bawahnya diambil dari RecursiveDirectoryIterator
     * native PHP yang di Windows menghasilkan '\' (mis.
     * "querydebug/traces\2026-08\TRC-xxx.json"). Lapisan filter
     * League\Flysystem\Filesystem yang membungkus adapter itu mencocokkan
     * prefix path dengan asumsi separator '/' konsisten; begitu ketemu '\'
     * di tengah path, filter itu gagal cocok dan MEMBUANG SEMUA hasil tanpa
     * exception — jadi Storage::allFiles()/directories() selalu balikin
     * array kosong walau file fisiknya ada. Manggil adapter mentah
     * (listContents) melewati filter yang bermasalah itu; kita normalisasi
     * separator sendiri di sini.
     *
     * Kalau adapter Flysystem tidak tersedia (mis. driver disk bukan lokal,
     * atau versi Flysystem berbeda di masa depan setelah upgrade), fallback
     * ke allFiles() bawaan Laravel supaya tetap jalan (walau berpotensi kena
     * bug yang sama di Windows sampai library-nya di-upgrade).
     *
     * @return array<int,string>
     */
    private static function listAllFiles($disk, string $root): array
    {
        try {
            $driver  = $disk->getDriver();
            $adapter = method_exists($driver, 'getAdapter') ? $driver->getAdapter() : null;

            if ($adapter === null || ! method_exists($adapter, 'listContents')) {
                throw new \RuntimeException('adapter tidak mendukung listContents');
            }

            $entries = $adapter->listContents($root, true);

            $files = [];
            foreach ($entries as $entry) {
                if (($entry['type'] ?? null) !== 'file') {
                    continue;
                }

                $files[] = str_replace('\\', '/', $entry['path']);
            }

            return $files;
        } catch (\Throwable $e) {
            // Fallback aman: tetap coba cara Laravel biasa daripada mati total.
            return $disk->allFiles($root);
        }
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

    /**
     * Folder tempat file lampiran (upload) untuk satu trace disimpan. Berada
     * di folder bulan yang sama dengan file JSON-nya, jadi retensi/pembersihan
     * cukup dilakukan per-bulan tanpa index terpisah.
     */
    public static function attachmentDir(string $code): string
    {
        $month = substr($code, 4, 6); // YYYYMM
        $month = substr($month, 0, 4) . '-' . substr($month, 4, 2);

        return self::root() . '/' . $month . '/' . $code . '-files';
    }

    public static function putAttachment(string $code, string $filename, $contents): string
    {
        $path = self::attachmentDir($code) . '/' . $filename;
        self::disk()->put($path, $contents);

        return $path;
    }

    public static function attachmentStream(string $path)
    {
        return self::disk()->readStream($path);
    }

    public static function attachmentExists(string $path): bool
    {
        return self::disk()->exists($path);
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
     * Hapus satu trace: file JSON-nya + folder lampirannya (kalau ada).
     * Dipanggil dari tombol Hapus per-baris maupun dari pruneOlderThan().
     */
    public static function delete(string $code): bool
    {
        if (! self::isValidCode($code)) {
            return false;
        }

        $disk = self::disk();
        $path = self::pathFor($code);

        if (! $disk->exists($path)) {
            return false;
        }

        $disk->delete($path);

        $dir = self::attachmentDir($code);
        if ($disk->exists($dir)) {
            $disk->deleteDirectory($dir);
        }

        return true;
    }

    /**
     * Hapus semua trace yang tanggalnya (diturunkan dari kode, bukan mtime
     * file — konsisten dengan pathFor()/recent()) lebih tua dari $days hari.
     * Dipakai form "bersihkan lebih lama dari N hari" dan Artisan command
     * querydebug:prune-traces.
     *
     * @return int  jumlah trace yang terhapus
     */
    public static function pruneOlderThan(int $days): int
    {
        $disk   = self::disk();
        $root   = self::root();
        $cutoff = now()->subDays($days)->format('Ymd');

        $count = 0;
        foreach (self::listAllFiles($disk, $root) as $file) {
            if (substr($file, -5) !== '.json') {
                continue;
            }

            $code = basename($file, '.json');
            if (! self::isValidCode($code)) {
                continue;
            }

            $datePart = substr($code, 4, 8); // YYYYMMDD
            if ($datePart < $cutoff && self::delete($code)) {
                $count++;
            }
        }

        return $count;
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

        // listAllFiles() dipakai (bukan Storage::allFiles()/directories() 2
        // level) karena scan rekursif jauh lebih tahan terhadap variasi
        // struktur folder — mis. file JSON yang ternyata bukan langsung di
        // {root}/{YYYY-MM}/, atau ada sub-folder tak terduga di antaranya.
        // directories()+files() diam-diam melewatkan trace di luar 2 level
        // itu tanpa error apa pun. Lihat komentar di listAllFiles() untuk
        // alasan kenapa ini tidak langsung memanggil Storage::allFiles().
        foreach (self::listAllFiles($disk, $root) as $file) {
            if (substr($file, -5) === '.json') {
                $files[] = $file;
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
                'note'       => isset($decoded['description']) ? $decoded['description'] : '',
                'user'       => isset($decoded['user']) ? $decoded['user'] : null,
                'captured_at' => isset($decoded['captured_at']) ? $decoded['captured_at'] : null,
                'steps'      => isset($decoded['steps']) ? count($decoded['steps']) : 0,
                'context'    => isset($decoded['context']) ? $decoded['context'] : [],
            ];
        }

        return $out;
    }
}
