@extends('layouts.app')

@section('content')
    <div class="box">
        <strong>Session sekarang:</strong><br>
        kdigr: {{ $current['kdigr'] ?? '-' }} |
        usid: {{ $current['usid'] ?? '-' }} |
        connection: {{ $current['connection'] ?? '(default)' }}
    </div>

    @if ($current['kdigr'] ?? null)
        <form method="POST" action="{{ route('demo.logout') }}">
            @csrf
            <button type="submit">Logout</button>
        </form>
        <p><a href="{{ route('demo.orders') }}">Lihat orders (N+1, tanpa eager load)</a></p>
        <p><a href="{{ route('demo.orders.eager') }}">Lihat orders (eager load, rapi)</a></p>
        <p><a href="{{ route('demo.orders.export') }}">Export orders (nama route mengandung "export")</a></p>
    @else
        <form method="POST" action="{{ route('demo.login') }}">
            @csrf
            <label>Cabang (kdigr):
                <select name="kdigr" required>
                    @foreach ($branches as $b)
                        <option value="{{ $b->kdigr }}">{{ $b->kdigr }} - {{ $b->nama }}</option>
                    @endforeach
                </select>
            </label>
            <br><br>
            <label>User (usid): <input type="text" name="usid" value="tester1" required></label>
            <br><br>
            <button type="submit">Login (set session)</button>
        </form>
    @endif
@endsection
