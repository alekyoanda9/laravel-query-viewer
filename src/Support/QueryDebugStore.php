<?php

namespace Sd1\QueryViewer\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ring buffer batch query per-user, disimpan di cache (driver: file).
 *
 * Kenapa aman pakai read-modify-write tanpa lock atomik (cache file di Laravel
 * 5.8 tidak support Cache::lock): key di-scope per-usid, dan request dari satu
 * user yang sama sudah di-serialize oleh PHP file-session lock. Jadi tidak ada
 * dua request yang menulis key yang sama secara bersamaan. Antar-user beda key,
 * jadi tidak saling tabrak.
 *
 * -- Delta polling (v2.3.0) ------------------------------------------------
 * Tiap batch mendapat:
 *   - 'seq' : nomor urut MONOTONIK per-identity (counter di key terpisah),
 *             dipakai client sebagai cursor: ?after=<seq> hanya mengambil batch
 *             yang lebih baru, jadi tiap batch dikirim SEKALI.
 *   - 'id'  : identitas STABIL (orderedUuid) yang tidak bergeser walau ada
 *             request baru masuk — dipakai locate EXPLAIN/Sampel by-id supaya
 *             indeks posisional tidak lagi memicu 409 "klik Refresh".
 * Selain itu store menyimpan 'generation' per-identity (token reset): berubah
 * saat clearFor()/lock(), jadi client tahu harus membuang cache lokal & fetch
 * penuh.
 */
class QueryDebugStore
{
    private static function key($usid): string
    {
        return 'qdebug:' . self::slug($usid);
    }

    private static function seqKey($usid): string
    {
        return 'qdebug:seq:' . self::slug($usid);
    }

    private static function genKey($usid): string
    {
        return 'qdebug:gen:' . self::slug($usid);
    }

    private static function slug($usid): string
    {
        return $usid !== null && $usid !== '' ? (string) $usid : 'guest';
    }

    private static function ttl()
    {
        return now()->addMinutes((int) config('querydebug.ttl_minutes', 30));
    }

    public static function push($usid, array $batch): void
    {
        $key = self::key($usid);

        // seq monotonik per-identity. Read-modify-write aman dengan alasan yang
        // sama seperti list di atas (session lock men-serialize request satu user).
        $seq = (int) Cache::get(self::seqKey($usid), 0) + 1;
        Cache::put(self::seqKey($usid), $seq, self::ttl());

        $batch['seq'] = $seq;
        $batch['id']  = self::newId();

        $list = Cache::get($key, []);
        $list[] = $batch;

        $max = (int) config('querydebug.max_batches', 30);
        if (count($list) > $max) {
            $list = array_slice($list, -$max);
        }

        Cache::put($key, $list, self::ttl());

        // Pastikan ada generation sejak batch pertama, supaya client punya token
        // yang stabil untuk dibandingkan.
        self::generation($usid);
    }

    private static function newId(): string
    {
        // Str::orderedUuid tersedia sejak Laravel 5.6 — id yang urut waktu &
        // unik tanpa bergantung posisi di ring buffer.
        try {
            return (string) Str::orderedUuid();
        } catch (\Throwable $e) {
            return uniqid('qd', true);
        }
    }

    /** Batch terbaru lebih dulu. */
    public static function recentFor($usid): array
    {
        return array_reverse(Cache::get(self::key($usid), []));
    }

    /**
     * Batch dengan seq > $afterSeq, terbaru lebih dulu. Dipakai endpoint delta
     * /recent?after=<seq>. Kalau $afterSeq <= 0 -> kembalikan semua (full).
     */
    public static function since($usid, int $afterSeq): array
    {
        $out = [];
        foreach (self::recentFor($usid) as $batch) {
            if ((int) (isset($batch['seq']) ? $batch['seq'] : 0) > $afterSeq) {
                $out[] = $batch;
            }
        }

        return $out;
    }

    /** seq tertinggi yang tercatat (0 kalau kosong). */
    public static function head($usid): int
    {
        return (int) Cache::get(self::seqKey($usid), 0);
    }

    /** seq TERLAMA yang masih tersimpan di ring buffer (0 kalau kosong). */
    public static function minSeq($usid): int
    {
        $list = Cache::get(self::key($usid), []);
        if (empty($list)) {
            return 0;
        }

        // list disimpan urut push (terlama dulu), jadi elemen pertama = terlama.
        $first = $list[0];

        return (int) (isset($first['seq']) ? $first['seq'] : 0);
    }

    /**
     * Token reset per-identity. Dibuat sekali lalu dipertahankan; diregenerasi
     * saat clearFor()/lock(). Client membandingkannya untuk memutuskan apakah
     * harus membuang cache lokal dan fetch penuh.
     */
    public static function generation($usid): string
    {
        $gen = Cache::get(self::genKey($usid));
        if (! is_string($gen) || $gen === '') {
            $gen = self::newGeneration();
            Cache::put(self::genKey($usid), $gen, self::ttl());
        }

        return $gen;
    }

    private static function bumpGeneration($usid): void
    {
        Cache::put(self::genKey($usid), self::newGeneration(), self::ttl());
    }

    private static function newGeneration(): string
    {
        return substr(md5(uniqid('gen', true)), 0, 8);
    }

    /**
     * Ambil satu query berdasarkan ID STABIL batch + indeks query di dalamnya.
     *
     * Dipakai endpoint EXPLAIN & Sampel Data: panel mengirim id batch + indeks
     * query + id verifikasi (hash SQL) — BUKAN string SQL-nya. Jadi SQL yang
     * dieksekusi selalu berasal dari store milik user itu sendiri, dan id batch
     * yang stabil membuat lookup tidak tergeser oleh request baru yang masuk di
     * sela-sela (menghilangkan 409 "klik Refresh" versi posisional).
     *
     * @return array|null ['batch' => array, 'query' => array]
     */
    public static function find($usid, string $id, int $queryIndex)
    {
        foreach (self::recentFor($usid) as $batch) {
            if ((string) (isset($batch['id']) ? $batch['id'] : '') !== $id) {
                continue;
            }

            if (! isset($batch['queries'][$queryIndex])) {
                return null;
            }

            return [
                'batch' => $batch,
                'query' => $batch['queries'][$queryIndex],
            ];
        }

        return null;
    }

    /**
     * Varian POSISIONAL lama (indeks batch di recentFor + indeks query).
     * Dipertahankan untuk kompatibilitas; jalur EXPLAIN/Sampel baru memakai
     * find() by-id di atas.
     *
     * @return array|null ['batch' => array, 'query' => array]
     */
    public static function locate($usid, int $batchIndex, int $queryIndex)
    {
        $batches = self::recentFor($usid);

        if (! isset($batches[$batchIndex]['queries'][$queryIndex])) {
            return null;
        }

        return [
            'batch' => $batches[$batchIndex],
            'query' => $batches[$batchIndex]['queries'][$queryIndex],
        ];
    }

    public static function clearFor($usid): void
    {
        Cache::forget(self::key($usid));
        // seq TIDAK direset (tetap monotonik) supaya client tidak bingung kalau
        // ada batch nyasar; generation-lah yang menandakan reset ke client.
        self::bumpGeneration($usid);
    }
}
