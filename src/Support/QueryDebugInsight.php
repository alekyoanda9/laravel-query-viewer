<?php

namespace Sd1\QueryViewer\Support;

/**
 * Analisa batch query jadi ringkasan yang bisa dibaca cepat oleh QA.
 *
 * Dihitung saat panel MENARIK data (endpoint /recent), bukan saat request user
 * berjalan. Jadi menyalakan insight tidak menambah beban ke request yang sedang
 * diproses — hanya ke request panel itu sendiri.
 *
 * Dua jenis temuan yang dibedakan, karena penanganannya beda:
 *
 *  - "redundan": raw SQL identik persis dijalankan >1x dalam satu request.
 *    Artinya hasil yang sama diambil berulang — biasanya cukup diselesaikan
 *    dengan menyimpan hasilnya di variabel/cache request.
 *
 *  - "N+1": template SQL sama (kerangka ber-'?' yang sama) dijalankan banyak
 *    kali dengan binding BERBEDA-BEDA. Ini pola query-di-dalam-loop, biasanya
 *    diselesaikan dengan eager loading / satu query WHERE IN.
 */
class QueryDebugInsight
{
    public static function enabled(): bool
    {
        return (bool) config('querydebug.insight.enabled', false);
    }

    public static function explainEnabled(): bool
    {
        return (bool) config('querydebug.insight.explain.enabled', false);
    }

    public static function sampleEnabled(): bool
    {
        return (bool) config('querydebug.sample.enabled', false);
    }

    /**
     * Tambahkan 'id' ke tiap query (selalu) dan 'insight' ke tiap batch
     * (hanya kalau fitur insight aktif).
     *
     * 'id' = md5 raw SQL, dipakai panel untuk memverifikasi bahwa query yang
     * mau di-EXPLAIN masih query yang sama dengan yang tampil di layar —
     * indeks batch bisa bergeser kalau ada request baru masuk di sela-sela.
     */
    public static function decorate(array $batches): array
    {
        $withInsight = self::enabled();

        foreach ($batches as $bi => $batch) {
            $queries = isset($batch['queries']) && is_array($batch['queries'])
                ? $batch['queries']
                : [];

            foreach ($queries as $qi => $query) {
                // Deteksi dari template ber-'?' kalau ada (bebas dari noise
                // nilai literal); fallback ke raw.
                $src = isset($query['sql']) && $query['sql'] !== ''
                    ? (string) $query['sql']
                    : (isset($query['raw']) ? (string) $query['raw'] : '');

                $queries[$qi]['id']    = self::queryId($query);
                $queries[$qi]['op']    = self::operation($src);
                $queries[$qi]['table'] = self::primaryTable($src);
            }

            $batches[$bi]['queries'] = $queries;

            if ($withInsight) {
                $batches[$bi]['insight'] = self::analyze($queries);
            }
        }

        return $batches;
    }

    public static function queryId(array $query): string
    {
        return md5(isset($query['raw']) ? (string) $query['raw'] : '');
    }

    /**
     * Jenis operasi untuk label tiket (INSERT/UPDATE/DELETE/SELECT/…), diambil
     * dari kata kunci pertama. Berguna terutama untuk case intervensi manual:
     * dev langsung tahu ini query yang mengubah data, dan tabelnya apa.
     */
    public static function operation(string $sql): string
    {
        $bare = ltrim(QueryDebugSql::stripComments($sql), " \t\r\n(");

        if (preg_match('/^(select|insert|update|delete|with|merge|truncate|call|show|explain|create|alter|drop)\b/i', $bare, $m)) {
            return strtoupper($m[1]);
        }

        return 'QUERY';
    }

    private static function analyze(array $queries): array
    {
        $slowMs      = (float) config('querydebug.slow_ms', 500);
        $threshold   = (int) config('querydebug.insight.n_plus_one_threshold', 5);
        $maxFindings = (int) config('querydebug.insight.max_findings', 5);

        $totalMs   = 0.0;
        $slowCount = 0;
        $failedCount = 0;
        $byRaw     = [];
        $byTemplate = [];

        foreach ($queries as $query) {
            $ms  = isset($query['time_ms']) ? (float) $query['time_ms'] : 0.0;
            $raw = isset($query['raw']) ? (string) $query['raw'] : '';
            $tpl = isset($query['sql']) && $query['sql'] !== '' ? (string) $query['sql'] : $raw;

            $totalMs += $ms;
            if ($ms >= $slowMs) {
                $slowCount++;
            }

            // Query yang gagal (lihat LogQueryDebug::recordFailedQuery) dihitung
            // terpisah, bukan ikut dedup redundan/N+1 — satu kali gagal bukan
            // "redundan", dan biasanya tidak berulang dengan pola binding yang
            // konsisten seperti N+1 sungguhan.
            if (! empty($query['failed'])) {
                $failedCount++;
                continue;
            }

            $rawKey = md5($raw);
            if (! isset($byRaw[$rawKey])) {
                $byRaw[$rawKey] = ['count' => 0, 'ms' => 0.0, 'sample' => $raw];
            }
            $byRaw[$rawKey]['count']++;
            $byRaw[$rawKey]['ms'] += $ms;

            $tplKey = md5($tpl);
            if (! isset($byTemplate[$tplKey])) {
                $byTemplate[$tplKey] = ['count' => 0, 'ms' => 0.0, 'sample' => $tpl, 'raws' => []];
            }
            $byTemplate[$tplKey]['count']++;
            $byTemplate[$tplKey]['ms'] += $ms;
            $byTemplate[$tplKey]['raws'][$rawKey] = true;
        }

        // --- query redundan (raw identik berulang) ---------------------------
        $repeated  = [];
        $redundant = 0;
        foreach ($byRaw as $item) {
            if ($item['count'] > 1) {
                $redundant += $item['count'] - 1;
                $repeated[] = [
                    'count'    => $item['count'],
                    'total_ms' => round($item['ms'], 1),
                    'table'    => self::primaryTable($item['sample']),
                    'preview'  => self::preview($item['sample']),
                ];
            }
        }

        // --- indikasi N+1 (template sama, binding beda-beda) -----------------
        $nPlusOne = [];
        foreach ($byTemplate as $item) {
            $distinct = count($item['raws']);
            if ($item['count'] >= $threshold && $distinct >= 2) {
                $nPlusOne[] = [
                    'count'    => $item['count'],
                    'distinct' => $distinct,
                    'total_ms' => round($item['ms'], 1),
                    'table'    => self::primaryTable($item['sample']),
                    'preview'  => self::preview($item['sample']),
                ];
            }
        }

        self::sortByCount($repeated);
        self::sortByCount($nPlusOne);

        return [
            'count'           => count($queries),
            'unique_count'    => count($byRaw),
            'total_ms'        => round($totalMs, 1),
            'slow_count'      => $slowCount,
            'failed_count'    => $failedCount,
            'redundant_count' => $redundant,
            'repeated'        => array_slice($repeated, 0, $maxFindings),
            'n_plus_one'      => array_slice($nPlusOne, 0, $maxFindings),
        ];
    }

    private static function sortByCount(array &$items): void
    {
        usort($items, function ($a, $b) {
            if ($a['count'] === $b['count']) {
                return $b['total_ms'] <=> $a['total_ms'];
            }

            return $b['count'] <=> $a['count'];
        });
    }

    /**
     * Tebak tabel utama sebuah query, sekadar untuk label temuan
     * ("indikasi N+1 di tabel x"). Heuristik, bukan parser SQL —
     * kalau tidak ketemu, panel cukup tidak menampilkan nama tabel.
     */
    public static function primaryTable(string $sql)
    {
        $bare = QueryDebugSql::stripComments($sql);

        $patterns = [
            '/\binsert\s+into\s+"?([a-zA-Z0-9_]+)"?(?:\."?([a-zA-Z0-9_]+)"?)?/i',
            '/\bupdate\s+(?:only\s+)?"?([a-zA-Z0-9_]+)"?(?:\."?([a-zA-Z0-9_]+)"?)?/i',
            '/\bfrom\s+(?:only\s+)?"?([a-zA-Z0-9_]+)"?(?:\."?([a-zA-Z0-9_]+)"?)?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $bare, $m)) {
                return isset($m[2]) && $m[2] !== '' ? $m[1] . '.' . $m[2] : $m[1];
            }
        }

        return null;
    }

    private static function preview(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql));
        $flat = $flat === null ? '' : $flat;

        return strlen($flat) > 140 ? substr($flat, 0, 140) . '…' : $flat;
    }
}
