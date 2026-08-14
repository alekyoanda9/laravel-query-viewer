<?php

namespace Sd1\QueryViewer\Repositories;

use Illuminate\Support\Facades\DB;
use Sd1\QueryViewer\Support\Context;

/**
 * Satu-satunya tempat package menyentuh DB (EXPLAIN). Koneksi diambil lewat
 * Context — jadi tidak bergantung pada BaseRepository / konvensi session
 * aplikasi mana pun. null = koneksi default app.
 */
class QueryDebugRepository
{
    protected function connection()
    {
        return DB::connection(Context::connectionName());
    }

    public function driverName(): string
    {
        return (string) $this->connection()->getDriverName();
    }

    /**
     * Jalankan EXPLAIN (opsional ANALYZE). Tiga pengaman berlapis:
     *  1. Selalu dalam transaksi yang PASTI di-rollback (finally).
     *  2. SET LOCAL statement_timeout — hanya berlaku untuk transaksi ini.
     *  3. FORMAT TEXT supaya sama dengan tampilan DBeaver/psql.
     *
     * @return array{plan:string,elapsed_ms:float}
     */
    public function explain(string $sql, bool $analyze, int $timeoutMs): array
    {
        $connection = $this->connection();

        $prefix = $analyze
            ? 'EXPLAIN (ANALYZE, BUFFERS, VERBOSE, COSTS, TIMING, FORMAT TEXT) '
            : 'EXPLAIN (VERBOSE, COSTS, FORMAT TEXT) ';

        $started = microtime(true);

        $connection->beginTransaction();

        try {
            $connection->statement('SET LOCAL statement_timeout = ' . (int) $timeoutMs);
            $rows = $connection->select($prefix . $sql);
        } finally {
            try {
                $connection->rollBack();
            } catch (\Exception $e) {
                // transaksi bisa sudah dibatalkan server (mis. kena timeout).
            }
        }

        $lines = [];
        foreach ($rows as $row) {
            $columns = (array) $row;
            $lines[] = array_key_exists('QUERY PLAN', $columns)
                ? $columns['QUERY PLAN']
                : reset($columns);
        }

        return [
            'plan'       => implode("\n", $lines),
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 1),
        ];
    }
}
