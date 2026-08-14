<?php

namespace Sd1\QueryViewer\Services;

use Sd1\QueryViewer\Exceptions\QueryDebugException;
use Sd1\QueryViewer\Support\Context;
use Sd1\QueryViewer\Support\QueryDebugStore;
use Sd1\QueryViewer\Support\TraceStore;

/**
 * Mengubah isi ring buffer (sementara, per-user) jadi trace permanen berkode.
 *
 * Pola yang dipakai adalah "flight recorder", bukan tombol Rekam:
 * perekaman sudah jalan terus di latar sejak sesi di-unlock, dan support baru
 * menekan tombol SETELAH menemukan kejanggalan. Alasannya sederhana — support
 * tidak pernah tahu bug akan muncul sebelum bug itu muncul, jadi tombol
 * "mulai rekam" pasti kelewat dipencet.
 */
class TraceService
{
    /**
     * @param  int    $limit    berapa step terakhir yang diambil
     * @param  int    $suspect  indeks step yang ditandai support sebagai biang masalah (-1 = tidak ada)
     */
    public function capture(string $identity, string $note, int $limit, int $suspect): array
    {
        // recentFor() mengembalikan TERBARU DULU. Untuk trace kita balik lagi
        // jadi kronologis, karena dev membacanya sebagai alur waktu.
        $batches = array_reverse(QueryDebugStore::recentFor($identity));

        if (empty($batches)) {
            throw new QueryDebugException(
                'Belum ada langkah yang terekam. Lakukan dulu langkah yang bermasalah, baru tekan Ambil Kasus.',
                422
            );
        }

        $max = (int) config('querydebug.trace.max_steps', 40);
        if ($limit > 0 && $limit < count($batches)) {
            $batches = array_slice($batches, -$limit);
        }
        if (count($batches) > $max) {
            $batches = array_slice($batches, -$max);
        }

        $steps = [];
        foreach (array_values($batches) as $i => $batch) {
            $steps[] = $this->toStep($i + 1, $batch);
        }

        $first = reset($batches);

        $trace = [
            'code'        => TraceStore::newCode(),
            'captured_at' => date('Y-m-d H:i:s'),
            'note'        => $note,
            'suspect'     => ($suspect >= 1 && $suspect <= count($steps)) ? $suspect : count($steps),
            'user'        => $identity,

            // Metadata yang sudah dipakai fitur export tiket dipakai ulang di
            // sini — untuk IAS-PHP isinya cabang/IGR, dan justru inilah yang
            // paling sering bikin dev gagal reproduce kalau tidak tercatat.
            'context'     => Context::ticketMeta(),
            'conn'        => isset($first['conn']) ? $first['conn'] : null,

            'app'         => [
                'url'      => config('app.url'),
                'host'     => request()->getHost(),
                'php'      => PHP_VERSION,
            ],

            'steps'       => $steps,
        ];

        TraceStore::put($trace);

        return $trace;
    }

    public function show(string $code): array
    {
        $trace = TraceStore::find($code);

        if ($trace === null) {
            throw new QueryDebugException('Trace ' . $code . ' tidak ditemukan.', 404);
        }

        return $trace;
    }

    public function recent(int $limit = 50): array
    {
        return TraceStore::recent($limit);
    }

    /**
     * Normalisasi satu batch jadi bentuk step yang stabil untuk viewer & JSON
     * export. Sengaja tidak menyimpan seluruh isi batch mentah supaya format
     * trace tidak ikut berubah tiap kali struktur internal store berubah.
     */
    private function toStep(int $no, array $batch): array
    {
        $queries = [];
        foreach ((isset($batch['queries']) ? $batch['queries'] : []) as $q) {
            $queries[] = [
                'raw'    => isset($q['raw']) ? $q['raw'] : '',
                'ms'     => isset($q['time_ms']) ? $q['time_ms'] : null,
                'failed' => ! empty($q['failed']),
                'error'  => isset($q['error']) ? $q['error'] : null,
            ];
        }

        return [
            'no'      => $no,
            'at'      => isset($batch['at']) ? $batch['at'] : null,
            'method'  => isset($batch['method']) ? $batch['method'] : null,
            'path'    => isset($batch['path']) ? $batch['path'] : null,
            'route'   => isset($batch['route']) ? $batch['route'] : null,
            'is_ajax' => ! empty($batch['is_ajax']),
            'status'  => isset($batch['status']) ? $batch['status'] : null,
            'dur_ms'  => isset($batch['dur_ms']) ? $batch['dur_ms'] : null,
            'conn'    => isset($batch['conn']) ? $batch['conn'] : null,
            'input'   => isset($batch['input']) ? $batch['input'] : [],
            'error'   => isset($batch['error']) ? $batch['error'] : null,
            'queries' => $queries,
        ];
    }
}
