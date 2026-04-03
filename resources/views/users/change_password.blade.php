@extends('layouts.app')

@section('title', 'Změnit heslo: ' . $user->full_name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
        Změnit heslo
    </div>
@endsection

@section('content')
    <h2>Změnit heslo: {{ $user->full_name }}</h2>

    <form method="POST" action="{{ route('users.password.update', $user->id) }}" class="form">
        @csrf
        @method('PUT')

        <table class="extended" cellspacing="0">
            <thead>
                <tr><th colspan="2">Nové heslo</th></tr>
            </thead>
            <tbody>
                <tr>
                    <th><label for="password">Nové heslo <span class="required">*</span></label></th>
                    <td>
                        <input type="password" id="password" name="password" autocomplete="new-password">
                        @error('password') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="password_confirmation">Potvrdit heslo <span class="required">*</span></label></th>
                    <td>
                        <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Změnit heslo"></p>
    </form>
@endsection
