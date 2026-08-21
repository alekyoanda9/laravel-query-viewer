<?php

namespace Sd1\QueryViewer\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

/**
 * Titik tunggal semua hal yang berbeda antar-aplikasi.
 *
 * Package ini TIDAK tahu bahwa IAS-PHP menyimpan koneksi cabang di
 * session('connection'), user di session('usid'), dsb — konvensi itu milik
 * satu aplikasi. Supaya package bisa dipakai app lain, keempat hal berikut
 * diambil lewat closure yang di-daftarkan tiap app dari service provider-nya
 * (lihat QueryViewer::…Using()).
 *
 * Kenapa lewat registrar, BUKAN lewat file config: closure tidak bisa
 * di-serialize, jadi kalau ditaruh di config, `php artisan config:cache`
 * langsung gagal. Menaruhnya di service provider aman terhadap config cache.
 *
 * Semua punya default yang masuk akal, jadi app "biasa" (koneksi tunggal,
 * auth standar) bisa jalan tanpa mendaftarkan apa pun.
 */
class Context
{
    /** @var callable|null */
    protected static $connection;
    /** @var callable|null */
    protected static $identity;
    /** @var callable|null */
    protected static $active;
    /** @var callable|null */
    protected static $context;

    public static function connectionUsing(callable $cb): void
    {
        static::$connection = $cb;
    }

    public static function identifyUsing(callable $cb): void
    {
        static::$identity = $cb;
    }

    public static function activeUsing(callable $cb): void
    {
        static::$active = $cb;
    }

    public static function contextUsing(callable $cb): void
    {
        static::$context = $cb;
    }

    /**
     * Nama koneksi DB yang query-nya didengarkan.
     * Default: null = koneksi default aplikasi.
     * IAS-PHP mendaftarkan: session('connection').
     *
     * @return string|null
     */
    public static function connectionName()
    {
        if (static::$connection) {
            return call_user_func(static::$connection);
        }

        return config('querydebug.connection'); // null => default connection
    }

    /**
     * Kunci pemilik store, supaya batch antar-user tidak tercampur.
     * Default: id user login, fallback ke session id.
     * IAS-PHP mendaftarkan: session('usid').
     */
    public static function identity(): string
    {
        if (static::$identity) {
            $id = call_user_func(static::$identity);

            return ($id !== null && $id !== '') ? (string) $id : 'guest';
        }

        if (Auth::check()) {
            return 'u' . Auth::id();
        }

        return 'sess-' . Session::getId();
    }

    /**
     * Apakah sesi ini sudah "unlock" (gate tahap dua). Default: flag di Cache,
     * SENGAJA bukan session. App jarang perlu mengganti ini.
     *
     * Kenapa Cache, bukan Session::put/forget (versi lama): Laravel menyimpan
     * seluruh isi session sebagai SATU blob yang dibaca utuh di awal request
     * dan ditulis balik utuh di akhir request (StartSession::terminate()) —
     * untuk SEMUA request grup 'web', termasuk GET biasa yang tidak sengaja
     * menyentuh session. Dashboard /viewer polling tiap 2,5 detik, jadi selalu
     * ada request lain yang session-nya sedang "dalam perjalanan" (sudah
     * dibaca, belum ditulis balik) persis saat Lock diproses. Kalau request
     * poll itu SELESAI (dan menulis balik blob session LAMA-nya) SETELAH Lock
     * menulis blob BARU, flag 'active' balik jadi true lagi — persis gejala
     * "sudah Lock tapi /viewer tetap jalan terus, gak pernah 403".
     *
     * Cache::put/forget menulis SATU key secara langsung (bukan baca-ubah-
     * tulis blob besar berisi key lain), jadi tidak kena race ini sama sekali
     * — sejalan dengan pendekatan yang sudah dipakai QueryDebugStore untuk
     * ring buffer batch.
     */
    public static function isActive(): bool
    {
        if (static::$active) {
            return (bool) call_user_func(static::$active);
        }

        return Cache::get(self::activeKey()) === true;
    }

    public static function markActive(): void
    {
        Cache::put(self::activeKey(), true, now()->addMinutes((int) config('querydebug.ttl_minutes', 30)));
    }

    public static function markInactive(): void
    {
        Cache::forget(self::activeKey());
    }

    private static function activeKey(): string
    {
        return 'qdebug:active:' . static::identity();
    }

    /**
     * Metadata yang muncul di header tiket export (mis. cabang, IGR, user).
     * Harus mengembalikan list of ['label' => ..., 'value' => ...].
     *
     * Default: baca dari config querydebug.export.extra_session — cara tanpa
     * kode untuk app yang menyimpan info-nya di session. App yang butuh logika
     * lebih (mis. gabungkan kode + nama cabang) mendaftarkan contextUsing().
     *
     * @return array<int,array{label:string,value:mixed}>
     */
    public static function ticketMeta(): array
    {
        if (static::$context) {
            $out = call_user_func(static::$context);

            return is_array($out) ? array_values(array_filter($out, function ($row) {
                return is_array($row)
                    && isset($row['value'])
                    && $row['value'] !== null
                    && $row['value'] !== '';
            })) : [];
        }

        $meta = [];
        foreach ((array) config('querydebug.export.extra_session', []) as $key => $label) {
            $value = Session::get($key);
            if ($value !== null && $value !== '') {
                $meta[] = ['label' => (string) $label, 'value' => $value];
            }
        }

        return $meta;
    }

    /**
     * Reset — berguna di test untuk melepas closure yang terdaftar.
     */
    public static function flush(): void
    {
        static::$connection = null;
        static::$identity = null;
        static::$active = null;
        static::$context = null;
    }
}
