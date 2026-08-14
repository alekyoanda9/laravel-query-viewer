<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Session;

/**
 * Gate khusus HALAMAN trace viewer.
 *
 * Kenapa tidak pakai VerifyQueryDebugKey saja: gate itu mensyaratkan header
 * X-Query-Debug-Key, dan header custom hanya bisa dikirim oleh fetch/XHR.
 * Halaman trace dibuka dev dengan MENGETIK/KLIK URL di browser — request
 * navigasi biasa tidak bisa membawa header. Kalau dipaksa, halaman ini selalu
 * 403 dan fiturnya mati total.
 *
 * Jadi di sini key diterima lewat query string sekali (?key=…), lalu ditandai
 * di session supaya tidak perlu ikut di URL terus-menerus — sekaligus supaya
 * kode trace aman dibagikan lewat chat tanpa ikut membawa API key di dalamnya.
 */
class VerifyTraceAccess
{
    const SESSION_FLAG = 'querydebug.trace_access';

    public function handle($request, Closure $next)
    {
        if (! config('querydebug.enabled')) {
            abort(404);
        }

        $host = config('querydebug.host');
        if ($host && $request->getHost() !== $host) {
            abort(404);
        }

        if (Session::get(self::SESSION_FLAG) === true) {
            return $next($request);
        }

        $expected = config('querydebug.key');
        $given    = (string) $request->query('key', '');

        if (is_string($expected) && $expected !== '' && $given !== '' && hash_equals($expected, $given)) {
            Session::put(self::SESSION_FLAG, true);

            // Redirect ke URL tanpa ?key= supaya key tidak nyangkut di riwayat
            // browser, di title bar saat share screen, atau di log akses.
            return redirect($request->url());
        }

        abort(403, 'Trace viewer terkunci. Buka sekali dengan ?key=<API key> untuk membuka akses di sesi ini.');
    }
}
