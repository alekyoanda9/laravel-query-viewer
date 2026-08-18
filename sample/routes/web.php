<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Sample routes untuk uji coba lokal laravel-query-viewer. Mensimulasikan
| pola IAS-PHP: session('kdigr'), session('usid'), session('connection').
|
*/

use App\Http\Controllers\DemoController;

Route::get('/', [DemoController::class, 'index'])->name('demo.index');
Route::post('/login', [DemoController::class, 'login'])->name('demo.login');
Route::post('/logout', [DemoController::class, 'logout'])->name('demo.logout');

Route::get('/orders', [DemoController::class, 'orders'])->name('demo.orders');
Route::get('/orders/eager', [DemoController::class, 'ordersEager'])->name('demo.orders.eager');
Route::get('/orders/export', [DemoController::class, 'exportOrders'])->name('demo.orders.export');
