@extends('layouts.app')

@section('title', 'Uživatel: ' . $user->full_name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        {{ $user->full_name }}
    </div>
@endsection

@section('content')
    <h2>{{ $user->full_name }}</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    <div style="margin-bottom:1em;">
        @if($canEdit)
            <a href="{{ route('users.edit', $user->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
            &nbsp;
        @endif
        @if($canChangePassword)
            <a href="{{ route('users.password', $user->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Změnit heslo">
                Změnit heslo
            </a>
            &nbsp;
        @endif
        @if($canChangeAppPwd)
            <a href="{{ route('users.edit', $user->id) }}#application_password">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Změnit app. heslo">
                Změnit app. heslo
            </a>
        @endif
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr><th colspan="2">Informace o uživateli</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>Login</th>
                <td>{{ $user->login }}</td>
            </tr>
            <tr>
                <th>Jméno</th>
                <td>{{ $user->full_name }}</td>
            </tr>
            @if($user->birthday && $user->birthday !== '0000-00-00')
                <tr>
                    <th>Datum narození</th>
                    <td>{{ $user->birthday }}</td>
                </tr>
            @endif
            <tr>
                <th>Typ</th>
                <td>{{ $user->type == 1 ? 'Hlavní uživatel' : 'Uživatel' }}</td>
            </tr>
            @if($user->comment)
                <tr>
                    <th>Komentář</th>
                    <td>{{ $user->comment }}</td>
                </tr>
            @endif
            <tr>
                <th>Člen</th>
                <td>
                    @if($user->member)
                        <a href="{{ route('members.show', $user->member_id) }}">{{ $user->member->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            @if($canViewAppPwd)
                <tr>
                    <th>Aplikační heslo</th>
                    <td>{{ $user->application_password ?: '—' }}</td>
                </tr>
            @endif
        </tbody>
    </table>
@endsection
