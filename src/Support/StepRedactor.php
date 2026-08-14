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
