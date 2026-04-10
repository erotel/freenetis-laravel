@extends('layouts.app')

@section('title', 'Přihlášení uživatele ' . $targetUser->login)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('login_logs.index') }}">Logy přihlášení</a> &raquo;
        {{ $targetUser->login }}
    </div>
@endsection

@section('content')
    <h2>Přihlášení uživatele {{ $targetUser->login }}</h2>

    {{ $logs->links() }}
    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Čas</th>
                <th>IP adresa</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log->id }}</td>
                    <td>{{ $log->time?->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $log->IP_address }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Žádné záznamy přihlášení.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $logs->links() }}
    </div>
@endsection
