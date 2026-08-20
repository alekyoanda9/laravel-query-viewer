<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Session;

/**
 * Gate untuk HALAMAN yang dibuka lewat NAVIGASI BROWSER (dashboard /viewer, dan
 * endpoint JSON-nya yang dipanggil dari halaman itu).
 *
 * Sama seperti VerifyTraceAccess: request navigasi biasa tidak bisa membawa
 * header X-Query-Debug-Key, jadi key diterima sekali lewat ?key=… lalu ditandai
 * di session. Flag session-nya DIBAGI dengan trace viewer — sekali dev membuka
 * salah satu (trace atau dashboard) dengan ?key=, keduanya ikut terbuka di sesi
 * itu, jadi tidak perlu menempel key dua kali.
 *
 * Ini generalisasi yang dimaksud §3.2: satu pola akses browser dipakai bersama
 * trace viewer & dashboard.
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

        if (Session::get(VerifyTraceAccess::SESSION_FLAG) === true) {
            return $next($request);
        }

        $expected = config('querydebug.key');
        $given    = (string) $request->query('key', '');

        if (is_string($expected) && $expected !== '' && $given !== '' && hash_equals($expected, $given)) {
            Session::put(VerifyTraceAccess::SESSION_FLAG, true);

            // Redirect ke URL tanpa ?key= supaya key tidak nyangkut di riwayat
            // browser / title bar saat share screen / log akses. Query lain
            // (mis. filter) dipertahankan.
            $params = $request->query();
            unset($params['key']);

            return redirect($request->url() . (empty($params) ? '' : ('?' . http_build_query($params))));
        }

        abort(403, 'Halaman terkunci. Buka sekali dengan ?key=<API key> untuk membuka akses di sesi ini.');
    }
}
