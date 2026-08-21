<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;
use Sd1\QueryViewer\Support\Context;

/**
 * Gate untuk HALAMAN yang dibuka lewat NAVIGASI BROWSER (dashboard /viewer, dan
 * endpoint JSON-nya yang dipanggil dari halaman itu).
 *
 * Request navigasi biasa tidak bisa membawa header X-Query-Debug-Key, jadi akses
 * halaman ini DIIKAT ke status "unlock" sesi (Context::isActive) — status yang
 * SAMA dengan yang dipakai panel & perekaman query:
 *
 *  - kalau sesi sudah unlock (lewat panel ATAU lewat ?key= di bawah) -> boleh;
 *  - kalau di-Lock (Context::markInactive) -> isActive() jadi false -> halaman
 *    ikut terkunci lagi. Flag-nya sendiri disimpan di Cache (lihat Context::
 *    isActive()), bukan Session — supaya polling /viewer/recent tiap 2,5 detik
 *    tidak bisa balik menghidupkan flag ini lewat race penulisan session.
 *  - kalau API key belum di-set di config -> tidak ada yang bisa unlock ->
 *    halaman tetap 403.
 *
 * Key masih bisa diberikan sekali lewat ?key= (untuk buka via link/bookmark
 * tanpa harus buka panel dulu); begitu cocok, sesi ditandai aktif lalu
 * di-redirect ke URL bersih tanpa key (supaya key tidak nyangkut di history /
 * title bar / log akses).
 */
class VerifyBrowserAccess
{
    public function handle($request, Closure $next)
    {
        if (! config('querydebug.enabled')) {
            abort(404);
        }

        $host = config('querydebug.host');
        if ($host && $request->getHost() !== $host) {
            abort(404);
        }

        $expected = config('querydebug.key');
        $given    = (string) $request->query('key', '');

        // Ada ?key= di URL: konsumsi SEKALI lalu SELALU redirect ke URL bersih.
        // Ini WAJIB dilakukan sebelum cek isActive() — kalau tidak, saat halaman
        // dibuka sementara sesi sudah aktif, key menempel di address bar dan
        // setiap refresh akan mem-markActive() ulang (membuka kunci lagi setelah
        // Lock). Key valid -> tandai aktif; key salah -> tetap dibuang tanpa
        // menandai aktif (biar tidak bocor & tidak loop).
        if ($given !== '') {
            if (is_string($expected) && $expected !== '' && hash_equals($expected, $given)) {
                Context::markActive();
            }

            $params = $request->query();
            unset($params['key']);

            return redirect($request->url() . (empty($params) ? '' : ('?' . http_build_query($params))));
        }

        // Tanpa key di URL: murni bergantung status unlock sesi.
        if (Context::isActive()) {
            return $next($request);
        }

        abort(403, 'Dashboard terkunci. Unlock Query Viewer dulu (lewat panel atau ?key=<API key>) untuk sesi ini.');
    }
}
