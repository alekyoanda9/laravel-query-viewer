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
     * Jalankan EXPLAIN (read-only). Tiga pengaman berlapis:
     *  1. Selalu dalam transaksi yang PASTI di-rollback (finally).
     *  2. SET LOCAL statement_timeout — hanya berlaku untuk transaksi ini.
     *  3. FORMAT TEXT supaya sama dengan tampilan DBeaver/psql.
     *
     * Varian ANALYZE (yang benar-benar mengeksekusi query) sudah DIHAPUS —
     * hanya EXPLAIN biasa yang tersisa.
     *
     * @return array{plan:string,elapsed_ms:float}
     */
    public function explain(string $sql, int $timeoutMs): array
    {
        $connection = $this->connection();
        $started    = microtime(true);

        $rows = $this->runInRollback(function ($conn) use ($sql) {
            return $conn->select('EXPLAIN (VERBOSE, COSTS, FORMAT TEXT) ' . $sql);
        }, $connection, $timeoutMs);

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

    /**
     * Ambil beberapa baris CONTOH dari sebuah SELECT read-only (Fitur 5).
     *
     * Memakai infrastruktur pengaman yang PERSIS SAMA dengan explain():
     * transaksi yang pasti di-rollback + statement_timeout. Query asli dibungkus
     *   SELECT * FROM (<sql>) AS q LIMIT <maxRows>
     * supaya jumlah baris yang benar-benar terambil dibatasi di sisi DB.
     *
     * @return array{columns:array<int,string>,rows:array<int,array<int,mixed>>,elapsed_ms:float}
     */
    public function sample(string $sql, int $maxRows, int $timeoutMs): array
    {
        $connection = $this->connection();
        $started    = microtime(true);

        $wrapped = 'SELECT * FROM (' . rtrim($sql, "; \t\r\n") . ') AS q LIMIT ' . max(1, (int) $maxRows);

        $rows = $this->runInRollback(function ($conn) use ($wrapped) {
            return $conn->select($wrapped);
        }, $connection, $timeoutMs);

        $columns = [];
        $out     = [];
        foreach ($rows as $row) {
            $assoc = (array) $row;
            if (empty($columns)) {
                $columns = array_keys($assoc);
            }
            $out[] = array_values($assoc);
        }

        return [
            'columns'    => $columns,
            'rows'       => $out,
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    /**
     * Bungkus $work dalam transaksi yang SELALU di-rollback + statement_timeout.
     * Satu tempat pengaman, dipakai explain() maupun sample().
     */
    private function runInRollback(callable $work, $connection, int $timeoutMs)
    {
        $connection->beginTransaction();

        try {
            $connection->statement('SET LOCAL statement_timeout = ' . (int) $timeoutMs);

            return $work($connection);
        } finally {
            try {
                $connection->rollBack();
            } catch (\Exception $e) {
                // transaksi bisa sudah dibatalkan server (mis. kena timeout).
            }
        }
    }
}
