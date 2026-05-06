@extends('layouts.app')
@section('title', 'Přihlášení uživatele ' . $targetUser->login)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('login_logs.index') }}">Logy přihlášení</a> &raquo; {{ $targetUser->login }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přihlášení uživatele {{ $targetUser->login }}</h2></div>

<div style="margin-bottom:8px">{{ $logs->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:160px">Čas</th>
            <th>IP adresa</th>
        </tr>
    </thead>
    <tbody>
        @forelse($logs as $log)
        <tr>
            <td>{{ $log->id }}</td>
            <td style="font-size:14px">{{ $log->time?->format('d.m.Y H:i:s') }}</td>
            <td style="font-family:monospace">{{ $log->IP_address }}</td>
        </tr>
        @empty
        <tr><td colspan="3" style="text-align:center;color:#aaa;padding:2rem">Žádné záznamy přihlášení.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $logs->links() }}</div>
</div>
@endsection
