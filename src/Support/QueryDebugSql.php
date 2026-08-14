<?php

namespace Sd1\QueryViewer\Support;

/**
 * Interpolasi SQL template ('?') + bindings menjadi raw SQL siap-copas ke DBeaver,
 * plus helper keamanan yang dipakai fitur EXPLAIN.
 *
 * Beda dengan Str::replaceArray('?', ...): interpolasi ini TIDAK mengganti '?'
 * yang berada di dalam string literal (mis. WHERE nama LIKE '%?%'), dan berhenti
 * setelah semua binding terpakai. Ini penting di PostgreSQL yang '?'-nya bisa
 * bermakna lain.
 *
 * Catatan: kalau ada query yang pakai operator JSONB '?', '?|', '?&' lewat
 * whereRaw(), '?' operator itu tetap tak bisa dibedakan dari placeholder di level
 * string SQL. Kasus itu jarang di IAS-PHP; kalau ketemu, tulis operatornya lewat
 * fungsi (mis. jsonb_exists) supaya raw SQL-nya tetap valid.
 */
class QueryDebugSql
{
    /**
     * Kata kunci yang, kalau muncul di luar string literal, bikin sebuah query
     * ditolak untuk EXPLAIN. Sengaja over-blocking: lebih baik menolak query
     * yang sebetulnya aman daripada meloloskan satu yang mengubah data.
     *
     * Kenapa perlu walau sudah cek "harus diawali SELECT": PostgreSQL
     * mengizinkan data-modifying CTE (WITH x AS (DELETE ... RETURNING ...)
     * SELECT ...), jadi awalan SELECT/WITH saja tidak menjamin read-only.
     */
    private static $forbidden = [
        'insert', 'update', 'delete', 'merge', 'truncate', 'drop', 'alter',
        'create', 'grant', 'revoke', 'copy', 'vacuum', 'reindex', 'cluster',
        'refresh', 'lock', 'call', 'do', 'set', 'reset', 'begin', 'start',
        'commit', 'rollback', 'savepoint', 'prepare', 'execute', 'deallocate',
        'listen', 'notify', 'unlisten', 'discard', 'nextval', 'setval',
        'pg_sleep', 'pg_terminate_backend', 'pg_cancel_backend', 'dblink',
        'lo_import', 'lo_export', 'pg_read_file', 'pg_ls_dir',
    ];

    public static function interpolate(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        $values = array_map([self::class, 'formatBinding'], array_values($bindings));

        $out = '';
        $len = strlen($sql);
        $i = 0;              // index karakter
        $b = 0;              // index binding
        $total = count($values);
        $inString = false;

        while ($i < $len) {
            $ch = $sql[$i];

            if ($inString) {
                $out .= $ch;
                // '' = escaped single quote di dalam string, bukan penutup
                if ($ch === "'" && $i + 1 < $len && $sql[$i + 1] === "'") {
                    $out .= "'";
                    $i += 2;
                    continue;
                }
                if ($ch === "'") {
                    $inString = false;
                }
                $i++;
                continue;
            }

            if ($ch === "'") {
                $inString = true;
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === '?' && $b < $total) {
                $out .= $values[$b++];
                $i++;
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Buang isi string literal (diganti '') supaya pemeriksaan kata kunci
     * tidak ketipu oleh data. Contoh: WHERE nama = 'DELETE FROM x' tidak
     * boleh dianggap query yang menghapus data.
     *
     * Identifier ber-double-quote ("delete") juga dikosongkan, karena nama
     * kolom/tabel bukan kata kunci.
     */
    public static function stripLiterals(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $out .= $quote . $quote; // sisakan penanda kosong
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === $quote) {
                        // quote ganda = escaped, bukan penutup
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Buang komentar SQL (-- ... dan slash-star ... star-slash) dari string
     * yang literal-nya SUDAH dikosongkan lewat stripLiterals().
     */
    public static function stripComments(string $sql): string
    {
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql);
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql);

        return $sql === null ? '' : $sql;
    }

    /**
     * True hanya kalau query ini aman dianggap read-only, yaitu:
     *  - satu statement saja (tidak ada ';' di tengah)
     *  - diawali SELECT / WITH / TABLE / VALUES
     *  - tidak mengandung kata kunci DDL/DML/side-effect di luar string literal
     *  - tidak memakai locking clause (FOR UPDATE / FOR SHARE dsb)
     *  - tidak memakai dollar-quoted body ($$ ... $$), karena isinya tidak
     *    ikut terperiksa oleh pemeriksaan di atas
     */
    public static function isReadOnly(string $sql): bool
    {
        $bare = self::stripComments(self::stripLiterals($sql));
        $bare = trim($bare);

        if ($bare === '') {
            return false;
        }

        // dollar-quoting tidak diperiksa isinya -> tolak saja
        if (strpos($bare, '$$') !== false || preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\$/', $bare)) {
            return false;
        }

        // multi-statement: ';' hanya boleh di paling akhir
        if (strpos(rtrim($bare, "; \t\r\n\0"), ';') !== false) {
            return false;
        }

        if (! preg_match('/^(select|with|table|values)\b/i', $bare)) {
            return false;
        }

        if (preg_match('/\bfor\s+(update|share|no\s+key\s+update|key\s+share)\b/i', $bare)) {
            return false;
        }

        $pattern = '/\b(' . implode('|', array_map('preg_quote', self::$forbidden)) . ')\b/i';

        return ! preg_match($pattern, $bare);
    }

    private static function formatBinding($binding): string
    {
        if (is_null($binding)) {
            return 'NULL';
        }
        if (is_bool($binding)) {
            return $binding ? 'TRUE' : 'FALSE';
        }
        if ($binding instanceof \DateTimeInterface) {
            return "'" . $binding->format('Y-m-d H:i:s') . "'";
        }
        if (is_int($binding) || is_float($binding)) {
            return (string) $binding;
        }

        return "'" . str_replace("'", "''", (string) $binding) . "'";
    }
}
