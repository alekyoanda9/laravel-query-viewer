<?php

namespace Sd1\QueryViewer\Services;

use Sd1\QueryViewer\Exceptions\QueryDebugException;
use Sd1\QueryViewer\Support\Context;
use Sd1\QueryViewer\Support\TraceStore;

/**
 * Menyimpan trace dari data ter-kurasi yang dikirim panel.
 *
 * Beda penting dari versi awal: langkah TIDAK lagi dibaca ulang dari ring
 * buffer saat capture. Panel mengirim snapshot yang dibekukan tepat saat
 * support menekan Ambil Kasus — lengkap dengan pengelompokan, langkah mana
 * yang disertakan, dan titik gagal. Alasannya: buffer terus merekam di latar
 * (flight recorder), jadi membaca ulang saat submit berisiko mengambil kondisi
 * yang sudah bergeser beberapa detik dari yang dilihat support.
 *
 * Data dari panel memang berasal dari server sendiri (sudah ter-redact saat
 * di-push ke buffer), tapi tetap dinormalisasi di sini supaya bentuk file
 * trace stabil dan tidak bergantung struktur internal panel.
 */
class TraceService
{
    /**
     * @param string $code  kode yang sudah dibuat controller (agar folder
     *                       lampiran bisa disiapkan sebelum JSON ditulis)
     * @param array $data  ['description','category','prpk','curated'=>['groups','steps']]
     * @param array $attachments  hasil normalisasi controller: [['type','kind','src','name','idx'], ...]
     */
    public function captureWithCode(string $code, string $identity, array $data, array $attachments): array
    {
        $curated = isset($data['curated']) && is_array($data['curated']) ? $data['curated'] : [];
        $rawSteps  = isset($curated['steps'])  && is_array($curated['steps'])  ? $curated['steps']  : [];
        $rawGroups = isset($curated['groups']) && is_array($curated['groups']) ? $curated['groups'] : [];

        $description = trim((string) (isset($data['description']) ? $data['description'] : ''));
        if ($description === '') {
            throw new QueryDebugException('Deskripsi masalah wajib diisi.', 422);
        }

        $includedCount = 0;
        foreach ($rawSteps as $s) {
            if (! empty($s['included'])) {
                $includedCount++;
            }
        }
        if ($includedCount === 0) {
            throw new QueryDebugException('Pilih minimal satu langkah untuk disertakan.', 422);
        }

        $max = (int) config('querydebug.trace.max_steps', 40);

        // Nomor langkah hanya diberikan ke langkah yang DISERTAKAN, mengikuti
        // urutan kronologis. Langkah yang dikecualikan tetap disimpan (included
        // = false) supaya dev bisa membukanya kalau ternyata itu yang penting,
        // tapi tidak ikut menomori narasi utama.
        $steps = [];
        $no = 0;
        foreach ($rawSteps as $s) {
            $included = ! empty($s['included']);
            if ($included) {
                if ($no >= $max) {
                    continue; // batasi hanya langkah yang disertakan
                }
                $no++;
            }
            $steps[] = $this->normalizeStep($s, $included ? $no : null);
        }

        $groups = [];
        foreach ($rawGroups as $g) {
            $label = trim((string) (isset($g['label']) ? $g['label'] : ''));
            $groups[] = [
                'label'  => $label !== '' ? $label : '(tanpa nama)',
                'failed' => ! empty($g['failed']),
                'origin' => isset($g['origin']) ? (string) $g['origin'] : null,
            ];
        }

        $first = null;
        foreach ($steps as $st) {
            if ($st['no'] !== null) {
                $first = $st;
                break;
            }
        }

        $categories = (array) config('querydebug.trace.categories', []);
        $category   = (string) (isset($data['category']) ? $data['category'] : '');
        if (! isset($categories[$category])) {
            $category = 'lainnya';
        }

        $trace = [
            'code'         => $code,
            'captured_at'  => date('Y-m-d H:i:s'),
            'user'         => $identity,

            'description'  => $description,
            'category'     => $category,
            'category_label' => isset($categories[$category]) ? $categories[$category] : $category,
            'prpk'         => $this->cleanPrpk(isset($data['prpk']) ? $data['prpk'] : ''),

            'context'      => Context::ticketMeta(),
            'conn'         => $first ? $first['conn'] : null,
            'app'          => [
                'url'  => config('app.url'),
                'host' => request()->getHost(),
                'php'  => PHP_VERSION,
            ],

            'excluded_count' => count($steps) - $includedCount,
            'attachments'  => array_values($attachments),
            'groups'       => $groups,
            'steps'        => $steps,
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

    public function delete(string $code): void
    {
        if (! TraceStore::delete($code)) {
            throw new QueryDebugException('Trace ' . $code . ' tidak ditemukan.', 404);
        }
    }

    public function prune(int $days): int
    {
        if ($days < 1) {
            throw new QueryDebugException('Jumlah hari tidak valid.', 422);
        }

        return TraceStore::pruneOlderThan($days);
    }

    private function cleanPrpk($value): string
    {
        $value = trim((string) $value);
        // Hanya karakter yang wajar untuk id PRPK/memo — cegah value liar
        // ikut tersimpan/terpampang.
        $value = preg_replace('/[^A-Za-z0-9\/\-_.]/', '', $value);

        return substr((string) $value, 0, 60);
    }

    private function normalizeStep(array $s, $no): array
    {
        $queries = [];
        foreach ((isset($s['queries']) && is_array($s['queries']) ? $s['queries'] : []) as $q) {
            $queries[] = [
                'raw'    => isset($q['raw']) ? (string) $q['raw'] : '',
                'ms'     => isset($q['ms']) ? $q['ms'] : null,
                'failed' => ! empty($q['failed']),
                'error'  => isset($q['error']) ? $q['error'] : null,
            ];
        }

        return [
            'no'         => $no,               // null = dikecualikan support
            'included'   => $no !== null,
            'fail_point' => ! empty($s['fail_point']),
            'group'      => isset($s['group']) ? (int) $s['group'] : 0,
            'at'         => isset($s['at']) ? $s['at'] : null,
            'method'     => isset($s['method']) ? $s['method'] : null,
            'path'       => isset($s['path']) ? $s['path'] : null,
            'route'      => isset($s['route']) ? $s['route'] : null,
            'is_ajax'    => ! empty($s['is_ajax']),
            'status'     => isset($s['status']) ? $s['status'] : null,
            'dur_ms'     => isset($s['dur_ms']) ? $s['dur_ms'] : null,
            'conn'       => isset($s['conn']) ? $s['conn'] : null,
            'input'      => isset($s['input']) && is_array($s['input']) ? $s['input'] : [],
            'error'      => isset($s['error']) ? $s['error'] : null,
            'queries'    => $queries,
        ];
    }
}
