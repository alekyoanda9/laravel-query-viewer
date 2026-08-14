<?php

namespace Sd1\QueryViewer;

use Sd1\QueryViewer\Support\Context;

/**
 * API publik package. Aplikasi memanggil method-method ini SEKALI dari
 * service provider-nya (mis. AppServiceProvider::boot) untuk memberi tahu
 * package cara mengambil koneksi DB, identitas user, dan metadata tiket
 * sesuai konvensi aplikasi itu.
 *
 * Contoh untuk IAS-PHP (PHP 7.1 — pakai closure biasa, bukan arrow fn):
 *
 *   use Sd1\QueryViewer\QueryViewer;
 *
 *   QueryViewer::connectionUsing(function () {
 *       return session('connection');
 *   });
 *
 *   QueryViewer::identifyUsing(function () {
 *       return session('usid') ?: 'guest';
 *   });
 *
 *   QueryViewer::contextUsing(function () {
 *       return [
 *           ['label' => 'Cabang (IGR)', 'value' => session('kdigr')],
 *           ['label' => 'User',         'value' => session('usid')],
 *       ];
 *   });
 *
 * Aplikasi "biasa" (koneksi tunggal + auth standar) tidak perlu memanggil
 * apa pun — default sudah cukup.
 */
class QueryViewer
{
    public static function connectionUsing(callable $cb): void
    {
        Context::connectionUsing($cb);
    }

    public static function identifyUsing(callable $cb): void
    {
        Context::identifyUsing($cb);
    }

    public static function contextUsing(callable $cb): void
    {
        Context::contextUsing($cb);
    }

    public static function activeUsing(callable $cb): void
    {
        Context::activeUsing($cb);
    }
}
