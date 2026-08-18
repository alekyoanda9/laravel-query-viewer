@extends('layouts.app')

@section('content')
    <p><a href="{{ route('demo.index') }}">&larr; kembali</a></p>

    <h3>{{ $eager ?? false ? 'Orders (eager load)' : 'Orders (N+1 sengaja)' }}</h3>

    <table>
        <tr><th>No Order</th><th>Cabang</th><th>Total</th></tr>
        @foreach ($orders as $order)
            <tr>
                <td>{{ $order->no_order }}</td>
                {{-- akses relasi di loop tanpa eager load = N+1, kalau
                     $eager belum di-load lewat with('branch') --}}
                <td>{{ $order->branch->nama }}</td>
                <td>{{ number_format($order->total, 0, ',', '.') }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin-top:16px;color:#666;font-size:13px;">
        Buka panel query viewer lalu bandingkan jumlah query &amp; temuan Insight
        antara halaman ini dengan
        <a href="{{ route('demo.orders.eager') }}">versi eager load</a>.
    </p>
@endsection
