@extends('layouts.app')

@section('title', 'Logy přihlášení')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('login_logs.index') }}">Logy přihlášení</a>
    </div>
@endsection

@section('content')
    <h2>Logy přihlášení</h2>
    <p><em>Přehled posledních přihlášení uživatelů</em></p>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Login</th>
                <th>Jméno</th>
                <th>Poslední přihlášení</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                <tr>
                    <td>{{ $user->id }}</td>
                    <td>
                        <a href="{{ route('login_logs.by_user', $user->id) }}">{{ $user->login }}</a>
                    </td>
                    <td>{{ $user->name }} {{ $user->surname }}</td>
                    <td>{{ $user->last_time }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Žádní uživatelé.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $users->links() }}
    </div>
@endsection
