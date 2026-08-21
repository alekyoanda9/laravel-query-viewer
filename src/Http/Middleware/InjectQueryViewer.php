<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\View;

/**
 * Menyuntik markup panel tepat sebelum </body> pada response HTML, supaya tim
 * TIDAK perlu menambah @include('querydebug::panel') di layout masing-masing.
 *
 * Aktif hanya kalau enabled + host cocok + response HTML yang punya </body>.
 * Panel Blade sendiri sudah menjaga enabled+host, dan JS menahan di balik key —
 * jadi menyuntik markup tidak berarti fitur "nyala"; ia baru jalan setelah
 * user submit API key.
 */
class InjectQueryViewer
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (! config('querydebug.enabled') || ! config('querydebug.auto_inject', true)) {
            return $response;
        }

        $host = config('querydebug.host');
        if ($host && $request->getHost() !== $host) {
            return $response;
        }

        // JANGAN suntik panel ke halaman milik package sendiri. Dashboard
        // (/viewer) & trace viewer adalah halaman full-page yang punya UI-nya
        // sendiri (dan </body> juga) — panel FAB melayang di atasnya cuma dobel
        // dan membingungkan. Endpoint API JSON tidak akan lolos cek text/html di
        // bawah, tapi ikut dikecualikan di sini demi jelasnya.
        $prefix = trim((string) config('querydebug.route_prefix', 'dev/query-debug'), '/');
        if ($prefix !== '' && ($request->is($prefix) || $request->is($prefix . '/*'))) {
            return $response;
        }

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }

        $contentType = method_exists($response, 'headers')
            ? $response->headers->get('Content-Type')
            : null;
        if ($contentType && stripos($contentType, 'text/html') === false) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || stripos($content, '</body>') === false) {
            return $response;
        }

        if (! View::exists('querydebug::panel')) {
            return $response;
        }

        $panel = View::make('querydebug::panel')->render();

        // Sisipkan sebelum </body> terakhir.
        $pos = strripos($content, '</body>');
        $content = substr($content, 0, $pos) . $panel . substr($content, $pos);

        $response->setContent($content);

        return $response;
    }
}
