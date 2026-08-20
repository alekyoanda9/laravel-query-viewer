<?php

namespace Sd1\QueryViewer;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Sd1\QueryViewer\Http\Middleware\InjectQueryViewer;
use Sd1\QueryViewer\Http\Middleware\LogQueryDebug;
use Sd1\QueryViewer\Http\Middleware\VerifyBrowserAccess;
use Sd1\QueryViewer\Http\Middleware\VerifyQueryDebugKey;
use Sd1\QueryViewer\Http\Middleware\VerifyTraceAccess;

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
        // lewat navigasi browser). Berbagi flag session dengan trace viewer.
        $router->aliasMiddleware('querydebug.browser', VerifyBrowserAccess::class);

        // Dengarkan query di seluruh request web (middleware sendiri yang cek
        // apakah sesi sudah unlock — kalau belum, ia transparan).
        $router->pushMiddlewareToGroup('web', LogQueryDebug::class);

        // Suntik panel otomatis, kecuali dimatikan (mau @include manual).
        if (config('querydebug.auto_inject', true)) {
            $router->pushMiddlewareToGroup('web', InjectQueryViewer::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/querydebug.php' => config_path('querydebug.php'),
            ], 'query-viewer-config');

            $this->publishes([
                __DIR__ . '/../resources/assets/js/query-debug.js' => public_path('vendor/query-viewer/query-debug.js'),
                __DIR__ . '/../resources/assets/js/query-debug-viewer.js' => public_path('vendor/query-viewer/query-debug-viewer.js'),
            ], 'query-viewer-assets');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/querydebug'),
            ], 'query-viewer-views');
        }
    }
}
