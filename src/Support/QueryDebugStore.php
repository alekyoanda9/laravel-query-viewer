<?php

namespace Sd1\QueryViewer\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Ring buffer batch query per-user, disimpan di cache (driver: file).
 *
 * Kenapa aman pakai read-modify-write tanpa lock atomik (cache file di Laravel
 * 5.8 tidak support Cache::lock): key di-scope per-usid, dan request dari satu
 * user yang sama sudah di-serialize oleh PHP file-session lock. Jadi tidak ada
 * dua request yang menulis key yang sama secara bersamaan. Antar-user beda key,
 * jadi tidak saling tabrak.
 */
class QueryDebugStore
{
    private static function key($usid): string
    {
        return 'qdebug:' . ($usid !== null && $usid !== '' ? $usid : 'guest');
    }

    public static function push($usid, array $batch): void
    {
        $key = self::key($usid);
        $list = Cache::get($key, []);
        $list[] = $batch;

        $max = (int) config('querydebug.max_batches', 30);
        if (count($list) > $max) {
            $list = array_slice($list, -$max);
        }

        Cache::put(
            $key,
            $list,
            now()->addMinutes((int) config('querydebug.ttl_minutes', 30))
        );
    }

    /** Batch terbaru lebih dulu. */
    public static function recentFor($usid): array
    {
        return array_reverse(Cache::get(self::key($usid), []));
    }

    /**
     * Ambil satu query berdasarkan posisinya di daftar recentFor().
     *
     * Dipakai endpoint EXPLAIN: panel mengirim indeks batch + indeks query,
     * BUKAN string SQL-nya. Jadi SQL yang dieksekusi selalu berasal dari store
     * milik user itu sendiri, bukan dari input client — client tidak punya
     * jalan untuk menitipkan SQL karangan sendiri lewat endpoint ini.
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
    }
}
