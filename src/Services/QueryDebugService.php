<?php

namespace Sd1\QueryViewer\Services;

use Sd1\QueryViewer\Exceptions\QueryDebugException;
use Sd1\QueryViewer\Repositories\QueryDebugRepository;
use Sd1\QueryViewer\Support\QueryDebugInsight;
use Sd1\QueryViewer\Support\QueryDebugSql;
use Sd1\QueryViewer\Support\QueryDebugStore;

class QueryDebugService
{
    /** @var QueryDebugRepository */
    private $repository;

    public function __construct(QueryDebugRepository $repository)
    {
        $this->repository = $repository;
    }

    public function recent(string $identity): array
    {
        return [
            'identity'        => $identity,
            'insight_enabled' => QueryDebugInsight::enabled(),
            'batches'         => QueryDebugInsight::decorate(QueryDebugStore::recentFor($identity)),
        ];
    }

    public function clear(string $identity): void
    {
        QueryDebugStore::clearFor($identity);
    }

    /**
     * EXPLAIN satu query dari store milik identity ini. Client mengirim POSISI
     * (indeks batch + indeks query) + id verifikasi — bukan string SQL.
     */
    public function explain(string $identity, int $batchIndex, int $queryIndex, string $id, bool $analyze): array
    {
        if (! QueryDebugInsight::explainEnabled()) {
            throw new QueryDebugException('Fitur EXPLAIN tidak diaktifkan di server ini.', 403);
        }

        if ($analyze && ! QueryDebugInsight::analyzeEnabled()) {
            throw new QueryDebugException('EXPLAIN ANALYZE dimatikan di server ini.', 403);
        }

        $found = QueryDebugStore::locate($identity, $batchIndex, $queryIndex);

        if ($found === null) {
            throw new QueryDebugException('Query tidak ditemukan di daftar. Klik Refresh lalu coba lagi.', 404);
        }

        $query = $found['query'];

        if (QueryDebugInsight::queryId($query) !== $id) {
            throw new QueryDebugException(
                'Daftar query sudah berubah sejak panel terakhir dimuat. Klik Refresh lalu coba lagi.',
                409
            );
        }

        $sql = rtrim(trim(isset($query['raw']) ? (string) $query['raw'] : ''), ';');

        if ($sql === '') {
            throw new QueryDebugException('Query kosong, tidak bisa di-EXPLAIN.', 422);
        }

        $maxLength = (int) config('querydebug.insight.explain.max_sql_length', 20000);
        if (strlen($sql) > $maxLength) {
            throw new QueryDebugException(
                'Query terlalu panjang untuk di-EXPLAIN (' . strlen($sql) . ' karakter, batas ' . $maxLength . ').',
                422
            );
        }

        if (! QueryDebugSql::isReadOnly($sql)) {
            throw new QueryDebugException(
                'Hanya query SELECT yang boleh di-EXPLAIN. Query ini terdeteksi mengubah data '
                . 'atau bukan statement tunggal, jadi tidak dijalankan.',
                422
            );
        }

        $driver = $this->repository->driverName();
        if ($driver !== 'pgsql') {
            throw new QueryDebugException(
                'EXPLAIN hanya didukung untuk koneksi PostgreSQL (driver koneksi ini: ' . $driver . ').',
                422
            );
        }

        try {
            $result = $this->repository->explain(
                $sql,
                $analyze,
                (int) config('querydebug.insight.explain.timeout_ms', 5000)
            );
        } catch (\Exception $e) {
            throw new QueryDebugException('EXPLAIN gagal: ' . $e->getMessage(), 422);
        }

        return [
            'analyze'    => $analyze,
            'sql'        => $sql,
            'plan'       => $result['plan'],
            'elapsed_ms' => $result['elapsed_ms'],
        ];
    }
}
