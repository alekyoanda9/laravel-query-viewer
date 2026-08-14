<?php

use Illuminate\Support\Facades\Route;
use Sd1\QueryViewer\Http\Controllers\QueryDebugController;

/*
| Route package. Pakai group 'web' supaya session (flag unlock) + CSRF (untuk
| POST) aktif, lalu 'querydebug.gate' untuk host + API key. Prefix dari config.
|
| Ditulis dengan referensi class (bukan string 'Controller@method') supaya
| tetap valid lintas versi Laravel — untuk Laravel 5.8 pun sintaks array
| [Controller::class, 'method'] sudah didukung di file route.
*/

Route::group([
    'prefix'     => config('querydebug.route_prefix', 'dev/query-debug'),
    'middleware' => ['web', 'querydebug.gate'],
], function () {
    Route::get('/recent', [QueryDebugController::class, 'recent']);
    Route::get('/clear', [QueryDebugController::class, 'clear']);
    Route::post('/explain', [QueryDebugController::class, 'explain']);
    Route::post('/unlock', [QueryDebugController::class, 'unlock']);
    Route::post('/lock', [QueryDebugController::class, 'lock']);
});
