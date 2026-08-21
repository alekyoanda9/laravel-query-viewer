<?php

namespace Sd1\QueryViewer\Http\Middleware;

use Closure;
use Sd1\QueryViewer\Support\Context;

/**
 * Gate tahap dua untuk endpoint DATA panel: sesi harus dalam status unlock
 * (Context::isActive). Dipasang SETELAH VerifyQueryDebugKey.
 *
 * Inilah yang menautkan panel ke status Lock: begitu markInactive() dipanggil
 * dari mana pun (tombol Lock panel, tombol Lock viewer, atau logout), /recent
 * balas 423 dan panel mengunci dirinya sendiri.
 *
 * unlock & lock TIDAK memakai gate ini — unlock justru harus jalan saat sesi
 * belum aktif (kalau tidak: ayam-dan-telur, tak akan pernah bisa unlock).
 */
class VerifyActive
{
    public function handle($request, Closure $next)
    {
        if (! Context::isActive()) {
            // 423 Locked, sengaja dibedakan dari 403 "key salah" supaya panel
            // bisa menampilkan pesan yang benar (dikunci, bukan key keliru).
            abort(423, 'Sesi Query Viewer sedang dikunci.');
        }

        return $next($request);
    }
}