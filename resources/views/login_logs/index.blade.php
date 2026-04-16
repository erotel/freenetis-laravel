@extends('layouts.app')
@section('title', 'Logy přihlášení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('login_logs.index') }}">Logy přihlášení</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Logy přihlášení</h2></div>
<div class="m-subtitle">Přehled posledních přihlášení uživatelů</div>

<div style="margin-bottom:8px">{{ $users->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Login</th>
            <th>Jméno</th>
            <th style="width:160px">Poslední přihlášení</th>
        </tr>
    </thead>
    <tbody>
        @forelse($users as $user)
        <tr>
            <td>{{ $user->id }}</td>
            <td><a class="m-link" href="{{ route('login_logs.by_user', $user->id) }}">{{ $user->login }}</a></td>
            <td>{{ $user->name }} {{ $user->surname }}</td>
            <td style="font-size:12px;color:#888">{{ $user->last_time }}</td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;color:#aaa;padding:2rem">Žádní uživatelé.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $users->links() }}</div>
</div>
@endsection
