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

        // Panel query-viewer memoles dirinya sendiri lewat polling (/recent
        // tiap beberapa detik) + endpoint lain (explain, unlock, capture,
        // trace/*). Semua itu jalan lewat middleware grup 'web' yang sama,
        // jadi TANPA pengecualian ini, tiap polling ikut kerekam sebagai
        // "langkah" — buffer cepat penuh dengan panggilan panel sendiri,
        // bukan aksi support yang sesungguhnya, dan step setup yang lama bisa
        // tergeser keluar sebelum sempat di-capture.
        if ($this->isOwnRequest($request)) {
            return $next($request);
        }

        $connection = Context::connectionName(); // null = koneksi default
        $collector  = new QueryCollector();
        $startedAt  = microtime(true);
        $status     = null;

        DB::connection($connection)->listen(function ($query) use ($collector) {
            $source = $this->captureSource();

            $collector->record([
                'connection' => $query->connectionName,
                'time_ms'    => $query->time,
                'sql'        => $query->sql,
                'raw'        => QueryDebugSql::interpolate($query->sql, $query->bindings),
                'file'       => $source['file'],
                'line'       => $source['line'],
            ]);
        });

        $requestError = null;
        $responseData = null;

        try {
            $response = $next($request);

            if (method_exists($response, 'getStatusCode')) {
                $status = $response->getStatusCode();
            }

            // Tangkap response (json/text saja, dibatasi ukuran, binary=metadata)
            // untuk tab Response di dashboard & halaman trace. Dibungkus try
            // supaya kegagalan capture TIDAK pernah mengganggu response asli.
            try {
                $responseData = \Sd1\QueryViewer\Support\ResponseCapturer::capture($response);
            } catch (\Throwable $ignored) {
                $responseData = null;
            }

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
            $this->flush($request, $collector, $connection, $requestError, $status, $startedAt, $responseData);
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

        $source = $this->captureSource();

        $collector->record([
            'connection' => method_exists($e, 'getConnectionName') ? $e->getConnectionName() : null,
            'time_ms'    => 0,
            'sql'        => $sql,
            'raw'        => $raw,
            'failed'     => true,
            'error'      => $this->shortMessage($e->getMessage()),
            'file'       => $source['file'],
            'line'       => $source['line'],
        ]);
    }

    /**
     * Tangkap file & baris kode PEMANGGIL query — mengikuti pola Telescope
     * FetchesStackTrace, tapi dengan prioritas WHITELIST bukan cuma blacklist:
     *
     *   1. Cari frame pertama yang jelas milik kode developer (app/, routes/)
     *      — ini paling actionable buat programmer, langsung tunjuk
     *      Controller/Model/Service yang relevan.
     *   2. Kalau sampai akhir trace tidak ada frame app/ sama sekali (mis.
     *      query terpicu murni dari lazy-load relasi saat Blade merender,
     *      Controller sudah selesai lebih dulu), pakai fallback: frame
     *      pertama yang bukan vendor/package-sendiri. Kalau fallback itu
     *      compiled view (storage/framework/views/xxxx.php), resolve balik
     *      ke path .blade.php asli supaya tetap actionable.
     *
     * @return array{file:?string,line:?int}
     */
    private function captureSource(): array
    {
        if (! (bool) config('querydebug.source.enabled', true)) {
            return ['file' => null, 'line' => null];
        }

        $depth = (int) config('querydebug.source.depth', 30);
        if ($depth < 1) {
            $depth = 30;
        }

        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth);

        $fallback = null;

        foreach ($frames as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }

            $file = str_replace('\\', '/', $frame['file']);

            if ($this->isInternalFrame($file)) {
                continue;
            }

            $line = isset($frame['line']) ? (int) $frame['line'] : null;

            // Prioritas #1: kode milik developer (app/, routes/) — paling
            // berguna buat dilihat programmer.
            if ($this->isAppFrame($file)) {
                return [
                    'file' => $this->relativePath($frame['file']),
                    'line' => $line,
                ];
            }

            // Bukan vendor, bukan app/ (mis. compiled view, closure route
            // di file lain) — simpan sebagai kandidat fallback, tapi tetap
            // lanjut cari frame app/ yang lebih berguna di frame berikutnya.
            if ($fallback === null) {
                $fallback = ['file' => $frame['file'], 'line' => $line];
            }
        }

        if ($fallback === null) {
            return ['file' => null, 'line' => null];
        }

        // Fallback compiled view? Coba resolve balik ke .blade.php asli
        // supaya bukan nama hash yang tidak actionable.
        $resolved = $this->resolveCompiledView($fallback['file']);
        if ($resolved !== null) {
            return ['file' => $resolved, 'line' => $fallback['line']];
        }

        return [
            'file' => $this->relativePath($fallback['file']),
            'line' => $fallback['line'],
        ];
    }

    /**
     * True kalau file ini kode aplikasi milik developer (app/, routes/) —
     * bukan file generated/compiled seperti storage/framework/views atau
     * bootstrap/cache.
     */
    private function isAppFrame(string $file): bool
    {
        $appPath = str_replace('\\', '/', app_path()) . '/';
        if (strpos($file, $appPath) === 0) {
            return true;
        }

        $routesPath = str_replace('\\', '/', base_path('routes')) . '/';
        if (strpos($file, $routesPath) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Compiled view (storage/framework/views/xxxx.php) tidak actionable buat
     * developer karena namanya hash. Laravel selalu menyisipkan komentar
     * berisi path asli .blade.php di baris terakhir file compiled, format:
     * /**PATH /full/path/to/original.blade.php ENDPATH**\/
     * Baca baris itu untuk balikin ke source Blade asli.
     */
    private function resolveCompiledView(string $compiledFile): ?string
    {
        if (strpos(str_replace('\\', '/', $compiledFile), '/storage/framework/views/') === false) {
            return null;
        }

        if (! is_readable($compiledFile)) {
            return null;
        }

        // Komentar PATH ada di baris TERAKHIR file compiled, jadi baca dari
        // belakang secukupnya saja (hindari load file gede penuh ke memory).
        $tail = $this->readTail($compiledFile, 500);
        if ($tail === null) {
            return null;
        }

        if (preg_match('#/\*\*PATH\s+(.+?)\s+ENDPATH\*\*/#', $tail, $m)) {
            return $this->relativePath(trim($m[1]));
        }

        return null;
    }

    /**
     * Baca N byte terakhir sebuah file tanpa load seluruh isinya ke memory.
     */
    private function readTail(string $path, int $bytes): ?string
    {
        $handle = @fopen($path, 'r');
        if (! $handle) {
            return null;
        }

        $size = filesize($path) ?: 0;
        $seek = max(0, $size - $bytes);

        fseek($handle, $seek);
        $tail = stream_get_contents($handle);
        fclose($handle);

        return $tail === false ? null : $tail;
    }

    private function isInternalFrame(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        // Framework & library (termasuk package ini kalau dipasang via composer).
        if (strpos($file, '/vendor/') !== false) {
            return true;
        }

        // Direktori SOURCE package ini sendiri (src/), untuk kasus di-load
        // lewat path repo (bukan vendor). __DIR__ = src/Http/Middleware ->
        // naik 2 = src/.
        //
        // PENTING: sengaja dipersempit ke src/ saja, BUKAN root repo
        // (dirname naik 3). Kalau pakai root repo, folder testing seperti
        // sample/ (app Laravel dummy yang ditaruh di dalam repo package
        // untuk keperluan dev lokal) ikut ke-exclude juga — padahal
        // app/Http/Controllers di dalam sample/ itu justru yang HARUS
        // kedeteksi sebagai "kode developer", persis seperti app/ di project
        // integrator yang sesungguhnya.
        $pkgSrcRoot = str_replace('\\', '/', dirname(__DIR__, 2));
        if ($pkgSrcRoot !== '' && strpos($file, $pkgSrcRoot . '/') === 0) {
            return true;
        }

        foreach ((array) config('querydebug.source.ignore', []) as $needle) {
            $needle = str_replace('\\', '/', (string) $needle);
            if ($needle !== '' && strpos($file, $needle) !== false) {
                return true;
            }
        }

        return false;
    }


    private function relativePath(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $base = str_replace('\\', '/', base_path());

        if ($base !== '' && strpos($file, $base . '/') === 0) {
            return substr($file, strlen($base) + 1);
        }

        return $file;
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
    private function flush($request, QueryCollector $collector, $connection, $requestError, $status = null, $startedAt = null, $responseData = null): void
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

            // Response yang ditangkap (json/text=body, binary=metadata) atau null.
            'response' => $responseData,

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

    /**
     * True kalau request ini menuju endpoint package sendiri (recent, explain,
     * capture, trace/*, dst) — dikenali dari route_prefix, bukan daftar route
     * eksplisit, supaya otomatis ikut kalau ada endpoint baru ditambah nanti.
     */
    private function isOwnRequest($request): bool
    {
        $prefix = trim((string) config('querydebug.route_prefix', 'dev/query-debug'), '/');
        if ($prefix === '') {
            return false;
        }

        $path = trim($request->path(), '/');

        return $path === $prefix || strpos($path, $prefix . '/') === 0;
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
