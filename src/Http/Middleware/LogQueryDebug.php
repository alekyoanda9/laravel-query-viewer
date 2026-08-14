<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
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

        $requestError = null;

        try {
            $response = $next($request);

            // PENTING: untuk error yang terjadi di dalam controller, $next()
            // TIDAK melempar exception. Routing pipeline Laravel menangkap
            // exception itu di dalam dirinya, me-render-nya lewat
            // ExceptionHandler, lalu MENGEMBALIKAN response 500 sebagai nilai
            // biasa — sambil menempelkan exception aslinya ke response lewat
            // $response->withException($e) (tersimpan di $response->exception).
            //
            // Jadi cara yang benar mendeteksi error di sini BUKAN menunggu
            // catch di bawah (yang praktis tidak pernah kena untuk error
            // controller), melainkan memeriksa response yang dikembalikan.
            $exception = $this->exceptionFromResponse($response);

            if ($exception !== null) {
                // Kalau errornya query SQL yang gagal, DB::listen() tidak
                // pernah fire untuknya — jadi rekam manual dari exception.
                if ($exception instanceof QueryException) {
                    $this->recordFailedQuery($collector, $exception);
                }

                $requestError = $this->describeError($exception);
            }

            return $response;
        } catch (QueryException $e) {
            // Fallback: hanya kena kalau exception BENAR-BENAR lolos sampai
            // sini (mis. ExceptionHandler tidak ter-bind, atau exception
            // dilempar dari middleware lain di luar destination). Untuk alur
            // normal, cabang ini tidak dieksekusi.
            $this->recordFailedQuery($collector, $e);
            $requestError = $this->describeError($e);
            throw $e;
        } catch (\Throwable $e) {
            $requestError = $this->describeError($e);
            throw $e;
        } finally {
            // finally SELALU jalan — request sukses, response ber-error, query
            // gagal, maupun exception yang benar-benar lolos.
            $this->flush($request, $collector, $connection, $requestError);
        }
    }

    /**
     * Ambil exception asli yang ditempelkan Laravel ke response saat error
     * di-render oleh routing pipeline (via $response->withException()).
     * Mengembalikan null kalau response ini bukan hasil error.
     *
     * @return \Throwable|null
     */
    private function exceptionFromResponse($response)
    {
        if (
            is_object($response)
            && isset($response->exception)
            && $response->exception instanceof \Throwable
        ) {
            return $response->exception;
        }

        return null;
    }

    /**
     * Tambahkan entri untuk query yang gagal ke collector, diambil dari
     * QueryException itu sendiri (bukan dari listen(), yang tidak pernah
     * fire untuk query yang gagal).
     */
    private function recordFailedQuery(QueryCollector $collector, QueryException $e): void
    {
        $sql = method_exists($e, 'getSql') ? (string) $e->getSql() : '';
        if ($sql === '') {
            return; // tidak ada apa pun yang bisa direkam
        }

        $bindings = method_exists($e, 'getBindings') ? (array) $e->getBindings() : [];

        try {
            $raw = QueryDebugSql::interpolate($sql, $bindings);
        } catch (\Throwable $ignored) {
            $raw = $sql; // interpolasi gagal (kasus langka) -> tampilkan template mentah
        }

        $collector->record([
            'connection' => method_exists($e, 'getConnectionName') ? $e->getConnectionName() : null,
            'time_ms'    => 0,
            'sql'        => $sql,
            'raw'        => $raw,
            'failed'     => true,
            'error'      => $this->shortMessage($e->getMessage()),
        ]);
    }

    /** @return array{class:string,message:string} */
    private function describeError(\Throwable $e): array
    {
        return [
            'class'   => get_class($e),
            'message' => $this->shortMessage($e->getMessage()),
        ];
    }

    private function shortMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message));
        $message = $message === null ? '' : $message;

        return strlen($message) > 500 ? substr($message, 0, 500) . '…' : $message;
    }

    /**
     * @param array|null $requestError ['class' => string, 'message' => string] kalau request ini error
     */
    private function flush($request, QueryCollector $collector, $connection, $requestError): void
    {
        // Tetap push walau query KOSONG asalkan request-nya error — supaya
        // "halaman ini 500 tanpa sempat menjalankan query apa pun" pun tetap
        // kelihatan di panel, bukan cuma senyap.
        if ($collector->count() === 0 && $requestError === null) {
            return;
        }

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

            // null kalau request sukses normal.
            'error'   => $requestError,

            'queries' => $collector->all(),
        ]);
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
