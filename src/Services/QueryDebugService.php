<?php

namespace Sd1\QueryViewer\Services;

use Sd1\QueryViewer\Exceptions\QueryDebugException;
use Sd1\QueryViewer\Repositories\QueryDebugRepository;
use Sd1\QueryViewer\Support\QueryDebugInsight;
use Sd1\QueryViewer\Support\QueryDebugSql;
use Sd1\QueryViewer\Support\QueryDebugStore;
use Sd1\QueryViewer\Support\StepRedactor;

class QueryDebugService
{
    /** @var QueryDebugRepository */
    private $repository;

    public function __construct(QueryDebugRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * DELTA /recent. Client mengirim seq tertinggi yang sudah ia terima
     * (?after=<seq>) + generation yang ia pegang (?gen=<token>). Server hanya
     * mengembalikan batch dengan seq > after, plus metadata sinkronisasi.
     *
     * Aturan:
     *  - Kalau generation client BEDA dari server (atau kosong) -> store dianggap
     *    sudah reset di sisi client: server mengirim FULL snapshot (after
     *    diabaikan) dan menandai 'full' => true supaya client membuang lokalnya.
     *  - Insight per-batch dihitung SEKALI untuk batch baru yang dikirim (bukan
     *    seluruh buffer tiap poll).
     */
    public function recent(string $identity, int $afterSeq = 0, string $gen = ''): array
    {
        $generation = QueryDebugStore::generation($identity);
        $full       = ($gen === '' || $gen !== $generation);

        $batches = $full
            ? QueryDebugStore::recentFor($identity)
            : QueryDebugStore::since($identity, $afterSeq);

        $limit = (int) config('querydebug.poll.max_batches_per_response', 100);
        if ($limit > 0 && count($batches) > $limit) {
            // Ambil yang TERBARU (recentFor/since sudah terbaru-dulu).
            $batches = array_slice($batches, 0, $limit);
        }

        return [
            'identity'        => $identity,
            'insight_enabled' => QueryDebugInsight::enabled(),
            'head'            => QueryDebugStore::head($identity),
            'min_seq'         => QueryDebugStore::minSeq($identity),
            'generation'      => $generation,
            'full'            => $full,
            'batches'         => $this->stripHeavy(QueryDebugInsight::decorate($batches)),
        ];
    }

    /**
     * Buang field BERAT (response body) dari batch sebelum dikirim di /recent —
     * response diambil lazy lewat batch/{id}/response. Yang ikut hanyalah
     * ringkasan response (status/tipe/ukuran/truncated) supaya UI tahu ada
     * response tanpa menyeret body-nya tiap poll.
     */
    private function stripHeavy(array $batches): array
    {
        foreach ($batches as $i => $batch) {
            if (isset($batch['response']) && is_array($batch['response'])) {
                $batches[$i]['response'] = \Sd1\QueryViewer\Support\ResponseCapturer::meta($batch['response']);
            }
        }

        return $batches;
    }

    /**
     * Detail satu batch untuk dashboard (lazy) — queries + payload + context,
     * TANPA response body (response tetap lewat endpoint terpisah).
     */
    public function batch(string $identity, string $batchId): array
    {
        $batch = QueryDebugStore::findBatch($identity, $batchId);
        if ($batch === null) {
            throw new QueryDebugException('Batch tidak ditemukan (mungkin sudah tergeser dari buffer).', 404);
        }

        $decorated = QueryDebugInsight::decorate([$batch]);
        $one = $this->stripHeavy($decorated)[0];

        return $one;
    }

    /**
     * Payload request teredaksi satu batch (lazy).
     */
    public function batchPayload(string $identity, string $batchId): array
    {
        $batch = QueryDebugStore::findBatch($identity, $batchId);
        if ($batch === null) {
            throw new QueryDebugException('Batch tidak ditemukan (mungkin sudah tergeser dari buffer).', 404);
        }

        return [
            'input'   => isset($batch['input']) && is_array($batch['input']) ? $batch['input'] : [],
            'context' => isset($batch['context']) && is_array($batch['context']) ? $batch['context'] : [],
        ];
    }

    /**
     * Response body satu batch (lazy) — hanya di sinilah body dikirim ke client.
     */
    public function batchResponse(string $identity, string $batchId): array
    {
        $batch = QueryDebugStore::findBatch($identity, $batchId);
        if ($batch === null) {
            throw new QueryDebugException('Batch tidak ditemukan (mungkin sudah tergeser dari buffer).', 404);
        }

        $response = isset($batch['response']) && is_array($batch['response']) ? $batch['response'] : null;

        if ($response === null) {
            return ['captured' => false];
        }

        return [
            'captured'     => true,
            'content_type' => isset($response['content_type']) ? $response['content_type'] : null,
            'status'       => isset($response['status']) ? $response['status'] : null,
            'kind'         => isset($response['kind']) ? $response['kind'] : null,
            'size'         => isset($response['size']) ? $response['size'] : null,
            'filename'     => isset($response['filename']) ? $response['filename'] : null,
            'truncated'    => ! empty($response['truncated']),
            'body'         => isset($response['body']) ? $response['body'] : null,
        ];
    }

    public function clear(string $identity): void
    {
        QueryDebugStore::clearFor($identity);
    }

    /**
     * EXPLAIN satu query dari store milik identity ini. Client mengirim ID BATCH
     * yang stabil + indeks query + id verifikasi (hash SQL) — bukan string SQL,
     * dan bukan indeks posisional yang bisa bergeser.
     */
    public function explain(string $identity, string $batchId, int $queryIndex, string $id): array
    {
        if (! QueryDebugInsight::explainEnabled()) {
            throw new QueryDebugException('Fitur EXPLAIN tidak diaktifkan di server ini.', 403);
        }

        $sql = $this->resolveReadOnlySql($identity, $batchId, $queryIndex, $id);

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
                (int) config('querydebug.insight.explain.timeout_ms', 5000)
            );
        } catch (\Exception $e) {
            throw new QueryDebugException('EXPLAIN gagal: ' . $e->getMessage(), 422);
        }

        return [
            'sql'        => $sql,
            'plan'       => $result['plan'],
            'elapsed_ms' => $result['elapsed_ms'],
        ];
    }

    /**
     * SAMPEL DATA (Fitur 5): jalankan ulang SELECT read-only, ambil beberapa
     * baris contoh. Reuse guard + resolver yang SAMA dengan EXPLAIN; bedanya
     * hanya di gate config-nya sendiri (querydebug.sample.enabled, default mati)
     * dan redaksi berbasis nama kolom sebelum hasil dikembalikan.
     */
    public function sample(string $identity, string $batchId, int $queryIndex, string $id): array
    {
        if (! QueryDebugInsight::sampleEnabled()) {
            throw new QueryDebugException('Fitur Sampel Data tidak diaktifkan di server ini.', 403);
        }

        $sql = $this->resolveReadOnlySql($identity, $batchId, $queryIndex, $id);

        $driver = $this->repository->driverName();
        if ($driver !== 'pgsql') {
            throw new QueryDebugException(
                'Sampel Data hanya didukung untuk koneksi PostgreSQL (driver koneksi ini: ' . $driver . ').',
                422
            );
        }

        $timeout = config('querydebug.sample.statement_timeout_ms');
        $timeout = ($timeout === null || $timeout === '')
            ? (int) config('querydebug.insight.explain.timeout_ms', 5000)
            : (int) $timeout;

        try {
            $result = $this->repository->sample(
                $sql,
                (int) config('querydebug.sample.max_rows', 3),
                $timeout
            );
        } catch (\Exception $e) {
            throw new QueryDebugException('Sampel Data gagal: ' . $e->getMessage(), 422);
        }

        return [
            'columns'    => $result['columns'],
            'rows'       => StepRedactor::sampleRows($result['columns'], $result['rows']),
            'elapsed_ms' => $result['elapsed_ms'],
        ];
    }

    /**
     * Cari query di store (by-id batch stabil), verifikasi masih query yang sama
     * (hash), dan pastikan aman dijalankan read-only. Dipakai bersama oleh
     * EXPLAIN & Sampel Data.
     */
    private function resolveReadOnlySql(string $identity, string $batchId, int $queryIndex, string $id): string
    {
        $found = QueryDebugStore::find($identity, $batchId, $queryIndex);

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
            throw new QueryDebugException('Query kosong, tidak bisa dijalankan.', 422);
        }

        $maxLength = (int) config('querydebug.insight.explain.max_sql_length', 20000);
        if (strlen($sql) > $maxLength) {
            throw new QueryDebugException(
                'Query terlalu panjang untuk dijalankan (' . strlen($sql) . ' karakter, batas ' . $maxLength . ').',
                422
            );
        }

        if (! QueryDebugSql::isReadOnly($sql)) {
            throw new QueryDebugException(
                'Hanya query SELECT yang boleh dijalankan. Query ini terdeteksi mengubah data '
                . 'atau bukan statement tunggal, jadi tidak dijalankan.',
                422
            );
        }

        return $sql;
    }
}
