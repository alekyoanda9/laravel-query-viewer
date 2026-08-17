<?php

use Illuminate\Support\Facades\Route;
use Sd1\QueryViewer\Http\Controllers\QueryDebugController;
use Sd1\QueryViewer\Http\Controllers\TraceController;

/*
| Route package. Pakai group 'web' supaya session (flag unlock) + CSRF (untuk
| POST) aktif, lalu 'querydebug.gate' untuk host + API key. Prefix dari config.
|
| Ditulis dengan referensi class (bukan string 'Controller@method') supaya
| tetap valid lintas versi Laravel — untuk Laravel 5.8 pun sintaks array
| [Controller::class, 'method'] sudah didukung di file route.
*/

$prefix = config('querydebug.route_prefix', 'dev/query-debug');

/*
| Endpoint yang dipanggil panel lewat fetch(): gate header API key.
*/
Route::group([
    'prefix'     => $prefix,
    'middleware' => ['web', 'querydebug.gate'],
], function () {
    Route::get('/recent', [QueryDebugController::class, 'recent']);
    Route::get('/clear', [QueryDebugController::class, 'clear']);
    Route::post('/explain', [QueryDebugController::class, 'explain']);
    Route::post('/unlock', [QueryDebugController::class, 'unlock']);
    Route::post('/lock', [QueryDebugController::class, 'lock']);

    // Support menekan tombol di panel -> promote ring buffer jadi trace.
    Route::post('/trace/capture', [TraceController::class, 'capture']);
});

/*
| Halaman trace viewer: DIBUKA DEV LEWAT BROWSER, jadi tidak bisa mengirim
| header custom. Gate-nya beda (key sekali lewat ?key=, lalu flag session).
| Route JSON ikut gate yang sama supaya dev bisa langsung men-download
| lampiran tiket dari browser yang sama tanpa tooling tambahan.
*/
Route::group([
    'prefix'     => $prefix . '/trace',
    'middleware' => ['web', 'querydebug.trace'],
], function () {
    Route::get('/', [TraceController::class, 'index']);
    Route::post('/prune', [TraceController::class, 'prune']);
    Route::get('/{code}', [TraceController::class, 'show']);
    Route::get('/{code}/json', [TraceController::class, 'json']);
    Route::get('/{code}/file/{idx}', [TraceController::class, 'attachment']);
    Route::post('/{code}/delete', [TraceController::class, 'delete']);
});
