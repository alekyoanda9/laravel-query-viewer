<?php

namespace App\Http\Controllers;

use App\Branch;
use App\Order;
use Illuminate\Http\Request;

class DemoController extends Controller
{
    /**
     * Halaman awal: pilih "cabang" + "user", simulasi login IAS-PHP.
     * Ini yang mengisi session('kdigr'), session('usid'), session('connection')
     * yang dibaca oleh QueryViewer::connectionUsing()/identifyUsing()/contextUsing()
     * di AppServiceProvider.
     */
    public function index()
    {
        $branches = Branch::all();

        return view('demo.index', [
            'branches' => $branches,
            'current'  => [
                'kdigr'      => session('kdigr'),
                'usid'       => session('usid'),
                'connection' => session('connection'),
            ],
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'kdigr' => 'required|exists:branches,kdigr',
            'usid'  => 'required|string',
        ]);

        session([
            'kdigr'      => $request->input('kdigr'),
            'usid'       => $request->input('usid'),
            // Contoh app multi-koneksi: nama koneksi turut cabang. Karena
            // sample ini cuma pakai satu koneksi sqlite, isi null saja
            // (artinya "koneksi default aplikasi") supaya query viewer tetap
            // mendengarkan koneksi yang benar-benar dipakai model.
            'connection' => null,
        ]);

        return redirect()->route('demo.orders');
    }

    public function logout()
    {
        session()->forget(['kdigr', 'usid', 'connection']);

        return redirect()->route('demo.index');
    }

    /**
     * Daftar order — SENGAJA N+1 (akses $order->branch->nama di loop tanpa
     * eager load) supaya panel Insight punya sesuatu untuk dideteksi.
     */
    public function orders()
    {
        abort_unless(session('kdigr'), 403, 'Pilih cabang dulu di halaman utama.');

        $orders = Order::orderByDesc('id')->limit(20)->get();

        return view('demo.orders', ['orders' => $orders]);
    }

    /**
     * Versi rapi dari halaman di atas, pakai eager load, buat perbandingan
     * "sebelum vs sesudah" di panel Insight.
     */
    public function ordersEager()
    {
        abort_unless(session('kdigr'), 403, 'Pilih cabang dulu di halaman utama.');

        $orders = Order::with('branch')->orderByDesc('id')->limit(20)->get();

        return view('demo.orders', ['orders' => $orders, 'eager' => true]);
    }

    /**
     * Endpoint dengan nama mengandung pola download_patterns (config
     * querydebug.download_patterns) — buat lihat bagaimana panel menandai
     * request semacam ini (mis. export/cetak) di grup query.
     */
    public function exportOrders()
    {
        abort_unless(session('kdigr'), 403, 'Pilih cabang dulu di halaman utama.');

        $orders = Order::with('branch')->get();

        return response()->json([
            'exported_at' => now()->toDateTimeString(),
            'count'       => $orders->count(),
        ]);
    }
}
