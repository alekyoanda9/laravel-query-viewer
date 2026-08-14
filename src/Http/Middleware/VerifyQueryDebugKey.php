<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;

/**
 * Gate untuk endpoint package: kill-switch + host + API key (hash_equals).
 * unlock/lock/explain (POST) dan recent/clear (GET) semuanya lewat sini.
 * Semua kegagalan tampil sebagai 404/403 tanpa membocorkan alasan detail.
 */
class VerifyQueryDebugKey
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
        $given = $request->header('X-Query-Debug-Key');

        if (! is_string($expected) || $expected === ''
            || ! is_string($given)
            || ! hash_equals($expected, $given)) {
            abort(403);
        }

        return $next($request);
    }
}
