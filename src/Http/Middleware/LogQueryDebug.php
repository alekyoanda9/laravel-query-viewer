<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Sd1\QueryViewer\Support\Context;
use Sd1\QueryViewer\Support\QueryCollector;
use Sd1\QueryViewer\Support\QueryDebugSql;
use Sd1\QueryViewer\Support\QueryDebugStore;

class LogQueryDebug
{
    public function handle($request, Closure $next)
    {
        if (! $this->active($request)) {
            return $next($request);
        }

        $connection = Context::connectionName(); // null = koneksi default
        $collector  = new QueryCollector();

        DB::connection($connection)->listen(function ($query) use ($collector) {
            $collector->record([
                'connection' => $query->connectionName,
                'time_ms'    => $query->time,
                'sql'        => $query->sql,
                'raw'        => QueryDebugSql::interpolate($query->sql, $query->bindings),
            ]);
        });

        $response = $next($request);

        if ($collector->count() > 0) {
            $route = $request->route();

            QueryDebugStore::push(Context::identity(), [
                'method'  => $request->method(),
                'path'    => $request->path(),
                'route'   => ($route && method_exists($route, 'getName')) ? $route->getName() : null,
                'origin'  => $this->originKey($request),
                'is_ajax' => $request->ajax() ? 1 : 0,
                'at'      => date('Y-m-d H:i:s'),

                // Koneksi + metadata tiket, di-snapshot SAAT query jalan.
                'conn'    => is_string($connection) && $connection !== ''
                    ? $connection
                    : DB::getDefaultConnection(),
                'context' => Context::ticketMeta(),

                'queries' => $collector->all(),
            ]);
        }

        return $response;
    }

    private function originKey($request): string
    {
        $self    = $request->path();
        $referer = $this->refererPath($request);

        if (! $referer) {
            return $self;
        }

        if ($request->ajax()) {
            return $referer;
        }

        if ($this->looksLikeDownload($self)) {
            return $referer;
        }

        return $self;
    }

    private function refererPath($request)
    {
        $referer = $request->headers->get('referer');
        if (! $referer) {
            return null;
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if ($path === false || $path === null || $path === '') {
            return null;
        }

        $path = ltrim($path, '/');

        return $path === '' ? null : $path;
    }

    private function looksLikeDownload($path): bool
    {
        foreach ((array) config('querydebug.download_patterns', []) as $keyword) {
            if ($keyword !== '' && stripos($path, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Aktif hanya kalau: fitur enable + host cocok + sesi sudah unlock.
     * "Koneksi sudah dipilih" tidak lagi jadi syarat wajib di sini karena
     * bisa saja app pakai koneksi default (Context mengembalikan null).
     */
    private function active($request): bool
    {
        if (! config('querydebug.enabled')) {
            return false;
        }

        $host = config('querydebug.host');
        if ($host && $request->getHost() !== $host) {
            return false;
        }

        return Context::isActive();
    }
}
