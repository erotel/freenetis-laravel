@extends('layouts.app')

@section('title', 'Změnit heslo')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        @if($member)
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        @endif
        Změnit heslo
    </div>
@endsection

@section('content')
    <h2>Změnit heslo: {{ $user->full_name }}</h2>

    <form method="POST" action="{{ route('users.password.update', $user->id) }}" class="form">
        @csrf
        @method('PUT')

        @if($errors->any())
            <div style="background:#f8d7da; border:1px solid #f5c6cb; padding:10px; margin-bottom:1em; border-radius:4px;">
                <ul style="margin:0; padding-left:1.2em;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <table class="extended" cellspacing="0">
            <thead>
                <tr><th colspan="2">Nové heslo</th></tr>
            </thead>
            <tbody>
                <tr>
                    <th><label for="password">Nové heslo <span class="required">*</span></label></th>
                    <td>
                        <input type="password" id="password" name="password" style="width:250px" autocomplete="new-password">
                        @error('password') <span style="color:red">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="password_confirmation">Heslo znovu <span class="required">*</span></label></th>
                    <td>
                        <input type="password" id="password_confirmation" name="password_confirmation" style="width:250px" autocomplete="new-password">
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:1.5em;">
            <button type="submit">Uložit heslo</button>
            @if($member)
            <a href="{{ route('members.show', $member->id) }}" style="margin-left:1em;">Zrušit</a>
            @else
            <a href="{{ route('users.show', $user->id) }}" style="margin-left:1em;">Zrušit</a>
            @endif
        </div>
    </form>
@endsection
