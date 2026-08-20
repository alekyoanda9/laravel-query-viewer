<?php

namespace Sd1\QueryViewer\Support;

/**
 * Menyaring input request sebelum ikut tersimpan sebagai bagian dari step.
 *
 * Input request itu justru bagian TERPENTING untuk reproduce (filter apa yang
 * dipilih, kode toko mana yang diedit), tapi juga tempat paling mungkin
 * bocornya password / token. Jadi input tetap direkam, tapi lewat sini:
 *
 *  - key yang cocok daftar sensitif  -> nilainya diganti '[redacted]'
 *  - nilai yang sangat panjang       -> dipotong (mis. paste CSV/base64)
 *  - array bersarang                 -> dibatasi kedalamannya
 *  - jumlah key                      -> dibatasi
 *
 * Filosofinya: rekam apa adanya kecuali yang jelas berbahaya, karena step yang
 * inputnya sudah dipangkas habis tidak lagi bisa dipakai reproduce.
 */
class StepRedactor
{
    public static function input(array $input): array
    {
        return self::walk($input, 0);
    }

    /**
     * Redaksi hasil "Sampel Data" (Fitur 5) berbasis NAMA KOLOM, memakai daftar
     * redact_keys yang SAMA dengan redaksi input di atas — satu titik kebijakan
     * redaksi, tidak ada jalur baru yang perlu diaudit terpisah.
     *
     * Kolom yang namanya cocok daftar sensitif -> nilainya '[redacted]'. Kolom
     * lain ditampilkan apa adanya untuk versi awal (lihat §5), hanya nilai yang
     * sangat panjang tetap dipotong.
     *
     * @param array<int,string>          $columns
     * @param array<int,array<int,mixed>> $rows  baris sebagai list nilai, urut kolom
     * @return array<int,array<int,mixed>>
     */
    public static function sampleRows(array $columns, array $rows): array
    {
        $maxValue = (int) config(
            'querydebug.sample.max_value_length',
            config('querydebug.trace.max_value_length', 300)
        );
        if ($maxValue <= 0) {
            $maxValue = (int) config('querydebug.trace.max_value_length', 300);
        }

        $sensitive = [];
        foreach ($columns as $i => $col) {
            $sensitive[$i] = self::isSensitive((string) $col);
        }

        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach (array_values((array) $row) as $i => $value) {
                if (! empty($sensitive[$i])) {
                    $line[] = '[redacted]';
                    continue;
                }

                if (is_null($value)) {
                    $line[] = null;
                    continue;
                }

                if (is_bool($value) || is_int($value) || is_float($value)) {
                    $line[] = $value;
                    continue;
                }

                $value = (string) $value;
                $line[] = strlen($value) > $maxValue
                    ? substr($value, 0, $maxValue) . '… (' . strlen($value) . ' char)'
                    : $value;
            }
            $out[] = $line;
        }

        return $out;
    }

    private static function walk(array $input, int $depth): array
    {
        $maxKeys  = (int) config('querydebug.trace.max_input_keys', 40);
        $maxValue = (int) config('querydebug.trace.max_value_length', 300);
        $maxDepth = 3;

        $out   = [];
        $count = 0;

        foreach ($input as $key => $value) {
            if ($count >= $maxKeys) {
                $out['…'] = '[' . (count($input) - $maxKeys) . ' field lain tidak ditampilkan]';
                break;
            }
            $count++;

            if (self::isSensitive((string) $key)) {
                $out[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $depth >= $maxDepth
                    ? '[array, ' . count($value) . ' item]'
                    : self::walk($value, $depth + 1);
                continue;
            }

            if (is_bool($value) || is_null($value) || is_int($value) || is_float($value)) {
                $out[$key] = $value;
                continue;
            }

            if (is_object($value)) {
                // Mis. UploadedFile — jangan pernah coba serialize isinya.
                $out[$key] = '[' . get_class($value) . ']';
                continue;
            }

            $value = (string) $value;
            $out[$key] = strlen($value) > $maxValue
                ? substr($value, 0, $maxValue) . '… (' . strlen($value) . ' char)'
                : $value;
        }

        return $out;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ((array) config('querydebug.trace.redact_keys', []) as $needle) {
            if ($needle !== '' && strpos($key, strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }
}
