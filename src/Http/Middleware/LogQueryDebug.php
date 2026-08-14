<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Sd1\QueryViewer\Support\Context;
use Sd1\QueryViewer\Support\QueryCollector;
use Sd1\QueryViewer\Support\QueryDebugSql;
use Sd1\QueryViewer\Support\QueryDebugStore;
use Sd1\QueryViewer\Support\StepRedactor;

class LogQueryDebug
{
    public function handle($request, Closure $next)
    {
        if (! $this->active($request)) {
            return $next($request);
        }

        $connection = Context::connectionName(); // null = koneksi default
        $collector  = new QueryCollector();
        $startedAt  = microtime(true);
        $status     = null;

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

            if (method_exists($response, 'getStatusCode')) {
                $status = $response->getStatusCode();
            }

            return $response;
        } catch (QueryException $e) {
            // Query yang GAGAL tidak pernah memicu listen() di atas — Laravel
            // hanya melempar event QueryExecuted untuk query yang berhasil.
            // Query-query lain yang sukses SEBELUM ini tetap ada di $collector
            // (listener fire real-time per query), jadi di sini kita cuma
            // perlu menambahkan satu entri untuk query yang gagal itu sendiri,
            // diambil dari SQL + bindings yang menempel di exception-nya.
            $this->recordFailedQuery($collector, $e);
            $requestError = $this->describeError($e);
            throw $e;
        } catch (\Throwable $e) {
            // Exception non-DB (validasi, logic error, dsb). Tidak ada query
            // spesifik untuk direkam, tapi request-nya sendiri tetap dicatat
            // sebagai error di level batch supaya kelihatan di panel.
            $requestError = $this->describeError($e);
            throw $e;
        } finally {
            // finally SELALU jalan — baik request sukses, query gagal, maupun
            // exception lain — jadi flush tidak lagi ikut lenyap kalau
            // request-nya error. Ini yang memperbaiki gap sebelumnya: dulu
            // flush ada SETELAH `$next($request)`, jadi kalau itu throw,
            // baris flush tidak pernah dieksekusi sama sekali.
            $this->flush($request, $collector, $connection, $requestError, $status, $startedAt);
        }
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
    private function flush($request, QueryCollector $collector, $connection, $requestError, $status = null, $startedAt = null): void
    {
        // Dulu syaratnya cuma count() > 0. Sekarang tetap push walau query
        // KOSONG asalkan request-nya error — supaya "halaman ini 500 tanpa
        // sempat menjalankan query apa pun" pun tetap kelihatan di panel,
        // bukan cuma senyap.
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

            // --- tiga field di bawah ini yang mengubah "batch query" jadi
            // "step yang bisa direproduksi". Tanpa input, dev cuma tahu halaman
            // apa yang dibuka, bukan filter/nilai apa yang dipakai.
            'input'    => StepRedactor::input($this->safeInput($request)),
            'status'   => $status,
            'dur_ms'   => $startedAt ? (int) round((microtime(true) - $startedAt) * 1000) : null,

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

    /**
     * Ambil input request dengan aman. Dibungkus try/catch karena request
     * dengan body non-parseable (JSON rusak, multipart aneh) bisa melempar —
     * dan middleware debug TIDAK BOLEH mematikan request aslinya.
     */
    private function safeInput($request): array
    {
        if (! (bool) config('querydebug.trace.capture_input', true)) {
            return [];
        }

        try {
            $input = $request->except(['_token']);

            return is_array($input) ? $input : [];
        } catch (\Throwable $e) {
            return [];
        }
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
