<?php

namespace Sd1\QueryViewer;

use Illuminate\Auth\Events\Logout;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Sd1\QueryViewer\Http\Middleware\InjectQueryViewer;
use Sd1\QueryViewer\Http\Middleware\LogQueryDebug;
use Sd1\QueryViewer\Http\Middleware\VerifyBrowserAccess;
use Sd1\QueryViewer\Http\Middleware\VerifyQueryDebugKey;
use Sd1\QueryViewer\Http\Middleware\VerifyTraceAccess;
use Sd1\QueryViewer\Support\Context;
use Sd1\QueryViewer\Support\QueryDebugStore;
use Sd1\QueryViewer\Http\Middleware\VerifyActive;

class QueryViewerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/querydebug.php', 'querydebug');
    }

    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'querydebug');
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        // Gate untuk endpoint package.
        $router->aliasMiddleware('querydebug.gate', VerifyQueryDebugKey::class);

        // Gate terpisah untuk halaman trace viewer (dibuka lewat navigasi
        // browser, jadi tidak bisa membawa header X-Query-Debug-Key).
        $router->aliasMiddleware('querydebug.trace', VerifyTraceAccess::class);

        // Gate untuk halaman dashboard /viewer + endpoint JSON-nya (juga dibuka
        // lewat navigasi browser). Pakai flag Cache yang SAMA dengan panel
        // (Context::isActive()) — TIDAK dengan trace viewer, yang gate-nya
        // sendiri (VerifyTraceAccess::SESSION_FLAG) dan tidak ikut ter-Lock.
        $router->aliasMiddleware('querydebug.browser', VerifyBrowserAccess::class);

        $router->aliasMiddleware('querydebug.active', VerifyActive::class);

        // Dengarkan query di seluruh request web (middleware sendiri yang cek
        // apakah sesi sudah unlock — kalau belum, ia transparan).
        $router->pushMiddlewareToGroup('web', LogQueryDebug::class);

        // Suntik panel otomatis, kecuali dimatikan (mau @include manual).
        if (config('querydebug.auto_inject', true)) {
            $router->pushMiddlewareToGroup('web', InjectQueryViewer::class);
        }

        // Bersihkan ring buffer + kunci lagi begitu dev logout, supaya dev
        // berikutnya yang pakai browser/session yang sama tidak mewarisi log
        // query dev sebelumnya. Identity() DIAMBIL SEBELUM Auth::logout()
        // menuntaskan proses (event Logout membawa $event->user, konsisten
        // dipakai kalau app mendaftarkan identifyUsing() dari Auth::user()).
        // Kalau identifyUsing() app mengambil dari session (mis. IAS-PHP:
        // session('usid')), PASTIKAN controller logout app TIDAK menghapus
        // session key itu SEBELUM memanggil Auth::logout() / Session::flush()
        // — kalau urutannya kebalik, identity() sudah 'guest' duluan saat
        // listener ini jalan dan yang ke-clear bukan store milik user itu.
        if (config('querydebug.enabled') && config('querydebug.clear_on_logout', true)) {
            Event::listen(Logout::class, function () {
                QueryDebugStore::clearFor(Context::identity());
                Context::markInactive();
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/querydebug.php' => config_path('querydebug.php'),
            ], 'query-viewer-config');

            $this->publishes([
                __DIR__ . '/../resources/assets/js/query-debug-shared.js' => public_path('vendor/query-viewer/query-debug-shared.js'),
                __DIR__ . '/../resources/assets/js/query-debug.js' => public_path('vendor/query-viewer/query-debug.js'),
                __DIR__ . '/../resources/assets/js/query-debug-viewer.js' => public_path('vendor/query-viewer/query-debug-viewer.js'),
            ], 'query-viewer-assets');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/querydebug'),
            ], 'query-viewer-views');
        }
    }
}
