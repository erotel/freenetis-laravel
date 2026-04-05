@extends('layouts.app')

@section('title', 'Přidat uživatele')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        Přidat nového uživatele
    </div>
@endsection

@section('content')
    <h2>Přidat nového uživatele</h2>

    <form method="POST" action="{{ route('users.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <thead>
                <tr><th colspan="2">Základní informace</th></tr>
            </thead>
            <tbody>
                <tr>
                    <th><label for="member_id">Člen <span class="required">*</span></label></th>
                    <td>
                        @if($member)
                            <input type="hidden" name="member_id" value="{{ $member->id }}">
                            {{ $member->name }}
                        @else
                            <select id="member_id" name="member_id">
                                <option value="">— vyberte člena —</option>
                                @foreach($members as $m)
                                    <option value="{{ $m->id }}" @selected(old('member_id') == $m->id)>
                                        {{ $m->name }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                        @error('member_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                @if($canEditLogin)
                    <tr>
                        <th><label for="login">Login <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="login" name="login" value="{{ old('login') }}" maxlength="100">
                            @error('login') <span class="error">{{ $message }}</span> @enderror
                        </td>
                    </tr>
                @endif
                <tr>
                    <th><label for="password">Heslo <span class="required">*</span></label></th>
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
                <tr>
                    <th><label for="pre_title">Titul před</label></th>
                    <td>
                        <input type="text" id="pre_title" name="pre_title" value="{{ old('pre_title') }}" maxlength="50">
                        @error('pre_title') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="name">Jméno</label></th>
                    <td>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="100">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="middle_name">Druhé jméno</label></th>
                    <td>
                        <input type="text" id="middle_name" name="middle_name" value="{{ old('middle_name') }}" maxlength="100">
                        @error('middle_name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="surname">Příjmení <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="surname" name="surname" value="{{ old('surname') }}" maxlength="100">
                        @error('surname') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="post_title">Titul za</label></th>
                    <td>
                        <input type="text" id="post_title" name="post_title" value="{{ old('post_title') }}" maxlength="50">
                        @error('post_title') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="birthday">Datum narození</label></th>
                    <td>
                        <input type="date" id="birthday" name="birthday" value="{{ old('birthday') }}">
                        @error('birthday') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="type">Typ <span class="required">*</span></label></th>
                    <td>
                        <select id="type" name="type">
                            <option value="1" @selected(old('type', 2) == 1)>Hlavní uživatel</option>
                            <option value="2" @selected(old('type', 2) == 2)>Uživatel</option>
                        </select>
                        @error('type') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="comment">Poznámka</label></th>
                    <td>
                        <textarea id="comment" name="comment" maxlength="250">{{ old('comment') }}</textarea>
                        @error('comment') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
