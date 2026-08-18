<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Sd1\QueryViewer\QueryViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
    // Koneksi DB mana yang query-nya didengarkan (null = default app).
    QueryViewer::connectionUsing(function () {
        return session('connection');
    });

    // Kunci pemilik store, supaya batch antar-user tidak tercampur.
    QueryViewer::identifyUsing(function () {
        return session('usid') ?: 'guest';
    });

    // Metadata yang muncul di header tiket export.
    QueryViewer::contextUsing(function () {
        return [
            ['label' => 'Cabang (IGR)', 'value' => session('kdigr')],
            ['label' => 'User',         'value' => session('usid')],
        ];
    });
    }
}
